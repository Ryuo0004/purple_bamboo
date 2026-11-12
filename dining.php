<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require logged-in user to access dining
if (empty($_SESSION['user_id'])) {
    header("Location: login.php?msg=" . urlencode("Please sign in first to access the resort dining menu.") . "&redirect=dining.php");
    exit();
}

require_once __DIR__ . '/dining_store.php';

$category = $_GET['cat'] ?? 'Main Dishes';
if ($category === 'All') {
    $category = 'Main Dishes';
}
$menu_items = DiningStore::getMenuItems($category);

// Pre-fill guest data from session
$user_id = $_SESSION['user_id'] ?? null;
$guest_name = $_SESSION['full_name'] ?? '';
$guest_email = $_SESSION['email'] ?? '';
$guest_phone = $_SESSION['phone'] ?? '';
$guest_health_conditions = $_SESSION['health_conditions'] ?? '';
$guest_health_info = $_SESSION['health_info'] ?? '';

// Pre-parse session health conditions into array for default allergy check
$default_allergies = [];
if (!empty($guest_health_conditions)) {
    $default_allergies = array_filter(array_map('trim', explode(',', $guest_health_conditions)));
}

// Check if user has an active checked-in room stay (strictly checked_in status, type=house)
$user_active_room = null;
$assigned_kubo = '';
if (!empty($user_id)) {
    require_once __DIR__ . '/booking_store.php';
    $all_b = BookingStore::getAllBookings();
    foreach ($all_b as $b) {
        $m_user = (($b['user_id'] ?? null) == $user_id) || (!empty($guest_email) && strtolower(trim($b['guest_email'] ?? '')) === strtolower(trim($guest_email)));
        $is_house = strtolower(trim($b['type'] ?? 'house')) === 'house';
        $is_checked_in = strtolower(trim($b['booking_status'] ?? '')) === 'checked_in';

        if ($m_user && $is_house && $is_checked_in) {
            $user_active_room = $b;
            $assigned_kubo = trim($b['item_name'] ?? $b['room_name'] ?? 'My Assigned Kubo');
            break;
        }
    }
}

$order_success = false;
$created_order = null;
$payment_error = '';
$order_error = '';

// Handle Form Submission for New Dining Order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'place_order') {
    $items_raw = $_POST['items_json'] ?? '[]';
    $items_arr = json_decode($items_raw, true) ?: [];

    if (!empty($items_arr)) {
        // SERVER-SIDE VALIDATION 1: Delivery Location
        $public_locations = ['Main Bamboo Dining Pavilion', 'Poolside Lounge Cabana'];
        $raw_loc = trim($_POST['kubo_location'] ?? '');
        $final_location = '';

        if ($user_active_room) {
            $room_service_str = "Room Service: " . $assigned_kubo;
            if ($raw_loc === $room_service_str || $raw_loc === $assigned_kubo || $raw_loc === ("Room: " . $assigned_kubo)) {
                $final_location = $room_service_str;
            } elseif (in_array($raw_loc, $public_locations, true)) {
                $final_location = $raw_loc;
            } else {
                $order_error = "Invalid delivery location. As an in-house guest, you can only order room service to your assigned kubo (" . htmlspecialchars($assigned_kubo) . ") or public resort dining areas.";
            }
        } else {
            if (in_array($raw_loc, $public_locations, true)) {
                $final_location = $raw_loc;
            } else {
                $order_error = "Kubo room service delivery is exclusively reserved for guests currently checked in to that accommodation. Please choose a public dining area (Main Bamboo Dining Pavilion or Poolside Lounge Cabana).";
            }
        }

        // SERVER-SIDE VALIDATION 2: Payment Method
        $payment_method = strtolower(trim($_POST['payment_method'] ?? 'cash'));
        if ($payment_method === 'charge_to_room') {
            if (!$user_active_room) {
                $order_error = "Charge to Room is only permitted for in-house guests with an active checked-in stay at the resort. Please select Cash on Delivery or GCash.";
            }
        } elseif (!in_array($payment_method, ['gcash', 'cash'])) {
            $payment_method = 'cash';
        }

        if (empty($order_error)) {
            $clean_gref = preg_replace('/[^0-9]/', '', trim($_POST['gcash_ref'] ?? ''));
            $payment_proof_path = null;

            if ($payment_method === 'gcash' && !empty($_FILES['proof_image']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/uploads/receipts/';
                if (!is_dir($upload_dir)) {
                    @mkdir($upload_dir, 0777, true);
                }
                $ext = strtolower(pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $new_filename = 'dining_receipt_' . time() . '_' . rand(100, 999) . '.' . $ext;
                    if (move_uploaded_file($_FILES['proof_image']['tmp_name'], $upload_dir . $new_filename)) {
                        $payment_proof_path = 'uploads/receipts/' . $new_filename;
                    }
                }
            }

            $order_data = [
                'user_id' => $user_id,
                'guest_name' => trim($_POST['guest_name'] ?? $guest_name),
                'guest_email' => trim($_POST['guest_email'] ?? $guest_email),
                'guest_phone' => trim($_POST['guest_phone'] ?? $guest_phone),
                'kubo_location' => $final_location,
                'items' => $items_arr,
                'total_amount' => floatval($_POST['total_amount'] ?? 0),
                'guest_allergies' => $_POST['guest_allergies'] ?? [],
                'allergy_notes' => trim($_POST['allergy_notes'] ?? ''),
                'requested_serve_time' => (trim($_POST['requested_serve_time'] ?? 'ASAP (15-20 Mins)') === 'Others' && !empty($_POST['other_serve_time'])) ? date('h:i A', strtotime(trim($_POST['other_serve_time']))) : trim($_POST['requested_serve_time'] ?? 'ASAP (15-20 Mins)'),
                'kitchen_notes' => trim($_POST['kitchen_notes'] ?? ''),
                'payment_method' => $payment_method,
                'payment_status' => ($payment_method === 'charge_to_room') ? 'charged_to_room' : (($payment_method === 'gcash' && !empty($clean_gref)) ? 'proof_submitted' : 'unpaid'),
                'gcash_ref' => !empty($clean_gref) ? $clean_gref : null,
                'payment_proof' => $payment_proof_path,
                'payment_notes' => trim($_POST['payment_notes'] ?? '')
            ];

            $created_order = DiningStore::createOrder($order_data);
            $order_success = true;
        }
    }
}

// Handle Direct GCash Payment Submission for existing Dining Order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_dining_payment') {
    $order_id = trim($_POST['order_id'] ?? '');
    $gref = trim($_POST['gcash_ref'] ?? '');
    $res = DiningStore::submitPaymentProof($order_id, $gref, $_FILES['proof_image'] ?? null);
    if (!empty($res['success'])) {
        header("Location: dining.php?paid=1&order=" . urlencode($order_id));
        exit();
    } else {
        $payment_error = $res['error'] ?? 'Payment submission failed.';
    }
}

// Handle Guest Order Cancellation (Only permitted while order is still pending)
$cancel_success = false;
$cancel_error = '';
$cancelled_order_id = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    $cancel_id = trim($_POST['order_id'] ?? '');
    if (!empty($cancel_id)) {
        $existing = DiningStore::getOrderById($cancel_id);
        if ($existing) {
            $currSt = strtolower(trim($existing['order_status'] ?? 'pending'));
            $orderEmail = strtolower(trim($existing['guest_email'] ?? ''));
            $sessEmail  = strtolower(trim($guest_email ?? ''));

            if ($currSt === 'pending') {
                if ($orderEmail === $sessEmail || !empty($_SESSION['user_id'])) {
                    DiningStore::updateOrderStatus($cancel_id, 'cancelled', 'Cancelled by guest before kitchen preparation');
                    $cancel_success = true;
                    $cancelled_order_id = $cancel_id;
                } else {
                    $cancel_error = "You are not authorized to cancel order #{$cancel_id}.";
                }
            } else {
                $cancel_error = "Order #{$cancel_id} cannot be cancelled because the kitchen has already {$currSt} it. Cancellation is only permitted while status is Pending.";
            }
        } else {
            $cancel_error = "Order #{$cancel_id} was not found.";
        }
    }
}

// Fetch Active Guest Dining Orders for live serve time tracking
$guest_orders = DiningStore::getAllOrders([
    'user_email' => $guest_email
]);

