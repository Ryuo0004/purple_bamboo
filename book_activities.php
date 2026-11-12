<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/booking_store.php';
require_once __DIR__ . '/inventory_store.php';

$all_activities = InventoryStore::getAllActivities();

// Determine existing booking if editing/rescheduling
$existing_ref = trim($_GET['ref'] ?? ($_POST['existing_ref'] ?? ''));
if (empty($existing_ref) && !empty($_SESSION['user_id'])) {
    if (!empty($_SESSION['last_booking']['id']) && ($_SESSION['last_booking']['type'] ?? '') === 'activity') {
        $existing_ref = $_SESSION['last_booking']['id'];
    }
}
$existing_booking = !empty($existing_ref) ? BookingStore::getBookingById($existing_ref) : null;

// Determine initially selected activities (supports multiple IDs)
$selected_activity_ids = [];
if (!empty($_GET['activity_ids'])) {
    if (is_array($_GET['activity_ids'])) {
        $selected_activity_ids = array_map('intval', $_GET['activity_ids']);
    } else {
        $selected_activity_ids = array_map('intval', explode(',', (string)$_GET['activity_ids']));
    }
} elseif (isset($_GET['activity_id'])) {
    $selected_activity_ids = [intval($_GET['activity_id'])];
} elseif ($existing_booking && !empty($existing_booking['item_id'])) {
    $selected_activity_ids = [intval($existing_booking['item_id'])];
}

$selected_activity_ids = array_values(array_filter($selected_activity_ids, function($id) { return $id > 0; }));
if (empty($selected_activity_ids) && !empty($all_activities)) {
    $selected_activity_ids = [intval($all_activities[0]['activity_id'])];
}

// Strictly require registered account login to book activities
if (empty($_SESSION['user_id'])) {
    $first_act_id = !empty($selected_activity_ids[0]) ? $selected_activity_ids[0] : 1;
    $redirect_url = 'book_activities.php?activity_ids=' . implode(',', $selected_activity_ids);
    $login_url = 'login.php?msg=' . urlencode('Please sign in with your registered account before booking an activity. Please sign in or register first.') . '&redirect=' . urlencode($redirect_url);
    header("Location: " . $login_url);
    exit();
}

// Disallow blocked user from booking activities
require_once __DIR__ . '/auth.php';
if ($block_reason = Auth::isUserBlocked($_SESSION['user_id'])) {
    Auth::logout();
    header("Location: login.php?msg=" . urlencode("Your account has been deactivated/blocked by management: " . $block_reason));
    exit();
}

$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sched_date = trim($_POST['scheduled_date'] ?? '');
    $pax = intval($_POST['pax'] ?? 0);
    $slot = trim($_POST['time_slot'] ?? '');
    $today_str = date('Y-m-d');

    // Parse submitted multiple activity IDs
    $raw_act_ids = $_POST['activity_ids'] ?? [];
    if (!is_array($raw_act_ids)) {
        $raw_act_ids = explode(',', (string)$raw_act_ids);
    }
    if (empty($raw_act_ids) && !empty($_POST['activity_id'])) {
        $raw_act_ids = [$_POST['activity_id']];
    }
    $submitted_act_ids = array_values(array_filter(array_map('intval', $raw_act_ids), function($id) { return $id > 0; }));

    $chosen_activities = [];
    $combined_price_per_pax = 0;
    $act_titles = [];
    $needs_time_slot = false;

    foreach ($submitted_act_ids as $aid) {
        $act_obj = InventoryStore::getActivityById($aid);
        if ($act_obj && !empty($act_obj['is_available'])) {
            $chosen_activities[] = $act_obj;
            $combined_price_per_pax += floatval($act_obj['price'] ?? 0);
            $act_titles[] = $act_obj['activity_name'];
            if (($act_obj['activity_type'] ?? 'full_day') !== 'full_day') {
                $needs_time_slot = true;
            }
        }
    }

    $is_full_day = !$needs_time_slot;

    $slot_valid = (bool) preg_match('/^(0?[1-9]|1[0-2]):[0-5][0-9] (AM|PM)$/', $slot);
    // Resort operating hours: 8:00 AM - 10:00 PM
    $slot_hm = $slot_valid ? date('H:i', strtotime($slot)) : '09:00';
    $slot_in_hours = ($slot_hm >= '08:00' && $slot_hm <= '22:00');
    $date_valid = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $sched_date) && strtotime($sched_date) !== false;

    if ($is_full_day) {
        $slot_valid = true;
        $slot_in_hours = true;
    }

    if (empty($chosen_activities)) {
        $error_msg = "Please select at least one available resort activity experience.";
    } elseif (!$date_valid) {
        $error_msg = "Please select a valid scheduled date for your activity reservation.";
    } elseif ($sched_date < $today_str) {
        $error_msg = "Scheduled activity date cannot be in the past.";
    } elseif ($pax < 1) {
        $error_msg = "Please specify a valid number of participants.";
    } elseif (!$is_full_day && !$slot_valid) {
        $error_msg = "Please select a valid preferred time slot for your session.";
    } elseif (!$is_full_day && !$slot_in_hours) {
        $error_msg = "Please choose a time within resort hours (8:00 AM - 10:00 PM).";
    } elseif (!$is_full_day && strtotime($sched_date . ' ' . $slot) < time()) {
        $error_msg = "The selected time has already passed. Please choose a later time.";
    } else {
        $start_time = $slot_hm . ':00';
        
        // Verify availability per activity. Pool activities are only limited by pool capacity;
        // every other (equipment/capacity limited) activity has its own cap and its own participant count
        // whenever it is combined with the pool or with other activities.
        $rows = [];
        $n_pool = 0;
        $n_lim = 0;
        $pool_min = PHP_INT_MAX;
        foreach ($chosen_activities as $c_act) {
            $duration = intval($c_act['duration_minutes'] ?? 60);
            if ($duration <= 0) $duration = 60;
            $end_time = date('H:i:s', strtotime($start_time) + ($duration * 60));
            $avail = BookingStore::getActivityAvailability($c_act['activity_id'], $sched_date, $start_time, $end_time);
            $act_min = $avail;

            $is_pool_act = strtolower(trim($c_act['category'] ?? '')) === 'pool';
            if (!$is_pool_act) {
                $c_cap = intval($c_act['capacity'] ?? 10);
                $c_reserved = max(0, $c_cap - $avail);
                $c_eq = $c_act['equipment'] ?? [];
                if (is_string($c_eq)) {
                    $c_eq = json_decode($c_eq, true);
                }
                if (is_array($c_eq)) {
                    foreach ($c_eq as $c_item) {
                        $c_rem = max(0, intval($c_item['qty'] ?? $c_cap) - $c_reserved);
                        if ($c_rem < $act_min) {
                            $act_min = $c_rem;
                        }
                    }
                }
            }

            $rows[] = ['act' => $c_act, 'is_pool' => $is_pool_act, 'limit' => $act_min, 'price' => floatval($c_act['price'] ?? 0)];
            if ($is_pool_act) {
                $n_pool++;
                $pool_min = min($pool_min, $act_min);
            } else {
                $n_lim++;
            }
        }

        $is_split = ($n_pool > 0 && $n_lim > 0) || $n_lim >= 2;
        if ($is_split) {
            $lim_limits = array_map(function($r) { return $r['limit']; }, array_filter($rows, function($r) { return !$r['is_pool']; }));
            $main_limit = $n_pool > 0 ? $pool_min : max($lim_limits);
        } else {
            $main_limit = min(array_map(function($r) { return $r['limit']; }, $rows));
        }

        // Resolve each activity's participant count
        $per_act_pax = [];
        $act_pax_post = $_POST['activity_pax'] ?? [];
        $split_error = '';
        if ($is_split) {
            foreach ($rows as $r) {
                $rid = intval($r['act']['activity_id']);
                if ($r['is_pool']) {
                    $per_act_pax[$rid] = $pax;
                    continue;
                }
                $req = max(1, intval($act_pax_post[$rid] ?? $pax));
                $cap_here = min($r['limit'], $pax);
                if ($req > $cap_here) {
                    $split_error = "Only {$cap_here} participant(s) can join " . $r['act']['activity_name'] . " (equipment/slots remaining: {$r['limit']}).";
                    break;
                }
                $per_act_pax[$rid] = $req;
            }
        }

        if ($pax > $main_limit) {
            $error_msg = "Sorry, availability has changed. Only {$main_limit} slot(s) remaining for your selected activities.";
        } elseif ($split_error !== '') {
            $error_msg = $split_error;
        } else {
            $ref_no = 'ACT-' . date('Y') . '-' . random_int(1000, 9999);
            $primary_act_id = $chosen_activities[0]['activity_id'];
            $joined_names = implode(', ', $act_titles);
            $note_parts = [];
            if ($is_split) {
                $total_amount = 0;
                $guest_total = $pax;
                $primary_pax = $pax;
                $primary_set = false;
                foreach ($rows as $r) {
                    $rid = intval($r['act']['activity_id']);
                    $cnt = $per_act_pax[$rid];
                    $total_amount += $r['price'] * $cnt;
                    $note_parts[] = $r['act']['activity_name'] . " ({$cnt} pax @ ₱" . number_format($r['price'], 2) . ")";
                    // Reserve equipment against the first limited activity using its own participant count
                    if (!$r['is_pool'] && !$primary_set) {
                        $primary_act_id = $rid;
                        $primary_pax = $cnt;
                        $primary_set = true;
                    }
                }
                $pax = $primary_pax;
            } else {
                $total_amount = $combined_price_per_pax * $pax;
            }

            $user_id = $_SESSION['user_id'];
            $profile_id = $_SESSION['profile_id'] ?? sprintf("PBR-GST-%05d", $user_id);
            $guest_name = !empty($_SESSION['full_name']) ? $_SESSION['full_name'] : 'Registered Guest';
            $guest_email = !empty($_SESSION['email']) ? $_SESSION['email'] : 'guest@example.com';
            $guest_phone = !empty($_SESSION['phone']) ? $_SESSION['phone'] : '09170000000';

            $special_notes = "Reserved Activities (" . count($chosen_activities) . "): " . $joined_names . " | Combined rate: ₱" . number_format($combined_price_per_pax, 2) . " / pax x {$pax} pax";
            if ($is_split) {
                $special_notes = "Reserved Activities (" . count($chosen_activities) . "): " . implode(' + ', $note_parts)
                    . " | Total guests: {$guest_total} | Total: ₱" . number_format($total_amount, 2);
            }

            if (!empty($existing_ref)) {
                $ref_no = $existing_ref;
                global $pdo;
                if ($pdo) {
                    try {
                        $stmt_up = $pdo->prepare("UPDATE bookings SET item_id = :item_id, item_name = :item_name, check_in = :check_in, check_out = :check_out, time_slot = :time_slot, start_time = :start_time, end_time = :end_time, pax = :pax, num_guests = :num_guests, total_amount = :total_amount, special_notes = :special_notes WHERE id = :id AND (user_id = :user_id OR :is_admin = 1)");
                        $stmt_up->execute([
                            ':item_id' => $primary_act_id,
                            ':item_name' => $joined_names,
                            ':check_in' => $sched_date,
                            ':check_out' => $sched_date,
                            ':time_slot' => $is_full_day ? 'Full Day' : $slot,
                            ':start_time' => $is_full_day ? null : $start_time,
                            ':end_time' => $is_full_day ? null : (isset($end_time) ? $end_time : null),
                            ':pax' => $pax,
                            ':num_guests' => $pax,
                            ':total_amount' => $total_amount,
                            ':special_notes' => $special_notes,
                            ':id' => $ref_no,
                            ':user_id' => $user_id,
                            ':is_admin' => ($user_role === 'admin' || $user_role === 'staff') ? 1 : 0
                        ]);
                    } catch (Throwable $t) {}
                }
            } else {
                BookingStore::createBooking([
                    'id' => $ref_no,
                    'guest_name' => $guest_name,
                    'guest_email' => $guest_email,
                    'guest_phone' => $guest_phone,
                    'type' => 'activity',
                    'item_id' => $primary_act_id,
                    'item_name' => $joined_names,
                    'check_in' => $sched_date,
                    'check_out' => $sched_date,
                    'time_slot' => $is_full_day ? 'Full Day' : $slot,
                    'start_time' => $is_full_day ? null : $start_time,
                    'end_time' => $is_full_day ? null : (isset($end_time) ? $end_time : null),
                    'pax' => $pax,
                    'total_amount' => $total_amount,
                    'profile_id' => $profile_id,
                    'user_id' => $user_id,
                    'payment_status' => 'pending',
                    'booking_status' => 'pending',
                    'special_notes' => $special_notes
                ]);
            }

            header("Location: payment_method.php?ref=" . $ref_no);
            exit();
        }
    }
}

