<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/booking_store.php';
require_once __DIR__ . '/inventory_store.php';
require_once __DIR__ . '/security.php';  // CSRF protection
require_once __DIR__ . '/auth.php';

// Strictly require registered account login to book
if (empty($_SESSION['user_id'])) {
    $redirect_id = intval($_GET['id'] ?? 1);
    $redirect_params = 'id=' . $redirect_id;
    if (!empty($_GET['check_in'])) $redirect_params .= '&check_in=' . urlencode($_GET['check_in']);
    if (!empty($_GET['check_out'])) $redirect_params .= '&check_out=' . urlencode($_GET['check_out']);
    $login_url = 'login.php?msg=' . urlencode('Please sign in with your registered account before booking. Please sign in or register first.') . '&redirect=' . urlencode('book_house.php?' . $redirect_params);
    header("Location: " . $login_url);
    exit();
}

// Disallow blocked or deactivated user from booking
if ($block_reason = Auth::isUserBlocked($_SESSION['user_id'])) {
    Auth::logout();
    header("Location: login.php?msg=" . urlencode("Your account has been deactivated/blocked by management: " . $block_reason));
    exit();
}

// Load all Kubos
$all_kubos_raw = InventoryStore::getAllKubos();
if (empty($all_kubos_raw)) {
    $all_kubos_raw = [
        [
            'kubo_id' => 1,
            'name' => 'Beach Kubo',
            'category' => 'Family Suite',
            'price' => 3500.00,
            'capacity' => 6,
            'available_units' => 5,
            'image' => 'assets/beach_kubo.jpg',
            'description' => 'Spacious air-conditioned Kubo with private veranda, refrigerator, and 6-person capacity.',
            'badges' => 'Free WiFi, Aircon',
            'status' => 'available'
        ],
        [
            'kubo_id' => 2,
            'name' => 'Orchid Honeymoon Kubo #2',
            'category' => 'Couples & Honeymoon',
            'price' => 2200.00,
            'capacity' => 2,
            'available_units' => 5,
            'image' => 'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=600&q=80',
            'description' => 'Cozy bamboo pavilion steps away from the main infinity pool. Romantic queen bed setup.',
            'badges' => 'Poolside, Smart TV',
            'status' => 'available'
        ],
        [
            'kubo_id' => 3,
            'name' => 'Royal Purple Pavilion #3',
            'category' => 'Group Villa',
            'price' => 5800.00,
            'capacity' => 15,
            'available_units' => 5,
            'image' => 'https://images.unsplash.com/photo-1618773928121-c32242e63f39?auto=format&fit=crop&w=600&q=80',
            'description' => '2-Storey bamboo villa equipped with private grilling station, sound system, and up to 15 guests.',
            'badges' => 'Up to 15 Pax, BBQ Grill',
            'status' => 'available'
        ]
    ];
}

// Selected Kubo
$selected_kubo_id = intval($_GET['id'] ?? ($_POST['kubo_id'] ?? ($all_kubos_raw[0]['kubo_id'] ?? 1)));
$kubo = InventoryStore::getKuboById($selected_kubo_id);
if (!$kubo) {
    foreach ($all_kubos_raw as $k) {
        if ($k['kubo_id'] == $selected_kubo_id) {
            $kubo = $k;
            break;
        }
    }
}
if (!$kubo) {
    $kubo = $all_kubos_raw[0];
    $selected_kubo_id = intval($kubo['kubo_id']);
}

// Stay Dates (Strictly enforce no past dates)
$today_str = date('Y-m-d');
$check_in = trim($_GET['check_in'] ?? ($_POST['check_in'] ?? $today_str));
if (!strtotime($check_in) || $check_in < $today_str) {
    $check_in = $today_str;
}
$default_checkout = date('Y-m-d', strtotime($check_in . ' +1 day'));
$check_out = trim($_GET['check_out'] ?? ($_POST['check_out'] ?? $default_checkout));
if (!strtotime($check_out) || $check_out <= $check_in) {
    $check_out = $default_checkout;
}

$d1 = new DateTime($check_in);
$d2 = new DateTime($check_out);
$nights = max(1, $d1->diff($d2)->days);

// Enrich Kubos with availability and remaining units
$kubos_list = [];
$selected_is_available = false;

foreach ($all_kubos_raw as $k) {
    $kid = intval($k['kubo_id']);
    $k_status = strtolower(trim($k['status'] ?? 'available'));
    $is_reno = ($k_status === 'renovation');
    $is_maint = in_array($k_status, ['maintenance', 'unavailable']);

    $units_total = intval($k['available_units'] ?? 5);
    if ($units_total <= 0) $units_total = 5;

    $units_left = ($is_reno || $is_maint) ? 0 : BookingStore::getRemainingUnits($kid, $check_in, $check_out);
    $is_avail = (!$is_reno && !$is_maint && $units_left > 0 && BookingStore::isItemAvailable($kid, $check_in, $check_out, 'house'));
    if (!$is_avail) {
        $units_left = 0;
    }
    $is_fully_booked = (!$is_avail || $units_left <= 0);

    // Badges array
    $raw_badges = $k['badges'] ?? '';
    $badge_array = array_filter(array_map('trim', explode(',', $raw_badges)));
    
    // Ensure image
    $img = !empty($k['image']) ? $k['image'] : (!empty($k['primary_image']) ? $k['primary_image'] : 'assets/beach_kubo.jpg');
    if ($kid == 1 && (empty($img) || str_contains($img, 'unsplash'))) {
        $img = 'assets/beach_kubo.jpg';
    }

    $kubos_list[] = [
        'kubo_id' => $kid,
        'name' => $k['name'] ?? $k['kubo_name_number'] ?? "Kubo #{$kid}",
        'category' => !empty($k['category']) ? $k['category'] : 'Resort Suite',
        'price' => floatval($k['price'] ?? 3500),
        'capacity' => intval($k['capacity'] ?? 6),
        'available_units' => $units_total,
        'units_left' => $units_left,
        'image' => $img,
        'description' => $k['description'] ?? 'Authentic bamboo villa designed for relaxation, scenic views, and modern comforts.',
        'badges' => $badge_array,
        'status' => $k_status,
        'is_available' => (bool)$is_avail,
        'is_fully_booked' => (bool)$is_fully_booked,
        'is_renovation' => (bool)$is_reno
    ];

    if ($kid === $selected_kubo_id && $is_avail) {
        $selected_is_available = true;
    }
}

// If the initially requested kubo is fully booked, auto-select the first available kubo
if (!$selected_is_available) {
    foreach ($kubos_list as $kl) {
        if ($kl['is_available'] && $kl['units_left'] > 0) {
            $selected_kubo_id = $kl['kubo_id'];
            $selected_is_available = true;
            break;
        }
    }
    if (!$selected_is_available) {
        $selected_kubo_id = 0; // None available
    }
}

// Load Activities
$raw_activities = InventoryStore::getAllActivities();
$available_activities = array_filter($raw_activities, function($act) {
    return !empty($act['is_available']);
});

// Ensure the 3 featured activities from the mockup are available
$activities_map = [];
foreach ($available_activities as $a) {
    $activities_map[strtolower(trim($a['activity_name']))] = $a;
}

$mockup_activities = [
    [
        'activity_id' => 101,
        'activity_name' => 'Kayaking',
        'category' => 'outdoor',
        'price' => 500.00,
        'duration' => '60 minutes',
        'duration_minutes' => 60,
        'description' => 'Clear-bottom scenic kayaking experience in resort lagoons and crystal waters.',
        'image' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=600&q=80',
        'is_available' => 1
    ],
    [
        'activity_id' => 102,
        'activity_name' => 'Scuba Diving',
        'category' => 'outdoor',
        'price' => 2500.00,
        'duration' => '90 minutes',
        'duration_minutes' => 90,
        'description' => 'Guided reef diving tour with certified diving instructor and full gear.',
        'image' => 'https://images.unsplash.com/photo-1544551763-77ef2d0cfc6c?auto=format&fit=crop&w=600&q=80',
        'is_available' => 1
    ],
    [
        'activity_id' => 103,
        'activity_name' => 'Pool Activity',
        'category' => 'pool',
        'price' => 350.00,
        'duration' => '60 minutes',
        'duration_minutes' => 60,
        'description' => 'Access to the main infinity pool, aqua fitness accessories, and poolside lounge.',
        'image' => 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80',
        'is_available' => 1
    ]
];