// Prioritize Active / In-Kitchen Orders First
if (!empty($guest_orders)) {
    usort($guest_orders, function($a, $b) {
        $stRank = [
            'accepted' => 1,
            'preparing' => 2,
            'ready' => 3,
            'out_for_serving' => 4,
            'delivering' => 5,
            'pending' => 6,
            'served' => 7,
            'completed' => 8,
            'cancelled' => 9
        ];
        $rA = $stRank[strtolower(trim($a['order_status'] ?? 'pending'))] ?? 50;
        $rB = $stRank[strtolower(trim($b['order_status'] ?? 'pending'))] ?? 50;
        if ($rA !== $rB) return $rA - $rB;
        return strtotime($b['created_at'] ?? 'now') - strtotime($a['created_at'] ?? 'now');
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resort Dining Menu & Food Allergy Order Portal | Purple Bamboo Resort</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ── Modern Dining Order Card & Stepper Tracker ── */
        .pbr-order-card {
            border: 1.5px solid #e2e8f0;
            border-radius: var(--radius-md, 12px);
            padding: 0.85rem 1rem;
            margin-bottom: 0.85rem;
            background: #ffffff;
            box-shadow: 0 2px 10px -2px rgba(15, 23, 42, 0.05);
            transition: all 0.2s ease;
        }
        .pbr-order-card:last-child {
            margin-bottom: 0;
        }
        .pbr-order-card:hover {
            box-shadow: 0 4px 14px -2px rgba(15, 23, 42, 0.08);
            border-color: #cbd5e1;
        }
        .pbr-order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.45rem;
            flex-wrap: wrap;
            gap: 0.4rem;
        }
        .pbr-order-pill {
            display: inline-flex;
            align-items: center;
            background: #f5f3ff;
            color: #7c3aed;
            border: 1px solid #ddd6fe;
            padding: 0.2rem 0.55rem;
            border-radius: 20px;
            font-size: 0.74rem;
            font-weight: 800;
            letter-spacing: 0.03em;
        }
        .pbr-order-location {
            font-weight: 700;
            font-size: 0.86rem;
            color: #1e1b4b;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .pbr-order-location i {
            color: #d97706;
            font-size: 0.88rem;
        }

        /* ── Order Tabs Bar (When Multiple Orders Exist) ── */
        .pbr-order-tabs-wrapper {
            margin-bottom: 0.85rem;
            padding: 0.45rem 0.55rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
        }
        .pbr-order-tabs-label {
            font-size: 0.68rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.35rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .pbr-order-tabs-list {
            display: flex;
            gap: 6px;
            overflow-x: auto;
            scrollbar-width: thin;
            padding-bottom: 2px;
        }
        .pbr-order-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0.35rem 0.7rem;
            border-radius: 8px;
            border: 1.5px solid #e2e8f0;
            background: #ffffff;
            color: #475569;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
            white-space: nowrap;
            font-family: inherit;
        }
        .pbr-order-tab-btn:hover {
            border-color: #cbd5e1;
            color: #0f172a;
        }
        .pbr-order-tab-btn.active {
            border-color: #7c3aed;
            background: #f5f3ff;
            color: #6d28d9;
            box-shadow: 0 2px 6px rgba(124, 58, 237, 0.15);
        }
        .pbr-tab-badge-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #f59e0b;
        }
        .pbr-tab-badge-dot.acc-dot {
            background: #10b981;
        }
        .pbr-tab-status {
            font-size: 0.68rem;
            font-weight: 800;
            opacity: 0.85;
            padding: 1px 4px;
            background: rgba(0,0,0,0.06);
            border-radius: 4px;
        }

        /* ── Scrollable List Wrapper ── */
        .pbr-tracker-orders-list {
            max-height: 520px;
            overflow-y: auto;
            scrollbar-width: thin;
            padding-right: 2px;
        }

        /* ── Stepper Component ── */
        .pbr-dining-stepper {
            position: relative;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin: 0.75rem 0 0.5rem !important;
            padding: 0 !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }
        .pbr-dining-stepper::before {
            display: none !important;
        }
        .pbr-stepper-track {
            position: absolute;
            top: 17px;
            left: 12%;
            right: 12%;
            height: 4px;
            background: #e2e8f0;
            border-radius: 999px;
            z-index: 1;
            transform: translateY(-50%);
            overflow: hidden;
        }
        .pbr-stepper-progress {
            height: 100%;
            background: linear-gradient(90deg, #10b981 0%, #7c3aed 100%);
            border-radius: 999px;
            transition: width 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .pbr-step-item {
            flex: 1 1 0 !important;
            min-width: 0 !important;
            position: relative !important;
            z-index: 2 !important;
            text-align: center !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            background: transparent !important;
            padding: 0 4px !important;
            box-sizing: border-box !important;
            overflow: hidden !important;
        }
        .pbr-step-circle {
            width: 34px !important;
            height: 34px !important;
            border-radius: 50% !important;
            background: #ffffff !important;
            border: 2px solid #cbd5e1 !important;
            color: #94a3b8 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 0.85rem !important;
            margin: 0 auto 0.3rem !important;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1) !important;
            box-shadow: 0 0 0 3px #ffffff !important;
        }
        .pbr-step-title {
            font-size: 0.74rem !important;
            font-weight: 700 !important;
            color: #64748b !important;
            line-height: 1.15 !important;
            transition: color 0.2s ease !important;
            white-space: nowrap !important;
            text-align: center !important;
            max-width: 100% !important;
        }
        .pbr-step-sub {
            font-size: 0.62rem !important;
            color: #94a3b8 !important;
            margin-top: 2px !important;
            font-weight: 600 !important;
            line-height: 1.1 !important;
            text-align: center !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            max-width: 100% !important;
            display: block !important;
        }

        /* COMPLETED STEP */
        .pbr-step-item.completed .pbr-step-circle {
            background: #10b981 !important;
            border-color: #10b981 !important;
            color: #ffffff !important;
            box-shadow: 0 0 0 3px #ffffff, 0 3px 10px rgba(16, 185, 129, 0.3) !important;
        }
        .pbr-step-item.completed .pbr-step-title {
            color: #065f46 !important;
            font-weight: 800 !important;
        }
        .pbr-step-item.completed .pbr-step-sub {
            color: #059669;
            font-weight: 600;
        }

        /* ACTIVE STEP */
        .pbr-step-item.active:not(.completed) .pbr-step-circle {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%) !important;
            border-color: #7c3aed !important;
            color: #ffffff !important;
            box-shadow: 0 0 0 3px #ffffff, 0 0 0 5px rgba(124, 58, 237, 0.2), 0 3px 12px rgba(124, 58, 237, 0.35) !important;
            animation: pbrActivePulse 2s infinite cubic-bezier(0.4, 0, 0.6, 1);
        }
        @keyframes pbrActivePulse {
            0%, 100% {
                box-shadow: 0 0 0 3px #ffffff, 0 0 0 5px rgba(124, 58, 237, 0.2), 0 3px 12px rgba(124, 58, 237, 0.35);
            }
            50% {
                box-shadow: 0 0 0 3px #ffffff, 0 0 0 8px rgba(124, 58, 237, 0.1), 0 3px 16px rgba(124, 58, 237, 0.45);
            }
        }
        .pbr-step-item.active .pbr-step-title {
            color: #5b21b6 !important;
            font-weight: 800 !important;
        }
        .pbr-step-item.active .pbr-step-sub {
            color: #7c3aed;
            font-weight: 700;
        }

        /* PENDING / AWAITING KITCHEN ACCEPTANCE STEP */
        .pbr-step-item.pending-step .pbr-step-circle {
            background: #fffbeb !important;
            border-color: #f59e0b !important;
            color: #d97706 !important;
            box-shadow: 0 0 0 3px #ffffff, 0 0 0 5px rgba(245, 158, 11, 0.2), 0 3px 10px rgba(245, 158, 11, 0.25) !important;
            animation: pbrPendingPulse 2s infinite cubic-bezier(0.4, 0, 0.6, 1);
        }
        @keyframes pbrPendingPulse {
            0%, 100% {
                box-shadow: 0 0 0 3px #ffffff, 0 0 0 4px rgba(245, 158, 11, 0.2), 0 3px 10px rgba(245, 158, 11, 0.25);
            }
            50% {
                box-shadow: 0 0 0 3px #ffffff, 0 0 0 8px rgba(245, 158, 11, 0.1), 0 3px 14px rgba(245, 158, 11, 0.35);
            }
        }
        .pbr-step-item.pending-step .pbr-step-title {
            color: #b45309 !important;
            font-weight: 800 !important;
        }
        .pbr-step-item.pending-step .pbr-step-sub {
            color: #d97706 !important;
            font-weight: 700 !important;
        }

        /* CANCELLED STEP */
        .pbr-step-item.cancelled .pbr-step-circle {
            background: #fee2e2 !important;
            border-color: #ef4444 !important;
            color: #dc2626 !important;
        }

        /* Meta Box */
        .pbr-order-meta {
            font-size: 0.78rem;
            background: #f8fafc;
            padding: 0.55rem 0.75rem;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            margin-top: 0.45rem;
        }

        /* Status Badges */
        .pbr-order-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.72rem;
            font-weight: 800;
            padding: 0.2rem 0.55rem;
            border-radius: 20px;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }
        .pbr-badge-accepted {
            background: #ede9fe;
            color: #6d28d9;
            border: 1.5px solid #c4b5fd;
        }
        .pbr-badge-preparing {
            background: #fef3c7;
            color: #b45309;
            border: 1.5px solid #fcd34d;
        }
        .pbr-badge-serving {
            background: #e0f2fe;
            color: #0369a1;
            border: 1.5px solid #7dd3fc;
        }
        .pbr-badge-served {
            background: #dcfce7;
            color: #15803d;
            border: 1.5px solid #86efac;
        }
        .pbr-badge-pending {
            background: #fef9c3;
            color: #a16207;
            border: 1.5px solid #fde047;
        }
        .pbr-badge-cancelled {
            background: #fee2e2;
            color: #b91c1c;
            border: 1.5px solid #fca5a5;
        }

        /* ── Responsive Mobile Enhancements ── */
        .dining-hero-banner {
            background: linear-gradient(135deg, #4c1d95, #7e22ce);
            color: #ffffff;
            padding: 2.5rem;
            margin-bottom: 2rem;
            border: none;
            position: relative;
            overflow: hidden;
            border-radius: var(--radius-md, 12px);
        }
        .dining-cat-bar {
            display: flex;
            gap: 0.65rem;
            align-items: center;
            margin-bottom: 1.5rem;
            overflow-x: auto;
            scrollbar-width: thin;
            padding-bottom: 0.4rem;
            -webkit-overflow-scrolling: touch;
        }
        .dining-cat-label {
            font-weight: 700;
            color: var(--primary-purple);
            margin-right: 0.25rem;
            white-space: nowrap;
        }
        .dining-mobile-cart-bar {
            position: fixed;
            bottom: 1rem;
            left: 1rem;
            right: 1rem;
            background: linear-gradient(135deg, #4c1d95 0%, #6d28d9 100%);
            color: #ffffff;
            padding: 0.75rem 1rem;
            border-radius: 14px;
            box-shadow: 0 10px 25px -3px rgba(76, 29, 149, 0.4), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 999;
            cursor: pointer;
            border: 1px solid rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            animation: slideUpMobileCart 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes slideUpMobileCart {
            from { transform: translateY(100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .mobile-cart-badge {
            background: #f59e0b;
            color: #1e1b4b;
            font-weight: 800;
            font-size: 0.78rem;
            padding: 0.15rem 0.5rem;
            border-radius: 20px;
        }
        @media (min-width: 993px) {
            .dining-mobile-cart-bar {
                display: none !important;
            }
        }
        @media (max-width: 768px) {
            .dining-hero-banner {
                padding: 1.5rem 1.25rem !important;
                margin-bottom: 1.25rem !important;
            }
            .dining-hero-banner h1 {
                font-size: 1.6rem !important;
            }
            .dining-hero-banner p {
                font-size: 0.88rem !important;
            }
            .dining-banner-icon {
                font-size: 7rem !important;
                right: -15px !important;
                bottom: -20px !important;
            }
            .dining-cat-bar {
                gap: 0.5rem;
                margin-bottom: 1.15rem;
            }
            .cat-tab {
                padding: 0.45rem 0.9rem !important;
                font-size: 0.82rem !important;
                white-space: nowrap !important;
            }
        }
        @media (max-width: 640px) {
            .pbr-dining-stepper {
                margin: 0.5rem 0 0.25rem !important;
            }
            .pbr-stepper-track {
                top: 14px;
            }
            .pbr-step-circle {
                width: 28px !important;
                height: 28px !important;
                font-size: 0.72rem !important;
                margin-bottom: 0.2rem !important;
            }
            .pbr-step-title {
                font-size: 0.65rem !important;
                white-space: normal !important;
                word-break: break-word;
                line-height: 1.15 !important;
            }
            .pbr-step-sub {
                display: none !important;
            }
        }
    </style>
</head>
<body>

    <?php include 'nav.php'; ?>

    <div class="container">
        <!-- Dining Header Banner -->
        <div class="card dining-hero-banner">
            <div style="position: relative; z-index: 2;">
                <span class="badge badge-gold" style="margin-bottom: 0.75rem;"><i class="fa-solid fa-utensils"></i> Resort Gourmet & Bamboo Room Service</span>
                <h1 style="font-family: 'Outfit', sans-serif; font-size: 2.5rem; color: #ffffff; margin-bottom: 0.5rem;">Resort Dining & Drinks Menu 🍹</h1>
                <p style="color: #f3e8ff; font-size: 1.05rem; max-width: 680px;">Order fresh resort meals, seafood grills, tropical smoothies, and cocktails with custom food allergy screening and real-time serve time tracking.</p>
            </div>
            <div class="dining-banner-icon" style="position: absolute; right: -10px; bottom: -30px; opacity: 0.15; font-size: 13rem; pointer-events: none;">
                🥥
            </div>
        </div>

        <?php if (!empty($payment_error)): ?>
            <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 1rem 1.25rem; border-radius: var(--radius-md); margin-bottom: 2rem;">
                <i class="fa-solid fa-triangle-exclamation" style="margin-right: 6px;"></i> <?= htmlspecialchars($payment_error) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($order_error)): ?>
            <div style="background: #fff1f2; border: 1.5px solid #f43f5e; color: #be123c; padding: 1.15rem 1.35rem; border-radius: var(--radius-md); margin-bottom: 2rem; box-shadow: 0 4px 12px rgba(244, 63, 94, 0.1);">
                <div style="font-weight: 800; font-family: 'Outfit', sans-serif; font-size: 1.05rem; margin-bottom: 0.35rem; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-circle-xmark" style="font-size: 1.2rem; color: #e11d48;"></i> Order Submission Error
                </div>
                <div style="font-size: 0.92rem; line-height: 1.5; color: #9f1239;">
                    <?= htmlspecialchars($order_error) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($order_success && $created_order): ?>
            <div style="background: #f0fdf4; border: 1px solid #86efac; color: #166534; padding: 1.25rem; border-radius: var(--radius-md); margin-bottom: 2rem;">
                <h3 style="font-family: 'Outfit', sans-serif; margin-bottom: 0.3rem;"><i class="fa-solid fa-circle-check" style="color: #10b981;"></i> Food & Drinks Order Submitted Successfully!</h3>
                <p style="font-size: 0.95rem; margin-bottom: 0.35rem;">Order Ref: <strong>#<?= htmlspecialchars($created_order['id']) ?></strong> | Serving Location: <strong><?= htmlspecialchars($created_order['kubo_location']) ?></strong> | Total: <strong style="color: #047857;">₱<?= number_format($created_order['total_amount'] ?? 0, 2) ?></strong></p>
                <p style="font-size: 0.88rem; color: #15803d; margin-bottom: 0.75rem;"><i class="fa-solid fa-stopwatch"></i> Requested Serve Time: <strong><?= htmlspecialchars($created_order['requested_serve_time']) ?></strong>. The kitchen team has received your order and allergy safety notes.</p>
                <div style="padding-top: 0.75rem; border-top: 1px dashed #86efac; font-size: 0.9rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
                    <div>
                        <strong>Payment Method:</strong> 
                        <?php if (($created_order['payment_method'] ?? 'cash') === 'gcash'): ?>
                            <span class="badge" style="background: #0284c7; color: #fff; font-weight: 700; padding: 0.25rem 0.6rem; border-radius: 4px;"><i class="fa-solid fa-mobile-screen"></i> GCash</span>
                            <?php if (($created_order['payment_status'] ?? '') === 'proof_submitted'): ?>
                                <span style="color: #0369a1; font-weight: 600; margin-left: 4px;"><i class="fa-solid fa-receipt"></i> Receipt uploaded, awaiting kitchen verification</span>
                            <?php else: ?>
                                <span style="color: #b91c1c; font-weight: 600; margin-left: 4px;"><i class="fa-solid fa-clock"></i> Payment pending</span>
                            <?php endif; ?>
                        <?php elseif (($created_order['payment_method'] ?? 'cash') === 'charge_to_room'): ?>
                            <span class="badge" style="background: #7c3aed; color: #fff; font-weight: 700; padding: 0.25rem 0.6rem; border-radius: 4px;"><i class="fa-solid fa-key"></i> Charged to Room Folio</span>
                            <span style="color: #6d28d9; font-weight: 600; margin-left: 4px;">Settle upon room checkout</span>
                        <?php else: ?>
                            <span class="badge" style="background: #166534; color: #fff; font-weight: 700; padding: 0.25rem 0.6rem; border-radius: 4px;"><i class="fa-solid fa-money-bill-wave"></i> Cash on Delivery</span>
                            <span style="color: #15803d; font-weight: 600; margin-left: 4px;">Pay upon serving at your location</span>
                        <?php endif; ?>
                    </div>
                    <?php if (($created_order['payment_method'] ?? 'cash') === 'gcash' && ($created_order['payment_status'] ?? 'unpaid') === 'unpaid'): ?>
                        <a href="payment_method.php?ref=<?= urlencode($created_order['id']) ?>" class="btn btn-sm btn-purple" style="font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-credit-card"></i> Pay or Upload GCash Receipt Now
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($cancel_success)): ?>
            <div style="background: #fef2f2; border: 1.5px solid #f87171; color: #991b1b; padding: 1.15rem 1.4rem; border-radius: var(--radius-md); margin-bottom: 2rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.08);">
                <div>
                    <h4 style="margin: 0 0 0.25rem 0; font-family: 'Outfit', sans-serif; color: #b91c1c; font-size: 1.05rem;">
                        <i class="fa-solid fa-circle-check" style="color: #ef4444;"></i> Order #<?= htmlspecialchars($cancelled_order_id) ?> Cancelled Successfully
                    </h4>
                    <p style="margin: 0; font-size: 0.88rem; color: #7f1d1d;">Your dining order was cancelled before kitchen preparation began. No charges will be incurred.</p>
                </div>
            </div>
        <?php elseif (!empty($cancel_error)): ?>
            <div style="background: #fffbeb; border: 1.5px solid #fde68a; color: #92400e; padding: 1.15rem 1.4rem; border-radius: var(--radius-md); margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 1.25rem; color: #d97706;"></i>
                <div>
                    <strong>Cancellation Notice:</strong> <?= htmlspecialchars($cancel_error) ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Category Tabs Filter -->
        <div class="dining-cat-bar">
            <span class="dining-cat-label"><i class="fa-solid fa-filter"></i> Category:</span>
            <a href="dining.php?cat=Main Dishes" class="cat-tab <?= ($category === 'Main Dishes') ? 'active' : '' ?>">Bamboo Main Grills</a>
            <a href="dining.php?cat=Appetizers" class="cat-tab <?= ($category === 'Appetizers') ? 'active' : '' ?>">Starters & Appetizers</a>
            <a href="dining.php?cat=Drinks %26 Cocktails" class="cat-tab <?= ($category === 'Drinks & Cocktails') ? 'active' : '' ?>">Beverages & Cocktails</a>
            <a href="dining.php?cat=Desserts %26 Snacks" class="cat-tab <?= ($category === 'Desserts & Snacks') ? 'active' : '' ?>">Desserts & Treats</a>
        </div>

        <div class="dining-layout-grid">
            
            <!-- Left: Menu Items Catalog -->
            <div class="dining-catalog-col">
                <h2 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple);"><i class="fa-solid fa-bowl-food"></i> Available Culinary Selections</h2>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1rem;">Select items to customize your dining order and specify any food &amp; drink allergies</p>

                <div class="menu-grid">
                    <?php foreach ($menu_items as $item): ?>
                        <div class="menu-card">
                            <img src="<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" class="menu-card-img">
                            <div class="menu-card-body">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.4rem;">
                                    <h3 style="font-size: 1.05rem; font-family: 'Outfit', sans-serif; color: var(--primary-purple); line-height: 1.3; margin: 0;"><?= htmlspecialchars($item['item_name']) ?></h3>
                                    <span style="font-weight: 800; font-size: 1.1rem; color: var(--bamboo-gold); white-space: nowrap;">₱<?= number_format($item['price'], 2) ?></span>
                                </div>
                                
                                <p style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.4; margin-bottom: 0.75rem; flex-grow: 1;"><?= htmlspecialchars($item['description']) ?></p>

                                <div style="margin-bottom: 0.85rem;">
                                    <?php if (!empty($item['allergens'])): ?>
                                        <?php 
                                            $allergenArr = array_map('trim', explode(',', $item['allergens']));
                                            foreach ($allergenArr as $alg):
                                                $isSafe = (strpos(strtolower($alg), 'free') !== false || strpos(strtolower($alg), 'vegan') !== false);
                                        ?>
                                            <span class="allergen-chip <?= $isSafe ? 'allergen-safe-chip' : '' ?>">
                                                <i class="fa-solid <?= $isSafe ? 'fa-leaf' : 'fa-triangle-exclamation' ?>"></i> <?= htmlspecialchars($alg) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <button type="button" class="btn btn-gold btn-sm" style="width: 100%; font-weight: 700;" onclick="confirmAddToCart(<?= $item['id'] ?>, '<?= addslashes($item['item_name']) ?>', <?= $item['price'] ?>, '<?= addslashes($item['allergens'] ?? '') ?>', this)">
                                    <i class="fa-solid fa-cart-plus"></i> Add to Order
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Right Column: Active Guest Order Serve Time Tracker & Cart Preview -->
            <div class="dining-sidebar">

                <!-- Panel: Live Guest Serve Time Tracker (Positioned at top for active visibility) -->
                <div class="card" id="serveTimeTrackerCard" style="border: 1.5px solid <?= !empty($guest_orders) ? '#86efac' : 'var(--border-color)' ?>; background: #ffffff;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 2px solid var(--purple-light); padding-bottom: 0.5rem; flex-wrap: wrap; gap: 0.5rem;">
                        <h3 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin: 0; font-size: 1.15rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fa-solid fa-stopwatch" style="color: #10b981;"></i> Active Order Serve Time Tracker
                        </h3>
                        <?php if (!empty($guest_orders)): ?>
                            <span class="badge badge-success" style="font-size: 0.72rem; padding: 0.2rem 0.55rem; font-weight: 800;">
                                <i class="fa-solid fa-bell"></i> <?= count($guest_orders) ?> Active
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($guest_orders)): ?>
                        <div style="text-align: center; color: var(--text-muted); padding: 1.25rem 0.5rem;">
                            <i class="fa-regular fa-clock" style="font-size: 2.2rem; margin-bottom: 0.5rem; color: var(--border-color);"></i>
                            <p style="font-size: 0.88rem; margin-bottom: 0.25rem;">No active dining orders found for your account.</p>
                            <small style="color: var(--text-muted); font-size: 0.78rem;">Your live kitchen preparation and serve time countdown will show here once you place an order.</small>
                        </div>
                    <?php else: ?>
                        <?php if (count($guest_orders) > 1): ?>
                            <!-- Order Selector Tabs (Instant toggle between orders) -->
                            <div class="pbr-order-tabs-wrapper">
                                <div class="pbr-order-tabs-label">
                                    <i class="fa-solid fa-layer-group"></i> Active Orders (<?= count($guest_orders) ?>):
                                </div>
                                <div class="pbr-order-tabs-list">
                                    <?php foreach ($guest_orders as $idx => $go): 
                                        $st_clean = strtolower(trim($go['order_status'] ?? 'pending'));
                                        $is_acc = in_array($st_clean, ['accepted', 'preparing', 'ready', 'out_for_serving', 'delivering', 'served']);
                                        $dot_class = $is_acc ? 'acc-dot' : 'pending-dot';
                                        $short_id = substr($go['id'], -4);
                                    ?>
                                        <button type="button" 
                                                class="pbr-order-tab-btn <?= ($idx === 0) ? 'active' : '' ?>" 
                                                onclick="switchTrackerOrder('<?= htmlspecialchars($go['id']) ?>', this)">
                                            <span class="pbr-tab-badge-dot <?= $dot_class ?>"></span>
                                            <strong>#<?= htmlspecialchars($short_id) ?></strong>
                                            <span class="pbr-tab-status"><?= strtoupper($st_clean) ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                    <button type="button" class="pbr-order-tab-btn" onclick="switchTrackerOrder('all', this)">
                                        <i class="fa-solid fa-arrows-split-up-and-left"></i> Show Both
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Orders List Container -->
                        <div class="pbr-tracker-orders-list">
                            <?php foreach ($guest_orders as $idx => $gord): 
                                $curr_st = strtolower(trim($gord['order_status'] ?? 'pending'));

                                $is_served     = in_array($curr_st, ['served', 'completed']);
                                $is_serving    = in_array($curr_st, ['ready', 'out_for_serving', 'delivering']);
                                $is_preparing  = ($curr_st === 'preparing');
                                $is_accepted   = ($curr_st === 'accepted');
                                $is_pending    = ($curr_st === 'pending');
                                $is_cancelled  = in_array($curr_st, ['cancelled', 'canceled']);

                                // Step 1: Received (Only marked as completed once accepted by kitchen)
                                if ($is_pending) {
                                    $s1_state = 'pending-step active';
                                    $s1_sub   = 'Waiting';
                                } elseif ($is_cancelled) {
                                    $s1_state = 'cancelled';
                                    $s1_sub   = 'Cancelled';
                                } else {
                                    $s1_state = 'completed';
                                    $s1_sub   = 'Accepted';
                                }

                                // Step 2: Preparing
                                if ($is_served || $is_serving) {
                                    $s2_state = 'completed';
                                    $s2_sub   = 'Done';
                                } elseif ($is_preparing) {
                                    $s2_state = 'active';
                                    $s2_sub   = 'Cooking';
                                } elseif ($is_accepted) {
                                    $s2_state = '';
                                    $s2_sub   = 'Queued';
                                } else {
                                    $s2_state = '';
                                    $s2_sub   = 'Waiting';
                                }

                                // Step 3: Serving
                                if ($is_served) {
                                    $s3_state = 'completed';
                                    $s3_sub   = 'Delivered';
                                } elseif ($is_serving) {
                                    $s3_state = 'active';
                                    $s3_sub   = ($curr_st === 'ready') ? 'Ready' : 'En Route';
                                } else {
                                    $s3_state = '';
                                    $s3_sub   = 'Waiting';
                                }

                                // Step 4: Served
                                if ($is_served) {
                                    $s4_state = 'completed active';
                                    $s4_sub   = 'Delivered';
                                } else {
                                    $s4_state = '';
                                    $s4_sub   = 'Final';
                                }

                                // Dynamic Progress Track Width
                                if ($is_served) {
                                    $progress_pct = 100;
                                } elseif ($is_serving) {
                                    $progress_pct = 70;
                                } elseif ($is_preparing) {
                                    $progress_pct = 40;
                                } else {
                                    $progress_pct = 0; // 0% when pending or accepted (not yet cooking/preparing)
                                }

                                // Status badge
                                if ($is_served) {
                                    $badge_class = 'pbr-badge-served';
                                    $badge_icon  = 'fa-solid fa-circle-check';
                                    $badge_label = 'SERVED';
                                } elseif ($is_serving) {
                                    $badge_class = 'pbr-badge-serving';
                                    $badge_icon  = 'fa-solid fa-person-walking-luggage fa-bounce';
                                    $badge_label = ($curr_st === 'ready') ? 'READY' : 'SERVING';
                                } elseif ($is_preparing) {
                                    $badge_class = 'pbr-badge-preparing';
                                    $badge_icon  = 'fa-solid fa-fire fa-beat';
                                    $badge_label = 'PREPARING';
                                } elseif ($is_accepted) {
                                    $badge_class = 'pbr-badge-accepted';
                                    $badge_icon  = 'fa-solid fa-check-double';
                                    $badge_label = 'ACCEPTED';
                                } elseif ($is_cancelled) {
                                    $badge_class = 'pbr-badge-cancelled';
                                    $badge_icon  = 'fa-solid fa-circle-xmark';
                                    $badge_label = 'CANCELLED';
                                } else {
                                    $badge_class = 'pbr-badge-pending';
                                    $badge_icon  = 'fa-solid fa-clock fa-spin';
                                    $badge_label = 'PENDING';
                                }

                                // Initial display: if multiple orders exist, first one is visible by default (can click tab to toggle)
                                $initial_display = (count($guest_orders) > 1 && $idx > 0) ? 'display: none;' : 'display: block;';
                            ?>
                                <div class="pbr-order-card" data-order-id="<?= htmlspecialchars($gord['id']) ?>" style="<?= $initial_display ?>">
                                    <div class="pbr-order-header">
                                        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                            <span class="pbr-order-pill">#<?= htmlspecialchars($gord['id']) ?></span>
                                            <span class="pbr-order-status-badge <?= $badge_class ?>">
                                                <i class="<?= $badge_icon ?>"></i> <?= $badge_label ?>
                                            </span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                            <div class="pbr-order-location">
                                                <i class="fa-solid fa-location-dot"></i>
                                                <?= htmlspecialchars($gord['kubo_location']) ?>
                                            </div>
                                            <?php if ($is_pending): ?>
                                                <button type="button" 
                                                        onclick="openCancelOrderModal('<?= htmlspecialchars($gord['id']) ?>', '<?= htmlspecialchars(addslashes($gord['kubo_location'])) ?>', '<?= htmlspecialchars(addslashes($gord['total_amount'] ?? '0')) ?>')" 
                                                        class="btn-cancel-pending-order"
                                                        title="Cancel this pending order"
                                                        style="background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; border-radius: 6px; padding: 0.22rem 0.6rem; font-size: 0.74rem; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: all 0.2s ease;">
                                                    <i class="fa-solid fa-ban"></i> Cancel Order
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Serve Stepper Progress -->
                                    <div class="stepper pbr-dining-stepper">
                                        <div class="pbr-stepper-track">
                                            <div class="pbr-stepper-progress" style="width: <?= $progress_pct ?>%;"></div>
                                        </div>

                                        <!-- 1. Received -->
                                        <div class="step-item pbr-step-item <?= $s1_state ?>">
                                            <div class="step-icon pbr-step-circle">
                                                <?php if (str_contains($s1_state, 'completed')): ?>
                                                    <i class="fa-solid fa-check"></i>
                                                <?php elseif ($is_cancelled): ?>
                                                    <i class="fa-solid fa-xmark"></i>
                                                <?php else: ?>
                                                    <i class="fa-regular fa-clock fa-spin"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="step-label pbr-step-title">Received</div>
                                            <span class="pbr-step-sub"><?= $s1_sub ?></span>
                                        </div>

                                        <!-- 2. Preparing -->
                                        <div class="step-item pbr-step-item <?= $s2_state ?>">
                                            <div class="step-icon pbr-step-circle">
                                                <?php if ($s2_state === 'completed'): ?>
                                                    <i class="fa-solid fa-check"></i>
                                                <?php else: ?>
                                                    <i class="fa-solid fa-fire <?= ($s2_state === 'active') ? 'fa-beat' : '' ?>"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="step-label pbr-step-title">Preparing</div>
                                            <span class="pbr-step-sub"><?= $s2_sub ?></span>
                                        </div>

                                        <!-- 3. Serving -->
                                        <div class="step-item pbr-step-item <?= $s3_state ?>">
                                            <div class="step-icon pbr-step-circle">
                                                <?php if ($s3_state === 'completed'): ?>
                                                    <i class="fa-solid fa-check"></i>
                                                <?php else: ?>
                                                    <i class="fa-solid fa-person-walking-luggage <?= ($s3_state === 'active') ? 'fa-bounce' : '' ?>"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="step-label pbr-step-title">Serving</div>
                                            <span class="pbr-step-sub"><?= $s3_sub ?></span>
                                        </div>

                                        <!-- 4. Served -->
                                        <div class="step-item pbr-step-item <?= $s4_state ?>">
                                            <div class="step-icon pbr-step-circle">
                                                <i class="fa-solid <?= $s4_state ? 'fa-circle-check' : 'fa-check' ?>"></i>
                                            </div>
                                            <div class="step-label pbr-step-title">Served</div>
                                            <span class="pbr-step-sub"><?= $s4_sub ?></span>
                                        </div>
                                    </div>

                                    <div class="pbr-order-meta">
                                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.25rem 0.5rem; margin-bottom: 0.25rem;">
                                            <span style="color: var(--text-muted);"><i class="fa-solid fa-clock"></i> Serve Time Metric:</span>
                                            <strong style="color: var(--primary-purple); font-weight: 800;"><?= htmlspecialchars($gord['serve_duration_text']) ?></strong>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.25rem 0.5rem;">
                                            <span style="color: var(--text-muted);"><i class="fa-solid fa-bell"></i> Requested Serving:</span>
                                            <strong style="color: var(--text-main);"><?= htmlspecialchars($gord['requested_serve_time']) ?></strong>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.25rem 0.5rem; margin-top: 0.3rem; padding-top: 0.3rem; border-top: 1px solid #f8fafc;">
                                            <span style="color: var(--text-muted);"><i class="fa-solid fa-wallet"></i> Payment (₱<?= number_format($gord['total_amount'] ?? 0, 2) ?>):</span>
                                            <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                <?php 
                                                $pm = strtolower($gord['payment_method'] ?? 'cash');
                                                $ps = strtolower($gord['payment_status'] ?? 'unpaid');
                                                if ($pm === 'gcash'): 
                                                    if ($ps === 'paid' || $ps === 'verified'): ?>
                                                        <span class="badge" style="background: #dcfce7; color: #15803d; font-size: 0.72rem; padding: 0.2rem 0.5rem; border-radius: 4px;"><i class="fa-solid fa-circle-check"></i> GCash Paid</span>
                                                    <?php elseif ($ps === 'proof_submitted'): ?>
                                                        <span class="badge" style="background: #fef3c7; color: #b45309; font-size: 0.72rem; padding: 0.2rem 0.5rem; border-radius: 4px;"><i class="fa-solid fa-hourglass-half"></i> GCash Proof Under Review</span>
                                                    <?php else: ?>
                                                        <span class="badge" style="background: #fee2e2; color: #dc2626; font-size: 0.72rem; padding: 0.2rem 0.5rem; border-radius: 4px;"><i class="fa-solid fa-triangle-exclamation"></i> GCash Unpaid</span>
                                                        <a href="payment_method.php?ref=<?= urlencode($gord['id']) ?>" class="btn-pay-gcash-link" style="background: #0284c7; color: #fff; font-size: 0.72rem; font-weight: 700; padding: 0.2rem 0.55rem; border-radius: 4px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                                            <i class="fa-solid fa-mobile-screen"></i> Pay GCash
                                                        </a>
                                                    <?php endif; 
                                                elseif ($pm === 'charge_to_room'): ?>
                                                    <span class="badge" style="background: #ede9fe; color: #6d28d9; font-size: 0.72rem; padding: 0.2rem 0.5rem; border-radius: 4px;"><i class="fa-solid fa-key"></i> Room Charged</span>
                                                <?php else: 
                                                    if ($ps === 'paid' || $ps === 'verified'): ?>
                                                        <span class="badge" style="background: #dcfce7; color: #15803d; font-size: 0.72rem; padding: 0.2rem 0.5rem; border-radius: 4px;"><i class="fa-solid fa-circle-check"></i> Cash Paid</span>
                                                    <?php else: ?>
                                                        <span class="badge" style="background: #f3f4f6; color: #374151; font-size: 0.72rem; padding: 0.2rem 0.5rem; border-radius: 4px;"><i class="fa-solid fa-money-bill-wave"></i> Cash on Delivery</span>
                                                    <?php endif; 
                                                endif; ?>
                                            </div>
                                        </div>
                                        <?php if (!empty($gord['items_parsed'])): ?>
                                            <div style="margin-top: 0.35rem; padding-top: 0.35rem; border-top: 1px solid #f1f5f9; font-size: 0.76rem; color: #475569;">
                                                <i class="fa-solid fa-utensils" style="color: #7c3aed; margin-right: 4px;"></i>
                                                <strong>Ordered:</strong>
                                                <?php 
                                                $item_strings = [];
                                                foreach ($gord['items_parsed'] as $it) {
                                                    $item_strings[] = ($it['qty'] ?? 1) . 'x ' . htmlspecialchars($it['name'] ?? 'Item');
                                                }
                                                echo implode(', ', $item_strings);
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($gord['guest_allergies'])): ?>
                                            <div style="margin-top: 0.35rem; padding-top: 0.35rem; border-top: 1px dashed #fecdd3; color: #b91c1c; font-size: 0.76rem; line-height: 1.35;">
                                                <strong><i class="fa-solid fa-shield-cat"></i> Allergens Flagged:</strong> <?= htmlspecialchars($gord['guest_allergies']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($is_pending): ?>
                                            <div style="margin-top: 0.45rem; padding-top: 0.45rem; border-top: 1px dashed #fcd34d; display: flex; justify-content: space-between; align-items: center; font-size: 0.74rem;">
                                                <span style="color: #92400e;"><i class="fa-regular fa-clock"></i> Still Pending &amp; can be cancelled</span>
                                                <button type="button" 
                                                        onclick="openCancelOrderModal('<?= htmlspecialchars($gord['id']) ?>', '<?= htmlspecialchars(addslashes($gord['kubo_location'])) ?>', '<?= htmlspecialchars(addslashes($gord['total_amount'] ?? '0')) ?>')" 
                                                        style="background: none; border: none; color: #dc2626; font-weight: 800; text-decoration: underline; cursor: pointer; padding: 0; font-size: 0.74rem;">
                                                    Cancel This Order
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                 
                </div>

                <!-- Panel: Selected Order Cart Preview -->
                <div class="card" id="cartBasketCard" style="background: #ffffff; overflow: visible;">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--purple-light); padding-bottom: 0.5rem; margin-bottom: 0.75rem;">
                        <h3 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin: 0; font-size: 1.15rem; display: flex; align-items: center; gap: 0.45rem;">
                            <i class="fa-solid fa-basket-shopping" style="color: var(--bamboo-gold);"></i> My Order Basket
                        </h3>
                        <span id="cartCountBadge" style="display: none; background: #f5f3ff; color: #7c3aed; border: 1px solid #ddd6fe; border-radius: 12px; font-size: 0.72rem; font-weight: 800; padding: 0.15rem 0.55rem;"></span>
                    </div>

                    <div id="cartEmptyState" style="text-align: center; padding: 1.75rem 1rem; color: var(--text-muted);">
                        <i class="fa-solid fa-utensils" style="font-size: 2.2rem; margin-bottom: 0.5rem; color: var(--border-color);"></i>
                        <p style="font-size: 0.88rem; margin-bottom: 0.25rem;">Your dining basket is currently empty.</p>
                        <small style="color: var(--text-muted); font-size: 0.78rem;">Click "Add to Order" on any food or drink item to get started.</small>
                    </div>

                    <div id="cartContainer" style="display: none;">
                        <div id="cartItemsList" style="margin-bottom: 0.75rem; max-height: 140px; overflow-y: auto; padding-right: 4px; scrollbar-width: thin;">
                            <!-- Dynamic Cart Items via JS -->
                        </div>

                        <div style="border-top: 1.5px dashed var(--border-color); padding-top: 0.65rem; margin-bottom: 0.75rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center; font-weight: 800; font-size: 1.05rem; color: var(--primary-purple);">
                                <span>Total Payable:</span>
                                <span id="cartTotalDisplay" style="color: var(--bamboo-gold); font-size: 1.25rem; font-weight: 900;">₱0.00</span>
                            </div>
                        </div>

                        <button type="button" 
                                id="btnConfirmOrderCheckout" 
                                class="btn btn-purple" 
                                style="width: 100%; font-weight: 800; font-size: 0.95rem; padding: 0.85rem 1rem; border-radius: 10px; display: flex; align-items: center; justify-content: center; gap: 8px; background: linear-gradient(135deg, #7c3aed 0%, #9333ea 100%) !important; color: #ffffff !important; border: none !important; box-shadow: 0 4px 14px rgba(124, 58, 237, 0.4); cursor: pointer; transition: all 0.2s ease;" 
                                onclick="openCheckoutModal()">
                            <i class="fa-solid fa-circle-check" style="color: #ffffff;"></i> Confirm Order &amp; Proceed to Safety Check
                        </button>
                    </div>
                </div>

            </div>

        </div>
    </div>

    <!-- ORDER CHECKOUT & ALLERGY SELECTION MODAL -->
    <div class="modal-overlay" id="checkoutModal">
        <div class="modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--purple-light); padding-bottom: 0.75rem; margin-bottom: 1.25rem;">
                <h2 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin: 0;">
                    <i class="fa-solid fa-shield-heart" style="color: var(--bamboo-gold);"></i> Complete Dining Order & Allergy Safety
                </h2>
                <button type="button" style="background: none; border: none; font-size: 1.4rem; cursor: pointer; color: var(--text-muted);" onclick="closeCheckoutModal()">&times;</button>
            </div>

            <form action="dining.php" method="POST" id="diningOrderForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="place_order">
                <input type="hidden" name="items_json" id="formItemsJson">
                <input type="hidden" name="total_amount" id="formTotalAmount">

                <!-- SECTION 1: SMART ALLERGEN CROSS-CHECK WARNING ALERT -->
                <div id="allergyConflictAlert" style="display: none; background: #fff1f2; border: 2px solid #f43f5e; border-radius: var(--radius-sm); padding: 1rem; margin-bottom: 1.25rem; color: #9f1239;">
                    <h4 style="margin-top: 0; margin-bottom: 0.4rem; font-family: 'Outfit', sans-serif; color: #be123c;">
                        <i class="fa-solid fa-triangle-exclamation" style="font-size: 1.2rem;"></i> ALLERGY SAFETY WARNING ALERT DETECTED
                    </h4>
                    <p style="font-size: 0.88rem; margin-bottom: 0.4rem;" id="allergyConflictText"></p>
                    <small style="color: #9f1239; font-weight: 600;">Please verify below if kitchen staff should substitute ingredients or prepare dish using separate allergen-free cookware.</small>
                </div>

                <!-- SECTION 2: GUEST ALLERGY SELECTION -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: var(--radius-sm); padding: 1.25rem; margin-bottom: 1.25rem;">
                    <h3 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); font-size: 1.05rem; margin-bottom: 0.5rem;">
                        <i class="fa-solid fa-notes-medical" style="color: #ef4444;"></i> Select Guest Food & Drinks Allergies:
                    </h3>
                    <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 0.75rem;">Check all applicable food or drink allergies so the chef team prepares your meal safely.</p>

                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 0.6rem;">
                        <label style="font-size: 0.85rem; display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                            <input type="checkbox" name="guest_allergies[]" value="Nuts / Peanuts" class="allergy-check" onchange="runAllergenCrossCheck()" <?= in_array('Nuts / Peanuts', $default_allergies) ? 'checked' : '' ?>>
                            <span>🥜 Nuts / Peanuts</span>
                        </label>
                        <label style="font-size: 0.85rem; display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                            <input type="checkbox" name="guest_allergies[]" value="Seafood / Shellfish" class="allergy-check" onchange="runAllergenCrossCheck()" <?= (in_array('Seafood / Shellfish', $default_allergies) || in_array('Seafood', $default_allergies)) ? 'checked' : '' ?>>
                            <span>🦐 Seafood / Shellfish</span>
                        </label>
                        <label style="font-size: 0.85rem; display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                            <input type="checkbox" name="guest_allergies[]" value="Dairy" class="allergy-check" onchange="runAllergenCrossCheck()" <?= (in_array('Dairy', $default_allergies) || in_array('Lactose', $default_allergies)) ? 'checked' : '' ?>>
                            <span>🥛 Lactose / Dairy</span>
                        </label>
                        <label style="font-size: 0.85rem; display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                            <input type="checkbox" name="guest_allergies[]" value="Gluten" class="allergy-check" onchange="runAllergenCrossCheck()" <?= in_array('Gluten', $default_allergies) ? 'checked' : '' ?>>
                            <span>🌾 Gluten</span>
                        </label>
                        <label style="font-size: 0.85rem; display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                            <input type="checkbox" name="guest_allergies[]" value="Eggs" class="allergy-check" onchange="runAllergenCrossCheck()" <?= in_array('Eggs', $default_allergies) ? 'checked' : '' ?>>
                            <span>🥚 Eggs</span>
                        </label>
                        <label style="font-size: 0.85rem; display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                            <input type="checkbox" name="guest_allergies[]" value="Soy" class="allergy-check" onchange="runAllergenCrossCheck()" <?= in_array('Soy', $default_allergies) ? 'checked' : '' ?>>
                            <span>🫘 Soy</span>
                        </label>
                    </div>

                    <div style="margin-top: 0.85rem;">
                        <label style="font-size: 0.85rem; font-weight: 700; color: var(--text-dark);"><i class="fa-solid fa-comment-medical"></i> Special Kitchen Preparation Notes / Dietary Instructions:</label>
                        <textarea name="allergy_notes" class="form-control" rows="2" placeholder="e.g. Cook without butter, use soy milk substitute, severe peanut contact sensitivity..."><?= htmlspecialchars($guest_health_info) ?></textarea>
                    </div>
                </div>

                <!-- SECTION 3: SERVING TIME & LOCATION -->
                <div class="dining-modal-grid form-row-2col">
                    <div class="form-group">
                        <label><i class="fa-solid fa-location-dot"></i> Delivery Location *</label>
                        <select name="kubo_location" id="kuboLocationSelect" class="form-control" required>
                            <?php if ($user_active_room): ?>
                                <option value="Room Service: <?= htmlspecialchars($assigned_kubo) ?>" selected>
                                    🏨 Room Service: <?= htmlspecialchars($assigned_kubo) ?> (My Kubo)
                                </option>
                            <?php endif; ?>
                            <option value="Main Bamboo Dining Pavilion" <?= !$user_active_room ? 'selected' : '' ?>>Main Bamboo Dining Pavilion (Dining Area)</option>
                            <option value="Poolside Lounge Cabana">Poolside Lounge Cabana (Lounge Cabana)</option>
                        </select>
                        <?php if ($user_active_room): ?>
                            <small style="color: #6d28d9; font-size: 0.76rem; margin-top: 4px; display: block;">
                                <i class="fa-solid fa-circle-check"></i> In-house guest verified for <strong><?= htmlspecialchars($assigned_kubo) ?></strong>.
                            </small>
                        <?php else: ?>
                            <small style="color: #64748b; font-size: 0.76rem; margin-top: 4px; display: block;">
                                <i class="fa-solid fa-circle-info"></i> Delivering to resort public areas. (Kubo room service requires an active checked-in stay).
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label><i class="fa-solid fa-stopwatch"></i> Requested Serving Time *</label>
                        <select name="requested_serve_time" id="serveTimeSelect" class="form-control" required onchange="document.getElementById('otherTimeGroup').style.display = (this.value === 'Others') ? 'block' : 'none'; document.getElementById('otherTimeInput').required = (this.value === 'Others');">
                            <option value="ASAP (15-20 Mins)">ASAP (Prepare immediately ~ 15-20 Mins)</option>
                            <option value="12:00 PM (Lunch)">12:00 PM (Lunch Schedule)</option>
                            <option value="1:30 PM">1:30 PM</option>
                            <option value="6:00 PM (Dinner)">6:00 PM (Dinner Schedule)</option>
                            <option value="7:30 PM">7:30 PM (Evening Drinks)</option>
                            <option value="Others">Others</option>
                        </select>
                        <div id="otherTimeGroup" style="display: none; margin-top: 0.75rem;">
                            <input type="time" name="other_serve_time" id="otherTimeInput" class="form-control">
                        </div>
                    </div>
                </div>

                <!-- SECTION 4: GUEST CONTACT INFO -->
                <div class="dining-modal-grid form-row-2col">
                    <div class="form-group">
                        <label><i class="fa-solid fa-user"></i> Guest Name</label>
                        <input type="text" name="guest_name" class="form-control name-only-input" value="<?= htmlspecialchars($guest_name) ?>" required pattern="^[A-Za-z\s\-\.ñÑ']+$" title="Guest name must only contain letters, spaces, and hyphens (no numbers allowed)">
                    </div>
                    <div class="form-group">
                        <label><i class="fa-solid fa-phone"></i> Mobile Contact Phone</label>
                        <input type="tel" name="guest_phone" class="form-control mobile-09-input" value="<?= htmlspecialchars($guest_phone) ?>" required maxlength="11" pattern="^09[0-9]{9}$" inputmode="numeric" title="Phone number must start with 09 and contain exactly 11 digits (e.g. 09171234567). Numbers only, no letters.">
                    </div>
                </div>

                <!-- SECTION 5: PAYMENT METHOD SELECTION -->
                <div style="background: #fdf4ff; border: 1.5px solid #e9d5ff; border-radius: var(--radius-sm); padding: 1.25rem; margin-bottom: 1.25rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 0.5rem;">
                        <h3 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); font-size: 1.05rem; margin: 0;">
                            <i class="fa-solid fa-credit-card" style="color: var(--primary-purple);"></i> Payment Method *
                        </h3>
                        <div style="font-size: 0.88rem; color: #6b21a8; font-weight: 700;">
                            Total Payable: <span id="diningPaymentTotalDisplay" style="color: #7c3aed; font-size: 1.05rem; font-weight: 900;">₱0.00</span>
                        </div>
                    </div>
                    <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 0.85rem;">
                        Select how you wish to pay for your dining order.
                        <?= $user_active_room ? 'You can charge to your room folio, pay via GCash, or pay cash upon food delivery.' : 'You can pay via GCash or pay cash upon food delivery.' ?>
                    </p>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.75rem; margin-bottom: 1rem;">
                        <!-- Cash on Delivery -->
                        <label id="diningLabelCash" style="border: 2px solid #7c3aed; background: #faf5ff; border-radius: 8px; padding: 0.85rem 0.75rem; cursor: pointer; display: flex; flex-direction: column; gap: 4px; transition: all 0.2s;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <input type="radio" name="payment_method" value="cash" checked onchange="toggleDiningPaymentView('cash')" style="accent-color: #7c3aed;">
                                <strong style="font-size: 0.9rem; color: #1e293b;"><i class="fa-solid fa-money-bill-wave" style="color: #10b981;"></i> Cash on Delivery</strong>
                            </div>
                            <span style="font-size: 0.76rem; color: #64748b; margin-left: 24px;">Pay upon serving at your location</span>
                        </label>

                        <!-- GCash Online Transfer -->
                        <label id="diningLabelGcash" style="border: 2px solid #e2e8f0; background: #ffffff; border-radius: 8px; padding: 0.85rem 0.75rem; cursor: pointer; display: flex; flex-direction: column; gap: 4px; transition: all 0.2s;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <input type="radio" name="payment_method" value="gcash" onchange="toggleDiningPaymentView('gcash')" style="accent-color: #0284c7;">
                                <strong style="font-size: 0.9rem; color: #1e293b;"><i class="fa-solid fa-mobile-screen" style="color: #0284c7;"></i> GCash Transfer</strong>
                            </div>
                            <span style="font-size: 0.76rem; color: #64748b; margin-left: 24px;">Send via GCash &amp; submit receipt</span>
                        </label>

                        <?php if ($user_active_room): ?>
                            <!-- Charge to Room (Only for checked-in guests) -->
                            <label id="diningLabelRoom" style="border: 2px solid #e2e8f0; background: #ffffff; border-radius: 8px; padding: 0.85rem 0.75rem; cursor: pointer; display: flex; flex-direction: column; gap: 4px; transition: all 0.2s;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <input type="radio" name="payment_method" value="charge_to_room" onchange="toggleDiningPaymentView('charge_to_room')" style="accent-color: #7c3aed;">
                                    <strong style="font-size: 0.9rem; color: #1e293b;"><i class="fa-solid fa-key" style="color: #7c3aed;"></i> Charge to Room</strong>
                                </div>
                                <span style="font-size: 0.76rem; color: #64748b; margin-left: 24px;">Bill to <?= htmlspecialchars($assigned_kubo) ?></span>
                            </label>
                        <?php else: ?>
                            <!-- Disabled room charge indicator for non-staying guests -->
                            <label style="border: 1.5px dashed #cbd5e1; background: #f8fafc; border-radius: 8px; padding: 0.85rem 0.75rem; opacity: 0.65; cursor: not-allowed; display: flex; flex-direction: column; gap: 4px;" title="Charge to Room is only available to guests currently checked in.">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <input type="radio" name="payment_method" value="charge_to_room" disabled style="cursor: not-allowed;">
                                    <strong style="font-size: 0.9rem; color: #94a3b8;"><i class="fa-solid fa-lock" style="color: #94a3b8;"></i> Charge to Room</strong>
                                </div>
                                <span style="font-size: 0.74rem; color: #94a3b8; margin-left: 24px;">Checked-in guests only</span>
                            </label>
                        <?php endif; ?>
                    </div>

                    <!-- SUB-VIEW: GCash Transfer Details & Proof Upload -->
                    <div id="diningGcashDetails" style="display: none; background: #ffffff; border: 1.5px solid #bae6fd; border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem;">
                        <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px dashed #e2e8f0; padding-bottom: 0.65rem; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
                            <div>
                                <span style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #0284c7; font-weight: 800;">Official Resort GCash Account</span>
                                <div style="font-family: 'Outfit', sans-serif; font-size: 1.25rem; font-weight: 900; color: #0369a1; letter-spacing: 0.05em;">
                                    <i class="fa-solid fa-mobile-screen-button"></i> 0956 697 8679
                                </div>
                                <div style="font-size: 0.8rem; color: #475569; font-weight: 600;">PURPLE BAMBOO RESORT</div>
                            </div>
                            <div style="text-align: right;">
                                <span style="font-size: 0.75rem; color: #64748b; display: block;">Resort QR Code:</span>
                                <a href="assets/gcash_qr.jpg" target="_blank" style="font-size: 0.78rem; color: #0284c7; font-weight: 700; text-decoration: underline; display: inline-flex; align-items: center; gap: 4px;">
                                    <i class="fa-solid fa-qrcode"></i> View / Scan QR
                                </a>
                            </div>
                        </div>

                        <div class="dining-modal-grid form-row-2col">
                            <div class="form-group" style="margin-bottom: 0.5rem;">
                                <label style="font-size: 0.82rem; font-weight: 700; color: #0f172a;">
                                    <i class="fa-solid fa-receipt" style="color: #0284c7;"></i> GCash Reference No. (Optional now):
                                </label>
                                <input type="text" name="gcash_ref" id="diningGcashRefInput" class="form-control" placeholder="13-digit ref (e.g. 1002345678901)" maxlength="13" pattern="^[0-9]{13}$" title="GCash Reference must be 13 digits if provided.">
                                <small style="color: #64748b; font-size: 0.72rem;">13 digits from your GCash confirmation SMS or screenshot.</small>
                            </div>

                            <div class="form-group" style="margin-bottom: 0.5rem;">
                                <label style="font-size: 0.82rem; font-weight: 700; color: #0f172a;">
                                    <i class="fa-solid fa-cloud-arrow-up" style="color: #0284c7;"></i> Upload Payment Screenshot / Receipt:
                                </label>
                                <input type="file" name="proof_image" id="diningProofInput" class="form-control" accept="image/png,image/jpeg,image/webp" style="padding: 0.35rem 0.5rem; font-size: 0.8rem;">
                                <small style="color: #64748b; font-size: 0.72rem;">Accepted formats: JPG, PNG, WEBP (Max 5MB).</small>
                            </div>
                        </div>
                        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 0.45rem 0.75rem; font-size: 0.76rem; color: #166534; margin-top: 0.5rem;">
                            <i class="fa-solid fa-circle-info"></i> <strong>Tip:</strong> You can submit the order now and complete or re-upload your GCash payment proof anytime from the tracker or booking history.
                        </div>
                    </div>

                    <?php if ($user_active_room): ?>
                        <!-- SUB-VIEW: Charge to Room Details (Checked-in guests only) -->
                        <div id="diningRoomDetails" style="display: none; background: #ffffff; border: 1.5px solid #ddd6fe; border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="width: 36px; height: 36px; border-radius: 50%; background: #f3e8ff; color: #7c3aed; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0;">
                                    <i class="fa-solid fa-bed"></i>
                                </div>
                                <div>
                                    <div style="font-size: 0.85rem; font-weight: 800; color: #4c1d95;">In-House Guest Verified</div>
                                    <div style="font-size: 0.8rem; color: #475569;">
                                        Stay Accommodation: <strong><?= htmlspecialchars($assigned_kubo) ?></strong> (Booking Ref #<?= htmlspecialchars($user_active_room['reference_number'] ?? $user_active_room['id']) ?>)
                                    </div>
                                    <div style="font-size: 0.74rem; color: #6d28d9; margin-top: 2px;">
                                        Order total will be charged to your <?= htmlspecialchars($assigned_kubo) ?> folio and settled upon checkout.
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="display: flex; gap: 0.75rem; margin-top: 1rem;">
                    <button type="button" class="btn btn-outline" style="flex: 1;" onclick="closeCheckoutModal()">Back to Menu</button>
                    <button type="submit" class="btn btn-gold" style="flex: 2; font-weight: 700;">
                        <i class="fa-solid fa-check-circle"></i> Confirm & Submit Dining Order
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let cart = [];
        try {
            <?php if (!empty($order_success)): ?>
            sessionStorage.removeItem('pbr_dining_cart');
            <?php else: ?>
            const savedCart = sessionStorage.getItem('pbr_dining_cart');
            if (savedCart) {
                cart = JSON.parse(savedCart);
            }
            <?php endif; ?>
        } catch (e) {}

        function addToCart(id, name, price, allergens, btnElement = null) {
            let existing = cart.find(i => i.id === id);
            if (existing) {
                existing.qty++;
            } else {
                cart.push({ id: id, name: name, price: price, allergens: allergens, qty: 1 });
            }
            renderCart();
            
            // Bring cart card into view so the confirm button is immediately visible
            if (window.innerWidth > 992) {
                const cartCard = document.getElementById('cartBasketCard');
                if (cartCard) {
                    cartCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
            
            if (btnElement) {
                const originalHtml = btnElement.innerHTML;
                btnElement.innerHTML = '<i class="fa-solid fa-check"></i> Added!';
                btnElement.style.backgroundColor = '#10b981';
                btnElement.style.borderColor = '#059669';
                btnElement.style.color = '#fff';
                setTimeout(() => {
                    btnElement.innerHTML = originalHtml;
                    btnElement.style.backgroundColor = '';
                    btnElement.style.borderColor = '';
                    btnElement.style.color = '';
                }, 1000);
            }
        }

        function updateQty(id, delta) {
            let item = cart.find(i => i.id === id);
            if (item) {
                item.qty += delta;
                if (item.qty <= 0) {
                    cart = cart.filter(i => i.id !== id);
                }
            }
            renderCart();
        }
        function removeFromCart(id) {
        cart = cart.filter(i => i.id !== id);
        renderCart();
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function renderCart() {
            const container = document.getElementById('cartContainer');
            const emptyState = document.getElementById('cartEmptyState');
            const list = document.getElementById('cartItemsList');
            const totalDisplay = document.getElementById('cartTotalDisplay');
            const countBadge = document.getElementById('cartCountBadge');

            if (cart.length === 0) {
                container.style.display = 'none';
                emptyState.style.display = 'block';
                if (countBadge) countBadge.style.display = 'none';
                return;
            }

            emptyState.style.display = 'none';
            container.style.display = 'block';

            let html = '';
            let total = 0;
            let totalQty = 0;

            cart.forEach(item => {
                let sub = item.price * item.qty;
                total += sub;
                totalQty += item.qty;
                html += `
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem; padding: 0.45rem 0.25rem; border-bottom: 1px solid #f1f5f9; font-size: 0.88rem; gap: 0.4rem;">
                        <div style="flex-grow: 1; min-width: 0; padding-right: 0.35rem;">
                            <div style="font-weight: 700; color: var(--primary-purple); line-height: 1.25; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${escapeHtml(item.name)}">${escapeHtml(item.name)}</div>
                            <div style="font-size: 0.78rem; color: var(--text-muted);">₱${item.price.toFixed(2)} each</div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.3rem; flex-shrink: 0;">
                            <button type="button" class="btn btn-outline btn-sm" style="padding: 0.1rem 0.35rem; font-size: 0.75rem; min-width: 20px; height: 24px; line-height: 1;" onclick="updateQty(${item.id}, -1)">-</button>
                            <span style="font-weight: 700; width: 16px; text-align: center; font-size: 0.85rem;">${item.qty}</span>
                            <button type="button" class="btn btn-outline btn-sm" style="padding: 0.1rem 0.35rem; font-size: 0.75rem; min-width: 20px; height: 24px; line-height: 1;" onclick="updateQty(${item.id}, 1)">+</button>
                            <span style="font-weight: 800; min-width: 55px; text-align: right; color: var(--bamboo-gold); font-size: 0.88rem;">₱${sub.toFixed(2)}</span>
                            <button type="button" onclick="removeFromCart(${item.id})" title="Remove item" style="border: none; background: #fee2e2; color: #ef4444; width: 24px; height: 24px; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font-size: 0.72rem; margin-left: 0.15rem;">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </div>
                    </div>
                `;
            });

            list.innerHTML = html;
            totalDisplay.innerText = '₱' + total.toFixed(2);
            if (countBadge) {
                countBadge.innerText = totalQty + (totalQty === 1 ? ' item' : ' items');
                countBadge.style.display = 'inline-block';
            }

            try {
                sessionStorage.setItem('pbr_dining_cart', JSON.stringify(cart));
            } catch (e) {}

            // Update floating mobile cart bar
            const mobileBar = document.getElementById('mobileCartBar');
            if (mobileBar) {
                if (cart.length > 0 && window.innerWidth <= 992) {
                    mobileBar.style.display = 'flex';
                    const countElem = document.getElementById('mobileCartCount');
                    const totElem = document.getElementById('mobileCartTotal');
                    if (countElem) countElem.innerText = totalQty;
                    if (totElem) totElem.innerText = '₱' + total.toFixed(2);
                } else {
                    mobileBar.style.display = 'none';
                }
            }
        }

        function openCancelOrderModal(orderId, location, total) {
            document.getElementById('cancelModalOrderIdInput').value = orderId;
            document.getElementById('cancelModalOrderIdDisplay').innerText = '#' + orderId;
            document.getElementById('cancelModalLocationDisplay').innerText = location || 'Resort Kubo';
            document.getElementById('cancelModalTotalDisplay').innerText = '₱' + parseFloat(total || 0).toFixed(2);
            const m = document.getElementById('cancelOrderModal');
            if (m) m.style.display = 'flex';
        }

        function closeCancelOrderModal() {
            const m = document.getElementById('cancelOrderModal');
            if (m) m.style.display = 'none';
        }

        function openCheckoutModal() {
            if (cart.length === 0) return;
            
            document.getElementById('formItemsJson').value = JSON.stringify(cart);
            let total = cart.reduce((sum, i) => sum + (i.price * i.qty), 0);
            document.getElementById('formTotalAmount').value = total;

            const pTot = document.getElementById('diningPaymentTotalDisplay');
            if (pTot) pTot.innerText = '₱' + total.toFixed(2);

            const checkedRadio = document.querySelector('input[name="payment_method"]:checked');
            toggleDiningPaymentView(checkedRadio ? checkedRadio.value : 'cash');

            runAllergenCrossCheck();
            document.getElementById('checkoutModal').classList.add('open');
        }

        function toggleDiningPaymentView(method) {
            const gcashBox = document.getElementById('diningGcashDetails');
            const roomBox = document.getElementById('diningRoomDetails');
            const lCash = document.getElementById('diningLabelCash');
            const lGcash = document.getElementById('diningLabelGcash');
            const lRoom = document.getElementById('diningLabelRoom');

            if (gcashBox) gcashBox.style.display = (method === 'gcash') ? 'block' : 'none';
            if (roomBox) roomBox.style.display = (method === 'charge_to_room') ? 'block' : 'none';

            if (lCash) {
                lCash.style.borderColor = (method === 'cash') ? '#7c3aed' : '#e2e8f0';
                lCash.style.background = (method === 'cash') ? '#faf5ff' : '#ffffff';
            }
            if (lGcash) {
                lGcash.style.borderColor = (method === 'gcash') ? '#0284c7' : '#e2e8f0';
                lGcash.style.background = (method === 'gcash') ? '#f0f9ff' : '#ffffff';
            }
            if (lRoom) {
                lRoom.style.borderColor = (method === 'charge_to_room') ? '#7c3aed' : '#e2e8f0';
                lRoom.style.background = (method === 'charge_to_room') ? '#faf5ff' : '#ffffff';
            }
        }

        function closeCheckoutModal() {
            document.getElementById('checkoutModal').classList.remove('open');
        }

        function runAllergenCrossCheck() {
            const selectedChecks = Array.from(document.querySelectorAll('.allergy-check:checked')).map(cb => cb.value.toLowerCase());
            const alertBox = document.getElementById('allergyConflictAlert');
            const alertText = document.getElementById('allergyConflictText');

            let conflicts = [];

            cart.forEach(item => {
                if (item.allergens) {
                    let itemAlgList = item.allergens.toLowerCase().split(',').map(s => s.trim());
                    selectedChecks.forEach(userAlg => {
                        let cleanUserAlg = userAlg.split('/')[0].trim();
                        itemAlgList.forEach(itemAlg => {
                            if (itemAlg.includes(cleanUserAlg) || cleanUserAlg.includes(itemAlg)) {
                                conflicts.push(`<strong>${item.name}</strong> contains <em>${item.allergens}</em> which matches your <strong>${userAlg}</strong> allergy.`);
                            }
                        });
                    });
                }
            });

            if (conflicts.length > 0) {
                alertText.innerHTML = conflicts.join('<br>');
                alertBox.style.display = 'block';
            } else {
                alertBox.style.display = 'none';
            }
        }

        function openOrderConfirmModal() {
            const modal = document.getElementById('orderConfirmModal');
            if (modal) modal.style.display = 'flex';
        }

        function closeOrderConfirmModal() {
            const modal = document.getElementById('orderConfirmModal');
            if (modal) modal.style.display = 'none';
        }

        function submitDiningOrder() {
            try { sessionStorage.removeItem('pbr_dining_cart'); } catch(e) {}
            const form = document.getElementById('diningOrderForm');
            form.dataset.confirmed = 'true';
            form.submit();
        }

        document.getElementById('diningOrderForm').addEventListener('submit', function(e) {
            if (!this.dataset.confirmed) {
                e.preventDefault();
                openOrderConfirmModal();
            }
        });
        let tempAddItem = null;

        function confirmAddToCart(id, name, price, allergens, btnElement) {
            tempAddItem = { id, name, price, allergens, btnElement };
            document.getElementById('addItemConfirmName').innerText = name;
            const modal = document.getElementById('addItemConfirmModal');
            if (modal) modal.style.display = 'flex';
        }

        function closeAddItemConfirmModal() {
            const modal = document.getElementById('addItemConfirmModal');
            if (modal) modal.style.display = 'none';
            tempAddItem = null;
        }

        function executeAddToCart() {
            if (tempAddItem) {
                addToCart(tempAddItem.id, tempAddItem.name, tempAddItem.price, tempAddItem.allergens, tempAddItem.btnElement);
                closeAddItemConfirmModal();
            }
        }
        function switchTrackerOrder(orderId, btn) {
            document.querySelectorAll('.pbr-order-tab-btn').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.pbr-order-card').forEach(card => {
                if (orderId === 'all') {
                    card.style.display = 'block';
                } else {
                    card.style.display = (card.getAttribute('data-order-id') === orderId) ? 'block' : 'none';
                }
            });
        }

        function scrollToCartOrCheckout() {
            const cartCard = document.getElementById('cartBasketCard');
            if (cartCard) {
                cartCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        window.addEventListener('resize', function() {
            const mobileBar = document.getElementById('mobileCartBar');
            if (mobileBar) {
                mobileBar.style.display = (cart.length > 0 && window.innerWidth <= 992) ? 'flex' : 'none';
            }
        });

        // Initialize cart display from storage on page load
        renderCart();
    </script>

    <!-- Sticky Mobile Floating Cart Bar -->
    <div id="mobileCartBar" class="dining-mobile-cart-bar" style="display: none;" onclick="scrollToCartOrCheckout()">
        <div style="display: flex; align-items: center; gap: 8px;">
            <span class="mobile-cart-badge" id="mobileCartCount">0</span>
            <span style="font-size: 0.88rem; font-weight: 700;">Cart: <strong id="mobileCartTotal" style="color: #fde047;">₱0.00</strong></span>
        </div>
        <button type="button" class="btn btn-gold btn-sm" style="font-weight: 800; padding: 0.35rem 0.85rem; border-radius: 20px;">
            View Basket <i class="fa-solid fa-arrow-down"></i>
        </button>
    </div>

    <!-- Add Item Confirmation Modal Dialog -->
    <div id="addItemConfirmModal" class="logout-modal-backdrop" style="display: none;" onclick="if(event.target === this) closeAddItemConfirmModal();">
        <div class="logout-modal-dialog">
            <div class="logout-modal-icon" style="background: rgba(14, 165, 233, 0.15); color: #0ea5e9; border-color: rgba(14, 165, 233, 0.35);">
                <i class="fa-solid fa-cart-plus"></i>
            </div>
            <h3 class="logout-modal-title">Add to Order?</h3>
            <p class="logout-modal-text">Are you sure you want to add <strong id="addItemConfirmName" style="color: var(--primary-purple);"></strong> to your dining order?</p>
            <div class="logout-modal-actions">
                <button type="button" class="logout-modal-btn logout-modal-btn-cancel" onclick="closeAddItemConfirmModal()">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </button>
                <button type="button" class="logout-modal-btn logout-modal-btn-confirm" style="background: linear-gradient(135deg, #0ea5e9, #0284c7); border-color: #0284c7;" onclick="executeAddToCart()">
                    <i class="fa-solid fa-check"></i> Yes, Add
                </button>
            </div>
        </div>
    </div>

    <!-- Order Confirmation Modal Dialog -->
    <div id="orderConfirmModal" class="logout-modal-backdrop" style="display: none;" onclick="if(event.target === this) closeOrderConfirmModal();">
        <div class="logout-modal-dialog">
            <div class="logout-modal-icon" style="background: rgba(234, 179, 8, 0.15); color: #eab308; border-color: rgba(234, 179, 8, 0.35);">
                <i class="fa-solid fa-bell-concierge"></i>
            </div>
            <h3 class="logout-modal-title">Confirm Your Order</h3>
            <p class="logout-modal-text">Are you sure you want to submit this dining order to the kitchen?</p>
            <div class="logout-modal-actions">
                <button type="button" class="logout-modal-btn logout-modal-btn-cancel" onclick="closeOrderConfirmModal()">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </button>
                <button type="button" class="logout-modal-btn logout-modal-btn-confirm" style="background: linear-gradient(135deg, #eab308, #ca8a04); border-color: #ca8a04;" onclick="submitDiningOrder()">
                    <i class="fa-solid fa-check"></i> Yes, Order
                </button>
            </div>
        </div>
    </div>

    <!-- Cancel Order Confirmation Modal Dialog -->
    <div id="cancelOrderModal" class="logout-modal-backdrop" style="display: none;" onclick="if(event.target === this) closeCancelOrderModal();">
        <div class="logout-modal-dialog" style="max-width: 440px;">
            <div class="logout-modal-icon" style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border-color: rgba(239, 68, 68, 0.35);">
                <i class="fa-solid fa-ban"></i>
            </div>
            <h3 class="logout-modal-title" style="color: #b91c1c;">Cancel Pending Order?</h3>
            <p class="logout-modal-text" style="margin-bottom: 0.75rem;">Are you sure you want to cancel order <strong id="cancelModalOrderIdDisplay" style="color: var(--primary-purple);"></strong>?</p>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.65rem 0.85rem; margin-bottom: 1.25rem; font-size: 0.82rem; text-align: left; color: #475569;">
                <div><i class="fa-solid fa-location-dot" style="color: #0284c7;"></i> Location: <strong id="cancelModalLocationDisplay">...</strong></div>
                <div style="margin-top: 0.25rem;"><i class="fa-solid fa-receipt" style="color: #7c3aed;"></i> Total Amount: <strong id="cancelModalTotalDisplay" style="color: #059669;">₱0.00</strong></div>
                <div style="margin-top: 0.35rem; color: #b91c1c; font-size: 0.75rem;">
                    <i class="fa-solid fa-circle-info"></i> Cancellation is only possible while status is still Pending.
                </div>
            </div>
            <form action="dining.php" method="POST" id="cancelOrderForm">
                <input type="hidden" name="action" value="cancel_order">
                <input type="hidden" name="order_id" id="cancelModalOrderIdInput" value="">
                <div class="logout-modal-actions">
                    <button type="button" class="logout-modal-btn logout-modal-btn-cancel" onclick="closeCancelOrderModal()">
                        <i class="fa-solid fa-xmark"></i> Keep Order
                    </button>
                    <button type="submit" class="logout-modal-btn" style="background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; border: 1px solid #dc2626;">
                        <i class="fa-solid fa-ban"></i> Yes, Cancel Order
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php include 'footer.php'; ?>

</body>
</html>
