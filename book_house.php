<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/booking_store.php';
require_once __DIR__ . '/inventory_store.php';
require_once __DIR__ . '/security.php';  // CSRF protection

$today_str = date('Y-m-d');
$kubo_id = intval($_GET['id'] ?? ($_POST['kubo_id'] ?? 1));
$check_in = trim($_GET['check_in'] ?? ($_POST['check_in'] ?? $today_str));
if (!strtotime($check_in) || $check_in < $today_str) {
    $check_in = $today_str;
}
$check_out = trim($_GET['check_out'] ?? ($_POST['check_out'] ?? date('Y-m-d', strtotime($check_in . ' +1 day'))));
if (!strtotime($check_out) || $check_out <= $check_in) {
    $check_out = date('Y-m-d', strtotime($check_in . ' +1 day'));
}

// Check if user is returning with an existing booking reference to preserve all details
$existing_ref = trim($_GET['ref'] ?? ($_POST['existing_ref'] ?? ''));
if (empty($existing_ref) && !empty($_SESSION['user_id'])) {
    if (!empty($_SESSION['last_booking']['id']) && !empty($_SESSION['last_booking']['item_id']) && intval($_SESSION['last_booking']['item_id']) === $kubo_id) {
        $existing_ref = $_SESSION['last_booking']['id'];
    } else {
        $check_pending = BookingStore::getPendingBookingForUser($_SESSION['user_id'], 'house', $kubo_id);
        if ($check_pending && empty($check_pending['payment_proof']) && empty($check_pending['gcash_ref'])) {
            $existing_ref = $check_pending['id'];
        }
    }
}
$existing_booking = null;
if (!empty($existing_ref)) {
    $existing_booking = BookingStore::getBookingById($existing_ref);
    if ($existing_booking && !empty($_SESSION['user_id'])) {
        $b_user_id = $existing_booking['user_id'] ?? null;
        $b_email = strtolower(trim($existing_booking['guest_email'] ?? ''));
        $curr_email = strtolower(trim($_SESSION['email'] ?? ''));
        $curr_id = $_SESSION['user_id'] ?? 0;
        $user_role = $_SESSION['role'] ?? 'guest';
        $is_admin = ($user_role === 'admin' || $user_role === 'staff');
        if ($b_user_id != $curr_id && $b_email !== $curr_email && !$is_admin) {
            $existing_booking = null;
            $existing_ref = '';
        }
    }
}

// Pre-populate from existing booking if available
$existing_in_time = '14:00';
$existing_out_time = '12:00';
$existing_notes = '';
$existing_guest_list = '';
$existing_addon_id = 0;
$existing_addon_pax = 1;
$existing_addon_ids = [];       // all previously selected add-on activity IDs
$existing_addon_pax_map = [];   // activity_id => participants (per-activity counts)
$existing_addon_date = $check_in;
$existing_addon_time = '09:00 AM';
$existing_addon_time24 = '09:00';

if ($existing_booking) {
    if (!isset($_GET['id']) && !empty($existing_booking['item_id'])) {
        $kubo_id = intval($existing_booking['item_id']);
    }
    if (!isset($_GET['check_in']) && !empty($existing_booking['check_in'])) {
        $check_in = substr($existing_booking['check_in'], 0, 10);
    }
    if (!isset($_GET['check_out']) && !empty($existing_booking['check_out'])) {
        $check_out = substr($existing_booking['check_out'], 0, 10);
    }
    if (!empty($existing_booking['check_in'])) {
        $t_in = date('H:i', strtotime($existing_booking['check_in']));
        if ($t_in && $t_in !== '00:00') $existing_in_time = $t_in;
    }
    if (!empty($existing_booking['check_out'])) {
        $t_out = date('H:i', strtotime($existing_booking['check_out']));
        if ($t_out && $t_out !== '00:00') $existing_out_time = $t_out;
    }
    $existing_guest_list = $existing_booking['guest_list'] ?? '';
    $raw_notes = $existing_booking['special_notes'] ?? '';
    if (strpos($raw_notes, '[Pool & Activities Add-ons]:') !== false) {
        $existing_notes = trim(explode('[Pool & Activities Add-ons]:', $raw_notes)[0]);
    } else {
        $existing_notes = $raw_notes;
    }

    // Load existing addons from session or database
    if (!empty($_SESSION['last_booking']) && ($_SESSION['last_booking']['id'] ?? '') === $existing_ref && !empty($_SESSION['last_booking']['addons'])) {
        $first_addon = $_SESSION['last_booking']['addons'][0];
        $existing_addon_id = intval($first_addon['activity_id'] ?? 0);
        $existing_addon_date = $first_addon['scheduled_date'] ?? $check_in;
        $existing_addon_time = $first_addon['time_slot'] ?? '09:00 AM';
        if (!empty($first_addon['start_time'])) {
            $existing_addon_time24 = date('H:i', strtotime($first_addon['start_time']));
        }
        foreach ($_SESSION['last_booking']['addons'] as $s_addon) {
            $s_id = intval($s_addon['activity_id'] ?? 0);
            if ($s_id <= 0) continue;
            $existing_addon_ids[] = $s_id;
            $existing_addon_pax_map[$s_id] = max(1, intval($s_addon['pax'] ?? $s_addon['qty'] ?? 1));
        }
    }
    if ($existing_addon_id === 0) {
        global $pdo;
        if ($pdo) {
            try {
                $stmt_a = $pdo->prepare("SELECT * FROM addon_reservations WHERE reservation_id = :ref");
                $stmt_a->execute([':ref' => $existing_ref]);
                $addon_rows_db = $stmt_a->fetchAll(PDO::FETCH_ASSOC);
                foreach ($addon_rows_db as $idx_a => $row_a) {
                    $r_id = intval($row_a['activity_id']);
                    if ($r_id <= 0) continue;
                    $existing_addon_ids[] = $r_id;
                    $existing_addon_pax_map[$r_id] = max(1, intval($row_a['quantity'] ?? 1));
                    if ($existing_addon_id === 0) {
                        $existing_addon_id = $r_id;
                        $existing_addon_date = $row_a['activity_date'] ?? $check_in;
                        if (!empty($row_a['start_time'])) {
                            $existing_addon_time24 = date('H:i', strtotime($row_a['start_time']));
                            $existing_addon_time = date('g:i A', strtotime($row_a['start_time']));
                        }
                    }
                }
            } catch (Throwable $e_a) {}
        }
    }
    // Main add-on participant count = the largest group joining any add-on (pool / whole group)
    if (!empty($existing_addon_pax_map)) {
        $existing_addon_pax = max($existing_addon_pax_map);
    }
}

// Get kubo details dynamically
$kubo = InventoryStore::getKuboById($kubo_id);
if (!$kubo) {
    header("Location: browse_kubos.php");
    exit();
}

$base_capacity = intval($kubo['capacity'] ?? 4);
if ($base_capacity <= 0) $base_capacity = 4;

$lead_guest_name = !empty($_SESSION['full_name']) ? $_SESSION['full_name'] : (!empty($existing_booking['guest_name']) ? $existing_booking['guest_name'] : '');
$existing_guest_names = [];
if (!empty($existing_guest_list)) {
    $raw_lines = preg_split("/\r\n|\n|\r/", $existing_guest_list);
    foreach ($raw_lines as $r_line) {
        $r_line = trim($r_line);
        if ($r_line !== '') {
            $existing_guest_names[] = $r_line;
        }
    }
}
if (empty($existing_guest_names) && !empty($lead_guest_name)) {
    $existing_guest_names = [$lead_guest_name];
}
$initial_guest_count = max(1, count($existing_guest_names) ?: 1);

$status = strtolower($kubo['status'] ?? 'available');
$is_renovation = ($status === 'renovation');
$total_units = BookingStore::getKuboUnits($kubo_id);
$initial_remaining_units = BookingStore::getRemainingUnits($kubo_id, $check_in, $check_out, $existing_ref);

// Retrieve existing booked ranges and occupied dates for this specific kubo
$booked_ranges = BookingStore::getBookedRangesForItem($kubo_id, 'house');
$booked_dates = BookingStore::getBookedDatesForItem($kubo_id, 'house');
$is_initially_available = ($total_units > 0 && $initial_remaining_units > 0 && BookingStore::isItemAvailable($kubo_id, $check_in, $check_out, 'house', $existing_ref));

// Calculate nights & price
$d1 = new DateTime($check_in);
$d2 = new DateTime($check_out);
$nights = max(1, $d1->diff($d2)->days);
$item_name = $kubo['name'] ?? "House Kubo #{$kubo_id}";
$rate_per_night = floatval($kubo['price'] ?? 3500);
$total_price = $nights * $rate_per_night;

// Fetch pool and resort activities for optional add-ons
$all_activities = InventoryStore::getAllActivities();
$available_activities = array_filter($all_activities, function($act) {
    return !empty($act['is_available']);
});

// Strictly require registered account login to book
if (empty($_SESSION['user_id'])) {
    $redirect_params = 'id=' . $kubo_id;
    if (!empty($_GET['check_in'])) $redirect_params .= '&check_in=' . urlencode($_GET['check_in']);
    if (!empty($_GET['check_out'])) $redirect_params .= '&check_out=' . urlencode($_GET['check_out']);
    if (!empty($existing_ref)) $redirect_params .= '&ref=' . urlencode($existing_ref);
    $login_url = 'login.php?msg=' . urlencode('Please sign in with your registered account before booking. Please sign in or register first.') . '&redirect=' . urlencode('book_house.php?' . $redirect_params);
    header("Location: " . $login_url);
    exit();
}

// Disallow blocked user from booking
require_once __DIR__ . '/auth.php';
if ($block_reason = Auth::isUserBlocked($_SESSION['user_id'])) {
    Auth::logout();
    header("Location: login.php?msg=" . urlencode("Your account has been deactivated/blocked by management: " . $block_reason));
    exit();
}

$error_msg = '';

// Check if user already has an active (confirm or pending) booking for this specific house
$is_admin_session = (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'staff']));
$active_conflict = (!empty($_SESSION['user_id']) && !$is_admin_session) ? BookingStore::getActiveBookingForUser($_SESSION['user_id'], 'house', $kubo_id, $_SESSION['email'] ?? null) : null;
$has_active_conflict = false;
$active_conflict_type = '';