$user_role = $_SESSION['role'] ?? 'guest';
$is_admin_layout = ($user_role === 'admin' || $user_role === 'staff');

$initial_date = !empty($existing_booking['check_in']) ? $existing_booking['check_in'] : date('Y-m-d');
if ($initial_date < date('Y-m-d')) {
    $initial_date = date('Y-m-d');
}

$initial_time_slot = !empty($existing_booking['time_slot']) ? $existing_booking['time_slot'] : '09:00 AM';
$initial_pax = !empty($existing_booking['pax']) ? intval($existing_booking['pax']) : 1;
if ($initial_pax < 1) $initial_pax = 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reserve Activity Sessions | Purple Bamboo Resort</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php if ($is_admin_layout): ?>
        <link rel="stylesheet" href="admin-style.css?v=<?= time() ?>">
    <?php else: ?>
        <link rel="stylesheet" href="style.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --act-purple: #7c3aed;
            --act-purple-dark: #1e1b4b;
            --act-purple-light: #faf5ff;
            --act-gold: #d97706;
            --act-gold-bg: #fffbeb;
            --act-emerald: #059669;
            --act-border: #e2e8f0;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: #1e293b;
        }

        /* Page Container */
        .booking-page-container {
            max-width: 1240px;
            width: 100%;
            margin: 0 auto;
            padding: 2.25rem 1.5rem 5rem 1.5rem;
            box-sizing: border-box;
        }

        /* Reschedule Notice Banner */
        .reschedule-alert-banner {
            background: linear-gradient(135deg, #eff6ff 0%, #e0e7ff 100%);
            border: 1.5px solid #a5b4fc;
            border-radius: 14px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.75rem;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #3730a3;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.08);
        }
        .reschedule-alert-banner i {
            font-size: 1.4rem;
            color: #4f46e5;
            flex-shrink: 0;
        }

        /* Flow Step Tracker */
        .booking-step-tracker,
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1.25rem;
            margin: 0.5rem auto 2.25rem auto;
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
        .step-item.done {
            color: #059669;
        }
        .step-item.active {
            color: #7c3aed;
            font-weight: 800;
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
            background: #e2e8f0;
            color: #475569;
            font-weight: 800;
            flex-shrink: 0;
            transition: all 0.25s ease;
        }
        .step-item.done .step-num {
            background: #059669;
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }
        .step-item.active .step-num {
            background: #7c3aed;
            color: #ffffff;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.18), 0 3px 10px rgba(124, 58, 237, 0.35);
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

        /* Tropical Page Header */
        .booking-page-header {
            margin-bottom: 2rem;
        }
        .booking-page-header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: clamp(1.65rem, 3.5vw, 2.35rem);
            font-weight: 800;
            color: #1e1b4b;
            line-height: 1.25;
            margin-bottom: 0.4rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .booking-page-header h1 i {
            color: #d97706;
            filter: drop-shadow(0 2px 8px rgba(217, 119, 6, 0.25));
        }
        .booking-page-header p {
            color: #64748b;
            font-size: clamp(0.92rem, 1.8vw, 1.05rem);
            margin: 0;
        }

        /* 2-Column Responsive Grid */
        .booking-layout-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(320px, 420px);
            gap: 2rem;
            align-items: start;
            width: 100%;
        }
        @media (max-width: 991px) {
            .booking-layout-grid {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
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
        @media (max-width: 640px) {
            .booking-form-card {
                padding: 1.35rem;
            }
        }

        /* Form Section Title */
        .form-section-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.15rem;
            font-weight: 800;
            color: #1e1b4b;
            margin-bottom: 1.15rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .form-section-title i {
            color: #7c3aed;
        }

        .selected-count-badge {
            font-size: 0.8rem;
            font-weight: 800;
            background: #ede9fe;
            color: #7c3aed;
            border: 1px solid #ddd6fe;
            padding: 3px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /* Activity Category Quick Filter Tabs */
        .act-filter-tabs {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .act-filter-tab {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            color: #475569;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .act-filter-tab:hover {
            border-color: #7c3aed;
            color: #7c3aed;
            background: #faf5ff;
        }
        .act-filter-tab.active {
            background: #7c3aed;
            color: #ffffff;
            border-color: #7c3aed;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.25);
        }

        /* Activity Selection Cards Grid (Multiple Checkboxes) */
        .activity-selection-grid {
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
            margin-bottom: 2.25rem;
        }
        .act-choice-card {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            padding: 0.9rem 1.15rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            user-select: none;
        }
        .act-choice-card:hover {
            border-color: #c4b5fd;
            background: #faf5ff;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(124, 58, 237, 0.08);
        }
        .act-choice-card.selected {
            border: 2px solid #7c3aed;
            background: #fdfaff;
            box-shadow: 0 6px 20px rgba(124, 58, 237, 0.14);
        }
        .act-choice-card.disabled {
            opacity: 0.55;
            cursor: not-allowed;
            pointer-events: none;
            background: #f8fafc;
        }
        .act-checkbox-input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .act-thumb-wrap {
            width: 70px;
            height: 70px;
            border-radius: 12px;
            overflow: hidden;
            flex-shrink: 0;
            border: 1px solid #e2e8f0;
            background: #f1f5f9;
            position: relative;
        }
        .act-thumb-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .act-info-col {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .act-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .act-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.05rem;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .act-custom-check {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            border: 2px solid #cbd5e1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 0.72rem;
            flex-shrink: 0;
            transition: all 0.2s ease;
        }
        .act-choice-card.selected .act-custom-check {
            border-color: #7c3aed;
            background: #7c3aed;
            box-shadow: 0 2px 6px rgba(124, 58, 237, 0.35);
        }
        .act-desc-snippet {
            font-size: 0.82rem;
            color: #64748b;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin: 0;
        }
        .act-meta-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 2px;
        }
        .act-badge-tag {
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            background: #ede9fe;
            color: #6d28d9;
            padding: 2px 7px;
            border-radius: 6px;
        }
        .act-duration-tag {
            font-size: 0.74rem;
            font-weight: 700;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .act-price-tag {
            font-size: 0.92rem;
            font-weight: 800;
            color: #7c3aed;
            margin-left: auto;
        }

        /* Schedule Calendar Section */
        .stay-schedule-section {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 18px;
            padding: 1.6rem;
            margin-bottom: 2.25rem;
            box-shadow: 0 4px 16px -2px rgba(124, 58, 237, 0.05);
        }

        /* Live Overview Banner */
        .schedule-overview-banner {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            gap: 1rem;
            background: linear-gradient(135deg, #faf5ff 0%, #f5f3ff 100%);
            border: 1.5px solid #ddd6fe;
            border-radius: 14px;
            padding: 0.95rem 1.25rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.06);
        }
        .overview-col {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .overview-col:last-child {
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
        .overview-value {
            font-size: 1.1rem;
            font-weight: 800;
            color: #1e1b4b;
            font-family: 'Outfit', sans-serif;
            line-height: 1.3;
        }
        .overview-divider {
            color: #cbd5e1;
            font-size: 0.85rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }

        /* Inline Calendar Card */
        .inline-calendar-card {
            background: #ffffff;
            border-radius: 14px;
            margin-top: 0.5rem;
            margin-bottom: 1.5rem;
        }

        /* Preferred Time Selector Box */
        .time-selector-card {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            padding: 1.25rem;
            margin-top: 1rem;
        }
        .resort-hours-banner {
            display: flex;
            align-items: center;
            gap: 7px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
            font-size: 0.8rem;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .time-chips-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 1rem;
        }
        .time-chip-btn {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            color: #334155;
            padding: 7px 13px;
            border-radius: 9px;
            font-size: 0.84rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
            font-family: inherit;
        }
        .time-chip-btn:hover {
            border-color: #7c3aed;
            color: #7c3aed;
            background: #f5f3ff;
            transform: translateY(-1px);
        }
        .time-chip-btn.active {
            background: #7c3aed;
            border-color: #7c3aed;
            color: #ffffff;
            box-shadow: 0 3px 10px rgba(124, 58, 237, 0.25);
        }
        .custom-time-picker-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 0.5rem;
            padding-top: 0.75rem;
            border-top: 1px dashed #cbd5e1;
        }
        .custom-time-select {
            padding: 0.55rem 0.85rem;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.88rem;
            color: #0f172a;
            background: #ffffff;
            outline: none;
            cursor: pointer;
        }
        .custom-time-select:focus {
            border-color: #7c3aed;
        }

        /* Full Day Pass Banner */
        .full-day-badge-banner {
            background: #ecfdf5;
            border: 1.5px solid #a7f3d0;
            color: #065f46;
            border-radius: 12px;
            padding: 0.9rem 1.15rem;
            font-weight: 700;
            font-size: 0.88rem;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 1rem;
        }

        /* Participant Stepper */
        .pax-stepper-control {
            display: inline-flex;
            align-items: center;
            gap: 0.65rem;
            background: #f8fafc;
            border: 2px solid #cbd5e1;
            border-radius: 14px;
            padding: 6px 10px;
            transition: border-color 0.2s ease;
        }
        .pax-stepper-control:focus-within {
            border-color: #7c3aed;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12);
        }
        .pax-step-btn {
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
        .pax-step-btn:hover {
            background: #7c3aed;
            color: #ffffff;
            transform: scale(1.05);
        }
        .pax-step-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            transform: none;
            background: #e2e8f0;
            color: #94a3b8;
        }
        .pax-input-box {
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
        .pax-unit-text {
            font-size: 0.85rem;
            font-weight: 700;
            color: #64748b;
            margin-right: 6px;
        }

        /* Availability Notice */
        .availability-notice {
            margin-top: 10px;
            font-weight: 800;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        /* Equipment Box */
        .equipment-box {
            margin-top: 14px;
            background: #fdf4ff;
            border: 1.5px solid #f0abfc;
            border-radius: 14px;
            padding: 12px 16px;
        }
        .equipment-box-title {
            font-size: 0.78rem;
            font-weight: 800;
            color: #86198f;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Sticky Summary Card */
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
            margin-bottom: 1.15rem;
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
            margin-bottom: 0.65rem;
        }

        /* Selected Activities Breakdown in Summary */
        .summary-selected-activities {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 1.25rem;
            max-height: 220px;
            overflow-y: auto;
            padding-right: 4px;
        }
        .summary-act-item {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 6px 10px;
        }
        .summary-act-item-thumb {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            object-fit: cover;
            flex-shrink: 0;
            border: 1px solid #cbd5e1;
        }
        .summary-act-item-info {
            flex: 1;
            min-width: 0;
        }
        .summary-act-item-title {
            font-family: 'Outfit', sans-serif;
            font-size: 0.88rem;
            font-weight: 800;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .summary-act-item-sub {
            font-size: 0.75rem;
            color: #64748b;
            font-weight: 600;
        }
        .summary-act-item-price {
            font-size: 0.88rem;
            font-weight: 800;
            color: #7c3aed;
            flex-shrink: 0;
        }

        .summary-details-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.35rem;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.92rem;
            padding-bottom: 0.55rem;
            border-bottom: 1px dashed #f1f5f9;
        }
        .summary-label {
            color: #64748b;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .summary-label i {
            color: #7c3aed;
            font-size: 0.95rem;
        }
        .summary-val {
            color: #0f172a;
            font-weight: 800;
        }
        .summary-total-section {
            border-top: 1.5px solid #f1f5f9;
            padding-top: 1.15rem;
            margin-top: 0.5rem;
        }
        .summary-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.35rem;
        }
        .summary-total-label {
            font-family: 'Outfit', sans-serif;
            font-size: 1.1rem;
            font-weight: 800;
            color: #1e1b4b;
        }
        .summary-total-amount {
            font-family: 'Outfit', sans-serif;
            font-size: 1.65rem;
            font-weight: 900;
            color: #d97706;
        }
        .summary-calc-hint {
            font-size: 0.82rem;
            color: #94a3b8;
            text-align: right;
            margin-bottom: 1.25rem;
        }
        .summary-gcash-guarantee {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 12px;
            padding: 10px 14px;
            font-size: 0.8rem;
            color: #166534;
            line-height: 1.45;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin-bottom: 1.35rem;
        }
        .summary-gcash-guarantee i {
            color: #10b981;
            font-size: 1rem;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .btn-reserve-submit {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            width: 100%;
            font-family: 'Outfit', sans-serif;
            font-size: 1.05rem;
            font-weight: 800;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(217, 119, 6, 0.3);
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .btn-reserve-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(217, 119, 6, 0.4);
            color: #ffffff;
        }
        .btn-reserve-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .summary-back-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            width: 100%;
            margin-top: 0.85rem;
            color: #64748b;
            font-size: 0.85rem;
            font-weight: 700;
            text-decoration: none;
            transition: color 0.15s ease;
        }
        .summary-back-link:hover {
            color: #7c3aed;
        }

        /* Per-activity participant cards */
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

        /* ---------- Responsive ---------- */
        @media (max-width: 768px) {
            .booking-page-container { padding: 1.25rem 1rem 3.5rem 1rem; }
            .booking-step-tracker, .step-indicator { gap: 0.65rem; margin-bottom: 1.5rem; }
            .stay-schedule-section { padding: 1rem; margin-bottom: 1.5rem; }
            .time-selector-card { padding: 0.9rem; }
            .pax-stepper-control { display: flex; width: 100%; box-sizing: border-box; justify-content: space-between; }
            .pax-input-box { flex: 1; min-width: 0; }
            .act-choice-card { padding: 0.75rem; gap: 0.75rem; }
            .act-thumb-wrap { width: 56px; height: 56px; }
            .act-price-tag { margin-left: 0; }
            .summary-act-item-title { white-space: normal; }
        }
        @media (max-width: 640px) {
            .booking-step-tracker, .step-indicator {
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
            .booking-step-tracker::-webkit-scrollbar, .step-indicator::-webkit-scrollbar { display: none !important; }
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
            .step-num {
                width: 26px !important;
                height: 26px !important;
                min-width: 26px !important;
                max-width: 26px !important;
                font-size: 0.74rem !important;
            }
            .step-divider {
                font-size: 0.65rem !important;
                color: #cbd5e1 !important;
                margin: 0 !important;
            }
        }
        @media (max-width: 520px) {
            .schedule-overview-banner {
                grid-template-columns: 1fr;
                gap: 0.6rem;
                padding: 0.85rem 1rem;
            }
            .overview-divider { display: none; }
            .overview-col:last-child { text-align: left; align-items: flex-start; }
            .custom-time-picker-row { flex-direction: column; align-items: stretch; }
            .custom-time-select { width: 100%; }
            .time-chip-btn { flex: 1 1 calc(33.333% - 8px); padding: 7px 6px; text-align: center; }
            .act-desc-snippet { display: none; }
            .act-thumb-wrap { width: 48px; height: 48px; }
            .booking-summary-card { padding: 1.25rem; }
            .summary-total-amount { font-size: 1.4rem; }
            .summary-calc-hint { text-align: left; }
            .pax-step-btn { width: 44px; height: 44px; }
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
                            <span class="admin-header-page-heading" style="font-family: 'Outfit', sans-serif;">Reserve Resort Activity</span>
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
        <div class="booking-page-container">
    <?php endif; ?>

        <!-- Reschedule Alert if in edit mode -->
        <?php if (!empty($existing_ref)): ?>
            <div class="reschedule-alert-banner">
                <i class="fa-solid fa-calendar-days"></i>
                <div>
                    <div>Modifying Reservation: <strong><?= htmlspecialchars($existing_ref) ?></strong></div>
                    <span style="font-size: 0.82rem; font-weight: 600; color: #4338ca;">You are updating your scheduled session date, time slot, or participant count.</span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Step Indicator -->
        <div class="booking-step-tracker step-indicator">
            <a href="browse_activities.php" class="step-item done" title="Choose Activity">
                <span class="step-num"><i class="fa-solid fa-check"></i></span>
                <span class="step-label">
                    <span class="step-text-full">Choose Activity</span>
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

        <!-- Tropical Header -->
        <div class="booking-page-header">
            <h1><i class="fa-solid fa-water-ladder"></i> Reserve Resort Activities</h1>
            <p>Select multiple activities, pick your preferred schedule on the interactive calendar, and confirm participants.</p>
        </div>

        <?php if (!empty($error_msg)): ?>
            <div style="background: rgba(239, 68, 68, 0.12); color: #b91c1c; padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1.75rem; border: 1px solid #fca5a5; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-xmark" style="font-size: 1.2rem;"></i> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <!-- Main Booking Form Layout -->
        <form action="book_activities.php<?= !empty($existing_ref) ? '?ref=' . urlencode($existing_ref) : '' ?>" method="POST" id="activityBookingForm">
            <input type="hidden" name="existing_ref" value="<?= htmlspecialchars($existing_ref) ?>">
            <input type="hidden" name="scheduled_date" id="inputScheduledDate" value="<?= htmlspecialchars($initial_date) ?>" required>
            <input type="hidden" name="time_slot" id="inputTimeSlot" value="<?= htmlspecialchars($initial_time_slot) ?>" required>
            <input type="hidden" id="modalTimeSlot" value="<?= date('H:i', strtotime($initial_time_slot)) ?>">

            <div class="booking-layout-grid">
                
                <!-- Left Column: Options, Calendar & Participants -->
                <div class="booking-form-card">
                    
                    <!-- 1. Select Resort Experience (Supports Multiple) -->
                    <div class="form-section-title" style="margin-bottom: 0.4rem;">
                        <span><i class="fa-solid fa-water-ladder"></i> 1. Select Resort Experience(s)</span>
                        <span class="selected-count-badge" id="selectedCountBadge">
                            <i class="fa-solid fa-check"></i> <span id="selectedCountNum"><?= count($selected_activity_ids) ?></span> Selected
                        </span>
                    </div>
                    <div style="font-size: 0.82rem; color: #64748b; font-weight: 600; margin-bottom: 1rem;">
                        <i class="fa-solid fa-circle-info" style="color: #7c3aed;"></i> Check one or more activities to combine them into a single reservation session.
                    </div>

                    <!-- Category Filter Tabs -->
                    <div class="act-filter-tabs">
                        <button type="button" class="act-filter-tab active" data-filter="all">All Activities</button>
                        <button type="button" class="act-filter-tab" data-filter="pool"><i class="fa-solid fa-water-ladder"></i> Pool &amp; Swimming</button>
                        <button type="button" class="act-filter-tab" data-filter="outdoor"><i class="fa-solid fa-sailboat"></i> Water Sports</button>
                        <button type="button" class="act-filter-tab" data-filter="wellness"><i class="fa-solid fa-spa"></i> Wellness &amp; Spa</button>
                    </div>

                    <div class="activity-selection-grid" id="activityCardsGrid">
                        <?php foreach ($all_activities as $act): 
                            $isAvail = !empty($act['is_available']);
                            $isSelected = in_array(intval($act['activity_id']), $selected_activity_ids);
                            $img = !empty($act['image']) ? $act['image'] : 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80';
                            $actCat = !empty($act['category']) ? strtolower(trim($act['category'])) : 'pool';
                            $actCatLabel = $act['category_label'] ?? ucfirst($actCat);
                            $act_dur_label = (!empty($act['duration_minutes']) && intval($act['duration_minutes']) > 0) ? ($act['duration'] ?? ($act['duration_minutes'] . ' Mins')) : 'Full Day Access';
                        ?>
                            <label class="act-choice-card <?= $isSelected ? 'selected' : '' ?> <?= !$isAvail ? 'disabled' : '' ?>" 
                                   data-act-id="<?= $act['activity_id'] ?>" 
                                   data-cat="<?= htmlspecialchars($actCat) ?>">
                                <input type="checkbox" 
                                       name="activity_ids[]" 
                                       value="<?= $act['activity_id'] ?>" 
                                       data-name="<?= htmlspecialchars($act['activity_name']) ?>"
                                       data-pool="<?= $actCat === 'pool' ? '1' : '0' ?>"
                                       data-price="<?= floatval($act['price']) ?>"
                                       data-type="<?= htmlspecialchars($act['activity_type'] ?? 'slot') ?>"
                                       data-duration="<?= htmlspecialchars($act_dur_label) ?>"
                                       data-category="<?= htmlspecialchars($actCatLabel) ?>"
                                       data-image="<?= htmlspecialchars($img) ?>"
                                       <?= $isSelected ? 'checked' : '' ?>
                                       <?= !$isAvail ? 'disabled' : '' ?>
                                       class="act-checkbox-input">
                                
                                <div class="act-thumb-wrap">
                                    <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($act['activity_name']) ?>" class="act-thumb-img" loading="lazy">
                                </div>

                                <div class="act-info-col">
                                    <div class="act-title-row">
                                        <h4 class="act-title"><?= htmlspecialchars($act['activity_name']) ?></h4>
                                        <div class="act-custom-check">
                                            <i class="fa-solid fa-check"></i>
                                        </div>
                                    </div>
                                    <p class="act-desc-snippet"><?= htmlspecialchars($act['description'] ?? 'Resort pool and recreation activity') ?></p>
                                    <div class="act-meta-row">
                                        <span class="act-badge-tag"><?= htmlspecialchars($actCatLabel) ?></span>
                                        <span class="act-duration-tag"><i class="fa-regular fa-clock"></i> <?= htmlspecialchars($act_dur_label) ?></span>
                                        <span class="act-price-tag">₱<?= number_format($act['price']) ?> <span style="font-size: 0.76rem; color: #64748b; font-weight: 600;">/ pax</span></span>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- 2. Schedule Date & Time Slot Section -->
                    <div class="stay-schedule-section">
                        <div class="form-section-title" style="margin-bottom: 0.85rem;">
                            <span><i class="fa-solid fa-calendar-check"></i> 2. Schedule Date &amp; Time Slot</span>
                            <span style="font-size: 0.78rem; font-weight: 800; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 2px 8px; border-radius: 20px;">
                                <i class="fa-solid fa-circle-check"></i> Interactive Calendar
                            </span>
                        </div>

                        <!-- Live Selection Overview Banner -->
                        <div class="schedule-overview-banner">
                            <div class="overview-col">
                                <span class="overview-label"><i class="fa-regular fa-calendar"></i> Scheduled Date</span>
                                <span class="overview-value" id="overviewDateDisplay"><?= date('D, M d, Y', strtotime($initial_date)) ?></span>
                            </div>
                            <div class="overview-divider">
                                <i class="fa-solid fa-arrow-right"></i>
                            </div>
                            <div class="overview-col">
                                <span class="overview-label"><i class="fa-regular fa-clock"></i> Session Time</span>
                                <span class="overview-value" id="overviewTimeDisplay"><?= htmlspecialchars($initial_time_slot) ?></span>
                            </div>
                        </div>

                        <!-- Embedded Monthly Calendar Picker -->
                        <div id="bookingCalendarPicker" class="inline-calendar-card"></div>

                        <!-- Time Picker Section (Hidden when all chosen activities are Full Day) -->
                        <div id="timeSlotPickerSection" class="time-selector-card">
                            <div class="resort-hours-banner">
                                <i class="fa-solid fa-clock"></i>
                                <span>Resort Operating Hours: <strong>8:00 AM &ndash; 10:00 PM</strong></span>
                            </div>

                            <label style="display: block; font-weight: 800; font-size: 0.88rem; color: #0f172a; margin-bottom: 0.65rem;">
                                Popular Session Start Times:
                            </label>

                            <div class="time-chips-grid" id="timeChipsGrid">
                                <?php 
                                $popular_times = ['08:00 AM', '09:00 AM', '10:00 AM', '11:00 AM', '01:00 PM', '02:00 PM', '03:00 PM', '04:00 PM', '05:00 PM', '06:00 PM', '07:00 PM', '08:00 PM'];
                                foreach ($popular_times as $pt):
                                    $pt_active = ($initial_time_slot === $pt);
                                ?>
                                    <button type="button" class="time-chip-btn <?= $pt_active ? 'active' : '' ?>" data-time="<?= $pt ?>">
                                        <?= $pt ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>

                            <div class="custom-time-picker-row">
                                <span style="font-size: 0.82rem; font-weight: 700; color: #64748b;">Or pick custom time:</span>
                                <select id="customTimeSelect" class="custom-time-select">
                                    <?php 
                                    for ($h = 8; $h <= 22; $h++) {
                                        for ($m = 0; $m < 60; $m += 15) {
                                            if ($h === 22 && $m > 0) break;
                                            $h24 = str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT);
                                            $ampm = $h >= 12 ? 'PM' : 'AM';
                                            $disp_h = $h % 12 === 0 ? 12 : $h % 12;
                                            $label12 = str_pad($disp_h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT) . ' ' . $ampm;
                                            $selected_t = ($label12 === $initial_time_slot) ? 'selected' : '';
                                            echo "<option value='{$label12}' data-24='{$h24}' {$selected_t}>{$label12}</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>

                            <div id="timeWarning" style="color: #ef4444; font-size: 0.8rem; display: none; margin-top: 0.65rem; font-weight: 700;">
                                <i class="fa-solid fa-triangle-exclamation"></i> The selected time has already passed for today. Please select a later time.
                            </div>
                        </div>

                        <!-- Full Day Notice Badge (Visible when all chosen activities are Full Day) -->
                        <div id="fullDayNotice" class="full-day-badge-banner" style="display: none;">
                            <i class="fa-solid fa-sun" style="font-size: 1.25rem; color: #059669;"></i>
                            <div>
                                <div><strong>Full Day Resort Access</strong></div>
                                <span style="font-size: 0.82rem; color: #047857;">Enjoy unlimited swimming and amenities access throughout operating hours (8:00 AM &ndash; 10:00 PM).</span>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Number of Participants / Passes -->
                    <div>
                        <div class="form-section-title">
                            <span><i class="fa-solid fa-users"></i> 3. Number of Participants</span>
                        </div>

                        <div class="pax-stepper-control">
                            <button type="button" class="pax-step-btn" id="paxMinusBtn" aria-label="Decrease participants">
                                <i class="fa-solid fa-minus"></i>
                            </button>
                            <input type="number" name="pax" id="paxInput" class="pax-input-box" min="1" max="20" value="<?= $initial_pax ?>" required>
                            <span class="pax-unit-text">participant(s)</span>
                            <button type="button" class="pax-step-btn" id="paxPlusBtn" aria-label="Increase participants">
                                <i class="fa-solid fa-plus"></i>
                            </button>
                        </div>

                        <!-- Separate participant count per equipment/limited activity (shown when combined with pool or other activities) -->
                        <div id="equipPaxSection" style="display: none; margin-top: 1rem;">
                            <div style="font-size: 0.85rem; font-weight: 800; color: #0f172a; margin-bottom: 0.2rem;">
                                <i class="fa-solid fa-person-snowboarding" style="color: #7c3aed;"></i>
                                Participants per activity with equipment / limits
                            </div>
                            <div style="font-size: 0.78rem; color: #64748b; font-weight: 600; margin-bottom: 0.65rem;">
                                Choose how many of your guests join each of these activities.
                            </div>
                            <div id="activityPaxList" style="display: flex; flex-direction: column; gap: 0.75rem;"></div>
                        </div>

                        <div id="availabilityNotice" class="availability-notice" style="color: #7c3aed;">
                            <i class="fa-solid fa-circle-check"></i> Checking availability...
                        </div>

                        <!-- Real-time Equipment & Gear Remaining Box -->
                        <div id="activityEquipmentBox" class="equipment-box" style="display: none;">
                            <div class="equipment-box-title">
                                <i class="fa-solid fa-boxes-stacked" style="color: #c084fc;"></i> Available Equipment &amp; Gear Remaining
                            </div>
                            <div id="activityEquipmentList" style="display: flex; flex-wrap: wrap; gap: 8px;"></div>
                        </div>
                    </div>

                </div><!-- /.booking-form-card -->

                <!-- Right Column: Sticky Summary & GCash Payment Action -->
                <div class="booking-summary-wrapper">
                    <div class="booking-summary-card">
                        
                        <div class="summary-header">
                            <span class="summary-badge" id="summaryCategoryBadge">
                                <i class="fa-solid fa-layer-group"></i> Selected Activities
                            </span>
                        </div>

                        <!-- Selected Activities List in Summary Card -->
                        <div class="summary-selected-activities" id="summarySelectedActivities">
                            <!-- Populated dynamically via JS -->
                        </div>

                        <div class="summary-details-list">
                            <div class="summary-row">
                                <span class="summary-label"><i class="fa-regular fa-calendar"></i> Date</span>
                                <span class="summary-val" id="summaryDateVal"><?= date('M d, Y', strtotime($initial_date)) ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label"><i class="fa-regular fa-clock"></i> Time</span>
                                <span class="summary-val" id="summaryTimeVal"><?= htmlspecialchars($initial_time_slot) ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label"><i class="fa-solid fa-list-check"></i> Activities</span>
                                <span class="summary-val" id="summaryActivitiesCount">1 Selected</span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label"><i class="fa-solid fa-users"></i> Participants</span>
                                <span class="summary-val" id="summaryPaxVal"><?= $initial_pax ?> Guest<?= $initial_pax > 1 ? 's' : '' ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label"><i class="fa-solid fa-tag"></i> Rate / Person</span>
                                <span class="summary-val" id="summaryRateVal">₱0.00</span>
                            </div>
                        </div>

                        <div class="summary-total-section">
                            <div class="summary-total-row">
                                <span class="summary-total-label">Total Amount</span>
                                <span class="summary-total-amount" id="summaryTotalAmount">₱0.00</span>
                            </div>
                            <div class="summary-calc-hint" id="summaryCalcHint">₱0 × 1 pax</div>
                        </div>

                        <div class="summary-gcash-guarantee">
                            <i class="fa-solid fa-shield-halved"></i>
                            <div>
                                <strong>Instant GCash Confirmation</strong>
                                <div>Guaranteed slot hold with official reservation reference upon payment.</div>
                            </div>
                        </div>

                        <button type="submit" class="btn-reserve-submit" id="btnSubmitReservation">
                            <i class="fa-solid fa-check-circle"></i> Confirm &amp; Proceed to GCash
                        </button>

                        <a href="browse_activities.php" class="summary-back-link">
                            <i class="fa-solid fa-arrow-left"></i> Browse Other Activities
                        </a>

                    </div><!-- /.booking-summary-card -->
                </div><!-- /.booking-summary-wrapper -->

            </div><!-- /.booking-layout-grid -->
        </form>

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
    // ---------- 1. State & References ----------
    const hiddenDate = document.getElementById('inputScheduledDate');
    const hiddenTime = document.getElementById('inputTimeSlot');
    const modalTime24 = document.getElementById('modalTimeSlot');

    const overviewDateDisplay = document.getElementById('overviewDateDisplay');
    const overviewTimeDisplay = document.getElementById('overviewTimeDisplay');

    const selectedCountBadge = document.getElementById('selectedCountBadge');
    const selectedCountNum = document.getElementById('selectedCountNum');
    const summaryCategoryBadge = document.getElementById('summaryCategoryBadge');
    const summarySelectedActivities = document.getElementById('summarySelectedActivities');
    const summaryDateVal = document.getElementById('summaryDateVal');
    const summaryTimeVal = document.getElementById('summaryTimeVal');
    const summaryActivitiesCount = document.getElementById('summaryActivitiesCount');
    const summaryPaxVal = document.getElementById('summaryPaxVal');
    const summaryRateVal = document.getElementById('summaryRateVal');
    const summaryTotalAmount = document.getElementById('summaryTotalAmount');
    const summaryCalcHint = document.getElementById('summaryCalcHint');

    const timeSlotPickerSection = document.getElementById('timeSlotPickerSection');
    const fullDayNotice = document.getElementById('fullDayNotice');
    const timeChips = document.querySelectorAll('.time-chip-btn');
    const customTimeSelect = document.getElementById('customTimeSelect');
    const timeWarning = document.getElementById('timeWarning');

    const paxInput = document.getElementById('paxInput');
    const paxMinusBtn = document.getElementById('paxMinusBtn');
    const paxPlusBtn = document.getElementById('paxPlusBtn');
    const availabilityNotice = document.getElementById('availabilityNotice');
    const btnSubmit = document.getElementById('btnSubmitReservation');
    const equipPaxSection = document.getElementById('equipPaxSection');
    const activityPaxList = document.getElementById('activityPaxList');

    const eqBox = document.getElementById('activityEquipmentBox');
    const eqList = document.getElementById('activityEquipmentList');

    // Helper: Format Date for Display
    function formatHumanDate(dateStr) {
        if (!dateStr) return '';
        const parts = dateStr.split('-');
        if (parts.length !== 3) return dateStr;
        const d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
    }

    function formatShortDate(dateStr) {
        if (!dateStr) return '';
        const parts = dateStr.split('-');
        if (parts.length !== 3) return dateStr;
        const d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function to24h(time12) {
        if (!time12 || time12 === 'Full Day') return '09:00';
        const match = time12.match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i);
        if (!match) return '09:00';
        let h = parseInt(match[1], 10);
        const m = match[2];
        const ampm = match[3].toUpperCase();
        if (ampm === 'PM' && h < 12) h += 12;
        if (ampm === 'AM' && h === 12) h = 0;
        return String(h).padStart(2, '0') + ':' + m;
    }

    // ---------- 2. Check Active Activities (Multi-Select) ----------
    function getSelectedActivityCheckboxes() {
        return Array.from(document.querySelectorAll('.act-checkbox-input:checked'));
    }

    function syncActivityUI() {
        const checkedInputs = getSelectedActivityCheckboxes();

        // Highlight cards
        document.querySelectorAll('.act-choice-card').forEach(card => {
            const cb = card.querySelector('.act-checkbox-input');
            if (cb && cb.checked) {
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
        });

        // Update count badge
        const count = checkedInputs.length;
        if (selectedCountNum) selectedCountNum.textContent = count;

        if (count === 0) {
            summaryCategoryBadge.innerHTML = `<i class="fa-solid fa-triangle-exclamation" style="color: #ea580c;"></i> No Activity Selected`;
            summarySelectedActivities.innerHTML = `
                <div style="text-align: center; padding: 1.25rem 0.5rem; background: #fff7ed; border: 1.5px dashed #fdba74; border-radius: 12px; color: #c2410c; font-size: 0.85rem; font-weight: 700;">
                    <i class="fa-solid fa-circle-exclamation" style="font-size: 1.2rem; margin-bottom: 4px; display: block;"></i>
                    Please select at least 1 activity on the left.
                </div>
            `;
            summaryActivitiesCount.textContent = '0 Selected';
            summaryRateVal.textContent = '₱0.00';
            summaryTotalAmount.textContent = '₱0.00';
            summaryCalcHint.textContent = 'Please select an activity';
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = `<i class="fa-solid fa-hand-pointer"></i> Select at least 1 Activity`;
            availabilityNotice.innerHTML = `<i class="fa-solid fa-circle-exclamation" style="color: #ea580c;"></i> Select at least 1 activity to check slots`;
            availabilityNotice.style.color = '#ea580c';
            if (eqBox) eqBox.style.display = 'none';
            return;
        }

        // Build selected activities summary list
        let combinedPrice = 0;
        let anyRequiresTimeSlot = false;
        let listHTML = '';

        checkedInputs.forEach(input => {
            const name = input.dataset.name;
            const price = parseFloat(input.dataset.price) || 0;
            const duration = input.dataset.duration || 'Session';
            const category = input.dataset.category || 'Water Activity';
            const image = input.dataset.image;
            const type = input.dataset.type || 'slot';

            combinedPrice += price;
            if (type !== 'full_day') {
                anyRequiresTimeSlot = true;
            }

            listHTML += `
                <div class="summary-act-item">
                    <img src="${image}" alt="${name}" class="summary-act-item-thumb">
                    <div class="summary-act-item-info">
                        <div class="summary-act-item-title">${name}</div>
                        <div class="summary-act-item-sub"><i class="fa-regular fa-clock"></i> ${duration} • ${category}</div>
                    </div>
                    <div class="summary-act-item-price">₱${price.toLocaleString()}</div>
                </div>
            `;
        });

        summarySelectedActivities.innerHTML = listHTML;
        summaryActivitiesCount.textContent = `${count} ${count > 1 ? 'Activities' : 'Activity'}`;
        summaryCategoryBadge.innerHTML = count > 1 
            ? `<i class="fa-solid fa-layer-group"></i> ${count} Experiences Bundled`
            : `<i class="fa-solid fa-water"></i> ${checkedInputs[0].dataset.category}`;

        summaryRateVal.textContent = '₱' + combinedPrice.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        // Handle time slot vs full-day across multi-selections
        if (!anyRequiresTimeSlot) {
            // All selected are full-day passes
            timeSlotPickerSection.style.display = 'none';
            fullDayNotice.style.display = 'flex';
            hiddenTime.value = 'Full Day';
            overviewTimeDisplay.textContent = 'Full Day Pass';
            summaryTimeVal.textContent = 'Full Day Access';
        } else {
            // At least one activity requires a timed slot
            timeSlotPickerSection.style.display = 'block';
            fullDayNotice.style.display = 'none';
            if (hiddenTime.value === 'Full Day') {
                hiddenTime.value = customTimeSelect.value || '09:00 AM';
            }
            overviewTimeDisplay.textContent = hiddenTime.value;
            summaryTimeVal.textContent = hiddenTime.value;
        }

        recalculateTotals();
        checkAvailability();
    }

    // ---------- 3. Recalculate Totals ----------
    // Pool activities are unlimited; every other activity is limited by its own equipment/capacity.
    // Per-activity participant counts are used when limited activities are combined with the pool or with each other.
    let activityPaxCounts = {};   // activity_id -> chosen participants
    let activityLimits = {};      // activity_id -> max available (from the API)

    function getActivityGroups() {
        const inputs = getSelectedActivityCheckboxes();
        const poolIn = inputs.filter(i => i.dataset.pool === '1');
        const limIn = inputs.filter(i => i.dataset.pool !== '1');
        return { poolIn, limIn, split: (poolIn.length > 0 && limIn.length > 0) || limIn.length >= 2 };
    }

    function getActivityPax(id) {
        const main = parseInt(paxInput.value, 10) || 1;
        const lim = activityLimits[id] !== undefined ? activityLimits[id] : main;
        const chosen = activityPaxCounts[id] !== undefined ? activityPaxCounts[id] : main;
        return Math.max(1, Math.min(chosen, lim, main));
    }

    function escapeText(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));
    }

    function renderActivityPaxList() {
        const g = getActivityGroups();
        equipPaxSection.style.display = g.split ? 'block' : 'none';
        if (!g.split) {
            activityPaxList.innerHTML = '';
            return;
        }
        const main = parseInt(paxInput.value, 10) || 1;
        let html = '';
        g.limIn.forEach(inp => {
            const id = inp.value;
            const lim = activityLimits[id] !== undefined ? activityLimits[id] : main;
            const max = Math.max(0, Math.min(lim, main));
            const val = getActivityPax(id);
            const soldOut = lim <= 0;
            html += `
                <div class="act-pax-card">
                    <div class="act-pax-name">${escapeText(inp.dataset.name)}</div>
                    <div class="pax-stepper-control">
                        <button type="button" class="pax-step-btn" data-act-step="-1" data-act="${id}" aria-label="Decrease participants"><i class="fa-solid fa-minus"></i></button>
                        <input type="number" name="activity_pax[${id}]" id="actPax_${id}" data-act="${id}" class="pax-input-box act-pax-input" min="1" max="${Math.max(1, max)}" value="${val}" ${soldOut ? 'disabled' : ''}>
                        <span class="pax-unit-text">participant(s)</span>
                        <button type="button" class="pax-step-btn" data-act-step="1" data-act="${id}" aria-label="Increase participants"><i class="fa-solid fa-plus"></i></button>
                    </div>
                    <div class="availability-notice" style="margin-top: 6px; font-size: 0.8rem; color: ${soldOut ? '#ef4444' : '#7c3aed'};">
                        ${soldOut ? 'No equipment/slots left for this date/time' : `Up to ${max} can join (available: ${lim})`}
                    </div>
                </div>`;
        });
        activityPaxList.innerHTML = html;
    }

    function setActivityPax(id, val) {
        const main = parseInt(paxInput.value, 10) || 1;
        const lim = activityLimits[id] !== undefined ? activityLimits[id] : main;
        const max = Math.max(1, Math.min(lim, main));
        val = Math.max(1, Math.min(parseInt(val, 10) || 1, max));
        activityPaxCounts[id] = val;
        const el = document.getElementById('actPax_' + id);
        if (el) el.value = val;
        recalculateTotals();
    }

    function recalculateTotals() {
        const checkedInputs = getSelectedActivityCheckboxes();
        if (checkedInputs.length === 0) return;

        const g = getActivityGroups();
        const pax = parseInt(paxInput.value, 10) || 1;
        let total = 0, hint;
        const combinedPrice = checkedInputs.reduce((s, i) => s + (parseFloat(i.dataset.price) || 0), 0);

        if (g.split) {
            const parts = [];
            checkedInputs.forEach(inp => {
                const price = parseFloat(inp.dataset.price) || 0;
                const cnt = inp.dataset.pool === '1' ? pax : getActivityPax(inp.value);
                total += price * cnt;
                parts.push(`₱${price.toLocaleString()} × ${cnt}`);
            });
            hint = parts.join(' + ');
        } else {
            total = combinedPrice * pax;
            hint = `₱${combinedPrice.toLocaleString()} × ${pax} pax (${checkedInputs.length} ${checkedInputs.length > 1 ? 'activities' : 'activity'})`;
        }

        summaryPaxVal.textContent = `${pax} Guest${pax > 1 ? 's' : ''}`;
        summaryTotalAmount.textContent = '₱' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        summaryCalcHint.textContent = hint;
    }

    // ---------- 4. Date & Time Validation ----------
    function validateNoPastTime() {
        const dateVal = hiddenDate.value;
        const timeVal = modalTime24.value;
        if (timeWarning) timeWarning.style.display = 'none';

        if (!dateVal || !timeVal || hiddenTime.value === 'Full Day') return true;

        const selectedDateTime = new Date(dateVal + 'T' + timeVal);
        const now = new Date();

        if (selectedDateTime < now) {
            if (timeWarning) timeWarning.style.display = 'block';
            return false;
        }
        return true;
    }

    // ---------- 5. Check Real-Time Availability Across Multiple Activities ----------
    function checkAvailability() {
        const checkedInputs = getSelectedActivityCheckboxes();
        if (checkedInputs.length === 0) {
            btnSubmit.disabled = true;
            return;
        }

        const actIds = checkedInputs.map(input => input.value).join(',');
        const date = hiddenDate.value;
        const time24 = modalTime24.value || '09:00';
        const reqPax = parseInt(paxInput.value, 10) || 1;

        availabilityNotice.innerHTML = `<i class="fa-solid fa-spinner fa-spin" style="color: #7c3aed;"></i> Checking slot availability...`;
        availabilityNotice.style.color = '#7c3aed';

        fetch(`api_check_activity_availability.php?activity_ids=${actIds}&date=${date}&start_time=${time24}`)
            .then(r => r.json())
            .then(data => {
                const groups = getActivityGroups();
                activityLimits = {};
                (data.activities || []).forEach(a => { activityLimits[String(a.activity_id)] = parseInt(a.available, 10) || 0; });

                // Main (group) count: limited by the pool if selected, otherwise by the largest limited activity.
                let mainAvail;
                let limAvail = null;
                if (groups.split) {
                    const limVals = groups.limIn.map(i => activityLimits[i.value] !== undefined ? activityLimits[i.value] : 0);
                    limAvail = limVals.length ? Math.min(...limVals) : 0;
                    mainAvail = groups.poolIn.length > 0 && data.pool_available !== null && data.pool_available !== undefined
                        ? parseInt(data.pool_available, 10)
                        : Math.max(...limVals);
                } else {
                    mainAvail = parseInt(data.available, 10);
                }
                const avail = mainAvail;
                paxInput.max = Math.max(1, isNaN(avail) ? 9999 : avail);

                renderActivityPaxList();
                if (groups.split) {
                    recalculateTotals();
                }

                if (avail > 0) {
                    if (reqPax <= avail) {
                        availabilityNotice.innerHTML = `<i class="fa-solid fa-circle-check" style="color: #059669;"></i> ${avail} participant slot(s) available for all selected activities`;
                        availabilityNotice.style.color = '#059669';
                        btnSubmit.disabled = false;
                        btnSubmit.innerHTML = `<i class="fa-solid fa-check-circle"></i> Confirm &amp; Proceed to GCash`;
                    } else {
                        availabilityNotice.innerHTML = `<i class="fa-solid fa-triangle-exclamation" style="color: #ea580c;"></i> Only ${avail} slot(s) remaining for this combination (you requested ${reqPax})`;
                        availabilityNotice.style.color = '#ea580c';
                        btnSubmit.disabled = true;
                    }
                } else {
                    availabilityNotice.innerHTML = `<i class="fa-solid fa-circle-xmark" style="color: #ef4444;"></i> Fully Booked for this date/time combination`;
                    availabilityNotice.style.color = '#ef4444';
                    btnSubmit.disabled = true;
                    btnSubmit.innerHTML = `<i class="fa-solid fa-ban"></i> Fully Booked`;
                }

                // Equipment-limited activities have no stock left: block booking
                if (groups.split && limAvail <= 0) {
                    availabilityNotice.innerHTML = `<i class="fa-solid fa-circle-xmark" style="color: #ef4444;"></i> Fully Booked: no equipment left for the selected equipment activities`;
                    availabilityNotice.style.color = '#ef4444';
                    btnSubmit.disabled = true;
                }

                // Render Equipment & Gear for all activities
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
                console.error('Availability check failed:', err);
                availabilityNotice.innerHTML = `<i class="fa-solid fa-circle-check" style="color: #059669;"></i> Available for reservation`;
                availabilityNotice.style.color = '#059669';
                btnSubmit.disabled = false;
                if (eqBox) eqBox.style.display = 'none';
            });
    }

    // ---------- 6. Event Listeners ----------

    // Category filter tabs
    document.querySelectorAll('.act-filter-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.act-filter-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            const cat = tab.dataset.filter;

            document.querySelectorAll('.act-choice-card').forEach(card => {
                if (cat === 'all' || card.dataset.cat === cat) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    });

    // Checkbox changes (automatically fired when clicking label card or checkbox)
    document.querySelectorAll('.act-checkbox-input').forEach(input => {
        input.addEventListener('change', syncActivityUI);
    });

    // Time chip click
    timeChips.forEach(chip => {
        chip.addEventListener('click', () => {
            timeChips.forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            const time12 = chip.dataset.time;
            hiddenTime.value = time12;
            modalTime24.value = to24h(time12);
            customTimeSelect.value = time12;

            overviewTimeDisplay.textContent = time12;
            summaryTimeVal.textContent = time12;

            validateNoPastTime();
            checkAvailability();
        });
    });

    // Custom time select
    if (customTimeSelect) {
        customTimeSelect.addEventListener('change', () => {
            const time12 = customTimeSelect.value;
            const opt = customTimeSelect.options[customTimeSelect.selectedIndex];
            const time24 = opt.dataset['24'] || to24h(time12);

            hiddenTime.value = time12;
            modalTime24.value = time24;

            timeChips.forEach(c => {
                if (c.dataset.time === time12) {
                    c.classList.add('active');
                } else {
                    c.classList.remove('active');
                }
            });

            overviewTimeDisplay.textContent = time12;
            summaryTimeVal.textContent = time12;

            validateNoPastTime();
            checkAvailability();
        });
    }

    // Per-activity participant steppers (capped by each activity's equipment/slot availability)
    if (activityPaxList) {
        activityPaxList.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-act-step]');
            if (!btn) return;
            const id = btn.dataset.act;
            const cur = getActivityPax(id);
            setActivityPax(id, cur + parseInt(btn.dataset.actStep, 10));
        });
        activityPaxList.addEventListener('input', (e) => {
            if (!e.target.classList.contains('act-pax-input')) return;
            setActivityPax(e.target.dataset.act, e.target.value);
        });
    }

    // Pax Stepper
    if (paxMinusBtn) {
        paxMinusBtn.addEventListener('click', () => {
            let val = parseInt(paxInput.value, 10) || 1;
            if (val > 1) {
                paxInput.value = val - 1;
                recalculateTotals();
                checkAvailability();
            }
        });
    }

    if (paxPlusBtn) {
        paxPlusBtn.addEventListener('click', () => {
            let val = parseInt(paxInput.value, 10) || 1;
            const max = parseInt(paxInput.max, 10) || 9999;
            if (val < max) {
                paxInput.value = val + 1;
                recalculateTotals();
                checkAvailability();
            }
        });
    }

    if (paxInput) {
        paxInput.addEventListener('input', () => {
            let val = parseInt(paxInput.value, 10) || 1;
            if (val < 1) val = 1;
            const max = parseInt(paxInput.max, 10) || 9999;
            if (val > max) val = max;
            paxInput.value = val;
            recalculateTotals();
            checkAvailability();
        });
    }

    // ---------- 7. Monthly Calendar Picker Init ----------
    initMonthlyCalendarPicker({
        containerId: 'bookingCalendarPicker',
        checkInInputId: 'inputScheduledDate',
        isSingleDate: true,
        initialCheckIn: "<?= htmlspecialchars($initial_date) ?>",
        isAdmin: <?= $is_admin_layout ? 'true' : 'false' ?>,
        onRangeSelected: function () {
            const newDate = hiddenDate.value;
            overviewDateDisplay.textContent = formatHumanDate(newDate);
            summaryDateVal.textContent = formatShortDate(newDate);
            validateNoPastTime();
            checkAvailability();
        }
    });

    // ---------- 8. Init on Page Load ----------
    (function init() {
        overviewDateDisplay.textContent = formatHumanDate(hiddenDate.value);
        summaryDateVal.textContent = formatShortDate(hiddenDate.value);
        modalTime24.value = to24h(hiddenTime.value);

        syncActivityUI();
    })();
    </script>

</body>
</html>