// Merge DB activities with fallback activities
$display_activities = [];
foreach ($mockup_activities as $ma) {
    $found = false;
    foreach ($available_activities as $act) {
        $n = strtolower(trim($act['activity_name']));
        if (str_contains($n, strtolower($ma['activity_name'])) || str_contains(strtolower($ma['activity_name']), $n)) {
            $act['image'] = !empty($act['image']) ? $act['image'] : $ma['image'];
            $act['duration'] = !empty($act['duration']) ? $act['duration'] : $ma['duration'];
            $display_activities[] = $act;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $display_activities[] = $ma;
    }
}
// Add any remaining DB activities not matched
foreach ($available_activities as $act) {
    $already_in = false;
    foreach ($display_activities as $da) {
        if ($da['activity_id'] == $act['activity_id']) {
            $already_in = true;
            break;
        }
    }
    if (!$already_in) {
        $act['image'] = !empty($act['image']) ? $act['image'] : 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80';
        $act['duration'] = !empty($act['duration']) ? $act['duration'] : '60 minutes';
        $display_activities[] = $act;
    }
}

// User details
$user_id = $_SESSION['user_id'];
$profile_id = $_SESSION['profile_id'] ?? sprintf("PBR-GST-%05d", $user_id);
$guest_name = !empty($_SESSION['full_name']) ? $_SESSION['full_name'] : 'Luffy D Monkey';
$guest_email = !empty($_SESSION['email']) ? $_SESSION['email'] : 'luffy@example.com';
$guest_phone = !empty($_SESSION['phone']) ? preg_replace('/[^0-9]/', '', $_SESSION['phone']) : '09170000000';
$user_avatar = $_SESSION['profile_picture'] ?? '';

// Pending booking check for this specific accommodation
$pending_booking = BookingStore::getPendingBookingForUser($user_id, 'house', $selected_kubo_id);
$error_msg = '';
if ($pending_booking) {
    $error_msg = "You already have a pending booking (" . htmlspecialchars($pending_booking['id']) . ") for this accommodation. Please wait until it is approved or declined before booking this room again. (You can still book other available houses or activities!)";
}

// POST Booking Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    $submitted_kubo_id = intval($_POST['kubo_id'] ?? $selected_kubo_id);
    $target_kubo = InventoryStore::getKuboById($submitted_kubo_id);
    if (!$target_kubo) {
        foreach ($kubos_list as $kl) {
            if ($kl['kubo_id'] == $submitted_kubo_id) {
                $target_kubo = $kl;
                break;
            }
        }
    }
    $target_name = $target_kubo['name'] ?? $target_kubo['kubo_name_number'] ?? "House Kubo #{$submitted_kubo_id}";
    $rate_per_night = floatval($target_kubo['price'] ?? 3500);

    $submitted_check_in  = trim($_POST['check_in'] ?? '');
    $submitted_check_out = trim($_POST['check_out'] ?? '');
    $submitted_check_in_time  = trim($_POST['check_in_time'] ?? '14:00');
    $submitted_check_out_time = trim($_POST['check_out_time'] ?? '12:00');
    $guest_list_raw = trim($_POST['guest_list'] ?? '');
    $notes_raw = trim($_POST['notes'] ?? '');

    // Resort operating hours: 8:00 AM - 10:00 PM
    $in_ts  = strtotime($submitted_check_in_time);
    $out_ts = strtotime($submitted_check_out_time);
    $in_hm  = $in_ts ? date('H:i', $in_ts) : '';
    $out_hm = $out_ts ? date('H:i', $out_ts) : '';
    $within_hours = ($in_hm !== '' && $out_hm !== ''
        && $in_hm >= '08:00' && $in_hm <= '22:00'
        && $out_hm >= '08:00' && $out_hm <= '22:00');

    if (empty($submitted_check_in) || empty($submitted_check_out)) {
        $error_msg = "Please select both Check-In and Check-Out dates to complete your reservation.";
    } elseif (!strtotime($submitted_check_in) || !strtotime($submitted_check_out)) {
        $error_msg = "Invalid stay dates submitted. Please select valid dates.";
    } elseif ($submitted_check_in < $today_str) {
        $error_msg = "Check-In date cannot be in the past. Please select today or a future date.";
    } elseif ($submitted_check_out <= $submitted_check_in) {
        $error_msg = "Check-Out date must be after your Check-In date (minimum 1 night stay).";
    } elseif ($submitted_kubo_id <= 0) {
        $error_msg = "Please select an available Kubo accommodation for your stay.";
    } elseif (empty($guest_list_raw)) {
        $error_msg = "Please provide the names of all guests staying for the resort manifest.";
    } elseif ($pending_booking = BookingStore::getPendingBookingForUser($user_id, 'house', $submitted_kubo_id)) {
        $error_msg = "You already have a pending booking (" . htmlspecialchars($pending_booking['id']) . ") for this accommodation. Please wait until it is approved or declined before booking this room again.";
    } elseif (!$within_hours) {
        $error_msg = "Please choose check-in and check-out times within resort hours (8:00 AM - 10:00 PM).";
    } elseif (!BookingStore::isItemAvailable($submitted_kubo_id, $submitted_check_in, $submitted_check_out, 'house') || BookingStore::getRemainingUnits($submitted_kubo_id, $submitted_check_in, $submitted_check_out) <= 0) {
        $error_msg = "Sorry! {$target_name} is fully booked for " . date('M d, Y', strtotime($submitted_check_in)) . " - " . date('M d, Y', strtotime($submitted_check_out)) . ". Please pick different dates or select another available Kubo.";
    } else {
        $d1 = new DateTime($submitted_check_in);
        $d2 = new DateTime($submitted_check_out);
        $calc_nights = max(1, $d1->diff($d2)->days);
        $room_total = $calc_nights * $rate_per_night;

        // Process Selected Activities
        $selected_addons = [];
        $addons_total = 0;
        $addons_summary_list = [];

        $chosen_activity_ids = [];
        if (!empty($_POST['selected_activities']) && is_array($_POST['selected_activities'])) {
            $chosen_activity_ids = array_map('intval', $_POST['selected_activities']);
        } elseif (!empty($_POST['addon_activity_id'])) {
            $chosen_activity_ids = [intval($_POST['addon_activity_id'])];
        }

        foreach ($chosen_activity_ids as $aid) {
            if ($aid <= 0) continue;
            // Find activity item
            $act_item = null;
            foreach ($display_activities as $da) {
                if ($da['activity_id'] == $aid) {
                    $act_item = $da;
                    break;
                }
            }
            if (!$act_item) {
                $act_item = InventoryStore::getActivityById($aid);
            }

            if ($act_item) {
                $act_price = floatval($act_item['price'] ?? 0);
                $pax = 1;
                $subtotal = $act_price * $pax;
                $addons_total += $subtotal;
                $act_name = $act_item['activity_name'] ?? "Activity #{$aid}";
                $act_dur = $act_item['duration'] ?? '60 minutes';

                $selected_addons[] = [
                    'activity_id' => $aid,
                    'name' => $act_name,
                    'price' => $act_price,
                    'qty' => $pax,
                    'pax' => $pax,
                    'subtotal' => $subtotal,
                    'scheduled_date' => $submitted_check_in,
                    'time_slot' => $act_dur,
                    'start_time' => '09:00:00',
                    'end_time' => '10:00:00'
                ];
                $addons_summary_list[] = "• {$act_name} - ₱" . number_format($subtotal, 2) . " [{$act_dur}]";
            }
        }

        $calc_total = $room_total + $addons_total;

        $final_special_notes = $notes_raw;
        if (!empty($addons_summary_list)) {
            $addon_block = "[Pool & Activities Add-ons]:\n" . implode("\n", $addons_summary_list);
            $final_special_notes = !empty($final_special_notes) 
                ? ($final_special_notes . "\n\n" . $addon_block) 
                : $addon_block;
        }

        $ref_no = 'PBR-' . date('Y') . '-' . random_int(1000, 9999);
        $format_in_time = date('g:i A', strtotime($submitted_check_in_time ?: '14:00'));
        $format_out_time = date('g:i A', strtotime($submitted_check_out_time ?: '12:00'));
        $check_in_dt = $submitted_check_in . ' ' . date('H:i:s', strtotime($submitted_check_in_time ?: '14:00'));
        $check_out_dt = $submitted_check_out . ' ' . date('H:i:s', strtotime($submitted_check_out_time ?: '12:00'));

        $booking = BookingStore::createBooking([
            'id' => $ref_no,
            'guest_name' => $guest_name,
            'guest_email' => $guest_email,
            'guest_phone' => $guest_phone,
            'type' => 'house',
            'item_id' => $submitted_kubo_id,
            'item_name' => $target_name,
            'check_in' => $check_in_dt,
            'check_out' => $check_out_dt,
            'time_slot' => "{$calc_nights} Night(s) Stay ({$format_in_time} - {$format_out_time})",
            'pax' => intval($target_kubo['capacity'] ?? 4),
            'total_amount' => $calc_total,
            'profile_id' => $profile_id,
            'user_id' => $user_id,
            'payment_status' => 'pending',
            'booking_status' => 'pending',
            'special_notes' => $final_special_notes,
            'guest_list' => $guest_list_raw,
            'addons' => $selected_addons
        ]);

        $_SESSION['last_booking'] = $booking;
        header("Location: payment_method.php?ref=" . $ref_no);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Your Stay | Purple Bamboo Resort</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
    <style>
        /* ==========================================================================
           BOOKING PAGE REDESIGN - HIGH FIDELITY SPEC
           ========================================================================== */
        :root {
            --pb-purple-primary: #7c3aed;
            --pb-purple-dark: #6d28d9;
            --pb-purple-deep: #1e1b4b;
            --pb-purple-light: #f5f3ff;
            --pb-purple-border: #ddd6fe;
            --pb-purple-glow: rgba(124, 58, 237, 0.15);
            --pb-green-badge: #10b981;
            --pb-green-bg: #ecfdf5;
            --pb-green-border: #a7f3d0;
            --pb-gray-bg: #f8f9fc;
            --pb-card-border: #e2e8f0;
            --pb-text-main: #0f172a;
            --pb-text-muted: #64748b;
        }

        body.booking-redesign-body {
            background-color: var(--pb-gray-bg);
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--pb-text-main);
            margin: 0;
            padding: 0;
            padding-top: 70px;
        }

        /* Top Header Override */
        body.booking-redesign-body .hs-top-header {
            left: 0 !important;
            height: 70px;
            background: linear-gradient(90deg, #190321 0%, #290a35 45%, #1d0325 100%) !important;
            border-bottom: 2px solid rgba(124, 58, 237, 0.45);
            box-shadow: 0 4px 25px rgba(15, 2, 20, 0.35);
            padding: 0 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 1000;
        }
        body.booking-redesign-body .app-sidebar,
        body.booking-redesign-body .hs-mobile-menu-btn {
            display: none !important;
        }

        .booking-top-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }
        .booking-top-brand-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, #7c3aed, #a855f7);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.15rem;
            box-shadow: 0 2px 10px rgba(124, 58, 237, 0.35);
        }
        .booking-top-brand-text {
            display: flex;
            flex-direction: column;
        }
        .booking-top-brand-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.05rem;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }
        .booking-top-brand-sub {
            font-size: 0.72rem;
            color: #f0abfc;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .booking-user-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 5px 12px 5px 6px;
            border-radius: 9999px;
            text-decoration: none;
            color: #ffffff;
            transition: all 0.2s ease;
        }
        .booking-user-chip:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.3);
        }
        .booking-user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #7c3aed;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.9rem;
            border: 1.5px solid rgba(255, 255, 255, 0.4);
        }
        .booking-user-name {
            font-size: 0.88rem;
            font-weight: 700;
            color: #ffffff;
        }
        .booking-user-caret {
            font-size: 0.75rem;
            color: #cbd5e1;
            margin-left: 2px;
        }
        .booking-logout-btn {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #f87171;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            margin-left: 8px;
        }
        .booking-logout-btn:hover {
            background: rgba(239, 68, 68, 0.2);
            color: #ffffff;
            border-color: #ef4444;
        }

        /* Page Container */
        .booking-wrapper {
            max-width: 1280px;
            width: 100%;
            margin: 0 auto;
            padding: 2.25rem 1.5rem 5rem 1.5rem;
            box-sizing: border-box;
        }

        /* Page Title */
        .booking-header-row {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 1.5rem;
        }
        .booking-header-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, #7c3aed, #9333ea);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.35rem;
            box-shadow: 0 4px 14px rgba(124, 58, 237, 0.25);
            flex-shrink: 0;
            margin-top: 2px;
        }
        .booking-header-text h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.95rem;
            font-weight: 800;
            color: var(--pb-purple-deep);
            margin: 0 0 4px 0;
            line-height: 1.2;
        }
        .booking-header-text p {
            font-size: 0.95rem;
            color: var(--pb-text-muted);
            margin: 0;
        }

        /* Stepper Component */
        .booking-stepper {
            background: #ffffff;
            border: 1.5px solid var(--pb-card-border);
            border-radius: 14px;
            padding: 0.9rem 1.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
            overflow-x: auto;
            gap: 1rem;
        }
        .stepper-item {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
            transition: all 0.2s ease;
        }
        .stepper-circle {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.82rem;
            font-weight: 800;
            transition: all 0.2s ease;
            background: #ede9fe;
            color: var(--pb-purple-primary);
        }
        .stepper-label {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--pb-text-muted);
            transition: all 0.2s ease;
        }
        .stepper-item.active .stepper-circle {
            background: var(--pb-purple-primary);
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.35);
        }
        .stepper-item.active .stepper-label {
            color: var(--pb-purple-deep);
            font-weight: 800;
        }
        .stepper-item.completed .stepper-circle {
            background: #10b981;
            color: #ffffff;
        }
        .stepper-item.completed .stepper-label {
            color: #059669;
        }

        /* 2-Column Main Grid */
        .booking-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.8fr) minmax(320px, 380px);
            gap: 1.85rem;
            align-items: start;
        }

        /* Generic Section Card */
        .booking-card {
            background: #ffffff;
            border: 1.5px solid var(--pb-card-border);
            border-radius: 16px;
            padding: 1.6rem 1.75rem;
            margin-bottom: 1.6rem;
            box-shadow: 0 4px 20px -2px rgba(124, 58, 237, 0.04);
            box-sizing: border-box;
            transition: border-color 0.2s ease;
        }

        .card-header-flex {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.35rem;
            flex-wrap: wrap;
            gap: 10px;
        }
        .card-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .card-icon-box {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: var(--pb-purple-light);
            border: 1px solid var(--pb-purple-border);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--pb-purple-primary);
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .card-title-group h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.22rem;
            font-weight: 800;
            color: var(--pb-purple-deep);
            margin: 0 0 2px 0;
        }
        .card-title-group p {
            font-size: 0.84rem;
            color: var(--pb-text-muted);
            margin: 0;
        }

        /* Badges */
        .badge-available {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--pb-green-bg);
            border: 1px solid var(--pb-green-border);
            color: #047857;
            font-size: 0.78rem;
            font-weight: 800;
            padding: 4px 12px;
            border-radius: 20px;
            white-space: nowrap;
        }
        .badge-date-specific {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--pb-purple-light);
            border: 1px solid var(--pb-purple-border);
            color: var(--pb-purple-primary);
            font-size: 0.78rem;
            font-weight: 800;
            padding: 4px 12px;
            border-radius: 20px;
            white-space: nowrap;
        }

        /* Your Stay Controls */
        .your-stay-row {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 14px;
            align-items: center;
        }
        .date-picker-box {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #ffffff;
            border: 1.5px solid var(--pb-card-border);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }
        .date-picker-box:hover {
            border-color: var(--pb-purple-primary);
            box-shadow: 0 0 0 3px var(--pb-purple-glow);
        }
        .date-picker-box input[type="date"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
            z-index: 2;
        }
        .date-picker-icon {
            color: var(--pb-purple-primary);
            font-size: 1.15rem;
        }
        .date-picker-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .date-picker-label {
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            color: var(--pb-text-muted);
            letter-spacing: 0.5px;
        }
        .date-picker-val {
            font-family: 'Outfit', sans-serif;
            font-size: 0.98rem;
            font-weight: 800;
            color: var(--pb-text-main);
        }
        .btn-update-dates {
            background: var(--pb-purple-primary);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            padding: 0.85rem 1.35rem;
            font-weight: 700;
            font-size: 0.88rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            white-space: nowrap;
            height: 100%;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.25);
        }
        .btn-update-dates:hover {
            background: var(--pb-purple-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(124, 58, 237, 0.35);
        }

        /* Choose Your Kubo 3-Card Grid */
        .kubos-cards-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
        }
        .kubo-card-item {
            background: #ffffff;
            border: 2px solid var(--pb-card-border);
            border-radius: 14px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: all 0.22s ease;
            cursor: pointer;
            position: relative;
        }
        .kubo-card-item:hover {
            border-color: #c4b5fd;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(124, 58, 237, 0.08);
        }
        .kubo-card-item.is-selected {
            border-color: var(--pb-purple-primary);
            box-shadow: 0 8px 24px rgba(124, 58, 237, 0.16);
            background: #fdfaff;
        }
        .kubo-card-img-wrap {
            position: relative;
            width: 100%;
            height: 145px;
            overflow: hidden;
            background: #f1f5f9;
        }
        .kubo-card-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        .kubo-card-item:hover .kubo-card-img {
            transform: scale(1.03);
        }
        .kubo-cat-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            background: rgba(109, 40, 217, 0.9);
            backdrop-filter: blur(4px);
            color: #ffffff;
            font-size: 0.66rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            padding: 3px 8px;
            border-radius: 6px;
            text-transform: uppercase;
        }
        .kubo-card-content {
            padding: 0.95rem 1rem 1rem 1rem;
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        .kubo-card-top-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 6px;
        }
        .kubo-card-name {
            font-family: 'Outfit', sans-serif;
            font-size: 0.98rem;
            font-weight: 800;
            color: var(--pb-purple-deep);
            line-height: 1.25;
            margin: 0;
        }
        .kubo-card-price-wrap {
            text-align: right;
            flex-shrink: 0;
        }
        .kubo-card-price {
            font-family: 'Outfit', sans-serif;
            font-size: 1.05rem;
            font-weight: 800;
            color: #6d28d9;
            line-height: 1;
        }
        .kubo-card-per-night {
            font-size: 0.62rem;
            font-weight: 800;
            color: var(--pb-text-muted);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-top: 2px;
        }
        .kubo-card-desc {
            font-size: 0.76rem;
            color: var(--pb-text-muted);
            line-height: 1.45;
            margin: 0 0 10px 0;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 2.2rem;
        }
        .kubo-card-chips {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            margin-bottom: 12px;
        }
        .kubo-chip-pill {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #334155;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 3px 6px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .kubo-chip-pill i {
            color: var(--pb-purple-primary);
            font-size: 0.72rem;
        }
        .btn-select-kubo {
            background: #ffffff;
            border: 1.5px solid var(--pb-purple-border);
            color: var(--pb-purple-primary);
            font-weight: 800;
            font-size: 0.84rem;
            padding: 0.55rem;
            border-radius: 9px;
            text-align: center;
            cursor: pointer;
            width: 100%;
            margin-top: auto;
            transition: all 0.2s ease;
        }
        .btn-select-kubo:hover {
            background: var(--pb-purple-light);
            border-color: var(--pb-purple-primary);
        }
        .kubo-card-item.is-selected .btn-select-kubo {
            background: var(--pb-purple-primary);
            color: #ffffff;
            border-color: var(--pb-purple-primary);
        }

        /* Fully Booked & Unavailable Kubo Cards */
        .kubo-card-item.is-fully-booked {
            opacity: 0.72;
            background: #fff8f8;
            border-color: #fca5a5;
            cursor: not-allowed;
        }
        .kubo-card-item.is-fully-booked:hover {
            transform: none;
            box-shadow: none;
            border-color: #f87171;
        }
        .kubo-card-item.is-fully-booked .kubo-card-img {
            filter: grayscale(70%) contrast(90%);
        }
        .kubo-card-item.is-fully-booked .kubo-card-price {
            color: #94a3b8;
        }
        .kubo-card-item.is-fully-booked .btn-select-kubo,
        .btn-select-kubo:disabled {
            background: #fee2e2 !important;
            color: #991b1b !important;
            border-color: #fca5a5 !important;
            cursor: not-allowed !important;
            opacity: 0.9 !important;
            pointer-events: none;
        }
        .kubo-booked-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(220, 38, 38, 0.95);
            backdrop-filter: blur(4px);
            color: #ffffff;
            font-size: 0.64rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            padding: 3px 8px;
            border-radius: 6px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 4px;
            box-shadow: 0 2px 8px rgba(220, 38, 38, 0.35);
            z-index: 2;
        }
        .kubo-chip-pill.pill-units.is-booked {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #991b1b;
            font-weight: 800;
        }
        .btn-update-dates.loading {
            opacity: 0.8;
            pointer-events: none;
        }

        /* Add Activities Section */
        .activities-cards-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 1rem;
        }
        .activity-card-item {
            background: #ffffff;
            border: 1.5px solid var(--pb-card-border);
            border-radius: 14px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }
        .activity-card-item:hover {
            border-color: #c4b5fd;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(124, 58, 237, 0.08);
        }
        .activity-card-item.is-checked {
            border-color: var(--pb-purple-primary);
            background: #fdfaff;
            box-shadow: 0 6px 20px rgba(124, 58, 237, 0.12);
        }
        .activity-card-img-wrap {
            width: 100%;
            height: 110px;
            background: #f1f5f9;
            overflow: hidden;
            position: relative;
        }
        .activity-card-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .activity-card-body {
            padding: 0.85rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .activity-card-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .custom-activity-check {
            width: 18px;
            height: 18px;
            border-radius: 5px;
            border: 1.5px solid #cbd5e1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 0.72rem;
            transition: all 0.2s ease;
            flex-shrink: 0;
            background: #ffffff;
        }
        .activity-card-item.is-checked .custom-activity-check {
            background: var(--pb-purple-primary);
            border-color: var(--pb-purple-primary);
        }
        .activity-card-text {
            display: flex;
            flex-direction: column;
        }
        .activity-card-title {
            font-family: 'Outfit', sans-serif;
            font-size: 0.92rem;
            font-weight: 800;
            color: var(--pb-purple-deep);
            line-height: 1.2;
        }
        .activity-card-duration {
            font-size: 0.72rem;
            color: var(--pb-text-muted);
            margin-top: 2px;
        }
        .activity-card-right {
            text-align: right;
            flex-shrink: 0;
        }
        .activity-card-price {
            font-family: 'Outfit', sans-serif;
            font-size: 0.98rem;
            font-weight: 800;
            color: #6d28d9;
        }
        .activity-card-icon {
            font-size: 0.72rem;
            color: #a855f7;
            margin-top: 2px;
        }

        .activities-info-banner {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.82rem;
            color: #475569;
        }
        .activities-info-banner i {
            color: var(--pb-purple-primary);
            font-size: 0.95rem;
            flex-shrink: 0;
        }

        /* Right Column Sticky Booking Summary */
        .summary-sidebar-card {
            background: #ffffff;
            border: 1.5px solid var(--pb-card-border);
            border-radius: 16px;
            padding: 1.5rem 1.6rem;
            box-shadow: 0 8px 30px rgba(124, 58, 237, 0.08);
            position: sticky;
            top: 90px;
            box-sizing: border-box;
        }
        .summary-header-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 1.15rem;
            border-bottom: 1px solid #f1f5f9;
            margin-bottom: 1.15rem;
        }
        .summary-header-icon {
            color: var(--pb-purple-primary);
            font-size: 1.2rem;
        }
        .summary-header-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--pb-purple-deep);
            margin: 0;
        }

        /* Dates Box */
        .summary-dates-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.75rem 0.95rem;
            margin-bottom: 1.25rem;
            gap: 8px;
        }
        .summary-date-col {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .summary-date-sub {
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            color: var(--pb-text-muted);
            letter-spacing: 0.4px;
        }
        .summary-date-text {
            font-family: 'Outfit', sans-serif;
            font-size: 0.88rem;
            font-weight: 800;
            color: var(--pb-text-main);
        }
        .summary-date-sep {
            color: #cbd5e1;
            font-size: 0.85rem;
        }

        /* Section Block in Summary */
        .summary-section-block {
            margin-bottom: 1.25rem;
            padding-bottom: 1.15rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .summary-section-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }
        .summary-section-title {
            font-size: 0.84rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--pb-text-muted);
        }
        .summary-edit-btn {
            background: none;
            border: none;
            color: var(--pb-purple-primary);
            font-size: 0.82rem;
            cursor: pointer;
            padding: 2px 4px;
            transition: all 0.2s ease;
        }
        .summary-edit-btn:hover {
            color: var(--pb-purple-dark);
            transform: scale(1.1);
        }

        /* Selected Kubo Row */
        .summary-kubo-box {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.65rem 0.85rem;
        }
        .summary-kubo-thumb {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            object-fit: cover;
            flex-shrink: 0;
            background: #f1f5f9;
        }
        .summary-kubo-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex: 1;
            min-width: 0;
        }
        .summary-kubo-name {
            font-family: 'Outfit', sans-serif;
            font-size: 0.92rem;
            font-weight: 800;
            color: var(--pb-purple-deep);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .summary-kubo-rate {
            font-size: 0.8rem;
            font-weight: 700;
            color: #6d28d9;
        }
        .summary-kubo-units {
            font-size: 0.72rem;
            color: var(--pb-text-muted);
        }

        /* Summary Activities List */
        .summary-activities-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .summary-act-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.82rem;
            color: #334155;
            background: #f8fafc;
            padding: 6px 10px;
            border-radius: 8px;
            border: 1px solid #f1f5f9;
        }
        .summary-act-item strong {
            color: #6d28d9;
            font-family: 'Outfit', sans-serif;
        }
        .summary-no-activities {
            color: #3b82f6;
            font-size: 0.84rem;
            font-weight: 600;
        }

        /* Total Section */
        .summary-total-block {
            margin-bottom: 1.25rem;
        }
        .summary-total-label {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--pb-text-muted);
            margin-bottom: 4px;
        }
        .summary-total-val-row {
            display: flex;
            align-items: baseline;
            gap: 8px;
        }
        .summary-total-amount {
            font-family: 'Outfit', sans-serif;
            font-size: 1.75rem;
            font-weight: 900;
            color: var(--pb-purple-primary);
            line-height: 1;
        }
        .summary-total-nights {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--pb-text-muted);
        }

        /* Primary Action Button */
        .btn-continue-checkout {
            background: var(--pb-purple-primary);
            color: #ffffff;
            border: none;
            width: 100%;
            padding: 0.85rem 1rem;
            border-radius: 12px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 0.92rem;
            font-weight: 800;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(124, 58, 237, 0.3);
        }
        .btn-continue-checkout:hover {
            background: var(--pb-purple-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(124, 58, 237, 0.4);
        }

        /* Step 4: Guest Details Card Form */
        #stepGuestDetailsCard,
        #stepReviewPaymentCard {
            display: none;
        }
        .form-label-bold {
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.92rem;
            color: var(--pb-purple-deep);
            margin-bottom: 0.45rem;
        }
        .form-control-custom {
            width: 100%;
            font-family: inherit;
            font-size: 0.92rem;
            border: 1.5px solid var(--pb-card-border);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            background: #ffffff;
            color: var(--pb-text-main);
            box-sizing: border-box;
            transition: all 0.2s ease;
        }
        .form-control-custom:focus {
            outline: none;
            border-color: var(--pb-purple-primary);
            box-shadow: 0 0 0 3px var(--pb-purple-glow);
        }
        .time-slots-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        /* Step Navigation Buttons */
        .step-nav-buttons {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 1.5rem;
        }
        .btn-step-back {
            background: #ffffff;
            border: 1.5px solid var(--pb-card-border);
            color: var(--pb-text-muted);
            padding: 0.75rem 1.4rem;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.88rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        .btn-step-back:hover {
            border-color: var(--pb-purple-primary);
            color: var(--pb-purple-primary);
        }

        /* Review Breakdown Table */
        .review-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.25rem;
        }
        .review-table th,
        .review-table td {
            padding: 0.75rem 0.5rem;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.88rem;
        }
        .review-table th {
            text-align: left;
            color: var(--pb-text-muted);
            font-weight: 700;
            font-size: 0.78rem;
            text-transform: uppercase;
        }
        .review-table td strong {
            color: var(--pb-purple-deep);
            font-weight: 800;
        }

        /* Responsive Breakpoints */
        @media (max-width: 1024px) {
            .booking-grid {
                grid-template-columns: 1fr;
            }
            .summary-sidebar-card {
                position: static;
                margin-top: 1rem;
            }
            .kubos-cards-grid {
                grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            }
            .activities-cards-grid {
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            }
        }
        @media (max-width: 640px) {
            .your-stay-row {
                grid-template-columns: 1fr;
            }
            .kubos-cards-grid,
            .activities-cards-grid {
                grid-template-columns: 1fr;
            }
            .booking-wrapper {
                padding: 1.25rem 1rem 3rem 1rem;
            }
            .time-slots-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="booking-redesign-body">

    <!-- Top Header -->
    <header class="hs-top-header">
        <a href="index.php" class="booking-top-brand">
            <div class="booking-top-brand-icon">
                <i class="fa-solid fa-hotel"></i>
            </div>
            <div class="booking-top-brand-text">
                <span class="booking-top-brand-title">THE PURPLE BAMBOO</span>
                <span class="booking-top-brand-sub">Beach Resort &amp; Cottages</span>
            </div>
        </a>

        <div style="display: flex; align-items: center;">
            <a href="user-dashboard.php" class="booking-user-chip" title="View Account Profile">
                <div class="booking-user-avatar">
                    <?= strtoupper(substr($guest_name, 0, 1)) ?>
                </div>
                <span class="booking-user-name"><?= htmlspecialchars($guest_name) ?></span>
                <i class="fa-solid fa-chevron-down booking-user-caret"></i>
            </a>

            <a href="logout.php" class="booking-logout-btn" title="Sign Out">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
            </a>
        </div>
    </header>

    <main class="booking-wrapper">

        <!-- Page Header -->
        <div class="booking-header-row">
            <div class="booking-header-icon">
                <i class="fa-solid fa-house-chimney"></i>
            </div>
            <div class="booking-header-text">
                <h1>Book Your Stay</h1>
                <p>Choose your preferred dates, select a Kubo, and add activities for a more memorable experience.</p>
            </div>
        </div>

        <!-- Stepper Component -->
        <div class="booking-stepper">
            <div class="stepper-item active" id="stepperStep1" onclick="goToStep(1)">
                <div class="stepper-circle">1</div>
                <span class="stepper-label">Dates</span>
            </div>
            <div class="stepper-item" id="stepperStep2" onclick="goToStep(2)">
                <div class="stepper-circle">2</div>
                <span class="stepper-label">Choose Kubo</span>
            </div>
            <div class="stepper-item" id="stepperStep3" onclick="goToStep(3)">
                <div class="stepper-circle">3</div>
                <span class="stepper-label">Activities (Optional)</span>
            </div>
            <div class="stepper-item" id="stepperStep4" onclick="goToStep(4)">
                <div class="stepper-circle">4</div>
                <span class="stepper-label">Guest Details</span>
            </div>
            <div class="stepper-item" id="stepperStep5" onclick="goToStep(5)">
                <div class="stepper-circle">5</div>
                <span class="stepper-label">Review &amp; Payment</span>
            </div>
        </div>

        <?php if (!empty($error_msg)): ?>
            <div style="background: rgba(239, 68, 68, 0.12); color: #b91c1c; padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1.5rem; border: 1.5px solid #fca5a5; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-exclamation" style="font-size: 1.25rem;"></i>
                <div><?= htmlspecialchars($error_msg) ?></div>
            </div>
        <?php endif; ?>

        <!-- Form Element Wrapping Submissions -->
        <form action="book_house.php" method="POST" id="mainBookingForm">
            <?= csrf_field() ?>
            <input type="hidden" name="kubo_id" id="formKuboId" value="<?= $selected_kubo_id ?>">
            <input type="hidden" name="check_in" id="formCheckIn" value="<?= htmlspecialchars($check_in) ?>">
            <input type="hidden" name="check_out" id="formCheckOut" value="<?= htmlspecialchars($check_out) ?>">
            <input type="hidden" name="check_in_time" id="formCheckInTime" value="14:00">
            <input type="hidden" name="check_out_time" id="formCheckOutTime" value="12:00">

            <div class="booking-grid">

                <!-- Left Column: Main Interactive Cards -->
                <div class="booking-main-col">

                    <!-- STEP 1-3 SELECTION VIEW -->
                    <div id="stepSelectionView">

                        <!-- CARD 1: Your Stay -->
                        <section class="booking-card" id="sectionYourStay">
                            <div class="card-header-flex">
                                <div class="card-header-left">
                                    <div class="card-icon-box">
                                        <i class="fa-regular fa-calendar-days"></i>
                                    </div>
                                    <div class="card-title-group">
                                        <h2>Your Stay</h2>
                                        <p>Select your check-in and check-out dates</p>
                                    </div>
                                </div>
                            </div>

                            <div class="your-stay-row">
                                <!-- Check-in Box -->
                                <div class="date-picker-box">
                                    <input type="date" id="datePickerCheckIn" value="<?= htmlspecialchars($check_in) ?>" min="<?= $today_str ?>" onchange="onDateChanged()" oninput="onDateInputCheckIn(this)">
                                    <i class="fa-regular fa-calendar date-picker-icon"></i>
                                    <div class="date-picker-info">
                                        <span class="date-picker-label">Check-in</span>
                                        <span class="date-picker-val" id="textCheckInDisplay"><?= date('M d, Y', strtotime($check_in)) ?></span>
                                    </div>
                                </div>

                                <!-- Check-out Box -->
                                <div class="date-picker-box">
                                    <input type="date" id="datePickerCheckOut" value="<?= htmlspecialchars($check_out) ?>" min="<?= date('Y-m-d', strtotime($check_in . ' +1 day')) ?>" onchange="onDateChanged()" oninput="onDateInputCheckOut(this)">
                                    <i class="fa-regular fa-calendar date-picker-icon"></i>
                                    <div class="date-picker-info">
                                        <span class="date-picker-label">Check-out</span>
                                        <span class="date-picker-val" id="textCheckOutDisplay"><?= date('M d, Y', strtotime($check_out)) ?></span>
                                    </div>
                                </div>

                                <!-- Update Dates Button -->
                                <button type="button" class="btn-update-dates" onclick="onUpdateDatesClicked()">
                                    <i class="fa-solid fa-arrows-rotate"></i>
                                    <span>Update Dates</span>
                                </button>
                            </div>
                        </section>

                        <!-- CARD 2: Choose Your Kubo -->
                        <section class="booking-card" id="sectionChooseKubo">
                            <div class="card-header-flex">
                                <div class="card-header-left">
                                    <div class="card-icon-box">
                                        <i class="fa-solid fa-house-chimney"></i>
                                    </div>
                                    <div class="card-title-group">
                                        <h2>Choose Your Kubo</h2>
                                        <p>Select from our beautifully designed kubos and cottages. Fully booked rooms cannot be selected.</p>
                                    </div>
                                </div>
                                <span class="badge-available" id="badgeDatesAvailable" style="<?= ($selected_kubo_id == 0) ? 'display: none;' : '' ?>">
                                    <i class="fa-solid fa-check"></i> Available for your dates
                                </span>
                            </div>

                            <!-- Alert when all rooms are fully booked for the selected dates -->
                            <div id="allRoomsBookedNotice" style="<?= ($selected_kubo_id == 0) ? 'display: flex;' : 'display: none;' ?> background: #fee2e2; border: 1.5px solid #fca5a5; border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; color: #991b1b; font-weight: 700; align-items: center; gap: 10px;">
                                <i class="fa-solid fa-calendar-xmark" style="font-size: 1.4rem; color: #dc2626; flex-shrink: 0;"></i>
                                <div>
                                    <strong style="display: block; margin-bottom: 2px;">All Accommodations are Fully Booked for These Dates!</strong>
                                    All room units are already reserved for <span id="textBookedNoticeDates"><?= date('M d, Y', strtotime($check_in)) ?> – <?= date('M d, Y', strtotime($check_out)) ?></span>. Please choose a different Check-In or Check-Out date above to find open vacancies.
                                </div>
                            </div>

                            <div class="kubos-cards-grid" id="kubosCardsContainer">
                                <?php foreach ($kubos_list as $k_item): 
                                    $is_sel = ($k_item['kubo_id'] == $selected_kubo_id && !$k_item['is_fully_booked']);
                                    $is_booked = $k_item['is_fully_booked'];
                                    $status_label = $k_item['is_renovation'] ? 'Under Renovation' : 'Fully Booked';
                                ?>
                                    <div class="kubo-card-item <?= $is_sel ? 'is-selected' : '' ?> <?= $is_booked ? 'is-fully-booked' : '' ?>" 
                                         id="kuboCardItem_<?= $k_item['kubo_id'] ?>"
                                         data-kuboid="<?= $k_item['kubo_id'] ?>"
                                         data-available="<?= $is_booked ? '0' : '1' ?>"
                                         onclick="handleKuboClick(<?= $k_item['kubo_id'] ?>)">
                                        
                                        <div class="kubo-card-img-wrap">
                                            <img src="<?= htmlspecialchars($k_item['image']) ?>" alt="<?= htmlspecialchars($k_item['name']) ?>" class="kubo-card-img" onerror="this.onerror=null; this.src='assets/beach_kubo.jpg';">
                                            <div class="kubo-cat-badge"><?= htmlspecialchars(strtoupper($k_item['category'])) ?></div>
                                            
                                            <div class="kubo-booked-badge" id="kuboBookedBadge_<?= $k_item['kubo_id'] ?>" style="<?= $is_booked ? 'display: flex;' : 'display: none;' ?>">
                                                <i class="fa-solid fa-ban"></i> <?= strtoupper($status_label) ?>
                                            </div>
                                        </div>

                                        <div class="kubo-card-content">
                                            <div class="kubo-card-top-row">
                                                <h3 class="kubo-card-name"><?= htmlspecialchars($k_item['name']) ?></h3>
                                                <div class="kubo-card-price-wrap">
                                                    <div class="kubo-card-price">₱<?= number_format($k_item['price']) ?></div>
                                                    <div class="kubo-card-per-night">PER NIGHT</div>
                                                </div>
                                            </div>

                                            <p class="kubo-card-desc"><?= htmlspecialchars($k_item['description']) ?></p>

                                            <div class="kubo-card-chips">
                                                <div class="kubo-chip-pill"><i class="fa-solid fa-users"></i> Max <?= $k_item['capacity'] ?> Pax</div>
                                                <div class="kubo-chip-pill pill-units <?= $is_booked ? 'is-booked' : '' ?>" id="kuboUnitsChip_<?= $k_item['kubo_id'] ?>">
                                                    <?php if ($is_booked): ?>
                                                        <i class="fa-solid fa-ban" style="color: #dc2626;"></i> 0 of <?= $k_item['available_units'] ?> Left (Booked)
                                                    <?php else: ?>
                                                        <i class="fa-solid fa-hotel"></i> <?= $k_item['units_left'] ?> of <?= $k_item['available_units'] ?> Units left
                                                    <?php endif; ?>
                                                </div>
                                                <?php 
                                                $added_count = 2;
                                                foreach ($k_item['badges'] as $b): 
                                                    if ($added_count >= 4) break;
                                                    $added_count++;
                                                    $icon = 'fa-tag';
                                                    if (stripos($b, 'wifi') !== false) $icon = 'fa-wifi';
                                                    elseif (stripos($b, 'aircon') !== false) $icon = 'fa-snowflake';
                                                    elseif (stripos($b, 'pool') !== false) $icon = 'fa-water';
                                                    elseif (stripos($b, 'tv') !== false) $icon = 'fa-tv';
                                                    elseif (stripos($b, 'bbq') !== false || stripos($b, 'grill') !== false) $icon = 'fa-fire';
                                                ?>
                                                    <div class="kubo-chip-pill"><i class="fa-solid <?= $icon ?>"></i> <?= htmlspecialchars($b) ?></div>
                                                <?php endforeach; ?>
                                            </div>

                                            <button type="button" 
                                                    class="btn-select-kubo" 
                                                    id="btnSelectKubo_<?= $k_item['kubo_id'] ?>"
                                                    <?= $is_booked ? 'disabled' : '' ?>>
                                                <?= $is_booked ? '<i class="fa-solid fa-ban"></i> Fully Booked' : ($is_sel ? 'Selected ✓' : 'Select Kubo') ?>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>

                        <!-- CARD 3: Add Activities (Optional) -->
                        <section class="booking-card" id="sectionActivities">
                            <div class="card-header-flex">
                                <div class="card-header-left">
                                    <div class="card-icon-box">
                                        <i class="fa-solid fa-water"></i>
                                    </div>
                                    <div class="card-title-group">
                                        <h2>Add Activities (Optional)</h2>
                                        <p>Make your stay even more exciting! Choose from our available activities.</p>
                                    </div>
                                </div>
                                <span class="badge-date-specific">
                                    <i class="fa-regular fa-calendar"></i> Date specific availability
                                </span>
                            </div>

                            <div class="activities-cards-grid">
                                <?php foreach ($display_activities as $act): 
                                    $aid = intval($act['activity_id']);
                                    $aprice = floatval($act['price'] ?? 0);
                                    $adur = $act['duration'] ?? '60 minutes';
                                    $aname = $act['activity_name'];
                                    $aimg = $act['image'];
                                ?>
                                    <div class="activity-card-item" id="actCard_<?= $aid ?>" onclick="toggleActivity(<?= $aid ?>, event)">
                                        <div class="activity-card-img-wrap">
                                            <img src="<?= htmlspecialchars($aimg) ?>" alt="<?= htmlspecialchars($aname) ?>" class="activity-card-img" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=600&q=80';">
                                        </div>
                                        <div class="activity-card-body">
                                            <div class="activity-card-left">
                                                <input type="checkbox" name="selected_activities[]" value="<?= $aid ?>" id="chkAct_<?= $aid ?>" style="display: none;" onchange="updateSummaryState()">
                                                <div class="custom-activity-check" id="checkIcon_<?= $aid ?>">
                                                    <i class="fa-solid fa-check"></i>
                                                </div>
                                                <div class="activity-card-text">
                                                    <span class="activity-card-title"><?= htmlspecialchars($aname) ?></span>
                                                    <span class="activity-card-duration"><?= htmlspecialchars($adur) ?></span>
                                                </div>
                                            </div>
                                            <div class="activity-card-right">
                                                <div class="activity-card-price">₱<?= number_format($aprice) ?></div>
                                                <div class="activity-card-icon"><i class="fa-solid fa-person-swimming"></i></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="activities-info-banner">
                                <i class="fa-solid fa-circle-info"></i>
                                <span>You can select one or more activities. Availability is based on your chosen dates and time.</span>
                            </div>
                        </section>

                    </div>

                    <!-- STEP 4: GUEST DETAILS FORM CARD -->
                    <section class="booking-card" id="stepGuestDetailsCard">
                        <div class="card-header-flex">
                            <div class="card-header-left">
                                <div class="card-icon-box">
                                    <i class="fa-solid fa-user-check"></i>
                                </div>
                                <div class="card-title-group">
                                    <h2>Guest Details &amp; Preferences</h2>
                                    <p>Please provide names of all staying guests and preferred arrival schedule.</p>
                                </div>
                            </div>
                        </div>

                        <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 1.1rem; margin-bottom: 1.4rem;">
                            <div style="font-size: 0.78rem; font-weight: 800; color: var(--pb-purple-primary); text-transform: uppercase; margin-bottom: 6px;">
                                <i class="fa-solid fa-address-card"></i> Registered Account Profile
                            </div>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; font-size: 0.88rem;">
                                <div><strong>Lead Guest:</strong> <?= htmlspecialchars($guest_name) ?></div>
                                <div><strong>Email:</strong> <?= htmlspecialchars($guest_email) ?></div>
                                <div><strong>Contact:</strong> <?= htmlspecialchars($guest_phone) ?></div>
                            </div>
                        </div>

                        <div style="margin-bottom: 1.35rem;">
                            <label class="form-label-bold" for="inputGuestList">
                                <i class="fa-solid fa-users" style="color: var(--pb-purple-primary);"></i> Names of Guests (Guest List) *
                            </label>
                            <textarea name="guest_list" id="inputGuestList" class="form-control-custom" rows="3" placeholder="Please enter the full names of all staying guests (e.g. John Doe, Jane Doe)..." required></textarea>
                            <small style="color: var(--pb-text-muted); font-size: 0.78rem; display: block; margin-top: 4px;">Required by resort security and guest manifest regulations.</small>
                        </div>

                        <div class="time-slots-grid" style="margin-bottom: 1.35rem;">
                            <div>
                                <label class="form-label-bold" for="selectCheckInTime">
                                    <i class="fa-solid fa-clock" style="color: var(--pb-purple-primary);"></i> Preferred Check-In Time
                                </label>
                                <select id="selectCheckInTime" class="form-control-custom" onchange="syncTimes()">
                                    <option value="14:00" selected>2:00 PM (Standard Check-In)</option>
                                    <option value="15:00">3:00 PM</option>
                                    <option value="16:00">4:00 PM</option>
                                    <option value="17:00">5:00 PM</option>
                                    <option value="18:00">6:00 PM</option>
                                    <option value="19:00">7:00 PM</option>
                                    <option value="20:00">8:00 PM</option>
                                </select>
                            </div>
                            <div>
                                <label class="form-label-bold" for="selectCheckOutTime">
                                    <i class="fa-regular fa-clock" style="color: #f59e0b;"></i> Preferred Check-Out Time
                                </label>
                                <select id="selectCheckOutTime" class="form-control-custom" onchange="syncTimes()">
                                    <option value="12:00" selected>12:00 PM (Standard Check-Out)</option>
                                    <option value="11:00">11:00 AM</option>
                                    <option value="10:00">10:00 AM</option>
                                    <option value="09:00">9:00 AM</option>
                                    <option value="08:00">8:00 AM</option>
                                </select>
                            </div>
                        </div>

                        <div style="margin-bottom: 1.35rem;">
                            <label class="form-label-bold" for="inputNotes">
                                <i class="fa-regular fa-note-sticky" style="color: var(--pb-purple-primary);"></i> Special Requests (Optional)
                            </label>
                            <textarea name="notes" id="inputNotes" class="form-control-custom" rows="2" placeholder="e.g. Quiet room requested, anniversary celebration, extra towels..."></textarea>
                        </div>

                        <div class="step-nav-buttons">
                            <button type="button" class="btn-step-back" onclick="goToStep(1)">
                                <i class="fa-solid fa-arrow-left"></i> Back to Selection
                            </button>
                            <button type="button" class="btn-update-dates" onclick="goToStep(5)" style="padding: 0.75rem 1.6rem;">
                                Continue to Review &amp; Payment <i class="fa-solid fa-arrow-right"></i>
                            </button>
                        </div>
                    </section>

                    <!-- STEP 5: REVIEW & PAYMENT CARD -->
                    <section class="booking-card" id="stepReviewPaymentCard">
                        <div class="card-header-flex">
                            <div class="card-header-left">
                                <div class="card-icon-box">
                                    <i class="fa-solid fa-receipt"></i>
                                </div>
                                <div class="card-title-group">
                                    <h2>Review &amp; Confirm Reservation</h2>
                                    <p>Please double check your booking summary before proceeding to payment.</p>
                                </div>
                            </div>
                        </div>

                        <table class="review-table">
                            <tbody>
                                <tr>
                                    <th>Selected Kubo</th>
                                    <td id="reviewKuboName">Beach Kubo</td>
                                </tr>
                                <tr>
                                    <th>Dates of Stay</th>
                                    <td id="reviewDates">Oct 10, 2026 – Oct 11, 2026</td>
                                </tr>
                                <tr>
                                    <th>Duration</th>
                                    <td id="reviewNights">1 Night(s)</td>
                                </tr>
                                <tr>
                                    <th>Arrival / Departure</th>
                                    <td id="reviewTimes">Check-in: 2:00 PM | Check-out: 12:00 PM</td>
                                </tr>
                                <tr>
                                    <th>Guest Manifest</th>
                                    <td id="reviewGuestList">Registered Guest</td>
                                </tr>
                                <tr>
                                    <th>Add-on Activities</th>
                                    <td id="reviewActivities">None</td>
                                </tr>
                                <tr style="border-top: 2px solid #e2e8f0;">
                                    <th style="font-size: 1rem; color: var(--pb-purple-deep);">Total Payable</th>
                                    <td style="font-size: 1.35rem; color: var(--pb-purple-primary); font-family: 'Outfit', sans-serif;" id="reviewTotalAmount">₱3,500</td>
                                </tr>
                            </tbody>
                        </table>

                        <div style="background: #faf5ff; border: 1.5px solid #e9d5ff; border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: flex-start; gap: 10px;">
                            <i class="fa-solid fa-shield-halved" style="color: var(--pb-purple-primary); font-size: 1.25rem; margin-top: 2px;"></i>
                            <div style="font-size: 0.84rem; color: #475569; line-height: 1.5;">
                                <strong>GCash Official Payment:</strong> You will be directed to submit your GCash mobile payment or QR confirmation immediately after submitting this reservation to confirm your dates.
                            </div>
                        </div>

                        <div class="step-nav-buttons">
                            <button type="button" class="btn-step-back" onclick="goToStep(4)">
                                <i class="fa-solid fa-arrow-left"></i> Back to Guest Details
                            </button>
                            <button type="submit" class="btn-update-dates" style="background: linear-gradient(135deg, #7c3aed, #9333ea); padding: 0.85rem 1.75rem; font-size: 0.95rem;">
                                <i class="fa-solid fa-lock"></i> Confirm Reservation &amp; Pay via GCash
                            </button>
                        </div>
                    </section>

                </div>

                <!-- Right Column: Sticky Booking Summary Sidebar -->
                <aside class="booking-sidebar-col">
                    <div class="summary-sidebar-card">
                        
                        <div class="summary-header-row">
                            <i class="fa-regular fa-calendar-check summary-header-icon"></i>
                            <h3 class="summary-header-title">Booking Summary</h3>
                        </div>

                        <!-- Dates Box -->
                        <div class="summary-dates-row">
                            <div class="summary-date-col">
                                <span class="summary-date-sub">Check-in</span>
                                <span class="summary-date-text" id="sideCheckInText"><?= date('M d, Y', strtotime($check_in)) ?></span>
                            </div>
                            <div class="summary-date-sep"><i class="fa-solid fa-arrow-right"></i></div>
                            <div class="summary-date-col" style="text-align: right;">
                                <span class="summary-date-sub">Check-out</span>
                                <span class="summary-date-text" id="sideCheckOutText"><?= date('M d, Y', strtotime($check_out)) ?></span>
                            </div>
                        </div>

                        <!-- Selected Kubo Section -->
                        <div class="summary-section-block">
                            <div class="summary-section-title-row">
                                <span class="summary-section-title">Selected Kubo</span>
                                <button type="button" class="summary-edit-btn" onclick="scrollToSection('sectionChooseKubo')" title="Change Kubo">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                            </div>
                            <div class="summary-kubo-box">
                                <img src="<?= htmlspecialchars($kubo['image'] ?? 'assets/beach_kubo.jpg') ?>" alt="Selected Kubo" class="summary-kubo-thumb" id="sideKuboThumb" onerror="this.onerror=null; this.src='assets/beach_kubo.jpg';">
                                <div class="summary-kubo-info">
                                    <span class="summary-kubo-name" id="sideKuboName"><?= htmlspecialchars($kubo['name'] ?? 'Beach Kubo') ?></span>
                                    <span class="summary-kubo-rate" id="sideKuboRate">₱<?= number_format($kubo['price'] ?? 3500) ?> / night</span>
                                    <span class="summary-kubo-units">1 unit</span>
                                </div>
                            </div>
                        </div>

                        <!-- Activities Section -->
                        <div class="summary-section-block">
                            <div class="summary-section-title-row">
                                <span class="summary-section-title">Activities</span>
                                <button type="button" class="summary-edit-btn" onclick="scrollToSection('sectionActivities')" title="Edit Activities">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                            </div>
                            <div id="sideActivitiesContainer">
                                <span class="summary-no-activities" id="sideNoActivities">No activities selected</span>
                                <div class="summary-activities-list" id="sideActivitiesList" style="display: none;"></div>
                            </div>
                        </div>

                        <!-- Estimated Total Section -->
                        <div class="summary-total-block">
                            <div class="summary-total-label">Estimated Total</div>
                            <div class="summary-total-val-row">
                                <span class="summary-total-amount" id="sideTotalAmount">₱<?= number_format(($nights * floatval($kubo['price'] ?? 3500))) ?></span>
                                <span class="summary-total-nights" id="sideTotalNights">(<?= $nights ?> <?= $nights == 1 ? 'night' : 'nights' ?>)</span>
                            </div>
                        </div>

                        <!-- Continue Button -->
                        <button type="button" class="btn-continue-checkout" id="btnContinueFlow" onclick="handleContinueClick()">
                            <span>Continue to Guest Details</span>
                            <i class="fa-solid fa-arrow-right"></i>
                        </button>

                    </div>
                </aside>

            </div>
        </form>

    </main>

    <?php include 'footer.php'; ?>

    <script>
        const todayStr = '<?= $today_str ?>';

        // State Object
        const bookingState = {
            currentStep: 1,
            kubos: <?= json_encode($kubos_list) ?>,
            activities: <?= json_encode($display_activities) ?>,
            selectedKuboId: <?= $selected_kubo_id ?>,
            checkIn: '<?= $check_in ?>',
            checkOut: '<?= $check_out ?>',
            checkInTime: '14:00',
            checkOutTime: '12:00',
            nights: <?= $nights ?>,
            selectedActivities: []
        };

        function formatDateString(isoStr) {
            if (!isoStr) return '';
            const d = new Date(isoStr + 'T00:00:00');
            const options = { month: 'short', day: 'numeric', year: 'numeric' };
            return d.toLocaleDateString('en-US', options);
        }

        function calculateNights(d1Str, d2Str) {
            const d1 = new Date(d1Str + 'T00:00:00');
            const d2 = new Date(d2Str + 'T00:00:00');
            const diffTime = d2.getTime() - d1.getTime();
            const days = Math.round(diffTime / (1000 * 60 * 60 * 24));
            return Math.max(1, days);
        }

        // Live input validation to immediately reject typing past dates
        function onDateInputCheckIn(input) {
            if (input.value && input.value < todayStr) {
                input.value = todayStr;
            }
        }

        function onDateInputCheckOut(input) {
            const inVal = document.getElementById('datePickerCheckIn').value || todayStr;
            if (input.value && input.value <= inVal) {
                const d = new Date(inVal + 'T00:00:00');
                d.setDate(d.getDate() + 1);
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                input.value = `${y}-${m}-${day}`;
            }
        }

        // Prevent picking fully booked rooms
        function handleKuboClick(kid) {
            const k = bookingState.kubos.find(item => item.kubo_id === kid);
            if (!k) return;

            const isBooked = !k.is_available || k.units_left <= 0 || k.is_fully_booked;
            if (isBooked) {
                const reason = k.is_renovation ? 'under renovation' : 'fully booked';
                alert(`⚠️ ${k.name} is ${reason} for your selected dates (${formatDateString(bookingState.checkIn)} – ${formatDateString(bookingState.checkOut)}).\n\nPlease choose another Kubo or pick different stay dates.`);
                return;
            }

            selectKubo(kid);
        }

        // Kubo Selection Handler
        function selectKubo(kid) {
            const k = bookingState.kubos.find(item => item.kubo_id === kid);
            if (!k || !k.is_available || k.units_left <= 0 || k.is_fully_booked) {
                return;
            }

            bookingState.selectedKuboId = kid;
            document.getElementById('formKuboId').value = kid;

            refreshKuboCardsSelection();
            updateSummaryState();
        }

        function refreshKuboCardsSelection() {
            bookingState.kubos.forEach(k => {
                const card = document.getElementById('kuboCardItem_' + k.kubo_id);
                const btn = document.getElementById('btnSelectKubo_' + k.kubo_id);
                const badge = document.getElementById('kuboBookedBadge_' + k.kubo_id);
                const unitsChip = document.getElementById('kuboUnitsChip_' + k.kubo_id);
                const isBooked = !k.is_available || k.units_left <= 0 || k.is_fully_booked;

                if (card && btn) {
                    if (isBooked) {
                        card.classList.add('is-fully-booked');
                        card.classList.remove('is-selected');
                        btn.disabled = true;
                        btn.innerHTML = '<i class="fa-solid fa-ban"></i> Fully Booked';
                        if (badge) {
                            badge.style.display = 'flex';
                            badge.innerHTML = `<i class="fa-solid fa-ban"></i> ${k.is_renovation ? 'UNDER RENOVATION' : 'FULLY BOOKED'}`;
                        }
                        if (unitsChip) {
                            unitsChip.classList.add('is-booked');
                            unitsChip.innerHTML = `<i class="fa-solid fa-ban" style="color: #dc2626;"></i> 0 of ${k.available_units} Left (Booked)`;
                        }
                    } else if (k.kubo_id === bookingState.selectedKuboId) {
                        card.classList.remove('is-fully-booked');
                        card.classList.add('is-selected');
                        btn.disabled = false;
                        btn.textContent = 'Selected ✓';
                        if (badge) badge.style.display = 'none';
                        if (unitsChip) {
                            unitsChip.classList.remove('is-booked');
                            unitsChip.innerHTML = `<i class="fa-solid fa-hotel"></i> ${k.units_left} of ${k.available_units} Units left`;
                        }
                    } else {
                        card.classList.remove('is-fully-booked');
                        card.classList.remove('is-selected');
                        btn.disabled = false;
                        btn.textContent = 'Select Kubo';
                        if (badge) badge.style.display = 'none';
                        if (unitsChip) {
                            unitsChip.classList.remove('is-booked');
                            unitsChip.innerHTML = `<i class="fa-solid fa-hotel"></i> ${k.units_left} of ${k.available_units} Units left`;
                        }
                    }
                }
            });
        }

        // Toggle Activity Handler
        function toggleActivity(aid, e) {
            const chk = document.getElementById('chkAct_' + aid);
            const card = document.getElementById('actCard_' + aid);
            if (!chk) return;

            if (e && e.target && e.target.tagName.toLowerCase() === 'input') {
                // already toggled
            } else {
                chk.checked = !chk.checked;
            }

            if (chk.checked) {
                card.classList.add('is-checked');
                if (!bookingState.selectedActivities.includes(aid)) {
                    bookingState.selectedActivities.push(aid);
                }
            } else {
                card.classList.remove('is-checked');
                bookingState.selectedActivities = bookingState.selectedActivities.filter(id => id !== aid);
            }

            updateSummaryState();
        }

        // Date Changed Handler - Enforces no past dates and triggers availability check
        function onDateChanged() {
            const inInput = document.getElementById('datePickerCheckIn');
            const outInput = document.getElementById('datePickerCheckOut');
            let cin = inInput.value;
            let cout = outInput.value;

            // Strict prevention of past date
            if (!cin || cin < todayStr) {
                alert(`⚠️ Check-In date cannot be in the past.\nAutomatically reset to today (${formatDateString(todayStr)}).`);
                cin = todayStr;
                inInput.value = todayStr;
            }

            // Calculate min checkout date (minimum 1 night)
            const cinDate = new Date(cin + 'T00:00:00');
            cinDate.setDate(cinDate.getDate() + 1);
            const y = cinDate.getFullYear();
            const m = String(cinDate.getMonth() + 1).padStart(2, '0');
            const d = String(cinDate.getDate()).padStart(2, '0');
            const minCheckout = `${y}-${m}-${d}`;
            outInput.min = minCheckout;

            if (!cout || cout <= cin) {
                cout = minCheckout;
                outInput.value = cout;
            }

            bookingState.checkIn = cin;
            bookingState.checkOut = cout;
            bookingState.nights = calculateNights(cin, cout);

            document.getElementById('formCheckIn').value = cin;
            document.getElementById('formCheckOut').value = cout;

            document.getElementById('textCheckInDisplay').textContent = formatDateString(cin);
            document.getElementById('textCheckOutDisplay').textContent = formatDateString(cout);

            // Fetch live availability for all rooms for updated dates
            fetchRoomAvailability(cin, cout);
        }

        function onUpdateDatesClicked() {
            onDateChanged();
        }

        // Live check room availability via AJAX
        let isFetchingAvailability = false;
        function fetchRoomAvailability(cin, cout) {
            if (isFetchingAvailability) return;
            isFetchingAvailability = true;

            const updateBtn = document.querySelector('.btn-update-dates');
            if (updateBtn) {
                updateBtn.classList.add('loading');
                updateBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Checking...';
            }

            fetch(`api_check_room_availability.php?check_in=${encodeURIComponent(cin)}&check_out=${encodeURIComponent(cout)}`)
                .then(res => res.json())
                .then(data => {
                    if (data && data.rooms) {
                        let selectedStillAvail = false;
                        let firstAvailId = 0;
                        let allBooked = true;

                        data.rooms.forEach(r => {
                            const kState = bookingState.kubos.find(k => k.kubo_id === r.kubo_id);
                            if (kState) {
                                kState.is_available = r.is_available;
                                kState.units_left = r.units_left;
                                kState.is_fully_booked = !r.is_available || r.units_left <= 0;
                                kState.status = r.status;
                                kState.is_renovation = r.is_renovation;
                            }

                            if (r.is_available && r.units_left > 0) {
                                allBooked = false;
                                if (!firstAvailId) firstAvailId = r.kubo_id;
                                if (r.kubo_id === bookingState.selectedKuboId) {
                                    selectedStillAvail = true;
                                }
                            }
                        });

                        // If currently selected room became fully booked, auto-switch to first available
                        if (!selectedStillAvail) {
                            const prevK = bookingState.kubos.find(k => k.kubo_id === bookingState.selectedKuboId);
                            const prevName = prevK ? prevK.name : 'Your selected Kubo';

                            if (firstAvailId > 0) {
                                bookingState.selectedKuboId = firstAvailId;
                                document.getElementById('formKuboId').value = firstAvailId;
                                const newK = bookingState.kubos.find(k => k.kubo_id === firstAvailId);
                                alert(`⚠️ Notice: ${prevName} is fully booked for ${formatDateString(cin)} – ${formatDateString(cout)}.\nWe have switched your selection to the available ${newK ? newK.name : 'available Kubo'}.`);
                            } else {
                                bookingState.selectedKuboId = 0;
                                document.getElementById('formKuboId').value = 0;
                            }
                        }

                        // Refresh DOM
                        refreshKuboCardsSelection();

                        // All rooms notice
                        const notice = document.getElementById('allRoomsBookedNotice');
                        const badgeAvail = document.getElementById('badgeDatesAvailable');
                        const noticeDates = document.getElementById('textBookedNoticeDates');
                        if (noticeDates) noticeDates.textContent = `${formatDateString(cin)} – ${formatDateString(cout)}`;

                        if (allBooked) {
                            if (notice) notice.style.display = 'flex';
                            if (badgeAvail) badgeAvail.style.display = 'none';
                        } else {
                            if (notice) notice.style.display = 'none';
                            if (badgeAvail) badgeAvail.style.display = 'inline-flex';
                        }

                        updateSummaryState();
                    }
                })
                .catch(err => {
                    console.error('Error fetching room availability:', err);
                })
                .finally(() => {
                    isFetchingAvailability = false;
                    if (updateBtn) {
                        updateBtn.classList.remove('loading');
                        updateBtn.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i> Update Dates';
                    }
                });
        }

        // Synchronize Check-In/Out Times
        function syncTimes() {
            const inTimeEl = document.getElementById('selectCheckInTime');
            const outTimeEl = document.getElementById('selectCheckOutTime');
            if (inTimeEl && outTimeEl) {
                const inTime = inTimeEl.value;
                const outTime = outTimeEl.value;
                bookingState.checkInTime = inTime;
                bookingState.checkOutTime = outTime;
                document.getElementById('formCheckInTime').value = inTime;
                document.getElementById('formCheckOutTime').value = outTime;
            }
        }

        // Update Summary Sidebar & Total
        function updateSummaryState() {
            const k = bookingState.kubos.find(item => item.kubo_id === bookingState.selectedKuboId);
            const nights = bookingState.nights;
            const btnContinue = document.getElementById('btnContinueFlow');

            // Dates
            document.getElementById('sideCheckInText').textContent = formatDateString(bookingState.checkIn);
            document.getElementById('sideCheckOutText').textContent = formatDateString(bookingState.checkOut);

            const isKuboValid = (k && k.is_available && k.units_left > 0 && !k.is_fully_booked);

            // Kubo Info
            if (k) {
                document.getElementById('sideKuboThumb').src = k.image;
                document.getElementById('sideKuboName').textContent = k.name + (isKuboValid ? '' : ' (Fully Booked)');
                document.getElementById('sideKuboRate').textContent = '₱' + Number(k.price).toLocaleString() + ' / night';
            } else {
                document.getElementById('sideKuboName').textContent = 'No Kubo Selected';
                document.getElementById('sideKuboRate').textContent = 'Please choose a Kubo';
            }

            // Disable Continue button if no valid room is selected or if room is fully booked
            if (btnContinue && bookingState.currentStep <= 3) {
                if (!isKuboValid) {
                    btnContinue.disabled = true;
                    btnContinue.style.opacity = '0.6';
                    btnContinue.style.cursor = 'not-allowed';
                    btnContinue.innerHTML = '<span><i class="fa-solid fa-ban"></i> Select an Available Kubo</span>';
                } else {
                    btnContinue.disabled = false;
                    btnContinue.style.opacity = '1';
                    btnContinue.style.cursor = 'pointer';
                    btnContinue.innerHTML = '<span>Continue to Guest Details</span> <i class="fa-solid fa-arrow-right"></i>';
                }
            }

            // Activities Calculation
            let activitiesTotal = 0;
            const actList = document.getElementById('sideActivitiesList');
            const noAct = document.getElementById('sideNoActivities');
            actList.innerHTML = '';

            if (bookingState.selectedActivities.length > 0) {
                noAct.style.display = 'none';
                actList.style.display = 'flex';

                bookingState.selectedActivities.forEach(aid => {
                    const act = bookingState.activities.find(a => a.activity_id === aid);
                    if (act) {
                        const price = Number(act.price) || 0;
                        activitiesTotal += price;

                        const row = document.createElement('div');
                        row.className = 'summary-act-item';
                        row.innerHTML = `<span>${escapeHtml(act.activity_name)}</span><strong>₱${price.toLocaleString()}</strong>`;
                        actList.appendChild(row);
                    }
                });
            } else {
                noAct.style.display = 'block';
                actList.style.display = 'none';
            }

            // Total Amount
            const kuboTotal = (isKuboValid ? Number(k.price) : 0) * nights;
            const grandTotal = kuboTotal + activitiesTotal;

            document.getElementById('sideTotalAmount').textContent = '₱' + grandTotal.toLocaleString();
            document.getElementById('sideTotalNights').textContent = `(${nights} ${nights === 1 ? 'night' : 'nights'})`;
        }

        function scrollToSection(sectionId) {
            goToStep(1);
            const el = document.getElementById(sectionId);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        // Multi-Step Navigation
        function goToStep(step) {
            // Validate dates
            if (bookingState.checkIn < todayStr) {
                alert('⚠️ Check-In date cannot be in the past. Please select today or a future date.');
                onDateChanged();
                return;
            }
            if (bookingState.checkOut <= bookingState.checkIn) {
                alert('⚠️ Check-Out date must be after your Check-In date.');
                onDateChanged();
                return;
            }

            if (step === 4 || step === 5) {
                // Must have an available kubo
                if (!bookingState.selectedKuboId || bookingState.selectedKuboId <= 0) {
                    alert('⚠️ Please select an available Kubo before proceeding.');
                    goToStep(2);
                    return;
                }

                const selK = bookingState.kubos.find(k => k.kubo_id === bookingState.selectedKuboId);
                if (!selK || !selK.is_available || selK.units_left <= 0 || selK.is_fully_booked) {
                    alert(`⚠️ ${selK ? selK.name : 'The selected room'} is fully booked for your dates. Please choose an available Kubo.`);
                    goToStep(2);
                    return;
                }
            }

            if (step === 5) {
                const guestList = document.getElementById('inputGuestList').value.trim();
                if (!guestList) {
                    alert('Please enter the names of all guests in the Guest List field before reviewing.');
                    goToStep(4);
                    document.getElementById('inputGuestList').focus();
                    return;
                }
                populateReview();
            }

            bookingState.currentStep = step;

            const selectionView = document.getElementById('stepSelectionView');
            const guestDetailsCard = document.getElementById('stepGuestDetailsCard');
            const reviewPaymentCard = document.getElementById('stepReviewPaymentCard');
            const btnContinue = document.getElementById('btnContinueFlow');

            // Hide all
            selectionView.style.display = 'none';
            guestDetailsCard.style.display = 'none';
            reviewPaymentCard.style.display = 'none';

            if (step <= 3) {
                selectionView.style.display = 'block';
                updateSummaryState();
                if (step === 2) {
                    document.getElementById('sectionChooseKubo').scrollIntoView({ behavior: 'smooth' });
                } else if (step === 3) {
                    document.getElementById('sectionActivities').scrollIntoView({ behavior: 'smooth' });
                }
            } else if (step === 4) {
                guestDetailsCard.style.display = 'block';
                btnContinue.disabled = false;
                btnContinue.style.opacity = '1';
                btnContinue.style.cursor = 'pointer';
                btnContinue.innerHTML = '<span>Continue to Review</span> <i class="fa-solid fa-arrow-right"></i>';
                btnContinue.style.display = 'flex';
                guestDetailsCard.scrollIntoView({ behavior: 'smooth' });
            } else if (step === 5) {
                reviewPaymentCard.style.display = 'block';
                btnContinue.style.display = 'none';
                reviewPaymentCard.scrollIntoView({ behavior: 'smooth' });
            }

            // Update Stepper
            for (let i = 1; i <= 5; i++) {
                const el = document.getElementById('stepperStep' + i);
                if (el) {
                    el.classList.remove('active', 'completed');
                    if (i < step) {
                        el.classList.add('completed');
                    } else if (i === step || (step <= 3 && i === 1)) {
                        el.classList.add('active');
                    }
                }
            }
        }

        function handleContinueClick() {
            if (bookingState.currentStep <= 3) {
                goToStep(4);
            } else if (bookingState.currentStep === 4) {
                goToStep(5);
            }
        }

        function populateReview() {
            const k = bookingState.kubos.find(item => item.kubo_id === bookingState.selectedKuboId);
            document.getElementById('reviewKuboName').textContent = k ? k.name : 'Beach Kubo';
            document.getElementById('reviewDates').textContent = formatDateString(bookingState.checkIn) + ' – ' + formatDateString(bookingState.checkOut);
            document.getElementById('reviewNights').textContent = `${bookingState.nights} Night(s)`;
            
            const formatTime = (t) => {
                const parts = t.split(':');
                let h = parseInt(parts[0], 10);
                const ampm = h >= 12 ? 'PM' : 'AM';
                h = h % 12 || 12;
                return h + ':' + (parts[1] || '00') + ' ' + ampm;
            };

            document.getElementById('reviewTimes').textContent = `Check-in: ${formatTime(bookingState.checkInTime)} | Check-out: ${formatTime(bookingState.checkOutTime)}`;
            document.getElementById('reviewGuestList').textContent = document.getElementById('inputGuestList').value.trim();

            if (bookingState.selectedActivities.length > 0) {
                const names = bookingState.selectedActivities.map(aid => {
                    const act = bookingState.activities.find(a => a.activity_id === aid);
                    return act ? `${act.activity_name} (₱${Number(act.price).toLocaleString()})` : '';
                }).filter(Boolean);
                document.getElementById('reviewActivities').textContent = names.join(', ');
            } else {
                document.getElementById('reviewActivities').textContent = 'None';
            }

            document.getElementById('reviewTotalAmount').textContent = document.getElementById('sideTotalAmount').textContent;
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
            });
        }

        // Form submit safety checks
        const bookingForm = document.getElementById('mainBookingForm');
        if (bookingForm) {
            bookingForm.addEventListener('submit', function(e) {
                if (bookingState.checkIn < todayStr) {
                    e.preventDefault();
                    alert('⚠️ Check-In date cannot be in the past.');
                    return false;
                }
                if (bookingState.checkOut <= bookingState.checkIn) {
                    e.preventDefault();
                    alert('⚠️ Check-Out date must be after your Check-In date.');
                    return false;
                }
                const selK = bookingState.kubos.find(k => k.kubo_id === bookingState.selectedKuboId);
                if (!selK || !selK.is_available || selK.units_left <= 0 || selK.is_fully_booked) {
                    e.preventDefault();
                    alert('⚠️ The selected Kubo is fully booked for your stay dates. Please choose an available Kubo.');
                    return false;
                }
            });
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', () => {
            refreshKuboCardsSelection();
            updateSummaryState();
        });
    </script>
</body>
</html>