if ($active_conflict) {
    $c_status = strtolower($active_conflict['booking_status'] ?? 'pending');
    $c_ref = $active_conflict['id'] ?? '';
    
    // Check if this is an in-progress checkout recovery for the exact same draft reference
    $p_proof = $active_conflict['payment_proof'] ?? '';
    $p_gref = $active_conflict['gcash_ref'] ?? '';
    $has_proof_attached = (!empty($p_proof) && $p_proof !== 'uploads/receipts/sample_receipt.jpg') || !empty($p_gref);

    if (in_array($c_status, ['confirmed', 'approved', 'checked_in'])) {
        // Confirmed reservation: user cannot book this house again while this booking is active
        $has_active_conflict = true;
        $active_conflict_type = 'confirmed';
        $error_msg = "You already have a CONFIRMED reservation (#" . htmlspecialchars($c_ref) . ") for this accommodation (" . htmlspecialchars($item_name) . ") for stay dates " . date('M d, Y', strtotime($active_conflict['check_in'])) . " – " . date('M d, Y', strtotime($active_conflict['check_out'])) . ". You cannot book this house again while you have an active confirmed reservation.";
    } elseif (in_array($c_status, ['pending', 'resched', 'reschedule_requested'])) {
        if (!$has_proof_attached && !empty($existing_ref) && $c_ref === $existing_ref) {
            // Recovering the same in-progress draft before payment proof upload
            $existing_booking = $active_conflict;
        } else {
            $has_active_conflict = true;
            $active_conflict_type = 'pending';
            $error_msg = "You already have a PENDING reservation (#" . htmlspecialchars($c_ref) . ") for this accommodation (" . htmlspecialchars($item_name) . "). Please wait until your existing booking is verified or completed before booking this house again.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();  // reject forged booking submissions
    $submitted_check_in  = trim($_POST['check_in'] ?? '');
    $submitted_check_out = trim($_POST['check_out'] ?? '');
    $submitted_check_in_time  = trim($_POST['check_in_time'] ?? '14:00');
    $submitted_check_out_time = trim($_POST['check_out_time'] ?? '12:00');

    // Guest contact details now come from the logged-in account (no form fields)
    $user_id = $_SESSION['user_id'];
    $profile_id = $_SESSION['profile_id'] ?? sprintf("PBR-GST-%05d", $user_id);
    $guest_name = !empty($_SESSION['full_name']) ? $_SESSION['full_name'] : 'Registered Guest';
    $guest_email = !empty($_SESSION['email']) ? $_SESSION['email'] : 'guest@example.com';
    $guest_phone = !empty($_SESSION['phone']) ? preg_replace('/[^0-9]/', '', $_SESSION['phone']) : '09170000000';

    $today_str = date('Y-m-d');

    // Resort operating hours: 8:00 AM - 10:00 PM
    $in_ts  = strtotime($submitted_check_in_time);
    $out_ts = strtotime($submitted_check_out_time);
    $in_hm  = $in_ts ? date('H:i', $in_ts) : '';
    $out_hm = $out_ts ? date('H:i', $out_ts) : '';
    $within_hours = ($in_hm !== '' && $out_hm !== ''
        && $in_hm >= '08:00' && $in_hm <= '22:00'
        && $out_hm >= '08:00' && $out_hm <= '22:00');

    // Process Optional Pool & Activities Add-ons & Additional Kubos
    $addon_activity_ids = $_POST['addon_activity_ids'] ?? [];
    $additional_kubos = $_POST['additional_kubos'] ?? [];

    // Calculate maximum allowed capacity based on primary kubo + any additional kubos
    $allowed_max_capacity = intval($kubo['capacity'] ?? 4);
    if ($allowed_max_capacity <= 0) $allowed_max_capacity = 4;
    foreach ($additional_kubos as $ak_id) {
        $ak_item = InventoryStore::getKuboById(intval($ak_id));
        if ($ak_item) {
            $allowed_max_capacity += intval($ak_item['capacity'] ?? 2);
        }
    }

    // Process guest names array or fallback to raw guest_list
    $raw_guest_names = $_POST['guest_names'] ?? [];
    $final_guest_names = [];
    if (is_array($raw_guest_names)) {
        foreach ($raw_guest_names as $gn) {
            $gn_clean = trim((string)$gn);
            if ($gn_clean !== '') {
                $final_guest_names[] = $gn_clean;
            }
        }
    }
    $submitted_guest_list = !empty($final_guest_names) ? implode("\n", $final_guest_names) : trim($_POST['guest_list'] ?? '');
    $submitted_pax = intval($_POST['num_guests'] ?? $_POST['pax'] ?? count($final_guest_names));
    if ($submitted_pax <= 0) $submitted_pax = max(1, count($final_guest_names));
    if ($submitted_pax > $allowed_max_capacity) $submitted_pax = $allowed_max_capacity;

    if ($has_active_conflict) {
        // Strictly block submission while user has an active confirmed or pending booking
    } elseif (empty($submitted_check_in) || empty($submitted_check_out)) {
        $error_msg = "Please select both Check-In and Check-Out dates on the calendar to complete your reservation.";
    } elseif (!strtotime($submitted_check_in) || !strtotime($submitted_check_out)) {
        $error_msg = "Invalid stay dates submitted. Please select valid dates from the calendar.";
    } elseif ($submitted_check_in < $today_str) {
        $error_msg = "Check-In date cannot be in the past. Please select today or a future date.";
    } elseif ($submitted_check_out <= $submitted_check_in) {
        $error_msg = "Check-Out date must be after your Check-In date (minimum 1 night stay).";
    } elseif (!$within_hours) {
        $error_msg = "Please choose check-in and check-out times within resort hours (8:00 AM - 10:00 PM).";
    } elseif ($is_renovation || $total_units <= 0) {
        $error_msg = "Sorry, this accommodation currently has 0 available units and cannot be reserved.";
    } elseif (!BookingStore::isItemAvailable($kubo_id, $submitted_check_in, $submitted_check_out, 'house', $existing_ref) || BookingStore::getRemainingUnits($kubo_id, $submitted_check_in, $submitted_check_out, $existing_ref) <= 0) {
        $error_msg = "Sorry! This room (" . htmlspecialchars($item_name) . ") has 0 units available for " . date('M d, Y', strtotime($submitted_check_in)) . " - " . date('M d, Y', strtotime($submitted_check_out)) . ". Please pick different dates on the calendar or select another accommodation.";
    } elseif (empty($submitted_guest_list)) {
        $error_msg = "Please provide the names of all guests staying for the resort manifest (maximum {$allowed_max_capacity} guests).";
    } else {
        $d1 = new DateTime($submitted_check_in);
        $d2 = new DateTime($submitted_check_out);
        $calc_nights = max(1, $d1->diff($d2)->days);
        $room_total = $calc_nights * $rate_per_night;

        // Process Optional Pool & Activities Add-ons
        $selected_addons = [];
        $addons_total = 0;
        $addons_summary_list = [];
        
        // Add additional kubos to cost and notes
        foreach($additional_kubos as $ak_id) {
            $ak_id = intval($ak_id);
            $ak = InventoryStore::getKuboById($ak_id);
            if ($ak) {
                $ak_price = floatval($ak['price'] ?? 0);
                $room_total += ($calc_nights * $ak_price);
                $addons_summary_list[] = "• Additional Kubo: {$ak['name']} ({$calc_nights} Nights) - ₱" . number_format($calc_nights * $ak_price, 2);
            }
        }
        
        // Add-on activities follow the same rules as book_activities.php:
        // pool activities are only limited by pool capacity and use the main participant count;
        // every other (equipment/capacity limited) activity has its own cap and its own participant
        // count whenever it is combined with the pool or with other limited activities.
        $addon_main_pax = max(1, min(intval($_POST['addon_pax'] ?? 2), 50));
        $addon_date = trim($_POST['addon_date'] ?? $submitted_check_in);
        $addon_time = trim($_POST['addon_time'] ?? '09:00 AM');
        $addon_pax_post = $_POST['addon_activity_pax'] ?? [];
        if (!is_array($addon_pax_post)) $addon_pax_post = [];
        $addon_time_ts = strtotime($addon_time);
        $addon_start_time = ($addon_time_ts ? date('H:i', $addon_time_ts) : '09:00') . ':00';

        $addon_rows = [];
        $addon_n_pool = 0;
        $addon_n_lim = 0;
        $addon_pool_min = PHP_INT_MAX;
        $seen_addon_ids = [];
        foreach ((array)$addon_activity_ids as $addon_act_id) {
            $addon_act_id = intval($addon_act_id);
            if ($addon_act_id <= 0 || isset($seen_addon_ids[$addon_act_id])) continue;
            $seen_addon_ids[$addon_act_id] = true;
            $act_item = InventoryStore::getActivityById($addon_act_id);
            if (!$act_item) continue;

            $duration = intval($act_item['duration_minutes'] ?? 60);
            if ($duration <= 0) $duration = 60;
            $a_end_time = date('H:i:s', strtotime($addon_start_time) + ($duration * 60));
            $avail = BookingStore::getActivityAvailability($addon_act_id, $addon_date, $addon_start_time, $a_end_time);
            $act_limit = $avail;

            $is_pool_act = strtolower(trim($act_item['category'] ?? '')) === 'pool';
            if (!$is_pool_act) {
                $c_cap = intval($act_item['capacity'] ?? 10);
                $c_reserved = max(0, $c_cap - $avail);
                $c_eq = $act_item['equipment'] ?? [];
                if (is_string($c_eq)) {
                    $c_eq = json_decode($c_eq, true);
                }
                if (is_array($c_eq)) {
                    foreach ($c_eq as $c_item) {
                        $c_rem = max(0, intval($c_item['qty'] ?? $c_cap) - $c_reserved);
                        if ($c_rem < $act_limit) {
                            $act_limit = $c_rem;
                        }
                    }
                }
            }

            $addon_rows[] = ['act' => $act_item, 'id' => $addon_act_id, 'is_pool' => $is_pool_act, 'limit' => $act_limit, 'end_time' => $a_end_time];
            if ($is_pool_act) {
                $addon_n_pool++;
                $addon_pool_min = min($addon_pool_min, $act_limit);
            } else {
                $addon_n_lim++;
            }
        }

        if (!empty($addon_rows)) {
            $addon_is_split = ($addon_n_pool > 0 && $addon_n_lim > 0) || $addon_n_lim >= 2;
            if ($addon_is_split) {
                $lim_limits = array_map(function($r) { return $r['limit']; }, array_filter($addon_rows, function($r) { return !$r['is_pool']; }));
                $addon_main_limit = $addon_n_pool > 0 ? $addon_pool_min : max($lim_limits);
            } else {
                $addon_main_limit = min(array_map(function($r) { return $r['limit']; }, $addon_rows));
            }

            if ($addon_main_pax > $addon_main_limit) {
                $error_msg = "Sorry, availability for your selected add-on activities has changed. Only {$addon_main_limit} slot(s) remaining for this date/time.";
            } else {
                foreach ($addon_rows as $r) {
                    $act_item = $r['act'];
                    $addon_act_id = $r['id'];
                    $act_name = $act_item['activity_name'] ?? "Activity #{$addon_act_id}";

                    // Resolve this activity's participant count
                    if ($addon_is_split && !$r['is_pool']) {
                        $req = max(1, intval($addon_pax_post[$addon_act_id] ?? $addon_main_pax));
                        $cap_here = min($r['limit'], $addon_main_pax);
                        if ($req > $cap_here) {
                            $error_msg = "Only {$cap_here} participant(s) can join the add-on {$act_name} (equipment/slots remaining: {$r['limit']}).";
                            break;
                        }
                        $pax = $req;
                    } else {
                        $pax = $addon_main_pax;
                    }

                    $act_price = floatval($act_item['price'] ?? 0);
                    $subtotal = $pax * $act_price;
                    $addons_total += $subtotal;
                    $is_full_day = ($act_item['activity_type'] ?? 'full_day') === 'full_day';

                    $selected_addons[] = [
                        'activity_id' => $addon_act_id,
                        'name' => $act_name,
                        'price' => $act_price,
                        'qty' => $pax,
                        'pax' => $pax,
                        'subtotal' => $subtotal,
                        'scheduled_date' => $addon_date,
                        'time_slot' => $is_full_day ? 'Full Day' : $addon_time,
                        'start_time' => $is_full_day ? null : $addon_start_time,
                        'end_time' => $is_full_day ? null : $r['end_time']
                    ];
                    $formatted_date = date('M d, Y', strtotime($addon_date));
                    $addons_summary_list[] = "• {$act_name} ({$pax} Pax @ ₱" . number_format($act_price, 2) . ") - ₱" . number_format($subtotal, 2) . " [Scheduled: {$formatted_date} at " . ($is_full_day ? 'Full Day' : $addon_time) . "]";
                }
            }
        } // end add-on activities

        if (empty($error_msg)) {
            $calc_total = $room_total + $addons_total;

            // Append add-ons cleanly to special notes for guest and admin review
            $notes_raw = trim($_POST['notes'] ?? '');
            $final_special_notes = $notes_raw;
            if (!empty($addons_summary_list)) {
                $addon_block = "[Pool & Activities Add-ons]:\n" . implode("\n", $addons_summary_list);
                $final_special_notes = !empty($final_special_notes) 
                    ? ($final_special_notes . "\n\n" . $addon_block) 
                    : $addon_block;
            }

            $format_in_time = date('g:i A', strtotime($submitted_check_in_time ?: '14:00'));
            $format_out_time = date('g:i A', strtotime($submitted_check_out_time ?: '12:00'));
            $check_in_dt = $submitted_check_in . ' ' . date('H:i:s', strtotime($submitted_check_in_time ?: '14:00'));
            $check_out_dt = $submitted_check_out . ' ' . date('H:i:s', strtotime($submitted_check_out_time ?: '12:00'));
            $time_slot_str = "{$calc_nights} Night(s) Stay ({$format_in_time} - {$format_out_time})";

            if (!empty($existing_ref)) {
                $ref_no = $existing_ref;
                global $pdo;
                if ($pdo) {
                    try {
                        $stmt_up = $pdo->prepare("
                            UPDATE bookings 
                            SET item_id = :item_id,
                                item_name = :item_name,
                                check_in = :check_in,
                                check_out = :check_out,
                                time_slot = :time_slot,
                                pax = :pax,
                                num_guests = :pax,
                                total_amount = :total_amount,
                                special_notes = :special_notes,
                                guest_list = :guest_list
                            WHERE id = :id AND (user_id = :user_id OR :is_admin = 1)
                        ");
                        $stmt_up->execute([
                            ':item_id' => $kubo_id,
                            ':item_name' => $item_name,
                            ':check_in' => $check_in_dt,
                            ':check_out' => $check_out_dt,
                            ':time_slot' => $time_slot_str,
                            ':pax' => $submitted_pax,
                            ':total_amount' => $calc_total,
                            ':special_notes' => $final_special_notes,
                            ':guest_list' => $submitted_guest_list,
                            ':id' => $ref_no,
                            ':user_id' => $user_id,
                            ':is_admin' => ($user_role === 'admin' || $user_role === 'staff') ? 1 : 0
                        ]);

                        // Refresh addon_reservations
                        $stmt_del = $pdo->prepare("DELETE FROM addon_reservations WHERE reservation_id = :ref");
                        $stmt_del->execute([':ref' => $ref_no]);
                        if (!empty($selected_addons)) {
                            foreach ($selected_addons as $addon) {
                                $stmt_add = $pdo->prepare("
                                    INSERT INTO addon_reservations (reservation_id, activity_id, activity_date, start_time, end_time, quantity)
                                    VALUES (:reservation_id, :activity_id, :activity_date, :start_time, :end_time, :quantity)
                                ");
                                $stmt_add->execute([
                                    ':reservation_id' => $ref_no,
                                    ':activity_id' => $addon['activity_id'],
                                    ':activity_date' => $addon['scheduled_date'],
                                    ':start_time' => $addon['start_time'] ?? null,
                                    ':end_time' => $addon['end_time'] ?? null,
                                    ':quantity' => $addon['pax'] ?? 1
                                ]);
                            }
                        }
                    } catch (Throwable $e_up) {
                        error_log("Update existing booking error: " . $e_up->getMessage());
                    }
                }

                $booking = BookingStore::getBookingById($ref_no);
                if ($booking) {
                    $booking['addons'] = $selected_addons;
                    $_SESSION['last_booking'] = $booking;
                }
                header("Location: payment_method.php?ref=" . urlencode($ref_no));
                exit();
            } else {
                $ref_no = 'PBR-' . date('Y') . '-' . random_int(1000, 9999);

                $booking = BookingStore::createBooking([
                    'id' => $ref_no,
                    'guest_name' => $guest_name,
                    'guest_email' => $guest_email,
                    'guest_phone' => $guest_phone,
                    'type' => 'house',
                    'item_id' => $kubo_id,
                    'item_name' => $item_name,
                    'check_in' => $check_in_dt,
                    'check_out' => $check_out_dt,
                    'time_slot' => $time_slot_str,
                    'pax' => $submitted_pax,
                    'num_guests' => $submitted_pax,
                    'total_amount' => $calc_total,
                    'profile_id' => $profile_id,
                    'user_id' => $user_id,
                    'payment_status' => 'pending',
                    'booking_status' => 'pending',
                    'special_notes' => $final_special_notes,
                    'guest_list' => $submitted_guest_list,
                    'addons' => $selected_addons
                ]);

                $_SESSION['last_booking'] = $booking;
                header("Location: payment_method.php?ref=" . $ref_no);
                exit();
            }
        }
    }
}

$user_role = $_SESSION['role'] ?? 'guest';
$is_admin_layout = ($user_role === 'admin' || $user_role === 'staff');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reserve <?= htmlspecialchars($item_name) ?> | Purple Bamboo Resort</title>
    <?php if ($is_admin_layout): ?>
        <link rel="stylesheet" href="admin-style.css?v=<?= time() ?>">
    <?php else: ?>
        <link rel="stylesheet" href="style.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Reservation Flow Progress Tracker */
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1.25rem;
            margin: 0.5rem auto 2rem auto;
            width: 100%;
            max-width: 960px;
            box-sizing: border-box;
            padding: 0 0.5rem;
        }
        .step-item {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            font-size: 0.88rem;
            font-weight: 700;
            color: #94a3b8;
            text-decoration: none;
            flex: 0 0 auto !important;
            min-width: 0;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap !important;
        }
        .step-item.active {
            color: #7c3aed;
            font-weight: 800;
        }
        .step-item.done {
            color: #059669;
        }
        .step-num {
            width: 28px;
            height: 28px;
            min-width: 28px;
            max-width: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.78rem;
            font-weight: 800;
            background: #e2e8f0;
            color: #475569;
            flex-shrink: 0;
            transition: all 0.25s ease;
        }
        .step-item.active .step-num {
            background: #7c3aed;
            color: #ffffff;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.18), 0 3px 10px rgba(124, 58, 237, 0.35);
        }
        .step-item.done .step-num {
            background: #10b981;
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }
        .step-divider {
            color: #cbd5e1;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin: 0 2px;
        }
        .step-label {
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
            line-height: 1.2;
        }
        .step-text-full { display: inline; }
        .step-text-short { display: none; }

        /* Page layout expansion */
        .booking-page-container {
            max-width: 1240px;
            width: 100%;
            margin: 0 auto;
            padding: 2.25rem 1.5rem 4rem 1.5rem;
            box-sizing: border-box;
        }

        .booking-page-header {
            margin-bottom: 2rem;
        }
        .booking-page-header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: clamp(1.65rem, 3.5vw, 2.35rem);
            font-weight: 800;
            color: #1e1b4b;
            line-height: 1.2;
            margin-bottom: 0.4rem;
        }
        .booking-page-header p {
            color: #64748b;
            font-size: clamp(0.92rem, 1.8vw, 1.05rem);
            margin: 0;
        }

        /* Stay Schedule & Calendar Section Redesign */
        .stay-schedule-section {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 18px;
            padding: 1.5rem;
            margin-bottom: 1.85rem;
            box-shadow: 0 4px 16px -2px rgba(124, 58, 237, 0.05);
            transition: all 0.2s ease;
        }
        .schedule-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .schedule-live-badge {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            font-size: 0.76rem;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            letter-spacing: 0.3px;
        }

        /* Live Selected Dates Overview Banner */
        .schedule-overview-banner {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            gap: 1rem;
            background: linear-gradient(135deg, #faf5ff 0%, #f5f3ff 100%);
            border: 1.5px solid #ddd6fe;
            border-radius: 14px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.06);
        }
        .overview-date-col {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .overview-date-col.checkout-col {
            text-align: right;
            align-items: flex-end;
        }
        .overview-label {
            font-size: 0.74rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b21a8;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .overview-date {
            font-size: 1.12rem;
            font-weight: 800;
            color: #1e1b4b;
            font-family: 'Outfit', sans-serif;
            line-height: 1.3;
        }
        .overview-time {
            font-size: 0.82rem;
            font-weight: 700;
            color: #7c3aed;
        }
        .overview-date-col.checkout-col .overview-label {
            color: #b45309;
        }
        .overview-date-col.checkout-col .overview-time {
            color: #d97706;
        }
        .overview-divider-col {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }
        .overview-nights-badge {
            background: linear-gradient(135deg, #7c3aed, #9333ea);
            color: #ffffff;
            font-size: 0.78rem;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 20px;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 2px 6px rgba(124, 58, 237, 0.25);
        }
        .overview-arrow-line {
            color: #cbd5e1;
            font-size: 0.85rem;
        }

        /* Duration Selector Pills */
        .stay-nights-badge {
            font-size: 0.8rem;
            background: #ede9fe;
            color: #7c3aed;
            padding: 2px 10px;
            border-radius: 20px;
            font-weight: 800;
            border: 1px solid #ddd6fe;
        }
        .stay-duration-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .duration-pill-btn {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            color: #334155;
            padding: 7px 14px;
            border-radius: 9px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .duration-pill-btn:hover {
            border-color: #7c3aed;
            color: #7c3aed;
            background: #f5f3ff;
            transform: translateY(-1px);
        }
        .duration-pill-btn.active {
            background: #7c3aed;
            border-color: #7c3aed;
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(124, 58, 237, 0.25);
        }
        .duration-pill-btn.custom-pill {
            background: #faf5ff;
            border-color: #c084fc;
            color: #7c3aed;
            font-weight: 800;
        }
        .duration-pill-btn.custom-pill:hover {
            background: #7c3aed;
            color: #ffffff;
            border-color: #7c3aed;
        }
        .duration-pill-btn.custom-pill.active {
            background: #7c3aed;
            color: #ffffff;
            border-color: #7c3aed;
        }

        /* Customize Stay Duration Panel (For stays longer than 7 days) */
        .custom-duration-panel {
            background: #fdfaff;
            border: 1.5px solid #d8b4fe;
            border-radius: 14px;
            padding: 1.25rem;
            margin-top: 0.85rem;
            margin-bottom: 0.5rem;
            animation: customPanelFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 14px rgba(124, 58, 237, 0.06);
        }
        @keyframes customPanelFadeIn {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .custom-duration-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.85rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .custom-duration-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: #581c87;
            display: flex;
            align-items: center;
            gap: 7px;
            font-family: 'Outfit', sans-serif;
        }
        .custom-duration-sub-badge {
            background: #f3e8ff;
            color: #7e22ce;
            font-size: 0.74rem;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 12px;
            border: 1px solid #d8b4fe;
        }
        .custom-duration-body {
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
        }
        .custom-stepper-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .stepper-label {
            font-size: 0.88rem;
            font-weight: 700;
            color: #334155;
        }
        .custom-stepper-wrap {
            display: inline-flex;
            align-items: center;
            background: #ffffff;
            border: 2px solid #7c3aed;
            border-radius: 11px;
            overflow: hidden;
            box-shadow: 0 2px 6px rgba(124, 58, 237, 0.1);
        }
        .stepper-btn {
            width: 38px;
            height: 38px;
            background: #f5f3ff;
            border: none;
            color: #7c3aed;
            font-size: 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease;
        }
        .stepper-btn:hover {
            background: #7c3aed;
            color: #ffffff;
        }
        .stepper-input {
            width: 58px;
            height: 38px;
            border: none;
            text-align: center;
            font-size: 1.1rem;
            font-weight: 800;
            color: #1e1b4b;
            font-family: 'Outfit', sans-serif;
            background: #ffffff;
            outline: none;
            -moz-appearance: textfield;
        }
        .stepper-input::-webkit-outer-spin-button,
        .stepper-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        .stepper-unit {
            padding-right: 10px;
            font-size: 0.85rem;
            font-weight: 800;
            color: #7c3aed;
            background: #ffffff;
            user-select: none;
        }
        .btn-apply-custom-nights {
            background: #7c3aed;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 8px 14px;
            font-size: 0.85rem;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        .btn-apply-custom-nights:hover {
            background: #6d28d9;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(124, 58, 237, 0.25);
        }
        .custom-presets-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .presets-label {
            font-size: 0.82rem;
            font-weight: 700;
            color: #64748b;
        }
        .custom-preset-chips {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .preset-chip-btn {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            color: #475569;
            padding: 5px 11px;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .preset-chip-btn:hover {
            border-color: #7c3aed;
            color: #7c3aed;
            background: #f5f3ff;
            transform: translateY(-1px);
        }
        .preset-chip-btn.active {
            background: #7c3aed;
            color: #ffffff;
            border-color: #7c3aed;
            box-shadow: 0 2px 6px rgba(124, 58, 237, 0.2);
        }
        .custom-duration-tip {
            font-size: 0.8rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 6px;
            line-height: 1.4;
            padding-top: 4px;
            border-top: 1px dashed #e9d5ff;
        }

        /* Inline Calendar Card */
        .inline-calendar-card {
            background: #ffffff;
            border-radius: 14px;
            margin-top: 1rem;
            margin-bottom: 1.25rem;
        }

        /* Resort Timing Card */
        .resort-timing-card {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            padding: 1.15rem 1.25rem;
            margin-top: 1rem;
        }
        .timing-col-checkin, .timing-col-checkout {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .timing-label {
            font-size: 0.88rem;
            font-weight: 800;
            color: #1e1b4b;
            display: flex;
            align-items: center;
            gap: 6px;
            margin: 0;
            font-family: 'Outfit', sans-serif;
        }
        .timing-note {
            font-size: 0.76rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 2px;
        }
        .checkout-fixed-display {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #fffbeb;
            border: 2px solid #fde68a;
            border-radius: 12px;
            padding: 0.75rem 1rem;
            color: #b45309;
            font-weight: 800;
        }
        .checkout-time-text {
            font-family: 'Outfit', sans-serif;
            font-size: 1.05rem;
            font-weight: 800;
        }

        @media (max-width: 640px) {
            .schedule-overview-banner {
                grid-template-columns: 1fr;
                gap: 0.75rem;
                text-align: center;
            }
            .overview-date-col.checkout-col {
                text-align: center;
                align-items: center;
            }
            .overview-date-col {
                align-items: center;
            }
            .overview-divider-col {
                flex-direction: row;
                justify-content: center;
            }
            .resort-timing-card {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
        }

        /* 2-Column Responsive Grid */
        .booking-layout-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(320px, 420px);
            gap: 2rem;
            align-items: start;
            width: 100%;
        }

        /* Main Form Card */
        .booking-form-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1.5px solid #e2e8f0;
            padding: 2.25rem;
            box-shadow: 0 10px 30px -5px rgba(124, 58, 237, 0.07);
            box-sizing: border-box;
            width: 100%;
        }

        /* Form Controls */
        .form-group {
            margin-bottom: 1.6rem;
        }
        .form-group:last-of-type {
            margin-bottom: 1.25rem;
        }
        .form-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 800;
            font-size: 0.95rem;
            color: #1e1b4b;
            margin-bottom: 0.65rem;
            font-family: 'Outfit', sans-serif;
        }
        .form-group textarea.form-control {
            width: 100%;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 16px; /* iOS zoom prevention */
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.9rem 1.1rem;
            background: #f8fafc;
            color: #0f172a;
            transition: all 0.2s ease;
            box-sizing: border-box;
            line-height: 1.5;
        }
        .form-group textarea.form-control:focus {
            outline: none;
            border-color: #7c3aed;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15);
        }

        /* Trigger Button */
        .date-time-trigger-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            background: #f8fafc;
            border: 2px solid #cbd5e1;
            padding: 1.1rem 1.35rem;
            border-radius: 14px;
            width: 100%;
            cursor: pointer;
            transition: all 0.25s ease;
            text-align: left;
            box-sizing: border-box;
        }
        .date-time-trigger-btn:hover {
            border-color: #7c3aed;
            background: #faf5ff;
            box-shadow: 0 4px 14px rgba(124, 58, 237, 0.12);
            transform: translateY(-1px);
        }
        .date-time-trigger-content {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
            flex: 1;
        }
        .date-time-trigger-label {
            font-size: 0.8rem;
            color: #64748b;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .date-time-trigger-value {
            font-size: 1.1rem;
            color: #0f172a;
            font-weight: 800;
            font-family: 'Outfit', sans-serif;
            line-height: 1.35;
            word-break: break-word;
        }
        .date-time-trigger-icon {
            width: 44px;
            height: 44px;
            background: #f1f5f9;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #7c3aed;
            font-size: 1.25rem;
            flex-shrink: 0;
            transition: all 0.2s ease;
        }
        .date-time-trigger-btn:hover .date-time-trigger-icon {
            background: #7c3aed;
            color: #ffffff;
        }

        /* Reservation Summary Card */
        .booking-summary-wrapper {
            width: 100%;
        }
        .booking-summary-card {
            background: #ffffff;
            border: 1.5px solid #e9d5ff;
            border-radius: 18px;
            padding: 1.85rem;
            box-shadow: 0 10px 30px -5px rgba(124, 58, 237, 0.08);
            box-sizing: border-box;
            width: 100%;
        }
        @media (min-width: 992px) {
            .booking-summary-card {
                position: sticky;
                top: 95px;
            }
        }
        .summary-header {
            margin-bottom: 1.25rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .summary-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #faf5ff;
            color: #7c3aed;
            border: 1px solid #e9d5ff;
            font-size: 0.76rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 3px 10px;
            border-radius: 20px;
            margin-bottom: 0.5rem;
        }
        .summary-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.35rem;
            font-weight: 800;
            color: #1e1b4b;
            margin: 0;
        }
        .summary-details {
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .summary-row-label {
            color: #64748b;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .summary-row-val {
            color: #0f172a;
            font-weight: 800;
        }
        .summary-row-sub {
            color: #94a3b8;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .summary-times-box {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            gap: 0.5rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.85rem 1rem;
            margin-top: 0.35rem;
        }
        .summary-time-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .summary-time-item:last-child {
            text-align: right;
        }
        .summary-time-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .summary-time-item:last-child .summary-time-label {
            justify-content: flex-end;
        }
        .summary-time-val {
            font-family: 'Outfit', sans-serif;
            font-size: 0.98rem;
            font-weight: 800;
        }
        .summary-time-val.in-val { color: #7c3aed; }
        .summary-time-val.out-val { color: #d97706; }
        .summary-time-sep {
            color: #cbd5e1;
            font-size: 0.85rem;
        }

        .summary-total-section {
            border-top: 1.5px solid #f1f5f9;
            padding-top: 1.25rem;
        }
        .summary-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.65rem;
        }
        .summary-total-label {
            font-family: 'Outfit', sans-serif;
            font-size: 1.15rem;
            font-weight: 800;
            color: #1e1b4b;
        }
        .summary-total-amount {
            font-family: 'Outfit', sans-serif;
            font-size: 1.65rem;
            font-weight: 900;
            color: #d97706;
        }
        .summary-gcash-note {
            font-size: 0.82rem;
            color: #64748b;
            line-height: 1.45;
            display: flex;
            align-items: flex-start;
            gap: 6px;
        }
        .summary-gcash-note i {
            color: #10b981;
            margin-top: 3px;
            flex-shrink: 0;
        }

        /* Add-ons Section Styles */
        .add-ons-section {
            margin-bottom: 1.75rem;
            padding-top: 0.25rem;
        }
        .add-ons-header {
            margin-bottom: 0.85rem;
        }
        .add-ons-header label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.98rem;
            font-weight: 800;
            color: #1e1b4b;
            font-family: 'Outfit', sans-serif;
            margin-bottom: 0.25rem;
        }
        .add-ons-subtitle {
            font-size: 0.82rem;
            color: #64748b;
            display: block;
        }
        .add-ons-grid {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .addon-card {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            padding: 0.85rem 1.1rem;
            transition: all 0.2s ease;
            position: relative;
        }
        .addon-card:hover {
            border-color: #c4b5fd;
            background: #faf5ff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.05);
        }
        .addon-card.selected {
            border-color: #7c3aed;
            background: #fdfaff;
            box-shadow: 0 4px 16px rgba(124, 58, 237, 0.1);
        }
        .addon-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            cursor: pointer;
            margin: 0;
            width: 100%;
            user-select: none;
        }
        .addon-left {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            flex: 1;
            min-width: 0;
        }
        .addon-checkbox {
            width: 20px;
            height: 20px;
            accent-color: #7c3aed;
            cursor: pointer;
            flex-shrink: 0;
        }
        .addon-img-wrap {
            width: 52px;
            height: 52px;
            border-radius: 10px;
            overflow: hidden;
            flex-shrink: 0;
            border: 1px solid #e2e8f0;
            background: #f1f5f9;
        }
        .addon-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .addon-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 0;
            flex: 1;
        }
        .addon-badges {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .addon-badge-cat {
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            background: #ede9fe;
            color: #6d28d9;
            padding: 2px 7px;
            border-radius: 6px;
        }
        .addon-badge-time {
            font-size: 0.72rem;
            font-weight: 700;
            color: #64748b;
        }
        .addon-name {
            font-family: 'Outfit', sans-serif;
            font-size: 0.98rem;
            font-weight: 800;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .addon-price-tag {
            font-size: 0.9rem;
            font-weight: 800;
            color: #7c3aed;
        }
        .addon-price-unit {
            font-size: 0.78rem;
            color: #64748b;
            font-weight: 600;
        }
        .addon-qty-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        .addon-qty-label {
            font-size: 0.8rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
        }
        .addon-qty-control {
            display: inline-flex;
            align-items: center;
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            overflow: hidden;
        }
        .addon-qty-btn {
            background: transparent;
            border: none;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            font-weight: 800;
            color: #1e1b4b;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .addon-qty-btn:hover {
            background: #7c3aed;
            color: #ffffff;
        }
        .addon-qty-input {
            width: 36px;
            height: 32px;
            border: none;
            background: #ffffff;
            text-align: center;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.95rem;
            color: #0f172a;
            -moz-appearance: textfield;
        }
        .addon-qty-input::-webkit-outer-spin-button,
        .addon-qty-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        /* Summary Add-ons Breakdown */
        .summary-addons-box {
            background: #faf5ff;
            border: 1px dashed #c4b5fd;
            border-radius: 12px;
            padding: 0.85rem 1rem;
            margin: 0.85rem 0;
        }
        .summary-addons-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
            padding-bottom: 0.35rem;
            border-bottom: 1px solid rgba(124, 58, 237, 0.15);
        }
        .summary-addons-title {
            font-size: 0.82rem;
            font-weight: 800;
            color: #6d28d9;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .summary-addons-total {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            color: #7c3aed;
            font-size: 0.95rem;
        }
        .summary-addons-list {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }
        .summary-addon-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.84rem;
        }
        .summary-addon-name {
            color: #334155;
            font-weight: 600;
        }
        .summary-addon-val {
            color: #7c3aed;
            font-weight: 800;
        }

        /* Modal Styles */
        .custom-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 99999;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            box-sizing: border-box;
        }
        .custom-modal-overlay.show {
            display: flex;
            animation: fadeIn 0.2s ease-out forwards;
        }
        .custom-modal-box {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 620px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            position: relative;
            transform: translateY(20px);
            opacity: 0;
        }
        .custom-modal-overlay.show .custom-modal-box {
            animation: slideUp 0.25s ease-out forwards;
        }
        @keyframes fadeIn { to { opacity: 1; } }
        @keyframes slideUp { to { transform: translateY(0); opacity: 1; } }
        .custom-modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1.5px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ffffff;
            flex-shrink: 0;
        }
        .custom-modal-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.2rem;
            font-weight: 800;
            color: #1e1b4b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .custom-modal-close {
            background: #f1f5f9;
            border: none;
            width: 38px; height: 38px;
            border-radius: 50%;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            color: #64748b;
            font-size: 1.1rem;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .custom-modal-close:hover {
            background: #fee2e2;
            color: #ef4444;
        }
        .custom-modal-body {
            padding: 1.25rem 1.5rem;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            flex: 1;
            min-height: 300px;
        }
        .custom-modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1.5px solid #f1f5f9;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            background: #ffffff;
            flex-shrink: 0;
        }

        .modal-time-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 1.25rem;
            padding-top: 1.25rem;
            border-top: 1.5px dashed #cbd5e1;
        }
        .modal-time-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 800;
            font-size: 0.88rem;
            color: #0f172a;
            margin-bottom: 0.45rem;
            font-family: 'Outfit', sans-serif;
        }
        .modal-time-input {
            background: #f8fafc;
            border: 2px solid #cbd5e1;
            border-radius: 10px;
            padding: 0.65rem 0.85rem;
            font-weight: 800;
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: #0f172a;
            width: 100%;
            font-size: 16px; /* 16px prevents iOS Safari auto-zoom */
            box-sizing: border-box;
        }
        .modal-time-warning {
            color: #ef4444;
            font-size: 0.8rem;
            display: none;
            margin-top: 0.45rem;
            font-weight: 700;
        }
        .modal-hours-note {
            margin-top: 1rem;
            font-size: 0.82rem;
            color: #64748b;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Mobile Breakpoints & Responsiveness */
        @media (max-width: 991px) {
            .booking-page-container {
                padding: 1.5rem 1.25rem 3rem 1.25rem;
            }
            .booking-layout-grid {
                grid-template-columns: 1fr !important;
                gap: 1.5rem !important;
            }
            .booking-summary-card {
                position: static !important;
            }
        }

        @media (max-width: 576px) {
            .booking-page-container {
                padding: 1rem 0.85rem 2.5rem 0.85rem;
            }
            .booking-form-card {
                padding: 1.25rem 1rem;
                border-radius: 14px;
            }
            .booking-summary-card {
                padding: 1.25rem 1rem;
                border-radius: 14px;
            }
            .date-time-trigger-btn {
                padding: 0.85rem 1rem;
                gap: 0.75rem;
            }
            .date-time-trigger-value {
                font-size: 0.98rem;
            }
            .date-time-trigger-icon {
                width: 38px;
                height: 38px;
                font-size: 1.1rem;
            }
            .custom-modal-overlay {
                padding: 0.5rem;
            }
            .custom-modal-box {
                max-height: 94vh;
                border-radius: 16px;
            }
            .custom-modal-header {
                padding: 1rem 1.25rem;
            }
            .custom-modal-title {
                font-size: 1.05rem;
            }
            .custom-modal-body {
                padding: 1rem 0.85rem;
            }
            .modal-time-grid {
                grid-template-columns: 1fr;
                gap: 0.85rem;
            }
            .custom-modal-footer {
                padding: 0.85rem 1rem;
            }
            .custom-modal-footer .btn {
                flex: 1;
                padding: 0.65rem 1rem;
                font-size: 0.95rem;
                text-align: center;
                justify-content: center;
            }
            .summary-total-amount {
                font-size: 1.45rem;
            }
            .summary-times-box {
                padding: 0.75rem 0.85rem;
            }
            .addon-label {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.65rem;
            }
            .addon-qty-wrapper {
                width: 100%;
                justify-content: flex-end;
                padding-top: 0.4rem;
                border-top: 1px dashed #e2e8f0;
            }
            .addon-name {
                white-space: normal;
                font-size: 0.92rem;
            }
            .addon-img-wrap {
                width: 46px;
                height: 46px;
            }
        }
    
        /* Custom Selection Grid */
        .selection-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 12px;
            margin-top: 8px;
            max-height: 290px;
            overflow-y: auto;
            padding: 4px;
        }
        .custom-card-checkbox {
            position: relative;
            display: block;
            cursor: pointer;
        }
        .custom-card-checkbox input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }
        .custom-card-checkbox .card-content {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            background: #ffffff;
            transition: all 0.2s ease;
            position: relative;
            min-height: 72px;
            box-sizing: border-box;
        }
        .custom-card-checkbox:hover .card-content {
            border-color: #c4b5fd;
            background: #faf5ff;
        }
        .custom-card-checkbox input:checked ~ .card-content {
            border-color: #7c3aed;
            background: #f5f3ff;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.15);
        }
        .custom-card-checkbox input:disabled ~ .card-content {
            border-color: #cbd5e1;
            background: #f1f5f9;
            opacity: 0.6;
            cursor: not-allowed;
        }
        .custom-card-checkbox .card-thumb-wrap {
            width: 54px;
            height: 54px;
            min-width: 54px;
            border-radius: 9px;
            overflow: hidden;
            position: relative;
            background: #f1f5f9;
            border: 1px solid rgba(0, 0, 0, 0.08);
            flex-shrink: 0;
        }
        .custom-card-checkbox .card-thumb-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.25s ease;
        }
        .custom-card-checkbox:hover .card-thumb-wrap img {
            transform: scale(1.08);
        }
        .custom-card-checkbox .card-info {
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-width: 0;
            flex: 1;
            padding-right: 18px;
        }
        .custom-card-checkbox .card-title {
            font-weight: 800;
            font-size: 0.88rem;
            color: #1e293b;
            line-height: 1.25;
            margin-bottom: 2px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .custom-card-checkbox input:checked ~ .card-content .card-title {
            color: #5b21b6;
        }
        .custom-card-checkbox .card-price {
            font-size: 0.8rem;
            font-weight: 700;
            color: #64748b;
            line-height: 1.2;
        }
        .custom-card-checkbox input:checked ~ .card-content .card-price {
            color: #7c3aed;
        }
        .custom-card-checkbox .check-icon {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 1rem;
            color: #7c3aed;
            opacity: 0;
            transform: scale(0.5);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            background: #ffffff;
            border-radius: 50%;
        }
        .custom-card-checkbox input:checked ~ .card-content .check-icon {
            opacity: 1;
            transform: scale(1);
        }
        /* Custom scrollbar for grids */
        .selection-grid::-webkit-scrollbar {
            width: 6px;
        }
        .selection-grid::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }
        .selection-grid::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }
        .selection-grid::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Dynamic Guest Manifest List Styles */
        .guest-manifest-card {
            background: #ffffff;
            border: 1.5px solid #e9d5ff;
            border-radius: 16px;
            padding: 1.25rem 1.35rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 4px 16px -2px rgba(124, 58, 237, 0.05);
        }
        .guest-manifest-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.85rem;
            flex-wrap: wrap;
            gap: 8px;
        }
        .guest-manifest-title {
            font-size: 0.98rem;
            font-weight: 800;
            color: #1e1b4b;
            font-family: 'Outfit', sans-serif;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }
        .guest-cap-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #faf5ff;
            border: 1.5px solid #ddd6fe;
            color: #7c3aed;
            font-weight: 800;
            font-size: 0.82rem;
            padding: 4px 12px;
            border-radius: 999px;
            letter-spacing: 0.2px;
            box-shadow: 0 2px 6px rgba(124, 58, 237, 0.08);
        }
        .guest-counter-control-strip {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            padding: 10px 14px;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .guest-count-label {
            font-weight: 800;
            font-size: 0.92rem;
            color: #1e1b4b;
            font-family: 'Outfit', sans-serif;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .guest-count-sub {
            font-size: 0.78rem;
            color: #64748b;
            margin-top: 2px;
        }
        .guest-inputs-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 12px;
        }
        .guest-slot-card {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            padding: 10px 14px;
            transition: all 0.2s ease;
        }
        .guest-slot-card:focus-within {
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12);
        }
        .guest-slot-card.lead-guest-card {
            background: #fdf4ff;
            border-color: #e9d5ff;
        }
        .guest-slot-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }
        .guest-slot-title {
            font-size: 0.84rem;
            font-weight: 800;
            color: #475569;
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: 'Outfit', sans-serif;
        }
        .guest-slot-card.lead-guest-card .guest-slot-title {
            color: #7c3aed;
        }
        .lead-guest-tag {
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            background: #ede9fe;
            color: #6d28d9;
            padding: 2px 8px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-remove-guest {
            background: transparent;
            border: none;
            color: #94a3b8;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-remove-guest:hover {
            color: #ef4444;
            background: #fee2e2;
        }
        .guest-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .guest-input-icon {
            position: absolute;
            left: 12px;
            color: #94a3b8;
            font-size: 0.9rem;
            pointer-events: none;
            transition: color 0.2s ease;
        }
        .guest-slot-card:focus-within .guest-input-icon {
            color: #7c3aed;
        }
        .guest-name-field {
            width: 100%;
            padding: 9px 12px 9px 34px !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 8px !important;
            font-size: 0.92rem !important;
            font-weight: 600 !important;
            color: #0f172a !important;
            background: #ffffff !important;
            box-sizing: border-box;
            transition: border-color 0.2s ease;
        }
        .guest-name-field:focus {
            border-color: #7c3aed !important;
            outline: none !important;
        }
        .btn-add-guest-slot {
            background: #ffffff;
            border: 1.5px dashed #7c3aed;
            color: #7c3aed;
            padding: 9px 18px;
            border-radius: 10px;
            font-weight: 800;
            font-size: 0.88rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            font-family: 'Outfit', sans-serif;
        }
        .btn-add-guest-slot:hover:not(:disabled) {
            background: #faf5ff;
            border-color: #6d28d9;
            color: #6d28d9;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.15);
        }
        .btn-add-guest-slot:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            border-style: solid;
            border-color: #cbd5e1;
            color: #94a3b8;
            background: #f8fafc;
        }
        .max-capacity-notice {
            font-size: 0.82rem;
            font-weight: 700;
            color: #d97706;
            background: #fffbeb;
            border: 1px solid #fde68a;
            padding: 8px 14px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ---------- Responsive supplement ---------- */
        @media (max-width: 768px) {
            .booking-form-card { padding: 1.5rem 1.15rem; }
            .selection-grid { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); }
            .step-indicator { gap: 0.65rem; margin-bottom: 1.5rem; }
            .summary-act-item-title { white-space: normal; }
        }
        @media (max-width: 640px) {
            .step-indicator {
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                flex-wrap: nowrap !important;
                gap: 0.45rem !important;
                margin: 0 auto 1.5rem auto !important;
                padding: 6px 4px !important;
                max-width: 100% !important;
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
                scrollbar-width: none !important;
            }
            .step-indicator::-webkit-scrollbar { display: none !important; }
            .step-item:not(.active) .step-label,
            .step-item:not(.active) span:not(.step-num) {
                display: none !important;
            }
            .step-item:not(.active) {
                flex: 0 0 auto !important;
                padding: 0 !important;
            }
            .step-item.active {
                display: inline-flex !important;
                align-items: center !important;
                flex: 0 0 auto !important;
                gap: 0.45rem !important;
                background: rgba(124, 58, 237, 0.08) !important;
                border: 1px solid rgba(124, 58, 237, 0.22) !important;
                border-radius: 24px !important;
                padding: 3px 10px 3px 3px !important;
                white-space: nowrap !important;
            }
            .step-item.active .step-label,
            .step-item.active span:not(.step-num) {
                display: inline-flex !important;
                white-space: nowrap !important;
                font-size: 0.8rem !important;
                font-weight: 800 !important;
                color: #7c3aed !important;
            }
            .step-indicator .step-num {
                width: 26px !important;
                height: 26px !important;
                min-width: 26px !important;
                max-width: 26px !important;
                font-size: 0.74rem !important;
            }
            .step-indicator .step-divider {
                font-size: 0.65rem !important;
                color: #cbd5e1 !important;
                margin: 0 !important;
            }
        }
        @media (max-width: 520px) {
            .schedule-overview-banner {
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                text-align: center !important;
                gap: 0.65rem !important;
                padding: 0.9rem 1rem !important;
            }
            .overview-date-col,
            .overview-date-col.checkout-col {
                text-align: center !important;
                align-items: center !important;
                width: 100% !important;
            }
            .overview-divider-col {
                display: flex !important;
                flex-direction: row !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 8px !important;
                width: 100% !important;
                padding: 2px 0 !important;
            }
            .overview-arrow-line {
                transform: rotate(90deg);
                font-size: 0.75rem !important;
            }
            .stay-duration-pills {
                display: grid !important;
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 6px !important;
            }
            .stay-duration-pills .custom-pill {
                grid-column: span 2 !important;
            }
            .selection-grid { grid-template-columns: 1fr; }
            .summary-times-box { grid-template-columns: 1fr; gap: 0.6rem; }
            .summary-total-amount { font-size: 1.4rem; }
            .btn-add-guest-slot { width: 100%; justify-content: center; }
            .max-capacity-notice { font-size: 0.78rem; padding: 8px 10px; }
            .addon-pax-stepper { display: flex; width: 100%; box-sizing: border-box; justify-content: space-between; }
            .addon-pax-input { flex: 1; min-width: 0; }
        }

        /* Per-activity participant cards for add-ons (same as book_activities.php) */
        .act-pax-card {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.7rem 0.9rem;
        }
        .act-pax-name {
            font-weight: 800;
            font-size: 0.88rem;
            color: #1e1b4b;
            margin-bottom: 0.45rem;
            overflow-wrap: anywhere;
        }
        .addon-pax-stepper {
            display: inline-flex;
            align-items: center;
            gap: 0.65rem;
            background: #f8fafc;
            border: 2px solid #cbd5e1;
            border-radius: 14px;
            padding: 6px 10px;
            transition: border-color 0.2s ease;
        }
        .addon-pax-stepper:focus-within {
            border-color: #7c3aed;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12);
        }
        .addon-pax-step-btn {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            border: none;
            background: #ffffff;
            color: #7c3aed;
            font-size: 1.1rem;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease;
        }
        .addon-pax-step-btn:hover:not(:disabled) {
            background: #7c3aed;
            color: #ffffff;
            transform: scale(1.05);
        }
        .addon-pax-step-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            background: #e2e8f0;
            color: #94a3b8;
        }
        .addon-pax-input {
            width: 65px;
            border: none;
            background: transparent;
            font-family: 'Outfit', sans-serif;
            font-size: 1.35rem;
            font-weight: 800;
            color: #1e1b4b;
            text-align: center;
            outline: none;
        }
        .addon-pax-unit {
            font-size: 0.85rem;
            font-weight: 700;
            color: #64748b;
            margin-right: 6px;
        }
    </style>

</head>
<body class="<?= $is_admin_layout ? 'admin-layout-body' : '' ?>">

    <?php if ($is_admin_layout): ?>
        <?php include 'admin-nav.php'; ?>
        <div class="admin-main-wrapper">
            <header class="admin-top-header">
                <div class="admin-header-left">
                    <button type="button" class="admin-mobile-toggle-btn" onclick="toggleAdminSidebar()" aria-label="Open Navigation Menu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <div class="admin-header-context">
                        <div class="admin-header-title-row">
                            <span class="admin-header-page-heading" style="font-family: 'Outfit', sans-serif;">Reserve Bahay Kubo</span>
                        </div>
                    </div>
                </div>

                <div class="admin-header-actions">
                    <a href="admin_profile.php" class="hs-user-badge" title="My Profile">
                    <span class="hs-user-avatar"><?= strtoupper(substr($admin_full_name ?? 'Admin', 0, 1)) ?></span>
                    <span class="hs-user-name"><?= htmlspecialchars($admin_full_name ?? 'Admin') ?></span>
                </a>
                <a href="logout.php" class="hs-btn-logout" onclick="openLogoutModal(event)" title="Sign Out"><i class="fa-solid fa-sign-out-alt"></i></a>
                </div>
            </header>
            <main class="admin-container">
    <?php else: ?>
        <?php include 'nav.php'; ?>
        <div class="container booking-page-container">
    <?php endif; ?>

        <!-- Reservation Flow Progress Tracker -->
        <div class="step-indicator">
            <a href="browse_kubos.php" class="step-item done" title="Choose Accommodation">
                <span class="step-num"><i class="fa-solid fa-check"></i></span>
                <span class="step-label">
                    <span class="step-text-full">Choose Accommodation</span>
                    <span class="step-text-short">Choose</span>
                </span>
            </a>
            <div class="step-divider"><i class="fa-solid fa-chevron-right"></i></div>
            <div class="step-item active">
                <span class="step-num">2</span>
                <span class="step-label">
                    <span class="step-text-full">Dates &amp; Details</span>
                    <span class="step-text-short">Dates &amp; Details</span>
                </span>
            </div>
            <div class="step-divider"><i class="fa-solid fa-chevron-right"></i></div>
            <div class="step-item">
                <span class="step-num">3</span>
                <span class="step-label">
                    <span class="step-text-full">Payment Method (GCash)</span>
                    <span class="step-text-short">Payment</span>
                </span>
            </div>
            <div class="step-divider"><i class="fa-solid fa-chevron-right"></i></div>
            <div class="step-item">
                <span class="step-num">4</span>
                <span class="step-label">
                    <span class="step-text-full">Confirmation</span>
                    <span class="step-text-short">Confirm</span>
                </span>
            </div>
        </div>

        <div class="booking-page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h1 style="margin: 0 0 0.35rem 0;">Complete Kubo Reservation</h1>
                <p style="margin: 0;">Verify your stay dates to request your reservation.</p>
            </div>
            <div>
                <a href="browse_kubos.php" class="btn btn-outline btn-sm" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 700;">
                    <i class="fa-solid fa-arrow-left"></i> Change Accommodation
                </a>
            </div>
        </div>

        <?php if ($has_active_conflict): ?>
            <div style="background: #fff1f2; border: 2px solid #fda4af; border-radius: 14px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 4px 15px rgba(225, 29, 72, 0.08); display: flex; align-items: flex-start; gap: 14px;">
                <div style="width: 44px; height: 44px; border-radius: 12px; background: #ffe4e6; color: #e11d48; display: flex; align-items: center; justify-content: center; font-size: 1.35rem; flex-shrink: 0;">
                    <i class="fa-solid fa-ban"></i>
                </div>
                <div style="flex: 1;">
                    <h4 style="margin: 0 0 5px; color: #9f1239; font-size: 1.05rem; font-weight: 800; font-family: 'Outfit', sans-serif;">
                        Active <?= strtoupper($active_conflict_type) ?> Reservation Exists
                    </h4>
                    <p style="margin: 0 0 12px; color: #be123c; font-size: 0.9rem; line-height: 1.55;">
                        <?= htmlspecialchars($error_msg) ?>
                    </p>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <a href="history.php" class="btn" style="background: #e11d48; color: #ffffff; padding: 0.5rem 1.15rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-clock-rotate-left"></i> View My Existing Booking
                        </a>
                        <a href="browse_kubos.php" class="btn" style="background: #ffffff; color: #be123c; border: 1.5px solid #fecdd3; padding: 0.5rem 1.15rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-house-chimney"></i> Browse Other Accommodations
                        </a>
                    </div>
                </div>
            </div>
        <?php elseif (!empty($error_msg)): ?>
            <div style="background: rgba(239, 68, 68, 0.15); color: #b91c1c; padding: 1rem 1.5rem; border-radius: 12px; margin-bottom: 1.5rem; border: 1.5px solid #fca5a5; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-xmark" style="font-size: 1.25rem;"></i> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <?php if (!$is_initially_available && $total_units > 0 && !$is_renovation): ?>
            <div style="background: #fee2e2; color: #991b1b; padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1.5rem; border: 1.5px solid #fca5a5; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-calendar-xmark" style="font-size: 1.3rem; color: #dc2626;"></i>
                <div>
                    <strong>Dates Fully Booked (0 Units Available):</strong>
                    This Kubo has 0 units available for <?= date('M d, Y', strtotime($check_in)) ?> - <?= date('M d, Y', strtotime($check_out)) ?>.
                    Please choose available dates on the calendar below to reserve this room.
                </div>
            </div>
        <?php endif; ?>

        <?php if ($total_units <= 0): ?>
            <!-- 0 Units Available Blocked Notice -->
            <div class="card" style="border: 2px solid #ef4444; background: #fef2f2; padding: 2rem; text-align: center; margin-bottom: 2rem; border-radius: 16px;">
                <div style="width: 70px; height: 70px; background: #dc2626; color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 1.25rem;">
                    <i class="fa-solid fa-ban"></i>
                </div>
                <h2 style="font-family: 'Outfit', sans-serif; color: #991b1b; font-size: 1.6rem; margin-bottom: 0.5rem;">
                    Currently Unavailable — 0 Units Available
                </h2>
                <p style="color: #7f1d1d; font-size: 1rem; max-width: 600px; margin: 0 auto 1.5rem;">
                    <strong><?= htmlspecialchars($item_name) ?></strong> currently has 0 available units and cannot be reserved.
                </p>
                <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
                    <a href="browse_kubos.php" class="btn btn-primary" style="padding: 0.85rem 1.5rem; font-size: 1rem;">
                        <i class="fa-solid fa-house-chimney"></i> Browse Other Available Kubos
                    </a>
                    <a href="check_availability.php" class="btn btn-outline" style="padding: 0.85rem 1.5rem; font-size: 1rem;">
                        <i class="fa-regular fa-calendar"></i> Check Date Availability
                    </a>
                </div>
            </div>
        <?php elseif ($is_renovation): ?>
            <!-- Renovation Blocked Notice -->
            <div class="card" style="border: 2px solid #ea580c; background: #fff7ed; padding: 2rem; text-align: center; margin-bottom: 2rem; border-radius: 16px;">
                <div style="width: 70px; height: 70px; background: #ea580c; color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 1.25rem;">
                    <i class="fa-solid fa-hammer"></i>
                </div>
                <h2 style="font-family: 'Outfit', sans-serif; color: #9a3412; font-size: 1.6rem; margin-bottom: 0.5rem;">
                    Under Renovation — This Room is Not Available
                </h2>
                <p style="color: #7c2d12; font-size: 1rem; max-width: 600px; margin: 0 auto 1.5rem;">
                    <strong><?= htmlspecialchars($item_name) ?></strong> is currently undergoing repairs and maintenance, and cannot be booked for the selected dates.
                </p>

                <!-- Admin's Damage Report -->
                <div style="background: #ffffff; border: 1px solid #fed7aa; border-left: 4px solid #ea580c; border-radius: 10px; padding: 1.25rem; max-width: 600px; margin: 0 auto 1.5rem; text-align: left;">
                    <div style="font-weight: 800; color: #9a3412; font-size: 0.9rem; margin-bottom: 0.35rem;">
                        <i class="fa-solid fa-wrench"></i> Damage / Reason for Renovation:
                    </div>
                    <p style="margin: 0; color: #0f172a; font-weight: 700; font-size: 0.95rem; line-height: 1.45;">
                        <?= !empty($kubo['renovation_notes']) ? nl2br(htmlspecialchars($kubo['renovation_notes'])) : 'This room is currently undergoing repairs and facility maintenance.' ?>
                    </p>
                    <div style="margin-top: 0.75rem; font-size: 0.85rem; color: #9a3412;">
                        <?php if (!empty($kubo['renovation_start'])): ?>
                            <span style="margin-right: 1rem;"><i class="fa-regular fa-calendar"></i> Started: <strong><?= date('M d, Y', strtotime($kubo['renovation_start'])) ?></strong></span>
                        <?php endif; ?>
                        <?php if (!empty($kubo['renovation_end'])): ?>
                            <span><i class="fa-regular fa-calendar-check"></i> Target Completion: <strong><?= date('M d, Y', strtotime($kubo['renovation_end'])) ?></strong></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
                    <a href="browse_kubos.php" class="btn btn-primary" style="padding: 0.85rem 1.5rem; font-size: 1rem;">
                        <i class="fa-solid fa-house-chimney"></i> Browse Other Available Kubos
                    </a>
                    <a href="check_availability.php" class="btn btn-outline" style="padding: 0.85rem 1.5rem; font-size: 1rem;">
                        <i class="fa-regular fa-calendar"></i> Check Date Availability
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- Normal Reservation Form -->
            <div class="booking-layout-grid">
                <div class="card booking-form-card">

                    <form action="book_house.php<?= !empty($existing_ref) ? '?ref=' . urlencode($existing_ref) : '' ?>" method="POST" id="bookingForm">
                        <input type="hidden" name="kubo_id" value="<?= $kubo_id ?>">
                        <input type="hidden" name="existing_ref" value="<?= htmlspecialchars($existing_ref) ?>">
                        <input type="hidden" name="total_amount" id="formTotalAmount" value="<?= $total_price ?>">
                        <?= csrf_field() ?>  <!-- CSRF token -->

                        <!-- Hidden inputs for stay schedule -->
                        <input type="hidden" name="check_in" id="inputCheckIn" value="<?= htmlspecialchars($check_in) ?>" required>
                        <input type="hidden" name="check_out" id="inputCheckOut" value="<?= htmlspecialchars($check_out) ?>" required>
                        <input type="hidden" name="check_in_time" id="inputCheckInTime" value="<?= htmlspecialchars($existing_in_time) ?>" required>
                        <input type="hidden" name="check_out_time" id="inputCheckOutTime" value="<?= htmlspecialchars($existing_out_time) ?>" required>
                        <input type="hidden" id="modalCheckInTime" value="<?= htmlspecialchars($existing_in_time) ?>">
                        <input type="hidden" id="modalCheckOutTime" value="<?= htmlspecialchars($existing_out_time) ?>">

                        <!-- Stay Schedule & Interactive Calendar Section -->
                        <div class="stay-schedule-section">
                            <!-- Header & Live Dates Summary Strip -->
                            <div class="schedule-section-header">
                                <label style="margin: 0; color: <?= $is_admin_layout ? '#f8fafc' : '#1e1b4b' ?>; font-size: 1.05rem; font-weight: 800; font-family: 'Outfit', sans-serif; display: flex; align-items: center; gap: 8px;">
                                    <i class="fa-solid fa-calendar-check" style="color: #7c3aed;"></i> Stay Schedule &amp; Calendar
                                </label>
                                <span class="schedule-live-badge">
                                    <i class="fa-solid fa-circle-dot" style="color: #10b981; font-size: 0.65rem;"></i> Select on Calendar
                                </span>
                            </div>

                            <!-- Live Selected Dates Overview Banner -->
                            <div class="schedule-overview-banner" id="scheduleOverviewBanner">
                                <div class="overview-date-col checkin-col">
                                    <span class="overview-label"><i class="fa-solid fa-plane-arrival"></i> Check-In</span>
                                    <strong class="overview-date" id="overviewCheckInDate"><?= date('M j, Y', strtotime($check_in)) ?></strong>
                                    <span class="overview-time" id="overviewCheckInTime"><?= date('g:i A', strtotime($existing_in_time)) ?></span>
                                </div>
                                <div class="overview-divider-col">
                                    <span class="overview-nights-badge" id="overviewNightsBadge">
                                        <i class="fa-solid fa-moon"></i> <span id="overviewNightsText"><?= $nights ?> Night(s)</span>
                                    </span>
                                    <div class="overview-arrow-line">
                                        <i class="fa-solid fa-arrow-right"></i>
                                    </div>
                                </div>
                                <div class="overview-date-col checkout-col">
                                    <span class="overview-label"><i class="fa-solid fa-plane-departure"></i> Check-Out</span>
                                    <strong class="overview-date" id="overviewCheckOutDate"><?= date('M j, Y', strtotime($check_out)) ?></strong>
                                    <span class="overview-time" id="overviewCheckOutTime"><?= date('g:i A', strtotime($existing_out_time)) ?></span>
                                </div>
                            </div>

                            <!-- Hidden element for legacy displaySelected reference -->
                            <div id="displaySelectedDateTime" style="display: none;"></div>

                            <!-- Duration Selector -->
                            <div class="duration-selector-wrap" style="margin-top: 1.15rem; margin-bottom: 0.75rem;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.55rem; flex-wrap: wrap; gap: 0.4rem;">
                                    <span style="font-weight: 800; font-size: 0.9rem; color: <?= $is_admin_layout ? '#f8fafc' : '#1e1b4b' ?>; font-family: 'Outfit', sans-serif;">
                                        <i class="fa-solid fa-clock-rotate-left" style="color: #7c3aed;"></i> How Many Days / Nights to Stay?
                                    </span>
                                    <span id="stayNightsBadge" class="stay-nights-badge"><?= $nights ?> Night(s)</span>
                                </div>

                                <div class="stay-duration-pills" id="stayDurationPills">
                                    <button type="button" class="duration-pill-btn <?= $nights == 1 ? 'active' : '' ?>" data-days="1">1 Night (1 Day)</button>
                                    <button type="button" class="duration-pill-btn <?= $nights == 2 ? 'active' : '' ?>" data-days="2">2 Nights</button>
                                    <button type="button" class="duration-pill-btn <?= $nights == 3 ? 'active' : '' ?>" data-days="3">3 Nights</button>
                                    <button type="button" class="duration-pill-btn <?= $nights == 4 ? 'active' : '' ?>" data-days="4">4 Nights</button>
                                    <button type="button" class="duration-pill-btn <?= $nights == 5 ? 'active' : '' ?>" data-days="5">5 Nights</button>
                                    <button type="button" class="duration-pill-btn <?= $nights == 7 ? 'active' : '' ?>" data-days="7">7 Nights (1 Wk)</button>
                                    <button type="button" class="duration-pill-btn custom-pill <?= ($nights > 5 && $nights != 7) ? 'active' : '' ?>" id="btnDurationCustom" data-days="custom">
                                        <i class="fa-solid fa-sliders"></i> Customize (7+ Days)
                                    </button>
                                </div>

                                <!-- Customize Stay Duration Panel (For stays longer than 7 days) -->
                                <div class="custom-duration-panel" id="customDurationPanel" style="<?= ($nights > 7 || ($nights > 5 && $nights != 7)) ? 'display: block;' : 'display: none;' ?>">
                                    <div class="custom-duration-header">
                                        <span class="custom-duration-title">
                                            <i class="fa-solid fa-sliders" style="color: #7c3aed;"></i> Customize Stay Duration (More than 7 Days)
                                        </span>
                                        <span class="custom-duration-sub-badge" id="customDurationSubBadge">
                                            Extended Stay
                                        </span>
                                    </div>

                                    <div class="custom-duration-body">
                                        <div class="custom-stepper-row">
                                            <label class="stepper-label">Number of Nights:</label>
                                            <div class="custom-stepper-wrap">
                                                <button type="button" class="stepper-btn" id="btnCustomDec" aria-label="Decrease nights"><i class="fa-solid fa-minus"></i></button>
                                                <input type="number" id="customNightsInput" class="stepper-input" min="8" max="90" value="<?= max(8, $nights) ?>" aria-label="Custom number of nights">
                                                <span class="stepper-unit">Nights</span>
                                                <button type="button" class="stepper-btn" id="btnCustomInc" aria-label="Increase nights"><i class="fa-solid fa-plus"></i></button>
                                            </div>
                                            <button type="button" class="btn-apply-custom-nights" id="btnApplyCustomNights">
                                                Apply <i class="fa-solid fa-check"></i>
                                            </button>
                                        </div>

                                        <div class="custom-presets-row">
                                            <span class="presets-label">Quick Presets:</span>
                                            <div class="custom-preset-chips" id="customPresetChips">
                                                <button type="button" class="preset-chip-btn <?= $nights == 8 ? 'active' : '' ?>" data-nights="8">8 Nights</button>
                                                <button type="button" class="preset-chip-btn <?= $nights == 10 ? 'active' : '' ?>" data-nights="10">10 Nights</button>
                                                <button type="button" class="preset-chip-btn <?= $nights == 14 ? 'active' : '' ?>" data-nights="14">14 Nights (2 Wks)</button>
                                                <button type="button" class="preset-chip-btn <?= $nights == 21 ? 'active' : '' ?>" data-nights="21">21 Nights (3 Wks)</button>
                                                <button type="button" class="preset-chip-btn <?= $nights == 30 ? 'active' : '' ?>" data-nights="30">30 Nights (1 Mo)</button>
                                            </div>
                                        </div>

                                        <div class="custom-duration-tip">
                                            <i class="fa-solid fa-lightbulb" style="color: #f59e0b;"></i>
                                            <span>Enter any number of nights (8–90 days) or click your arrival &amp; departure dates directly on the calendar below.</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Calendar Display Card -->
                            <div class="inline-calendar-card">
                                <div id="bookingCalendarPicker" style="width: 100%; min-height: 380px;"></div>
                            </div>

                            <!-- Check-In Time Selection Row -->
                            <div class="resort-timing-card">
                                <div class="timing-col-checkin">
                                    <label class="timing-label">
                                        <i class="fa-solid fa-clock" style="color: #7c3aed;"></i> Check-In Time
                                    </label>
                                    <div style="position: relative;">
                                        <div id="checkInTimeDisplay" class="custom-time-display">
                                            <span id="checkInTimeText"><?= date('g:i A', strtotime($existing_in_time)) ?></span>
                                            <i class="fa-regular fa-clock" style="color: #7c3aed;"></i>
                                        </div>
                                        <div id="checkInTimeDropdown" class="custom-time-dropdown" style="display: none;">
                                            <div class="custom-time-hours-banner" style="margin: 0 0 0.5rem 0; padding: 2px 4px 6px 4px; border-bottom: 1px solid #f1f5f9; font-size: 0.78rem;">
                                                <i class="fa-solid fa-clock"></i> <span>Resort check-in hours: <strong class="highlight-hours">8:00 AM &ndash; 10:00 PM</strong> <span class="interval-note">(5-min intervals)</span></span>
                                            </div>
                                            <div id="checkInTimeGrid" class="custom-time-grid"></div>
                                        </div>
                                    </div>
                                    <div class="timing-note">
                                        <i class="fa-solid fa-circle-info" style="color: #7c3aed;"></i> Check-in available 8:00 AM &ndash; 10:00 PM
                                    </div>
                                </div>

                                <div class="timing-col-checkout">
                                    <label class="timing-label">
                                        <i class="fa-regular fa-clock" style="color: #d97706;"></i> Standard Check-Out Policy
                                    </label>
                                    <div class="checkout-fixed-display">
                                        <span class="checkout-time-text">12:00 PM (Noon)</span>
                                        <i class="fa-solid fa-shield-halved" style="color: #d97706;"></i>
                                    </div>
                                    <div class="timing-note">
                                        <i class="fa-solid fa-broom" style="color: #d97706;"></i> Required for resort housekeeping &amp; sanitation
                                    </div>
                                </div>
                            </div>

                            <div id="hoursWarning" class="modal-time-warning" style="display: none; margin-top: 0.75rem;">
                                <i class="fa-solid fa-triangle-exclamation"></i> Please choose a check-in time between 8:00 AM and 10:00 PM.
                            </div>
                            <div id="timeWarning" class="modal-time-warning" style="display: none; margin-top: 0.75rem;">
                                <i class="fa-solid fa-triangle-exclamation"></i> You cannot select a past check-in time for today.
                            </div>
                        </div>

                        <!-- Dynamic Guest Manifest List based on Accommodation Capacity -->
                        <div class="form-group guest-manifest-card">
                            <div class="guest-manifest-header">
                                <label class="guest-manifest-title">
                                    <i class="fa-solid fa-users" style="color: #7c3aed;"></i> Names of Guests (Guest List) *
                                </label>
                                <span id="guestCapacityBadge" class="guest-cap-pill">
                                    <i class="fa-solid fa-user-group"></i> Max Capacity: <strong id="guestMaxCapDisplay"><?= $base_capacity ?></strong> Guests
                                </span>
                            </div>

                            <!-- Guest Count Stepper Control -->
                            <div class="guest-counter-control-strip">
                                <div>
                                    <div class="guest-count-label">
                                        <i class="fa-solid fa-user-check" style="color: #7c3aed;"></i> Number of Guests Staying
                                    </div>
                                    <div class="guest-count-sub" id="guestCapacitySubtext">
                                        Accommodation capacity: Up to <?= $base_capacity ?> guests
                                    </div>
                                </div>
                                <div class="custom-stepper-wrap">
                                    <button type="button" class="stepper-btn" id="btnGuestDec" aria-label="Decrease number of guests"><i class="fa-solid fa-minus"></i></button>
                                    <input type="number" id="guestCountInput" name="num_guests" class="stepper-input" min="1" max="<?= $base_capacity ?>" value="<?= $initial_guest_count ?>" readonly aria-label="Number of guests">
                                    <span class="stepper-unit">Pax</span>
                                    <button type="button" class="stepper-btn" id="btnGuestInc" aria-label="Increase number of guests"><i class="fa-solid fa-plus"></i></button>
                                </div>
                            </div>

                            <!-- Dynamic Guest Name Slots List -->
                            <div id="guestInputsList" class="guest-inputs-list">
                                <!-- Populated dynamically by JavaScript -->
                            </div>

                            <!-- Hidden Sync Fields for Database -->
                            <textarea name="guest_list" id="finalGuestList" style="display: none;"><?= htmlspecialchars($existing_guest_list) ?></textarea>
                            <input type="hidden" name="pax" id="finalPaxInput" value="<?= $initial_guest_count ?>">

                            <!-- Add Guest Slot Action & Notice -->
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 10px; flex-wrap: wrap; gap: 8px;">
                                <button type="button" id="btnAddGuestSlot" class="btn-add-guest-slot">
                                    <i class="fa-solid fa-user-plus"></i> <span id="btnAddGuestText">Add Another Guest</span>
                                </button>
                                <small style="color: #64748b; font-size: 0.78rem; display: flex; align-items: center; gap: 4px;">
                                    <i class="fa-solid fa-circle-info" style="color: #7c3aed;"></i> Required for security and resort manifest.
                                </small>
                            </div>

                            <div id="maxCapacityReachedNotice" class="max-capacity-notice" style="display: none; margin-top: 10px;">
                                <i class="fa-solid fa-shield-halved"></i>
                                <span>Maximum capacity of <strong id="noticeMaxCapCount"><?= $base_capacity ?></strong> guests reached for your selected accommodation.</span>
                            </div>
                        </div>

                        <!-- Optional Pool & Resort Add-ons (Matching book_activities design) -->
                        <div class="form-group" style="margin-top: 0.5rem; margin-bottom: 1.25rem;">
                            <label style="display: flex; align-items: center; gap: 8px; font-weight: 800; font-size: 0.95rem; color: #1e1b4b; margin-bottom: 0.5rem; font-family: 'Outfit', sans-serif;">
                                <i class="fa-solid fa-house" style="color: #7c3aed;"></i> Add Additional Kubos
                            </label>
                            <div class="selection-grid">
                                <?php 
                                $all_kubos = InventoryStore::getAllKubos();
                                foreach ($all_kubos as $k) {
                                    $kid = intval($k['kubo_id'] ?? $k['id']);
                                    if ($kid == $kubo_id) continue;
                                    $k_status = strtolower(trim($k['status'] ?? 'available'));
                                    if (in_array($k_status, ['renovation', 'maintenance', 'unavailable'])) continue;
                                    $k_price = floatval($k['price'] ?? 3500);
                                    $k_cap = intval($k['capacity'] ?? 2);
                                    $k_img = !empty($k['image']) ? $k['image'] : (!empty($k['primary_image']) ? $k['primary_image'] : 'assets/beach_kubo.jpg');
                                ?>
                                <label class="custom-card-checkbox">
                                    <input type="checkbox" name="additional_kubos[]" value="<?= $kid ?>" data-price="<?= $k_price ?>" data-capacity="<?= $k_cap ?>" data-name="<?= htmlspecialchars($k['name'], ENT_QUOTES) ?>" class="additional-kubo-cb" onchange="recalculateTotals()">
                                    <div class="card-content">
                                        <div class="card-thumb-wrap">
                                            <img src="<?= htmlspecialchars($k_img) ?>" alt="<?= htmlspecialchars($k['name']) ?>" loading="lazy" onerror="this.onerror=null; this.src='assets/beach_kubo.jpg';">
                                        </div>
                                        <div class="card-info">
                                            <span class="card-title"><?= htmlspecialchars($k['name']) ?></span>
                                            <span class="card-price">₱<?= number_format($k_price) ?> / night &bull; <small style="font-weight: 700; color: #7c3aed;">+<?= $k_cap ?> Guests</small></span>
                                        </div>
                                        <i class="fa-solid fa-circle-check check-icon"></i>
                                    </div>
                                </label>
                                <?php } ?>
                            </div>
                        </div>

                        <div class="form-group" style="margin-top: 0.5rem; margin-bottom: 1.25rem;">
                            <label style="display: flex; align-items: center; gap: 8px; font-weight: 800; font-size: 0.95rem; color: #1e1b4b; margin-bottom: 0.5rem; font-family: 'Outfit', sans-serif;">
                                <i class="fa-solid fa-water-ladder" style="color: #7c3aed;"></i> Select Activity
                            </label>
                            <div class="selection-grid" id="addonActivitySelectGrid">
                                <?php foreach ($available_activities as $act): 
                                    $aid = intval($act['activity_id']);
                                    $act_price = floatval($act['price'] ?? 0);
                                    $act_dur = (!empty($act['duration_minutes']) && intval($act['duration_minutes']) > 0) ? htmlspecialchars($act['duration'] ?? ($act['duration_minutes'] . ' Mins')) : 'No time limit';
                                    $act_name = htmlspecialchars($act['activity_name']);
                                    $act_img = !empty($act['image']) ? $act['image'] : (!empty($act['activity_image']) ? $act['activity_image'] : 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80');
                                    
                                    $eq = json_decode($act['equipment'] ?? '[]', true);
                                    $disabled = false;
                                    if (is_array($eq) && count($eq) > 0) {
                                        $qty = 0;
                                        foreach($eq as $e) $qty += intval($e['qty'] ?? 0);
                                        if ($qty == 0) $disabled = true;
                                    } else {
                                        if (intval($act['total_units'] ?? 1) == 0) $disabled = true;
                                    }
                                ?>
                                <label class="custom-card-checkbox">
                                    <input type="checkbox" name="addon_activity_ids[]" value="<?= $aid ?>" class="addon-activity-cb" onchange="recalculateTotals()"
                                            data-price="<?= $act_price ?>" 
                                            data-name="<?= htmlspecialchars($act_name, ENT_QUOTES) ?>" 
                                            data-duration="<?= htmlspecialchars($act_dur, ENT_QUOTES) ?>"
                                            data-type="<?= htmlspecialchars($act['activity_type'] ?? 'full_day', ENT_QUOTES) ?>"
                                            data-pool="<?= strtolower(trim($act['category'] ?? '')) === 'pool' ? '1' : '0' ?>"
                                            <?= $disabled ? 'disabled' : '' ?>
                                            <?= (in_array($aid, (array)($existing_addon_ids ?? [$existing_addon_id])) ? 'checked' : '') ?>>
                                    <div class="card-content">
                                        <div class="card-thumb-wrap">
                                            <img src="<?= htmlspecialchars($act_img) ?>" alt="<?= $act_name ?>" loading="lazy" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80';">
                                        </div>
                                        <div class="card-info">
                                            <span class="card-title"><?= $act_name ?></span>
                                            <span class="card-price">₱<?= number_format($act_price) ?> / <?= $act_dur ?></span>
                                        </div>
                                        <i class="fa-solid fa-circle-check check-icon"></i>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div id="addonDetailsWrapper">
                            <div class="form-group" style="margin-bottom: 1.25rem;">
                                <label style="display: flex; align-items: center; gap: 8px; font-weight: 800; font-size: 0.95rem; color: #1e1b4b; margin-bottom: 0.5rem; font-family: 'Outfit', sans-serif;">
                                    <i class="fa-solid fa-calendar-check" style="color: #7c3aed;"></i> Activity Schedule
                                </label>

                                <button type="button" class="date-time-trigger-btn" id="btnOpenAddonModal" aria-label="Select activity date and time">
                                    <div class="date-time-trigger-content">
                                        <span class="date-time-trigger-label">DATE &amp; TIME</span>
                                        <span class="date-time-trigger-value" id="displayAddonDateTime">
                                            <?= $existing_addon_id > 0 ? (date('M j, Y', strtotime($existing_addon_date)) . ' &mdash; ' . htmlspecialchars($existing_addon_time)) : 'Select Date &amp; Time...' ?>
                                        </span>
                                    </div>
                                    <div class="date-time-trigger-icon" style="color: #7c3aed;">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </div>
                                </button>

                                <input type="hidden" name="addon_date" id="inputAddonDate" value="<?= htmlspecialchars($existing_addon_date) ?>">
                                <input type="hidden" name="addon_time" id="inputAddonTime" value="<?= htmlspecialchars($existing_addon_time) ?>">
                            </div>

                            <div class="form-group" style="margin-bottom: 1.25rem;">
                                <label style="display: flex; align-items: center; gap: 8px; font-weight: 800; font-size: 0.95rem; color: #1e1b4b; margin-bottom: 0.5rem; font-family: 'Outfit', sans-serif;">
                                    <i class="fa-solid fa-users" style="color: #7c3aed;"></i> Number of Participants / Quantity
                                </label>
                                <input type="number" name="addon_pax" id="addonPaxInput" class="form-control" min="1" max="50" value="<?= $existing_addon_pax ?>" style="background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 12px; padding: 0.85rem 1rem; font-size: 1rem; font-weight: 700; color: #0f172a; width: 100%;">
                                <div id="addonAvailabilityNotice" style="margin-top: 8px; font-weight: 800; font-size: 0.9rem; color: #7c3aed;"></div>

                                <!-- Separate participant count per equipment/limited activity (shown when combined with pool or other limited activities) -->
                                <div id="addonEquipPaxSection" style="display: none; margin-top: 1rem;">
                                    <div style="font-size: 0.85rem; font-weight: 800; color: #0f172a; margin-bottom: 0.2rem;">
                                        <i class="fa-solid fa-person-snowboarding" style="color: #7c3aed;"></i>
                                        Participants per activity with equipment / limits
                                    </div>
                                    <div style="font-size: 0.78rem; color: #64748b; font-weight: 600; margin-bottom: 0.65rem;">
                                        Choose how many of your guests join each of these activities.
                                    </div>
                                    <div id="addonActivityPaxList" style="display: flex; flex-direction: column; gap: 0.75rem;"></div>
                                </div>

                                <!-- Remaining Equipment & Gear Status Box -->
                                <div id="addonEquipmentBox" style="display: none; margin-top: 12px; background: #fdf4ff; border: 1.5px solid #f0abfc; border-radius: 12px; padding: 12px 14px;">
                                    <div style="font-size: 0.8rem; font-weight: 800; color: #86198f; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                                        <i class="fa-solid fa-boxes-stacked" style="color: #c084fc;"></i> Available Equipment &amp; Gear Remaining
                                    </div>
                                    <div id="addonEquipmentList" style="display: flex; flex-wrap: wrap; gap: 8px;"></div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fa-solid fa-note-sticky" style="color: #7c3aed;"></i> Special Requests (Optional)</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="e.g. Early check-in request, extra pillows, birthday setup..."><?= htmlspecialchars($existing_notes) ?></textarea>
                        </div>

                        <?php if ($has_active_conflict): ?>
                            <button type="button" disabled class="btn-book-kubo btn-lg" style="width: 100%; margin-top: 0.5rem; font-weight: 800; border: none; background: #cbd5e1; color: #64748b; cursor: not-allowed; box-shadow: none;">
                                <i class="fa-solid fa-ban"></i>
                                <span>Booking Blocked (<?= ucfirst($active_conflict_type) ?> Reservation Exists)</span>
                            </button>
                        <?php else: ?>
                            <button type="submit" id="btnSubmitBooking" class="btn-book-kubo btn-lg" style="width: 100%; margin-top: 0.5rem; font-weight: 800; border: none;">
                                <span>Continue to GCash Payment</span>
                                <i class="fa-solid fa-arrow-right btn-arrow"></i>
                            </button>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Price Breakdown Summary Sidebar -->
                <div class="booking-summary-wrapper">
                    <div class="card booking-summary-card">
                        <div class="summary-header">
                            <div class="summary-badge">
                                <i class="fa-solid fa-hotel"></i> <?= htmlspecialchars($kubo['category'] ?? 'Resort Kubo') ?>
                            </div>
                            <h3 class="summary-title"><?= htmlspecialchars($item_name) ?></h3>
                        </div>

                        <div class="summary-details">
                            <div class="summary-row">
                                <span class="summary-row-label"><i class="fa-solid fa-tag" style="color: #7c3aed;"></i> Nightly Rate</span>
                                <strong class="summary-row-val">₱<?= number_format($rate_per_night) ?> <span class="summary-row-sub">/ night</span></strong>
                            </div>
                            <div class="summary-row">
                                <span class="summary-row-label"><i class="fa-regular fa-moon" style="color: #7c3aed;"></i> Stay Duration</span>
                                <strong class="summary-row-val" id="summaryNights"><?= $nights ?> Night(s)</strong>
                            </div>
                            <div class="summary-row">
                                <span class="summary-row-label"><i class="fa-solid fa-users" style="color: #7c3aed;"></i> Capacity</span>
                                <strong class="summary-row-val" id="summaryCapacity">Up to <?= htmlspecialchars($base_capacity) ?> Guests</strong>
                            </div>
                            <div class="summary-row">
                                <span class="summary-row-label"><i class="fa-solid fa-user-check" style="color: #7c3aed;"></i> Guests Staying</span>
                                <strong class="summary-row-val" id="summaryGuestsPax"><?= $initial_guest_count ?> Guest<?= $initial_guest_count > 1 ? 's' : '' ?></strong>
                            </div>

                            <!-- Check-In / Check-Out Times Box -->
                            <div class="summary-times-box">
                                <div class="summary-time-item">
                                    <span class="summary-time-label"><i class="fa-solid fa-clock" style="color: #7c3aed;"></i> Check-In</span>
                                    <strong class="summary-time-val in-val" id="summaryCheckInTime"><?= date('g:i A', strtotime($existing_in_time)) ?></strong>
                                </div>
                                <div class="summary-time-sep"><i class="fa-solid fa-arrow-right"></i></div>
                                <div class="summary-time-item">
                                    <span class="summary-time-label"><i class="fa-regular fa-clock" style="color: #f59e0b;"></i> Check-Out</span>
                                    <strong class="summary-time-val out-val" id="summaryCheckOutTime"><?= date('g:i A', strtotime($existing_out_time)) ?></strong>
                                </div>
                            </div>

                            <!-- Selected Add-ons Box (Shown dynamically when any addon is checked) -->
                            <div class="summary-addons-box" id="summaryAddonsBox" style="display: none;">
                                <div class="summary-addons-header">
                                    <span class="summary-addons-title"><i class="fa-solid fa-water-ladder"></i> Add-ons</span>
                                    <span class="summary-addons-total" id="summaryAddonsSubtotal">₱0.00</span>
                                </div>
                                <div class="summary-addons-list" id="summaryAddonsList">
                                    <!-- Populated by JS -->
                                </div>
                            </div>
                        </div>

                        <div class="summary-total-section">
                            <div class="summary-total-row">
                                <span class="summary-total-label">Total Payable:</span>
                                <span class="summary-total-amount" id="summaryTotalPayable">₱<?= number_format($total_price) ?></span>
                            </div>
                            <div class="summary-gcash-note">
                                <i class="fa-solid fa-shield-check"></i>
                                <span>Payment via GCash Official QR Code / Mobile Transfer required to confirm booking.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    <!-- Optional Activity Add-on Modal (Kept for activity date/time selection) -->

    <!-- Date & Time Picker Modal for Optional Activity Add-on -->
    <div class="custom-modal-overlay" id="addonDateTimeModal" role="dialog" aria-modal="true" aria-labelledby="modalAddonDateTimeTitle">
        <div class="custom-modal-box">
            <div class="custom-modal-header">
                <h3 class="custom-modal-title" id="modalAddonDateTimeTitle">
                    <i class="fa-regular fa-calendar-days" style="color: #7c3aed;"></i> Select Activity Date &amp; Time
                </h3>
                <button type="button" class="custom-modal-close" id="btnCloseAddonModal" aria-label="Close modal"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="custom-modal-body">
                <div id="addonCalendarPicker"></div>

                <div id="modalAddonTimePickerSection" style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1.5px dashed #cbd5e1;">
                    <div class="form-group" style="position: relative;">
                        <label style="display: flex; align-items: center; gap: 6px; font-weight: 800; font-size: 0.95rem; color: #0f172a; margin-bottom: 0.65rem; font-family: 'Outfit', sans-serif;">
                            <i class="fa-solid fa-clock" style="color: #7c3aed;"></i> Preferred Activity Time
                        </label>
                        <input type="hidden" id="modalAddonTimeSlot" value="<?= htmlspecialchars($existing_addon_time24) ?>">
                        <div id="customTimePickerDisplay" class="custom-time-display active">
                            <span id="customTimePickerText"><?= date('g:i A', strtotime($existing_addon_time24)) ?></span>
                            <i class="fa-regular fa-clock" style="color: #64748b;"></i>
                        </div>
                        
                        <div class="custom-time-hours-banner">
                            <i class="fa-solid fa-clock"></i>
                            <span>Resort operating hours: <span class="highlight-hours">8:00 AM &ndash; 10:00 PM</span> <span class="interval-note">(5-minute intervals)</span></span>
                        </div>

                        <div id="customTimePickerContainer" class="custom-time-grid-box">
                            <div id="customTimeGrid" class="custom-time-grid">
                                <!-- populated by JS -->
                            </div>
                        </div>

                        <div id="addonTimeWarning" class="modal-time-warning"><i class="fa-solid fa-triangle-exclamation"></i> You cannot select a past time for today.</div>
                        <div id="addonHoursWarning" class="modal-time-warning"><i class="fa-solid fa-triangle-exclamation"></i> Please choose a time between 8:00 AM and 10:00 PM.</div>
                    </div>
                </div>
            </div>
            <div class="custom-modal-footer">
                <button type="button" class="btn btn-outline" id="btnCancelAddonModal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSaveAddonModal" style="background: #7c3aed; color: #fff; font-weight: 800; padding: 0.65rem 1.5rem; border-radius: 10px; border: none;">Save Selection</button>
            </div>
        </div>
    </div>

    <?php if ($is_admin_layout): ?>
            </main>
        </div>
    <?php else: ?>
        </div>
        <?php include 'footer.php'; ?>
    <?php endif; ?>

    <?php
    $calendar_script_file = __DIR__ . '/assets/monthly_calendar_picker.js';
    if (file_exists($calendar_script_file)) {
        echo "<script>\n" . file_get_contents($calendar_script_file) . "\n</script>\n";
    } else {
        echo '<script src="assets/monthly_calendar_picker.js"></script>' . "\n";
    }
    ?>
    <script>
    // ---------- 1. State Variables ----------
    const ratePerNight = <?= $rate_per_night ?>;
    const totalUnits = <?= $total_units ?>;
    let roomRangeIsAvailable = <?= $is_initially_available ? 'true' : 'false' ?>;
    let addonIsAvailable = true;
    let currentNights = <?= $nights ?>;
    const bookedRanges = <?= json_encode($booked_ranges) ?>;
    const bookedDates = <?= json_encode($booked_dates) ?>;

    // Guest Manifest Capacity State
    const baseKuboCapacity = <?= $base_capacity ?>;
    let currentTotalMaxCap = baseKuboCapacity;
    const leadGuestName = <?= json_encode($lead_guest_name) ?>;
    let initialGuestNames = <?= json_encode($existing_guest_names) ?>;
    if (!Array.isArray(initialGuestNames) || initialGuestNames.length === 0) {
        initialGuestNames = [leadGuestName || ''];
    }
    let guestNamesState = [...initialGuestNames];

    // ---------- 2. Helper Functions ----------
    function formatTime12h(timeStr) {
        if (!timeStr) return "";
        const parts = timeStr.split(":");
        let hour = parseInt(parts[0], 10);
        const mins = parts[1] || "00";
        const ampm = hour >= 12 ? "PM" : "AM";
        hour = hour % 12 || 12;
        return hour + ":" + mins + " " + ampm;
    }

    function escapeHtml(text) {
        if (!text) return '';
        return String(text).replace(/[&<>"']/g, function(m) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[m];
        });
    }

    function isWithinResortHours(t) {
        return !!t && t >= '08:00' && t <= '22:00';
    }

    function calculateCheckoutTime(inTime) {
        // Resort standard policy: Check-out is strictly 12:00 PM (Noon) for cleaning and sanitation
        return "12:00";
    }

    // ---------- Guest Manifest Dynamic Functions ----------
    function renderGuestInputs() {
        const container = document.getElementById('guestInputsList');
        if (!container) return;

        container.innerHTML = '';
        guestNamesState.forEach((name, idx) => {
            const isLead = (idx === 0);
            const card = document.createElement('div');
            card.className = 'guest-slot-card' + (isLead ? ' lead-guest-card' : '');
            card.id = `guestSlot_${idx}`;

            let headerHtml = '';
            if (isLead) {
                headerHtml = `
                    <div class="guest-slot-header">
                        <span class="guest-slot-title">
                            <i class="fa-solid fa-user-check" style="color: #7c3aed;"></i> Guest 1 (Lead Guest) *
                        </span>
                        <span class="lead-guest-tag">
                            <i class="fa-solid fa-shield"></i> Lead Guest
                        </span>
                    </div>
                `;
            } else {
                headerHtml = `
                    <div class="guest-slot-header">
                        <span class="guest-slot-title">
                            <i class="fa-regular fa-user" style="color: #7c3aed;"></i> Guest ${idx + 1} *
                        </span>
                        <button type="button" class="btn-remove-guest" onclick="removeGuestSlot(${idx})" title="Remove Guest ${idx + 1}">
                            <i class="fa-solid fa-trash-can"></i> Remove
                        </button>
                    </div>
                `;
            }

            card.innerHTML = `
                ${headerHtml}
                <div class="guest-input-wrap">
                    <i class="fa-regular fa-id-card guest-input-icon"></i>
                    <input type="text" 
                           name="guest_names[]" 
                           class="guest-name-field" 
                           value="${escapeHtml(name)}" 
                           placeholder="${isLead ? 'Full Name of Lead Guest / Account Holder' : 'Enter full name of Guest ' + (idx + 1) + '...'}" 
                           required 
                           oninput="onGuestNameChange(${idx}, this.value)">
                </div>
            `;
            container.appendChild(card);
        });

        const countInput = document.getElementById('guestCountInput');
        if (countInput) countInput.value = guestNamesState.length;

        const paxHidden = document.getElementById('finalPaxInput');
        if (paxHidden) paxHidden.value = guestNamesState.length;

        const summaryPax = document.getElementById('summaryGuestsPax');
        if (summaryPax) {
            summaryPax.textContent = guestNamesState.length + (guestNamesState.length > 1 ? ' Guests' : ' Guest');
        }

        updateGuestControlsState();
        syncGuestListToHidden();
    }

    function onGuestNameChange(idx, val) {
        guestNamesState[idx] = val;
        syncGuestListToHidden();
    }

    function syncGuestListToHidden() {
        const hiddenTextarea = document.getElementById('finalGuestList');
        if (!hiddenTextarea) return;
        const cleaned = guestNamesState.map(n => n.trim()).filter(n => n !== '');
        hiddenTextarea.value = cleaned.join('\n');
    }

    function addGuestSlot() {
        if (guestNamesState.length < currentTotalMaxCap) {
            guestNamesState.push('');
            renderGuestInputs();
            const allInputs = document.querySelectorAll('.guest-name-field');
            if (allInputs.length > 0) {
                allInputs[allInputs.length - 1].focus();
            }
        }
    }

    function removeGuestSlot(idx) {
        if (idx > 0 && guestNamesState.length > 1) {
            guestNamesState.splice(idx, 1);
            renderGuestInputs();
        }
    }

    function updateGuestControlsState() {
        const btnAdd = document.getElementById('btnAddGuestSlot');
        const btnInc = document.getElementById('btnGuestInc');
        const btnDec = document.getElementById('btnGuestDec');
        const notice = document.getElementById('maxCapacityReachedNotice');
        const btnAddText = document.getElementById('btnAddGuestText');

        const remaining = currentTotalMaxCap - guestNamesState.length;

        if (btnDec) {
            btnDec.disabled = (guestNamesState.length <= 1);
            btnDec.style.opacity = (guestNamesState.length <= 1) ? '0.45' : '1';
        }

        if (guestNamesState.length >= currentTotalMaxCap) {
            if (btnAdd) {
                btnAdd.disabled = true;
            }
            if (btnInc) {
                btnInc.disabled = true;
                btnInc.style.opacity = '0.45';
            }
            if (btnAddText) {
                btnAddText.textContent = `Max Capacity Reached (${currentTotalMaxCap} Guests)`;
            }
            if (notice) {
                notice.style.display = 'flex';
            }
        } else {
            if (btnAdd) {
                btnAdd.disabled = false;
            }
            if (btnInc) {
                btnInc.disabled = false;
                btnInc.style.opacity = '1';
            }
            if (btnAddText) {
                btnAddText.textContent = `Add Another Guest (${remaining} slot${remaining > 1 ? 's' : ''} left)`;
            }
            if (notice) {
                notice.style.display = 'none';
            }
        }
    }

    function updateGuestMaxCapacity(newMax, extraCap) {
        currentTotalMaxCap = newMax;
        const badgeEl = document.getElementById('guestMaxCapDisplay');
        if (badgeEl) badgeEl.textContent = newMax;

        const subtextEl = document.getElementById('guestCapacitySubtext');
        if (subtextEl) {
            if (extraCap > 0) {
                subtextEl.innerHTML = `Accommodation capacity: <strong>${baseKuboCapacity}</strong> (Primary) + <strong>${extraCap}</strong> (Additional) = <strong>${newMax} Max Guests</strong>`;
            } else {
                subtextEl.innerHTML = `Accommodation capacity: Up to <strong>${baseKuboCapacity}</strong> guests`;
            }
        }

        const countInput = document.getElementById('guestCountInput');
        if (countInput) countInput.max = newMax;

        const noticeCap = document.getElementById('noticeMaxCapCount');
        if (noticeCap) noticeCap.textContent = newMax;

        // If current guests exceeds new max, trim excess
        if (guestNamesState.length > newMax) {
            guestNamesState = guestNamesState.slice(0, newMax);
            renderGuestInputs();
        } else {
            updateGuestControlsState();
        }
    }

    // ---------- 3. DOM Elements (Stay Schedule & Add-ons) ----------
    // Stay elements
    const hiddenInDate = document.getElementById('inputCheckIn');
    const hiddenOutDate = document.getElementById('inputCheckOut');
    const hiddenInTime = document.getElementById('inputCheckInTime');
    const hiddenOutTime = document.getElementById('inputCheckOutTime');
    const modalInTime = document.getElementById('modalCheckInTime');
    const modalOutTime = document.getElementById('modalCheckOutTime');
    const displaySelected = document.getElementById('displaySelectedDateTime');
    const summaryCheckInTime = document.getElementById('summaryCheckInTime');
    const summaryCheckOutTime = document.getElementById('summaryCheckOutTime');
    const modal = document.getElementById('dateTimeModal');
    const btnOpen = document.getElementById('btnOpenDateTimeModal');
    const btnClose = document.getElementById('btnCloseDateTimeModal');
    const btnCancel = document.getElementById('btnCancelDateTimeModal');
    const btnSave = document.getElementById('btnSaveDateTimeModal');

    // Addon elements
    const actSelect = document.getElementById('addonActivitySelect');
    const paxInput = document.getElementById('addonPaxInput');
    const detailsWrapper = document.getElementById('addonDetailsWrapper');
    const hiddenAddonDate = document.getElementById('inputAddonDate');
    const hiddenAddonTime = document.getElementById('inputAddonTime');
    const modalAddonTime = document.getElementById('modalAddonTimeSlot');
    const displayAddonDateTime = document.getElementById('displayAddonDateTime');
    const addonModal = document.getElementById('addonDateTimeModal');
    const btnOpenAddon = document.getElementById('btnOpenAddonModal');
    const btnCloseAddon = document.getElementById('btnCloseAddonModal');
    const btnCancelAddon = document.getElementById('btnCancelAddonModal');
    const btnSaveAddon = document.getElementById('btnSaveAddonModal');

    // Summary outputs
    const summaryAddonsBox = document.getElementById('summaryAddonsBox');
    const summaryAddonsList = document.getElementById('summaryAddonsList');
    const summaryAddonsSubtotal = document.getElementById('summaryAddonsSubtotal');
    const summaryNights = document.getElementById('summaryNights');
    const summaryTotal = document.getElementById('summaryTotalPayable');
    const formTotal = document.getElementById('formTotalAmount');
    const btnSubmit = document.getElementById('btnSubmitBooking');

    let addonCalendarReady = false;

    // ---------- Add-on activity groups (same rules as book_activities.php) ----------
    // Pool activities are only limited by pool capacity and use the main participant count.
    // Every other activity is limited by its own equipment/capacity and gets its own participant
    // count when combined with the pool or with other limited activities.
    const addonEquipPaxSection = document.getElementById('addonEquipPaxSection');
    const addonActivityPaxList = document.getElementById('addonActivityPaxList');
    let addonActivityPaxCounts = <?= json_encode((object)array_map('intval', $existing_addon_pax_map)) ?>; // activity_id -> chosen participants
    let addonActivityLimits = {};  // activity_id -> max available (from the API)

    function getAddonActivityGroups() {
        const inputs = Array.from(document.querySelectorAll('.addon-activity-cb:checked'));
        const poolIn = inputs.filter(i => i.dataset.pool === '1');
        const limIn = inputs.filter(i => i.dataset.pool !== '1');
        return { inputs, poolIn, limIn, split: (poolIn.length > 0 && limIn.length > 0) || limIn.length >= 2 };
    }

    function getAddonMainPax() {
        return Math.max(1, parseInt(paxInput ? paxInput.value : 1, 10) || 1);
    }

    function getAddonActivityPax(id) {
        const main = getAddonMainPax();
        const lim = addonActivityLimits[id] !== undefined ? addonActivityLimits[id] : main;
        const chosen = addonActivityPaxCounts[id] !== undefined ? addonActivityPaxCounts[id] : main;
        return Math.max(1, Math.min(chosen, lim, main));
    }

    function renderAddonActivityPaxList() {
        if (!addonEquipPaxSection || !addonActivityPaxList) return;
        const g = getAddonActivityGroups();
        addonEquipPaxSection.style.display = g.split ? 'block' : 'none';
        if (!g.split) {
            addonActivityPaxList.innerHTML = '';
            return;
        }
        const main = getAddonMainPax();
        let html = '';
        g.limIn.forEach(inp => {
            const id = inp.value;
            const lim = addonActivityLimits[id] !== undefined ? addonActivityLimits[id] : main;
            const max = Math.max(0, Math.min(lim, main));
            const val = getAddonActivityPax(id);
            const soldOut = lim <= 0;
            html += `
                <div class="act-pax-card">
                    <div class="act-pax-name">${escapeHtml(inp.dataset.name || id)}</div>
                    <div class="addon-pax-stepper">
                        <button type="button" class="addon-pax-step-btn" data-act-step="-1" data-act="${id}" aria-label="Decrease participants" ${soldOut || val <= 1 ? 'disabled' : ''}><i class="fa-solid fa-minus"></i></button>
                        <input type="number" name="addon_activity_pax[${id}]" id="addonActPax_${id}" data-act="${id}" class="addon-pax-input addon-act-pax-input" min="1" max="${Math.max(1, max)}" value="${val}" ${soldOut ? 'disabled' : ''}>
                        <span class="addon-pax-unit">participant(s)</span>
                        <button type="button" class="addon-pax-step-btn" data-act-step="1" data-act="${id}" aria-label="Increase participants" ${soldOut || val >= max ? 'disabled' : ''}><i class="fa-solid fa-plus"></i></button>
                    </div>
                    <div style="margin-top: 6px; font-size: 0.8rem; font-weight: 800; color: ${soldOut ? '#ef4444' : '#7c3aed'};">
                        ${soldOut ? 'No equipment/slots left for this date/time' : `Up to ${max} can join (available: ${lim})`}
                    </div>
                </div>`;
        });
        addonActivityPaxList.innerHTML = html;
    }

    function setAddonActivityPax(id, val) {
        const main = getAddonMainPax();
        const lim = addonActivityLimits[id] !== undefined ? addonActivityLimits[id] : main;
        const max = Math.max(1, Math.min(lim, main));
        val = Math.max(1, Math.min(parseInt(val, 10) || 1, max));
        addonActivityPaxCounts[id] = val;
        renderAddonActivityPaxList();
        recalculateTotals(true);
    }

    if (addonActivityPaxList) {
        addonActivityPaxList.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-act-step]');
            if (!btn || btn.disabled) return;
            const id = btn.dataset.act;
            setAddonActivityPax(id, getAddonActivityPax(id) + parseInt(btn.dataset.actStep, 10));
        });
        addonActivityPaxList.addEventListener('change', (e) => {
            if (!e.target.classList.contains('addon-act-pax-input')) return;
            setAddonActivityPax(e.target.dataset.act, e.target.value);
        });
    }

    // ---------- 4. Display Update & Recalculate Functions ----------
    function updateAddonDisplayOnly() {
        if (!hiddenAddonDate || !hiddenAddonDate.value) return;
        const parts = hiddenAddonDate.value.split('-');
        if (parts.length === 3) {
            const dObj = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
            const dateFormatted = dObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            const timeVal = modalAddonTime && modalAddonTime.value ? modalAddonTime.value : '15:33';
            const timeFormatted = formatTime12h(timeVal);
            if (hiddenAddonTime) hiddenAddonTime.value = timeFormatted;
            if (displayAddonDateTime) {
                displayAddonDateTime.innerHTML = dateFormatted + ' &mdash; ' + timeFormatted;
            }
        }
        recalculateTotals();
    }

    function recalculateTotals(skipAvailabilityCheck) {
        let roomSubtotal = currentNights * ratePerNight;
        let addonsTotal = 0;
        let addonsHtml = '';
        let hasAct = false;
        
        // Add additional kubos
        const additionalKubos = document.querySelectorAll('.additional-kubo-cb:checked');
        let extraCapacity = 0;
        additionalKubos.forEach(cb => {
            const price = parseFloat(cb.dataset.price) || 0;
            const cap = parseInt(cb.dataset.capacity || 0, 10);
            extraCapacity += cap;
            const sub = currentNights * price;
            roomSubtotal += sub;
            addonsHtml += `<div class="summary-addon-row" style="flex-direction: column; align-items: flex-start; gap: 2px;">
                                <div style="display: flex; justify-content: space-between; width: 100%; align-items: center;">
                                    <span class="summary-addon-name">• Addl Kubo: ${escapeHtml(cb.dataset.name)}</span>
                                    <span class="summary-addon-val">₱${sub.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                                </div>
                            </div>`;
            hasAct = true;
        });

        // Update guest capacity and summary sidebar capacity
        updateGuestMaxCapacity(baseKuboCapacity + extraCapacity, extraCapacity);
        const summaryCapEl = document.getElementById('summaryCapacity');
        if (summaryCapEl) {
            summaryCapEl.textContent = (extraCapacity > 0)
                ? `Up to ${baseKuboCapacity + extraCapacity} Guests (${baseKuboCapacity} + ${extraCapacity} Addl)`
                : `Up to ${baseKuboCapacity} Guests`;
        }

        
        const selectedActivities = document.querySelectorAll('.addon-activity-cb:checked');
        if (selectedActivities.length > 0) {
            const pax = getAddonMainPax();
            const addonGroups = getAddonActivityGroups();
            
            selectedActivities.forEach(opt => {
                hasAct = true;
                const price = parseFloat(opt.dataset.price) || 0;
                const name = opt.dataset.name || opt.value;
                const type = opt.dataset.type || 'full_day';
                // Limited activities use their own participant count when combined (split mode)
                const actPax = (addonGroups.split && opt.dataset.pool !== '1') ? getAddonActivityPax(opt.value) : pax;
                const sub = actPax * price;
                addonsTotal += sub;

                const dateVal = hiddenAddonDate ? hiddenAddonDate.value : '';
                const timeVal = hiddenAddonTime ? hiddenAddonTime.value : '';
                let schedText = '';
                if (type !== 'full_day' && dateVal) {
                    const parts = dateVal.split('-');
                    if (parts.length === 3) {
                        const d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
                        schedText = `<div style="font-size: 0.76rem; color: #64748b; margin-top: 3px;"><i class="fa-regular fa-clock" style="color: #7c3aed;"></i> ${d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} at ${escapeHtml(timeVal)}</div>`;
                    }
                }

                addonsHtml += `
                    <div class="summary-addon-row" style="flex-direction: column; align-items: flex-start; gap: 2px;">
                        <div style="display: flex; justify-content: space-between; width: 100%; align-items: center;">
                            <span class="summary-addon-name">• ${escapeHtml(name)} (${actPax} Pax × ₱${price.toLocaleString()})</span>
                            <span class="summary-addon-val">₱${sub.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                        </div>
                        ${schedText}
                    </div>
                `;
            });
            if (hasAct) {
                if (detailsWrapper) detailsWrapper.style.display = 'block';
            }
        } 
        
        if (hasAct) {
            if (summaryAddonsBox) {
                summaryAddonsBox.style.display = 'block';
                if (summaryAddonsList) {
                    summaryAddonsList.innerHTML = addonsHtml;
                }
                if (summaryAddonsSubtotal) {
                    summaryAddonsSubtotal.textContent = '₱' + addonsTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
            }
        } else {
            if (detailsWrapper) detailsWrapper.style.display = 'none';
            if (summaryAddonsBox) {
                summaryAddonsBox.style.display = 'none';
                if (summaryAddonsList) summaryAddonsList.innerHTML = '';
            }
        }

        const total = roomSubtotal + addonsTotal;

        if (summaryNights) summaryNights.textContent = currentNights + ' Night(s)';
        if (summaryTotal) summaryTotal.textContent = '₱' + total.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        if (formTotal) formTotal.value = total;

        if (!skipAvailabilityCheck) {
            renderAddonActivityPaxList();
            checkAddonAvailability();
        }
    }

    function updateBookingButtonState() {
        const btnSubmit = document.getElementById('btnSubmitBooking');
        if (!btnSubmit) return;

        if (totalUnits <= 0) {
            btnSubmit.disabled = true;
            btnSubmit.style.opacity = '0.55';
            btnSubmit.style.cursor = 'not-allowed';
            btnSubmit.innerHTML = `<span><i class="fa-solid fa-ban"></i> 0 Units Available (Unavailable)</span>`;
            return;
        }

        if (!roomRangeIsAvailable) {
            btnSubmit.disabled = true;
            btnSubmit.style.opacity = '0.55';
            btnSubmit.style.cursor = 'not-allowed';
            btnSubmit.innerHTML = `<span><i class="fa-solid fa-ban"></i> Dates Fully Booked (0 Units)</span>`;
            return;
        }

        if (!addonIsAvailable) {
            btnSubmit.disabled = true;
            btnSubmit.style.opacity = '0.55';
            btnSubmit.style.cursor = 'not-allowed';
            btnSubmit.innerHTML = `<span><i class="fa-solid fa-ban"></i> Add-on Fully Booked</span>`;
            return;
        }

        btnSubmit.disabled = false;
        btnSubmit.style.opacity = '1';
        btnSubmit.style.cursor = 'pointer';
        btnSubmit.innerHTML = `<span>Continue to GCash Payment</span><i class="fa-solid fa-arrow-right btn-arrow"></i>`;
    }

    function checkAddonAvailability() {
        const availabilityNotice = document.getElementById('addonAvailabilityNotice');
        const eqBox = document.getElementById('addonEquipmentBox');
        const eqList = document.getElementById('addonEquipmentList');
        
        const selectedActivities = document.querySelectorAll('.addon-activity-cb:checked');
        const selectedIds = Array.from(selectedActivities).map(cb => cb.value).join(',');
        
        if (selectedIds === '') {
            if (availabilityNotice) availabilityNotice.innerHTML = '';
            if (eqBox) eqBox.style.display = 'none';
            addonIsAvailable = true;
            updateBookingButtonState();
            return;
        }

        const date = hiddenAddonDate ? hiddenAddonDate.value : '';
        const timeInput = document.getElementById('modalAddonTimeSlot');
        const time24 = timeInput ? timeInput.value : '09:00';
        const reqPax = parseInt(paxInput ? paxInput.value : 1, 10);

        fetch(`api_check_activity_availability.php?activity_ids=${selectedIds}&date=${date}&start_time=${time24}`)
            .then(r => r.json())
            .then(data => {
                const groups = getAddonActivityGroups();
                addonActivityLimits = {};
                (data.activities || []).forEach(a => { addonActivityLimits[String(a.activity_id)] = parseInt(a.available, 10) || 0; });

                // Main (group) count: limited by the pool if selected, otherwise by the largest limited activity.
                let avail;
                let limAvail = null;
                if (groups.split) {
                    const limVals = groups.limIn.map(i => addonActivityLimits[i.value] !== undefined ? addonActivityLimits[i.value] : 0);
                    limAvail = limVals.length ? Math.min(...limVals) : 0;
                    avail = groups.poolIn.length > 0 && data.pool_available !== null && data.pool_available !== undefined
                        ? parseInt(data.pool_available, 10)
                        : Math.max(...limVals);
                } else {
                    avail = parseInt(data.available, 10);
                }
                if (isNaN(avail)) avail = 0;

                // Re-render per-activity steppers with fresh limits and refresh totals (no re-fetch)
                renderAddonActivityPaxList();
                recalculateTotals(true);

                if (avail > 0) {
                    if (availabilityNotice) {
                        availabilityNotice.innerHTML = `<i class="fa-solid fa-circle-check" style="color: var(--emerald);"></i> ${avail} participant slot(s) available${selectedActivities.length > 1 ? ' for all selected activities' : ''}`;
                        availabilityNotice.style.color = 'var(--emerald)';
                    }
                    if (paxInput) paxInput.max = avail;
                    if (reqPax <= avail) {
                        addonIsAvailable = true;
                    } else {
                        addonIsAvailable = false;
                        if (availabilityNotice) {
                            availabilityNotice.innerHTML = `<i class="fa-solid fa-triangle-exclamation" style="color: #ea580c;"></i> Only ${avail} slot(s) remaining${selectedActivities.length > 1 ? ' for this combination' : ''} (you requested ${reqPax})`;
                            availabilityNotice.style.color = '#ea580c';
                        }
                    }
                } else {
                    addonIsAvailable = false;
                    if (availabilityNotice) {
                        availabilityNotice.innerHTML = `<i class="fa-solid fa-circle-xmark" style="color: #ef4444;"></i> Fully Booked for this date/time`;
                        availabilityNotice.style.color = '#ef4444';
                    }
                    if (paxInput) paxInput.max = 0;
                }

                // Equipment-limited activities have no stock left: block booking
                if (groups.split && limAvail !== null && limAvail <= 0) {
                    addonIsAvailable = false;
                    if (availabilityNotice) {
                        availabilityNotice.innerHTML = `<i class="fa-solid fa-circle-xmark" style="color: #ef4444;"></i> Fully Booked: no equipment left for the selected equipment activities`;
                        availabilityNotice.style.color = '#ef4444';
                    }
                }
                
                updateBookingButtonState();

                // Render real-time remaining equipment & gear (Kayak, Scuba, Paddles, etc.)
                if (data.equipment && data.equipment.length > 0) {
                    let eqHTML = '';
                    data.equipment.forEach(item => {
                        const isOut = item.remaining <= 0;
                        const isLow = !isOut && item.remaining <= 2;
                        const badgeBg = isOut ? '#fee2e2' : (isLow ? '#fff7ed' : '#ffffff');
                        const badgeBorder = isOut ? '#fca5a5' : (isLow ? '#fdba74' : '#e9d5ff');
                        const textColor = isOut ? '#b91c1c' : (isLow ? '#c2410c' : '#581c87');
                        const iconColor = isOut ? '#ef4444' : (isLow ? '#ea580c' : '#7c3aed');
                        const countColor = isOut ? '#dc2626' : (isLow ? '#c2410c' : '#6b21a8');

                        eqHTML += `
                            <div style="display: inline-flex; align-items: center; gap: 7px; background: ${badgeBg}; border: 1.5px solid ${badgeBorder}; color: ${textColor}; padding: 6px 12px; border-radius: 8px; font-size: 0.82rem; font-weight: 700; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                                <i class="fa-solid ${item.icon || 'fa-boxes-stacked'}" style="color: ${iconColor}; font-size: 0.9rem;"></i>
                                <span>${item.name}:</span>
                                <span style="font-weight: 900; color: ${countColor};">${item.remaining} of ${item.qty} remaining</span>
                            </div>
                        `;
                    });
                    if (eqList) eqList.innerHTML = eqHTML;
                    if (eqBox) eqBox.style.display = 'block';
                } else {
                    if (eqBox) eqBox.style.display = 'none';
                }
            })
            .catch(err => {
                console.error(err);
                if (eqBox) eqBox.style.display = 'none';
            });
    }

    function updateDisplayOnly() {
        if (!hiddenInDate || !hiddenOutDate) return;
        if (modalInTime && hiddenInTime) hiddenInTime.value = modalInTime.value;
        if (modalOutTime && hiddenOutTime) hiddenOutTime.value = modalOutTime.value;

        const inDate = hiddenInDate.value;
        const outDate = hiddenOutDate.value;

        if (inDate && outDate) {
            const d1 = new Date(inDate + 'T00:00:00');
            const d2 = new Date(outDate + 'T00:00:00');
            const d1Str = d1.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            const d2Str = d2.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            const inTimeStr = hiddenInTime ? formatTime12h(hiddenInTime.value) : '2:00 PM';
            const outTimeStr = hiddenOutTime ? formatTime12h(hiddenOutTime.value) : '12:00 PM';

            // Sync Overview Banner elements
            const ovInDate = document.getElementById('overviewCheckInDate');
            const ovInTime = document.getElementById('overviewCheckInTime');
            const ovNightsText = document.getElementById('overviewNightsText');
            const ovOutDate = document.getElementById('overviewCheckOutDate');
            const ovOutTime = document.getElementById('overviewCheckOutTime');

            if (ovInDate) ovInDate.textContent = d1Str;
            if (ovInTime) ovInTime.textContent = inTimeStr;
            if (ovNightsText) ovNightsText.textContent = currentNights + ' Night' + (currentNights > 1 ? 's' : '');
            if (ovOutDate) ovOutDate.textContent = d2Str;
            if (ovOutTime) ovOutTime.textContent = outTimeStr + ' (Noon)';

            if (displaySelected) {
                displaySelected.innerHTML = `${d1Str} (${inTimeStr}) <br><span style="font-size:0.8rem;color:#64748b;"><i class="fa-solid fa-arrow-right"></i> ${d2Str} (${outTimeStr})</span>`;
            }
            if (summaryCheckInTime) summaryCheckInTime.textContent = inTimeStr;
            if (summaryCheckOutTime) summaryCheckOutTime.textContent = outTimeStr;
        }
    }

    // ---------- 5. Validations & Modal Handlers ----------
    function validateNoPastTime() {
        if (!hiddenInDate || !modalInTime) return true;
        const inDateVal = hiddenInDate.value;
        const inTimeVal = modalInTime.value;
        const warning = document.getElementById('timeWarning');
        if (warning) warning.style.display = 'none';

        if (!inDateVal || !inTimeVal) return true;

        const selectedDateTime = new Date(inDateVal + 'T' + inTimeVal);
        const now = new Date();

        if (selectedDateTime < now) {
            if (warning) warning.style.display = 'block';
            let curHours = now.getHours();
            let curMins = now.getMinutes();
            if (curHours < 8) { curHours = 8; curMins = 0; }
            if (curHours > 22) { curHours = 22; curMins = 0; }
            modalInTime.value = String(curHours).padStart(2, '0') + ':' + String(curMins).padStart(2, '0');
            if (modalOutTime) {
                modalOutTime.value = calculateCheckoutTime(modalInTime.value);
            }
            if (window.checkInPicker) window.checkInPicker.updateDisplay();
            return false;
        }
        return true;
    }

    function validateResortHours() {
        if (!modalInTime) return true;
        const warning = document.getElementById('hoursWarning');
        const ok = isWithinResortHours(modalInTime.value);
        if (warning) warning.style.display = ok ? 'none' : 'block';
        return ok;
    }

    function onCheckInTimeChange() {
        validateResortHours();
        validateNoPastTime();
        if (modalInTime && modalOutTime) {
            modalOutTime.value = calculateCheckoutTime(modalInTime.value);
        }
        updateDisplayOnly();
        recalculateTotals();
    }

    if (modalInTime) {
        modalInTime.addEventListener('input', onCheckInTimeChange);
        modalInTime.addEventListener('change', onCheckInTimeChange);
    }

    // Addon Modal Handlers
    function openAddonModal() {
        if (addonModal) {
            addonModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeAddonModal() {
        if (addonModal) {
            addonModal.classList.remove('show');
            document.body.style.overflow = '';
        }
    }

    if (btnOpenAddon) btnOpenAddon.addEventListener('click', openAddonModal);
    if (btnCloseAddon) btnCloseAddon.addEventListener('click', closeAddonModal);
    if (btnCancelAddon) btnCancelAddon.addEventListener('click', closeAddonModal);
    if (addonModal) {
        addonModal.addEventListener('click', (e) => {
            if (e.target === addonModal) closeAddonModal();
        });
        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && addonModal.classList.contains('show')) closeAddonModal();
        });
    }

    function validateAddonHours() {
        if (!modalAddonTime) return true;
        const warning = document.getElementById('addonHoursWarning');
        const ok = isWithinResortHours(modalAddonTime.value);
        if (warning) warning.style.display = ok ? 'none' : 'block';
        return ok;
    }

    function validateNoPastAddonTime() {
        if (!hiddenAddonDate || !modalAddonTime) return true;
        const dateVal = hiddenAddonDate.value;
        const timeVal = modalAddonTime.value;
        const warning = document.getElementById('addonTimeWarning');
        if (warning) warning.style.display = 'none';

        if (!dateVal || !timeVal) return true;

        const selectedDateTime = new Date(dateVal + 'T' + timeVal);
        const now = new Date();

        if (selectedDateTime < now) {
            if (warning) warning.style.display = 'block';
            const future = new Date(now.getTime() + 60 * 60 * 1000);
            modalAddonTime.value = String(future.getHours()).padStart(2, '0') + ':' + String(future.getMinutes()).padStart(2, '0');
            return false;
        }
        return true;
    }

    if (modalAddonTime) {
        modalAddonTime.addEventListener('change', validateAddonHours);
        modalAddonTime.addEventListener('change', validateNoPastAddonTime);
    }

    if (btnSaveAddon) {
        btnSaveAddon.addEventListener('click', () => {
            if (!validateAddonHours()) return;
            if (!validateNoPastAddonTime()) return;
            updateAddonDisplayOnly();
            closeAddonModal();
        });
    }

    // Addon Input Event Listeners
    if (actSelect) {
        actSelect.addEventListener('change', () => {
            recalculateTotals();
        });
    }

    if (paxInput) {
        paxInput.addEventListener('input', () => {
            if (paxInput.max && paxInput.value !== '') {
                const max = parseInt(paxInput.max, 10);
                const val = parseInt(paxInput.value, 10);
                if (!isNaN(max) && !isNaN(val) && val > max) {
                    paxInput.value = max;
                }
            }
            recalculateTotals();
        });
        paxInput.addEventListener('change', () => {
            if (paxInput.max && paxInput.value !== '') {
                const max = parseInt(paxInput.max, 10);
                const min = parseInt(paxInput.min || 1, 10);
                let val = parseInt(paxInput.value, 10);
                
                if (isNaN(val) || val < min) {
                    paxInput.value = min;
                } else if (!isNaN(max) && val > max) {
                    paxInput.value = max;
                }
            } else if (paxInput.value === '') {
                paxInput.value = paxInput.min || 1;
            }
            recalculateTotals();
        });
    }

    // ---------- 5.5 Stay Duration Pills & Customizer Sync ----------
    function syncDurationPills(nights) {
        const badge = document.getElementById('stayNightsBadge');
        if (badge) {
            badge.textContent = nights + (nights > 7 ? ' Nights (Custom Stay)' : ' Night(s)');
        }
        const ovNightsText = document.getElementById('overviewNightsText');
        if (ovNightsText) {
            ovNightsText.textContent = nights + ' Night' + (nights > 1 ? 's' : '');
        }

        let matched = false;
        document.querySelectorAll('.duration-pill-btn').forEach(btn => {
            const dur = btn.dataset.days;
            if (dur !== 'custom' && parseInt(dur, 10) === nights) {
                btn.classList.add('active');
                matched = true;
            } else {
                btn.classList.remove('active');
            }
        });

        const customBtn = document.getElementById('btnDurationCustom');
        const customPanel = document.getElementById('customDurationPanel');
        const customInput = document.getElementById('customNightsInput');
        const customSubBadge = document.getElementById('customDurationSubBadge');

        if (!matched || nights > 7) {
            if (customBtn) customBtn.classList.add('active');
            if (customPanel) customPanel.style.display = 'block';
            if (customInput && document.activeElement !== customInput) {
                customInput.value = nights;
            }
            if (customSubBadge) {
                customSubBadge.textContent = `${nights} Nights Selected`;
            }
        } else {
            if (customBtn) customBtn.classList.remove('active');
            if (customPanel && nights <= 7) {
                customPanel.style.display = 'none';
            }
        }

        // Sync preset chips
        document.querySelectorAll('.preset-chip-btn').forEach(chip => {
            const chipN = parseInt(chip.dataset.nights, 10);
            if (chipN === nights) {
                chip.classList.add('active');
            } else {
                chip.classList.remove('active');
            }
        });
    }

    function setCustomNights(nights) {
        nights = Math.max(8, Math.min(90, parseInt(nights, 10) || 8));
        const customInput = document.getElementById('customNightsInput');
        if (customInput && document.activeElement !== customInput) {
            customInput.value = nights;
        }

        const calEl = document.getElementById('bookingCalendarPicker');
        if (calEl && typeof calEl._setStayDuration === 'function') {
            const ok = calEl._setStayDuration(nights);
            if (ok) {
                currentNights = nights;
                syncDurationPills(nights);
            }
        } else {
            const inVal = hiddenInDate ? hiddenInDate.value : '<?= $check_in ?>';
            if (inVal) {
                const inD = new Date(inVal + 'T00:00:00');
                inD.setDate(inD.getDate() + nights);
                const y = inD.getFullYear();
                const m = String(inD.getMonth() + 1).padStart(2, '0');
                const d = String(inD.getDate()).padStart(2, '0');
                if (hiddenOutDate) hiddenOutDate.value = `${y}-${m}-${d}`;
                currentNights = nights;
                updateDisplayOnly();
                recalculateTotals();
                syncDurationPills(nights);
            }
        }
    }

    document.querySelectorAll('.duration-pill-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const days = this.dataset.days;
            if (days === 'custom') {
                const customPanel = document.getElementById('customDurationPanel');
                if (customPanel) customPanel.style.display = 'block';
                const customInput = document.getElementById('customNightsInput');
                let targetNights = customInput ? parseInt(customInput.value, 10) : 8;
                if (!targetNights || targetNights <= 7) targetNights = Math.max(8, currentNights);
                setCustomNights(targetNights);
                return;
            }
            const nights = parseInt(days, 10);
            const calEl = document.getElementById('bookingCalendarPicker');
            if (calEl && typeof calEl._setStayDuration === 'function') {
                calEl._setStayDuration(nights);
            } else {
                const inVal = hiddenInDate ? hiddenInDate.value : '<?= $check_in ?>';
                if (inVal) {
                    const inD = new Date(inVal + 'T00:00:00');
                    inD.setDate(inD.getDate() + nights);
                    const y = inD.getFullYear();
                    const m = String(inD.getMonth() + 1).padStart(2, '0');
                    const d = String(inD.getDate()).padStart(2, '0');
                    if (hiddenOutDate) hiddenOutDate.value = `${y}-${m}-${d}`;
                    currentNights = nights;
                    updateDisplayOnly();
                    recalculateTotals();
                }
            }
            syncDurationPills(nights);
        });
    });

    // Custom Duration Stepper and Presets Listeners
    const btnCustomDec = document.getElementById('btnCustomDec');
    const btnCustomInc = document.getElementById('btnCustomInc');
    const customInputEl = document.getElementById('customNightsInput');
    const btnApplyCustom = document.getElementById('btnApplyCustomNights');

    if (btnCustomDec) {
        btnCustomDec.addEventListener('click', function(e) {
            e.preventDefault();
            const cur = parseInt(customInputEl ? customInputEl.value : 8, 10) || 8;
            setCustomNights(Math.max(8, cur - 1));
        });
    }

    if (btnCustomInc) {
        btnCustomInc.addEventListener('click', function(e) {
            e.preventDefault();
            const cur = parseInt(customInputEl ? customInputEl.value : 8, 10) || 8;
            setCustomNights(Math.min(90, cur + 1));
        });
    }

    if (customInputEl) {
        customInputEl.addEventListener('change', function() {
            setCustomNights(this.value);
        });
        customInputEl.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                setCustomNights(this.value);
            }
        });
    }

    if (btnApplyCustom) {
        btnApplyCustom.addEventListener('click', function(e) {
            e.preventDefault();
            if (customInputEl) setCustomNights(customInputEl.value);
        });
    }

    document.querySelectorAll('.preset-chip-btn').forEach(chip => {
        chip.addEventListener('click', function(e) {
            e.preventDefault();
            const pNights = parseInt(this.dataset.nights, 10);
            if (pNights) setCustomNights(pNights);
        });
    });

    // ---------- 6. Calendar Initializations ----------
    // Stay Range Calendar
    try {
        console.log('🗓️ Initializing booking calendar with:', {
            checkIn: '<?= $check_in ?>',
            checkOut: '<?= $check_out ?>',
            totalUnits: totalUnits,
            bookedDatesCount: bookedDates.length
        });
        initMonthlyCalendarPicker({
            containerId: 'bookingCalendarPicker',
            checkInInputId: 'inputCheckIn',
            checkOutInputId: 'inputCheckOut',
            initialCheckIn: '<?= $check_in ?>',
            initialCheckOut: '<?= $check_out ?>',
            bookedRanges: bookedRanges,
            bookedDates: bookedDates,
            availableUnits: totalUnits,
            totalUnits: totalUnits,
            isAvailable: roomRangeIsAvailable,
            isAdmin: <?= $is_admin_layout ? 'true' : 'false' ?>,
            showDurationStrip: false,
            onRangeSelected: function(range) {
                console.log('✓ Date range selected:', range);
                currentNights = range.nights;
                syncDurationPills(range.nights);
                if (hiddenAddonDate && range.checkIn) {
                    hiddenAddonDate.value = range.checkIn;
                    updateAddonDisplayOnly();
                }
                validateNoPastTime();
                if (modalInTime && modalOutTime) {
                    modalOutTime.value = calculateCheckoutTime(modalInTime.value);
                }
                roomRangeIsAvailable = (range.isAvailable !== false);
                updateDisplayOnly();
                recalculateTotals();
                updateBookingButtonState();
            }
        });
        console.log('✓ Booking calendar initialized successfully');
    } catch (err) {
        console.error('❌ Error initializing booking calendar:', err);
    }

    // Add-on Single Date Calendar
    try {
        initMonthlyCalendarPicker({
            containerId: 'addonCalendarPicker',
            checkInInputId: 'inputAddonDate',
            isSingleDate: true,
            initialCheckIn: hiddenAddonDate && hiddenAddonDate.value ? hiddenAddonDate.value : '<?= $check_in ?>',
            isAdmin: <?= $is_admin_layout ? 'true' : 'false' ?>,
            onRangeSelected: function(range) {
                if (!addonCalendarReady) return;
                console.log('✓ Activity date selected:', range);
                validateNoPastAddonTime();
                updateAddonDisplayOnly();
            }
        });
        addonCalendarReady = true;
        console.log('✓ Add-on calendar initialized successfully');
    } catch (err) {
        console.error('❌ Error initializing add-on calendar:', err);
    }

    // ---------- 7. Initial Page Load ----------
    window.addEventListener('DOMContentLoaded', () => {
        if (hiddenInDate && hiddenInDate.value && modalInTime) {
            const now = new Date();
            const selectedDateTime = new Date(hiddenInDate.value + 'T' + modalInTime.value);
            const localDateString = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
            if (selectedDateTime < now && hiddenInDate.value === localDateString) {
                let curHours = now.getHours();
                let curMins = now.getMinutes();
                if (curHours < 8) { curHours = 8; curMins = 0; }
                if (curHours > 22) { curHours = 22; curMins = 0; }
                modalInTime.value = String(curHours).padStart(2, '0') + ':' + String(curMins).padStart(2, '0');
            }
        }
        if (modalInTime && modalOutTime) {
            modalOutTime.value = calculateCheckoutTime(modalInTime.value);
        }
        setTimeout(updateDisplayOnly, 100);
        updateAddonDisplayOnly();
        recalculateTotals();
        updateBookingButtonState();
        syncDurationPills(currentNights);

        // Guest Manifest controls initialization
        renderGuestInputs();
        const btnGuestDec = document.getElementById('btnGuestDec');
        const btnGuestInc = document.getElementById('btnGuestInc');
        if (btnGuestDec) {
            btnGuestDec.addEventListener('click', () => {
                if (guestNamesState.length > 1) {
                    removeGuestSlot(guestNamesState.length - 1);
                }
            });
        }
        if (btnGuestInc) {
            btnGuestInc.addEventListener('click', () => {
                addGuestSlot();
            });
        }
        const btnAddSlot = document.getElementById('btnAddGuestSlot');
        if (btnAddSlot) {
            btnAddSlot.addEventListener('click', () => {
                addGuestSlot();
            });
        }
    });

    // Form submission validation
    const form = document.getElementById('bookingForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const inVal = (hiddenInDate ? hiddenInDate.value : '').trim();
            const outVal = (hiddenOutDate ? hiddenOutDate.value : '').trim();

            if (!inVal || !outVal) {
                e.preventDefault();
                alert('⚠️ Incomplete stay dates! Please pick both your Check-In and Check-Out dates on the calendar.');
                return false;
            }
            if (hiddenInTime && hiddenOutTime && (!isWithinResortHours(hiddenInTime.value) || !isWithinResortHours(hiddenOutTime.value))) {
                e.preventDefault();
                alert('⚠️ Please choose check-in and check-out times between 8:00 AM and 10:00 PM.');
                return false;
            }
            if (new Date(outVal) <= new Date(inVal)) {
                e.preventDefault();
                alert('⚠️ Check-Out date must be after Check-In date (minimum 1 night stay).');
                return false;
            }
            if (totalUnits <= 0 || !roomRangeIsAvailable) {
                e.preventDefault();
                alert('⚠️ Sorry! This accommodation has 0 units available for the selected dates. Please choose different dates on the calendar.');
                return false;
            }
            if (!addonIsAvailable) {
                e.preventDefault();
                alert('⚠️ The selected add-on is fully booked or exceeds available participant slots.');
                return false;
            }

            // Sync and validate guest manifest
            syncGuestListToHidden();
            const guestListVal = (document.getElementById('finalGuestList') ? document.getElementById('finalGuestList').value : '').trim();
            if (!guestListVal) {
                e.preventDefault();
                alert('⚠️ Please provide the names of all guests staying for the resort manifest.');
                const firstEmpty = Array.from(document.querySelectorAll('.guest-name-field')).find(inp => !inp.value.trim());
                if (firstEmpty) firstEmpty.focus();
                return false;
            }
        });
    }

    // ---------- Reusable Custom Time Picker Builder ----------
    function initCustomTimePicker(cfg) {
        const display = document.getElementById(cfg.displayId);
        const text = document.getElementById(cfg.textId);
        const dropdown = document.getElementById(cfg.dropdownId);
        const grid = document.getElementById(cfg.gridId);
        const input = document.getElementById(cfg.inputId);

        if (!display || !text || !dropdown || !grid || !input) return null;

        let timesHTML = '';
        for (let h = 8; h <= 22; h++) {
            for (let m = 0; m < 60; m += 5) {
                if (h === 22 && m > 0) break;
                const hrString = h.toString().padStart(2, '0');
                const minString = m.toString().padStart(2, '0');
                const val = `${hrString}:${minString}`;
                const ampm = h >= 12 ? 'PM' : 'AM';
                const displayHr = h % 12 === 0 ? 12 : h % 12;
                timesHTML += `<div class="time-cell" data-val="${val}">
                    <span class="t-hr">${displayHr}:${minString}</span><span class="t-ampm">${ampm}</span>
                </div>`;
            }
        }
        grid.innerHTML = timesHTML;

        function updateDisplay() {
            const val = input.value || cfg.defaultVal || '08:00';
            const parts = val.split(':');
            let hr = parseInt(parts[0], 10);
            const m = parts[1] || '00';
            const ampm = hr >= 12 ? 'PM' : 'AM';
            const dHr = hr % 12 === 0 ? 12 : hr % 12;

            text.textContent = `${dHr}:${m} ${ampm}`;

            grid.querySelectorAll('.time-cell').forEach(cell => {
                const isSel = (cell.dataset.val === val);
                cell.setAttribute('data-selected', isSel ? 'true' : 'false');
                if (isSel) {
                    cell.style.background = '#f8fafc';
                    cell.style.border = '1px solid #cbd5e1';
                    cell.style.boxShadow = '0 2px 6px rgba(0,0,0,0.06)';
                } else {
                    cell.style.background = '';
                    cell.style.border = '1px solid transparent';
                    cell.style.boxShadow = '';
                }
            });
        }

        if (cfg.isInline) {
            display.addEventListener('click', () => {
                const selectedCell = grid.querySelector('.time-cell[data-selected="true"]');
                if (selectedCell && dropdown) {
                    dropdown.scrollTop = Math.max(0, (selectedCell.offsetTop - grid.offsetTop) - (dropdown.clientHeight / 2) + (selectedCell.clientHeight / 2));
                }
            });

            grid.addEventListener('click', (e) => {
                const cell = e.target.closest('.time-cell');
                if (cell) {
                    input.value = cell.dataset.val;
                    updateDisplay();
                    input.dispatchEvent(new Event('change'));
                    if (cfg.onChange) cfg.onChange(cell.dataset.val);
                }
            });
        } else {
            display.addEventListener('click', (e) => {
                e.stopPropagation();
                document.querySelectorAll('.custom-time-dropdown').forEach(d => {
                    if (d !== dropdown) d.style.display = 'none';
                });
                document.querySelectorAll('.custom-time-display').forEach(d => {
                    if (d !== display) d.classList.remove('active');
                });

                const isShowing = dropdown.style.display === 'block';
                dropdown.style.display = isShowing ? 'none' : 'block';
                if (isShowing) {
                    display.classList.remove('active');
                } else {
                    display.classList.add('active');
                    const selectedCell = grid.querySelector('.time-cell[data-selected="true"]');
                    if (selectedCell) {
                        dropdown.scrollTop = selectedCell.offsetTop - dropdown.offsetHeight / 2 + 20;
                    }
                }
            });

            grid.addEventListener('click', (e) => {
                const cell = e.target.closest('.time-cell');
                if (cell) {
                    input.value = cell.dataset.val;
                    updateDisplay();
                    dropdown.style.display = 'none';
                    display.classList.remove('active');
                    input.dispatchEvent(new Event('change'));
                    if (cfg.onChange) cfg.onChange(cell.dataset.val);
                }
            });

            document.addEventListener('click', (e) => {
                if (!display.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.style.display = 'none';
                    display.classList.remove('active');
                }
            });
        }

        updateDisplay();
        return { updateDisplay };
    }

    window.checkInPicker = initCustomTimePicker({
        displayId: 'checkInTimeDisplay',
        textId: 'checkInTimeText',
        dropdownId: 'checkInTimeDropdown',
        gridId: 'checkInTimeGrid',
        inputId: 'modalCheckInTime',
        defaultVal: '<?= $existing_in_time ?>',
        onChange: onCheckInTimeChange
    });

    window.addonPicker = initCustomTimePicker({
        displayId: 'customTimePickerDisplay',
        textId: 'customTimePickerText',
        dropdownId: 'customTimePickerContainer',
        gridId: 'customTimeGrid',
        inputId: 'modalAddonTimeSlot',
        defaultVal: '<?= $existing_addon_time24 ?>',
        isInline: true,
        onChange: () => {
            if (modalAddonTime) modalAddonTime.dispatchEvent(new Event('change'));
            updateAddonDisplayOnly();
        }
    });

    const origOpenAddonModal = openAddonModal;
    openAddonModal = function() {
        origOpenAddonModal();
        setTimeout(() => {
            const container = document.getElementById('customTimePickerContainer');
            const grid = document.getElementById('customTimeGrid');
            const selectedCell = grid ? grid.querySelector('.time-cell[data-selected="true"]') : null;
            if (container && selectedCell) {
                container.scrollTop = Math.max(0, (selectedCell.offsetTop - grid.offsetTop) - (container.clientHeight / 2) + (selectedCell.clientHeight / 2));
            }
        }, 120);
    };

    const oldAddonUpdate = updateAddonDisplayOnly;
    updateAddonDisplayOnly = function() {
        if (window.addonPicker) window.addonPicker.updateDisplay();
        oldAddonUpdate();
    };
    </script>

</body>
</html>