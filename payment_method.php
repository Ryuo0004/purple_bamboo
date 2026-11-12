<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/booking_store.php';
require_once __DIR__ . '/dining_store.php';

// Verify that user is logged in
if (empty($_SESSION['user_id'])) {
    $ref_param = !empty($_GET['ref']) ? '?redirect=' . urlencode('payment_method.php?ref=' . $_GET['ref']) : '';
    header("Location: login.php" . $ref_param);
    exit();
}

$ref = trim($_GET['ref'] ?? ($_POST['ref_no'] ?? ''));
$is_dining = false;
$dining_order = null;

if (!empty($ref) && (str_starts_with($ref, 'PBR-FOOD-') || str_contains($ref, 'FOOD'))) {
    $dining_order = DiningStore::getOrderById($ref);
    if ($dining_order) {
        $is_dining = true;
    }
}

if (!$is_dining) {
    if (empty($ref)) {
        // If no ref provided, try to find the user's latest booking
        $user_id = $_SESSION['user_id'];
        $user_email = strtolower(trim($_SESSION['email'] ?? ''));
        $all_bookings = BookingStore::getAllBookings();
        foreach ($all_bookings as $b) {
            if (($b['user_id'] ?? null) == $user_id || strtolower($b['guest_email'] ?? '') === $user_email) {
                $ref = $b['id'];
                break;
            }
        }
    }

    $booking = !empty($ref) ? BookingStore::getBookingById($ref) : null;
    if (!$booking && !empty($ref)) {
        $dining_order = DiningStore::getOrderById($ref);
        if ($dining_order) {
            $is_dining = true;
        }
    }
}

if ($is_dining && $dining_order) {
    $d_items = is_string($dining_order['order_items_json']) ? json_decode($dining_order['order_items_json'], true) : $dining_order['order_items_json'];
    $item_names = [];
    if (is_array($d_items)) {
        foreach ($d_items as $di) {
            $item_names[] = ($di['qty'] ?? 1) . 'x ' . ($di['name'] ?? $di['item_name'] ?? 'Item');
        }
    }
    $d_title = !empty($item_names) ? implode(', ', $item_names) : 'Resort Dining Order';

    $booking = [
        'id' => $dining_order['id'],
        'type' => 'dining',
        'item_name' => 'Resort Dining: ' . $d_title,
        'kubo_location' => $dining_order['kubo_location'] ?? 'Beach Kubo',
        'check_in' => $dining_order['created_at'],
        'check_out' => $dining_order['created_at'],
        'time_slot' => $dining_order['requested_serve_time'] ?? 'ASAP',
        'total_amount' => $dining_order['total_amount'],
        'payment_status' => $dining_order['payment_status'] ?? 'unpaid',
        'booking_status' => $dining_order['order_status'] ?? 'pending',
        'gcash_ref' => $dining_order['gcash_ref'] ?? null,
        'payment_proof' => $dining_order['payment_proof'] ?? null,
        'guest_name' => $dining_order['guest_name'],
        'guest_email' => $dining_order['guest_email'],
        'guest_phone' => $dining_order['guest_phone'],
        'user_id' => $dining_order['user_id']
    ];
}

// Security: If booking exists, ensure user owns the booking or is admin/staff
$user_role = $_SESSION['role'] ?? 'guest';
$is_admin = ($user_role === 'admin' || $user_role === 'staff');

if ($booking && !$is_admin) {
    $b_user_id = $booking['user_id'] ?? null;
    $b_email = strtolower(trim($booking['guest_email'] ?? ''));
    $curr_email = strtolower(trim($_SESSION['email'] ?? ''));
    $curr_id = $_SESSION['user_id'] ?? 0;

    if ($b_user_id != $curr_id && $b_email !== $curr_email) {
        // Cannot access another user's booking payment
        header("Location: user-dashboard.php");
        exit();
    }
}

$success_msg = '';
$error_msg = '';

// Handle payment proof submission
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['submit_payment'])) {
    $gref = trim($_POST['gcash_ref'] ?? '');
    $ref_no = trim($_POST['ref_no'] ?? $ref);

    // Validate GCash Reference Number format
    $clean_gref = preg_replace('/[^0-9]/', '', $gref);
    $clean_gref = substr($clean_gref, 0, 13);
    if (empty($clean_gref) || strlen($clean_gref) !== 13) {
        $error_msg = 'Please enter a valid 13-digit GCash Reference Number (e.g. 1002938475612).';
    } elseif (empty($_FILES['proof_image']) || $_FILES['proof_image']['error'] !== UPLOAD_ERR_OK) {
        $error_msg = 'Please upload a clear screenshot or photo of your official GCash transaction receipt.';
    } else {
        $upload_dir = __DIR__ . '/uploads/receipts/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_info = pathinfo($_FILES['proof_image']['name']);
        $ext = strtolower($file_info['extension'] ?? 'jpg');
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];

        if (!in_array($ext, $allowed)) {
            $error_msg = 'Only image files (JPG, PNG, WEBP) are allowed as valid payment proof.';
        } else {
            $safe_ref = preg_replace('/[^A-Za-z0-9_-]/', '', $ref_no);
            $new_filename = 'receipt_' . $safe_ref . '_' . time() . '.' . $ext;
            $target_file = $upload_dir . $new_filename;

            if (move_uploaded_file($_FILES['proof_image']['tmp_name'], $target_file)) {
                $proof_path = 'uploads/receipts/' . $new_filename;
                if ($is_dining) {
                    global $pdo;
                    if ($pdo) {
                        try {
                            $stmt = $pdo->prepare("UPDATE dining_orders SET payment_method = 'gcash', payment_status = 'proof_submitted', gcash_ref = :gref, payment_proof = :proof WHERE id = :id");
                            $stmt->execute([':gref' => $clean_gref, ':proof' => $proof_path, ':id' => $ref_no]);
                        } catch (Exception $e) {}
                    }
                    $json_orders = DiningStore::loadOrdersFromJson();
                    foreach ($json_orders as &$jo) {
                        if (($jo['id'] ?? '') === $ref_no) {
                            $jo['payment_method'] = 'gcash';
                            $jo['payment_status'] = 'proof_submitted';
                            $jo['gcash_ref'] = $clean_gref;
                            $jo['payment_proof'] = $proof_path;
                        }
                    }
                    @file_put_contents(DiningStore::getJsonFilePath(), json_encode($json_orders, JSON_PRETTY_PRINT));
                    if (function_exists('log_activity')) {
                        log_activity("DINING_PAYMENT_SUBMITTED", "GCash payment proof submitted for Dining Order #{$ref_no}");
                    }
                    $dining_order = DiningStore::getOrderById($ref_no);
                    $booking['payment_status'] = 'proof_submitted';
                    $booking['gcash_ref'] = $clean_gref;
                    $booking['payment_proof'] = $proof_path;
                } else {
                    BookingStore::resubmitPaymentProof($ref_no, $clean_gref, $proof_path);
                    // Refresh booking details
                    $booking = BookingStore::getBookingById($ref_no);
                }
                $success_msg = 'Your GCash payment proof has been submitted successfully! Our team is currently verifying it for immediate confirmation.';
            } else {
                $error_msg = 'There was an issue uploading your receipt. Please try again.';
            }
        }
    }
}

$payment_status = strtolower($booking['payment_status'] ?? 'pending');
$booking_status = strtolower($booking['booking_status'] ?? 'pending');
$is_already_paid = ($payment_status === 'verified');
$has_submitted_proof = (!empty($booking['gcash_ref']) && !empty($booking['payment_proof']) && $booking['payment_proof'] !== 'uploads/receipts/sample_receipt.jpg') || ($payment_status === 'proof_submitted' && !empty($booking['gcash_ref']));
$payment_is_submitted = ($has_submitted_proof || $is_already_paid || $payment_status === 'proof_submitted' || $payment_status === 'verified' || (!empty($booking['gcash_ref']) && !empty($booking['payment_proof'])));

// Determine Step 2 (Dates & Details) Back Navigation URL
$step2_back_url = 'browse_kubos.php';
if ($booking) {
    $b_type = strtolower($booking['type'] ?? 'house');
    $b_item_id = intval($booking['item_id'] ?? 1);
    $b_ref = $booking['id'] ?? $ref;
    $b_cin = !empty($booking['check_in']) ? substr($booking['check_in'], 0, 10) : '';
    $b_cout = !empty($booking['check_out']) ? substr($booking['check_out'], 0, 10) : '';

    if ($b_type === 'activity') {
        $step2_back_url = "book_activities.php?activity_id=" . urlencode($b_item_id) . "&ref=" . urlencode($b_ref);
    } else {
        $step2_params = ['id' => $b_item_id, 'ref' => $b_ref];
        if (!empty($b_cin)) $step2_params['check_in'] = $b_cin;
        if (!empty($b_cout)) $step2_params['check_out'] = $b_cout;
        $step2_back_url = "book_house.php?" . http_build_query($step2_params);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Method: GCash Payment | Purple Bamboo Resort</title>
    <link rel="icon" type="image/jpeg" href="assets/logo.jpg">
    <link rel="stylesheet" href="style.css">
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
            color: var(--primary-purple);
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
            background: var(--primary-purple);
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

        @media (max-width: 1024px) and (min-width: 769px) {
            .step-indicator { gap: 0.85rem; margin-bottom: 1.75rem; }
            .step-item { font-size: 0.82rem; gap: 0.45rem; }
            .step-num { width: 26px; height: 26px; min-width: 26px; font-size: 0.76rem; }
        }
        @media (max-width: 768px) and (min-width: 641px) {
            .step-indicator { gap: 0.65rem; margin-bottom: 1.5rem; }
            .step-item { font-size: 0.78rem; gap: 0.4rem; }
            .step-num { width: 25px; height: 25px; min-width: 25px; font-size: 0.72rem; }
            .step-text-full { display: none; }
            .step-text-short { display: inline; }
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
            .step-item:not(.active) .step-label {
                display: none !important;
            }
            .step-item:not(.active) { flex: 0 0 auto !important; padding: 0 !important; }
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
            .step-item.active .step-label {
                display: inline-flex !important;
                white-space: nowrap !important;
                font-size: 0.8rem !important;
                font-weight: 800 !important;
                color: var(--primary-purple) !important;
            }
            .step-item.active .step-text-full { display: inline !important; }
            .step-item.active .step-text-short { display: none !important; }
            .step-num {
                width: 26px !important;
                height: 26px !important;
                min-width: 26px !important;
                max-width: 26px !important;
                font-size: 0.74rem !important;
            }
            .step-divider { font-size: 0.65rem !important; color: #cbd5e1 !important; margin: 0 !important; }
        }
        @media (max-width: 380px) {
            .step-indicator { gap: 0.35rem !important; }
            .step-item.active .step-text-full { display: none !important; }
            .step-item.active .step-text-short { display: inline !important; }
            .step-item.active { padding: 2px 8px 2px 2px !important; }
            .step-item.active .step-label { font-size: 0.75rem !important; }
        }
        .gcash-card {
            background: linear-gradient(135deg, #005ce6 0%, #003db3 100%);
            border-radius: 16px;
            color: #ffffff;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0, 92, 230, 0.25);
            position: relative;
            overflow: hidden;
        }
        .gcash-card::after {
            content: 'GCash';
            position: absolute;
            right: -15px;
            bottom: -20px;
            font-size: 5rem;
            font-weight: 900;
            opacity: 0.08;
            color: #fff;
            pointer-events: none;
            letter-spacing: -2px;
        }
        .qr-box {
            background: #ffffff;
            border-radius: 12px;
            padding: 1.25rem;
            text-align: center;
            display: inline-block;
            box-shadow: 0 4px 15px rgba(0,0,0,0.12);
        }
        .qr-image {
            width: 190px;
            height: auto;
            border-radius: 8px;
            display: block;
            margin: 0 auto;
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        .qr-image:hover {
            transform: scale(1.03);
        }
        .account-badge {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 10px;
            padding: 0.85rem 1.25rem;
            margin-top: 1.25rem;
        }
        .amount-highlight {
            font-size: 2rem;
            font-weight: 900;
            color: #fbbf24;
            letter-spacing: 0.5px;
            font-family: 'Outfit', sans-serif;
        }
        .lightbox-modal {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(6px);
            z-index: 99999;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .lightbox-modal.active {
            display: flex;
        }
        .lightbox-content {
            background: #ffffff;
            border-radius: 14px;
            max-width: 480px;
            width: 100%;
            overflow: hidden;
            box-shadow: 0 20px 50px rgba(0,0,0,0.5);
            animation: modalFadeIn 0.25s ease-out;
            position: relative;
        }
        @keyframes modalFadeIn {
            from { transform: scale(0.92); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .payment-layout-grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 2rem;
            align-items: start;
        }
        @media (max-width: 860px) {
            .payment-layout-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
        }
        /* Mobile View Refinements (Screens <= 640px) */
        @media (max-width: 640px) {
            .payment-page-container {
                padding: 1.25rem 0.65rem 3.5rem 0.65rem !important;
            }
            .payment-header-block h1 {
                font-size: 1.45rem !important;
                line-height: 1.25 !important;
            }
            .payment-header-block p {
                font-size: 0.85rem !important;
            }
            .gcash-card {
                padding: 1.25rem 1rem !important;
            }
            .gcash-card::after {
                font-size: 3.2rem !important;
                bottom: -10px !important;
                right: -10px !important;
            }
            .qr-box {
                padding: 0.85rem !important;
            }
            .qr-image {
                width: 155px !important;
            }
            .account-badge {
                padding: 0.75rem 0.85rem !important;
            }
            .account-badge-number-row {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 6px !important;
            }
            .account-badge strong {
                font-size: 1.1rem !important;
            }
            .payable-summary-card {
                padding: 1.15rem 1rem !important;
            }
            .summary-header-row {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 0.75rem !important;
            }
            .summary-header-row > div:last-child {
                text-align: left !important;
                width: 100% !important;
                display: flex !important;
                align-items: center !important;
                gap: 8px !important;
            }
            .summary-total-row {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 0.4rem !important;
            }
            .amount-highlight {
                font-size: 1.6rem !important;
            }
            .submission-status-card {
                padding: 1.25rem 1rem !important;
            }
            .proof-actions-row,
            .verified-actions-row,
            .alert-actions-row {
                flex-direction: column !important;
                gap: 0.5rem !important;
            }
            .proof-actions-row .btn,
            .verified-actions-row .btn,
            .alert-actions-row .btn {
                width: 100% !important;
                min-height: 44px !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                font-size: 0.88rem !important;
            }
            .lightbox-modal {
                padding: 0.75rem !important;
            }
            .lightbox-content {
                max-height: 90vh !important;
                overflow-y: auto !important;
                padding: 1.25rem 1rem !important;
            }
        }
    </style>
</head>
<body>

    <?php include 'nav.php'; ?>

    <div class="container payment-page-container" style="max-width: 1060px; padding: 2.5rem 1rem 4rem 1rem;">
        
        <!-- Reservation Flow Progress Tracker -->
        <div class="step-indicator">
            <a href="javascript:void(0)" onclick="handleBackNavigation()" class="step-item done" style="text-decoration: none; color: inherit; cursor: pointer;" title="Back to Previous Step">
                <span class="step-num"><i class="fa-solid fa-check"></i></span>
                <span class="step-label">
                    <span class="step-text-full">Choose Accommodation</span>
                    <span class="step-text-short">Choose</span>
                </span>
            </a>
            <div class="step-divider"><i class="fa-solid fa-chevron-right"></i></div>
            <a href="javascript:void(0)" onclick="handleBackNavigation()" class="step-item done" style="text-decoration: none; color: inherit; cursor: pointer;" title="Back to Dates & Details">
                <span class="step-num"><i class="fa-solid fa-check"></i></span>
                <span class="step-label">
                    <span class="step-text-full">Dates &amp; Details</span>
                    <span class="step-text-short">Dates</span>
                </span>
            </a>
            <div class="step-divider"><i class="fa-solid fa-chevron-right"></i></div>
            <?php $is_step_4 = ($payment_is_submitted && $payment_status !== 'rejected'); ?>
            <div class="step-item <?= $is_step_4 ? 'done' : 'active' ?>">
                <span class="step-num"><?= $is_step_4 ? '<i class="fa-solid fa-check"></i>' : '3' ?></span>
                <span class="step-label">
                    <span class="step-text-full">Payment Method (GCash)</span>
                    <span class="step-text-short">Payment</span>
                </span>
            </div>
            <div class="step-divider"><i class="fa-solid fa-chevron-right"></i></div>
            <div class="step-item <?= $is_step_4 ? 'active' : '' ?>">
                <span class="step-num"><?= $is_already_paid ? '<i class="fa-solid fa-check"></i>' : '4' ?></span>
                <span class="step-label">
                    <span class="step-text-full">Confirmation</span>
                    <span class="step-text-short">Confirm</span>
                </span>
            </div>
        </div>

        <div class="page-header payment-header-block" style="margin-bottom: 2rem;">
            <div>
                <?php if ($is_step_4): ?>
                    <span class="badge badge-success" style="margin-bottom: 0.4rem;"><i class="fa-solid fa-clipboard-check"></i> Reservation Processing</span>
                    <h1 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin: 0.2rem 0;">Reservation Confirmation</h1>
                    <p style="color: var(--text-muted); font-size: 0.95rem;">Your payment proof has been submitted and is under review. Please see your travel guide below.</p>
                <?php else: ?>
                    <span class="badge badge-gold" style="margin-bottom: 0.4rem;"><i class="fa-solid fa-wallet"></i> Secured Payment Gateway</span>
                    <h1 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin: 0.2rem 0;">GCash Express Payment</h1>
                    <p style="color: var(--text-muted); font-size: 0.95rem;">Pay directly using GCash for instant confirmation of your resort reservation.</p>
                <?php endif; ?>
            </div>
            <div>
            </div>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div style="background: rgba(16, 185, 129, 0.12); color: #047857; padding: 1.25rem 1.5rem; border-radius: 12px; margin-bottom: 2rem; border: 1.5px solid #6ee7b7; display: flex; align-items: flex-start; gap: 12px;">
                <i class="fa-solid fa-circle-check" style="font-size: 1.6rem; color: #10b981; margin-top: 2px;"></i>
                <div>
                    <strong style="font-size: 1.05rem; display: block; margin-bottom: 0.25rem;">Payment Proof Submitted!</strong>
                    <span><?= htmlspecialchars($success_msg) ?></span>
                    <div class="alert-actions-row" style="margin-top: 0.75rem; display: flex; gap: 0.75rem; flex-wrap: wrap;">
                        <a href="user-dashboard.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-user-circle"></i> View in My Dashboard</a>
                        <a href="view_email.php?ref=<?= urlencode($booking['id'] ?? $ref) ?>" target="_blank" class="btn btn-outline btn-sm"><i class="fa-solid fa-file-invoice"></i> Open Booking Voucher</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_msg)): ?>
            <div style="background: #fee2e2; color: #b91c1c; padding: 1.1rem 1.4rem; border-radius: 10px; margin-bottom: 2rem; border: 1.5px solid #fca5a5; display: flex; align-items: center; gap: 10px; font-weight: 700;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 1.3rem;"></i>
                <div><?= htmlspecialchars($error_msg) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!$booking): ?>
            <div class="card" style="text-align: center; padding: 3rem;">
                <i class="fa-solid fa-calendar-xmark" style="font-size: 3rem; color: var(--primary-purple); margin-bottom: 1rem;"></i>
                <h2>No Reservation Found</h2>
                <p style="color: var(--text-muted); margin-bottom: 1.5rem;">Could not locate the booking reference number for this payment.</p>
                <a href="browse_kubos.php" class="btn btn-primary"><i class="fa-solid fa-house-chimney"></i> Browse Accommodations</a>
            </div>
        <?php else: ?>

            <div class="payment-layout-grid">
                
                <!-- Left Column: Official Resort GCash Details & QR -->
                <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                    
                    <div class="gcash-card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="width: 44px; height: 44px; background: #ffffff; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fa-solid fa-mobile-screen-button" style="color: #005ce6; font-size: 1.4rem;"></i>
                                </div>
                                <div>
                                    <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 800; letter-spacing: 1px; opacity: 0.9;">Official Payment Channel</span>
                                    <h2 style="font-family: 'Outfit', sans-serif; font-size: 1.4rem; margin: 0; color: #fff;">GCash Merchant QR</h2>
                                </div>
                            </div>
                            <span style="background: rgba(255,255,255,0.22); padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase;">
                                <i class="fa-solid fa-shield-halved"></i> Verified
                            </span>
                        </div>

                        <div style="display: flex; flex-direction: column; align-items: center; margin-bottom: 1.5rem;">
                            <div class="qr-box">
                                <img src="assets/gcash_qr.jpg" alt="Official GCash QR Code" class="qr-image" onclick="openQrModal()">
                                <div style="margin-top: 0.6rem; font-size: 0.78rem; font-weight: 700; color: #005ce6; cursor: pointer;" onclick="openQrModal()">
                                    <i class="fa-solid fa-magnifying-glass-plus"></i> Click to enlarge QR
                                </div>
                            </div>
                        </div>

                        <div class="account-badge">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                <span style="font-size: 0.8rem; opacity: 0.85;">Account Name:</span>
                                <strong style="font-size: 0.95rem; letter-spacing: 0.5px;">PURPLE BAMBOO RESORT</strong>
                            </div>
                            <div class="account-badge-number-row" style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-size: 0.8rem; opacity: 0.85;">GCash Number:</span>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <strong style="font-size: 1.25rem; font-family: monospace; letter-spacing: 1px; color: #fbbf24;">0956 697 8679</strong>
                                    <button type="button" onclick="copyGcashNumber()" style="background: rgba(255,255,255,0.25); border: none; color: #fff; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; cursor: pointer;" title="Copy number">
                                        <i class="fa-solid fa-copy"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Step By Step Instructions -->
                        <div style="margin-top: 1.5rem; background: rgba(0, 0, 0, 0.15); border-radius: 10px; padding: 1.1rem 1.25rem;">
                            <div style="font-weight: 800; font-size: 0.88rem; margin-bottom: 0.6rem; color: #fef08a;">
                                <i class="fa-solid fa-list-ol"></i> Payment Steps:
                            </div>
                            <ol style="margin: 0; padding-left: 1.2rem; font-size: 0.85rem; line-height: 1.6; opacity: 0.95;">
                                <li>Open your <strong>GCash App</strong> on your mobile phone.</li>
                                <li>Select <strong>Scan QR</strong> or <strong>Send Money &rarr; Express Send</strong> to <code>0956 697 8679</code>.</li>
                                <li>Enter the exact amount due: <strong>₱<?= number_format(floatval($booking['total_amount']), 2) ?></strong></li>
                                <li>Save or take a screenshot of your official <strong>GCash Transaction Receipt</strong>.</li>
                                <li>Copy the <strong>13-digit Reference Number</strong> and upload the receipt image in the form on the right.</li>
                            </ol>
                        </div>
                    </div>

                    <!-- Stay Summary Mini Card -->
                    <div class="card" style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 1.25rem 1.5rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem;">
                            <span style="font-size: 0.78rem; font-weight: 800; color: #64748b; text-transform: uppercase;">
                                <i class="fa-solid fa-id-card"></i> Registered Guest Account
                            </span>
                            <span class="badge badge-purple" style="font-size: 0.72rem;">
                                <i class="fa-solid fa-user-check"></i> <?= htmlspecialchars($_SESSION['profile_id'] ?? 'Registered Guest') ?>
                            </span>
                        </div>
                        <div style="font-size: 0.92rem; line-height: 1.6;">
                            <div>Name: <strong><?= htmlspecialchars($booking['guest_name']) ?></strong></div>
                            <div>Email: <strong><?= htmlspecialchars($booking['guest_email']) ?></strong></div>
                            <div>Mobile: <strong><?= htmlspecialchars($booking['guest_phone']) ?></strong></div>
                        </div>
                    </div>

                </div>

                <!-- Right Column: Amount Payable & Payment Proof Submission Form -->
                <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                    
                    <!-- Payable Summary Card -->
                    <div class="card payable-summary-card" style="background: #faf5ff; border: 2px solid var(--purple-accent); border-radius: 14px; padding: 1.5rem;">
                        <div class="summary-header-row" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; border-bottom: 1px dashed #d8b4fe; padding-bottom: 1rem;">
                            <div>
                                <span class="badge badge-gold" style="font-size: 0.75rem; margin-bottom: 0.3rem;">
                                    Ref #<?= htmlspecialchars($booking['id']) ?>
                                </span>
                                <h3 style="margin: 0.2rem 0; font-family: 'Outfit', sans-serif; color: var(--primary-purple); font-size: 1.25rem;">
                                    <?= htmlspecialchars($booking['item_name']) ?>
                                </h3>
                                <div style="display: flex; flex-direction: column; gap: 3px; margin-top: 4px;">
                                    <?php if (!empty($is_dining)): ?>
                                        <span style="font-size: 0.82rem; color: #475569; font-weight: 600;">
                                            <i class="fa-solid fa-location-dot" style="color: #7c3aed;"></i> Delivery: <?= htmlspecialchars($booking['kubo_location'] ?? 'Beach Kubo') ?>
                                        </span>
                                        <span style="font-size: 0.78rem; color: #b45309; font-weight: 700;">
                                            <i class="fa-regular fa-clock"></i> Schedule: <?= htmlspecialchars($booking['time_slot'] ?? 'ASAP') ?> &bull; Order Date: <?= date('M d, Y', strtotime($booking['check_in'])) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="font-size: 0.82rem; color: #475569; font-weight: 600;">
                                            <i class="fa-regular fa-calendar"></i> <?= date('M d, Y', strtotime($booking['check_in'])) ?> 
                                            <?php if (!empty($booking['check_out']) && $booking['check_out'] !== $booking['check_in']): ?>
                                                &rarr; <?= date('M d, Y', strtotime($booking['check_out'])) ?>
                                            <?php endif; ?>
                                        </span>
                                        <?php
                                            $disp_in_time = !empty($booking['check_in']) && strlen($booking['check_in']) > 10 ? date('g:i A', strtotime($booking['check_in'])) : '2:00 PM';
                                            $disp_out_time = !empty($booking['check_out']) && strlen($booking['check_out']) > 10 ? date('g:i A', strtotime($booking['check_out'])) : '12:00 PM';
                                        ?>
                                        <span style="font-size: 0.78rem; display: inline-flex; align-items: center; gap: 8px;">
                                            <span style="color: #b45309; font-weight: 700;"><i class="fa-regular fa-clock"></i> In: <?= $disp_in_time ?></span>
                                            <span style="color: #047857; font-weight: 700;"><i class="fa-regular fa-clock"></i> Out: <?= $disp_out_time ?></span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <span style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Status:</span><br>
                                <?php if ($is_already_paid): ?>
                                    <span class="badge badge-success"><i class="fa-solid fa-circle-check"></i> Verified Paid</span>
                                <?php elseif ($has_submitted_proof): ?>
                                    <span class="badge badge-gold"><i class="fa-solid fa-clock"></i> Awaiting Verification</span>
                                <?php else: ?>
                                    <span class="badge badge-pending"><i class="fa-solid fa-hourglass-start"></i> Awaiting Proof</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="summary-total-row" style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span style="font-size: 0.82rem; color: #64748b; font-weight: 700; text-transform: uppercase; display: block;">Total Amount Due:</span>
                                <span style="font-size: 0.8rem; color: var(--primary-purple);">Taxes and resort fees included</span>
                            </div>
                            <div class="amount-highlight" style="color: var(--primary-purple);">
                                ₱<?= number_format(floatval($booking['total_amount']), 2) ?>
                            </div>
                        </div>

                        <?php if (!empty($booking['addons']) && is_array($booking['addons'])): ?>
                            <div style="margin-top: 0.85rem; padding-top: 0.75rem; border-top: 1px dashed #d8b4fe;">
                                <div style="font-size: 0.78rem; font-weight: 800; color: #7c3aed; text-transform: uppercase; margin-bottom: 0.35rem;">
                                    <i class="fa-solid fa-water-ladder"></i> Included Pool &amp; Resort Add-ons:
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                                    <?php foreach ($booking['addons'] as $ba): 
                                        $paxCount = intval($ba['pax'] ?? $ba['qty'] ?? 1);
                                        $schedStr = '';
                                        if (!empty($ba['scheduled_date'])) {
                                            $schedStr = ' &mdash; ' . date('M d, Y', strtotime($ba['scheduled_date']));
                                            if (!empty($ba['time_slot'])) {
                                                $schedStr .= ' at ' . htmlspecialchars($ba['time_slot']);
                                            }
                                        }
                                    ?>
                                        <div style="display: flex; justify-content: space-between; font-size: 0.82rem; color: #475569;">
                                            <span>• <?= htmlspecialchars($ba['name']) ?> (<?= $paxCount ?> Pax<?= $schedStr ?>)</span>
                                            <strong style="color: #7c3aed;">₱<?= number_format(floatval($ba['subtotal']), 2) ?></strong>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: 0.85rem; padding-top: 0.75rem; border-top: 1px dashed #e2e8f0; font-size: 0.78rem; color: #64748b; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                            <span><i class="fa-solid fa-file-contract" style="color: var(--primary-purple);"></i> Governed by Resort <a href="terms.php" target="_blank" style="color: var(--primary-purple); font-weight: 700; text-decoration: underline;">Terms &amp; Agreement</a></span>
                            <span>Standard Check-In 2:00 PM / Check-Out 12:00 PM</span>
                        </div>
                    </div>

                    <!-- Submission / Status Form Card -->
                    <div class="card submission-status-card" style="border-radius: 14px; padding: 1.75rem; box-shadow: 0 4px 20px rgba(0,0,0,0.06);">
                        
                        <?php if ($is_already_paid): ?>
                            <!-- Verified State -->
                            <div style="text-align: center; padding: 1rem 0;">
                                <div style="width: 70px; height: 70px; background: #ecfdf5; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem;">
                                    <i class="fa-solid fa-circle-check" style="font-size: 2.5rem; color: #10b981;"></i>
                                </div>
                                <h3 style="color: #065f46; font-family: 'Outfit', sans-serif; margin-bottom: 0.4rem;">Payment Verified!</h3>
                                <p style="color: #64748b; font-size: 0.9rem; max-width: 400px; margin: 0 auto 1.5rem;">
                                    Your GCash payment has been successfully verified by our resort front desk. Your booking voucher is now ready for your stay.
                                </p>
                                <div style="background: #f8fafc; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; text-align: left; font-size: 0.88rem;">
                                    <p style="margin: 0 0 0.4rem 0;"><strong>GCash Reference #:</strong> <code style="color: #005ce6; font-weight: 800;"><?= htmlspecialchars($booking['gcash_ref'] ?? 'N/A') ?></code></p>
                                    <p style="margin: 0;"><strong>Booking Ref:</strong> <?= htmlspecialchars($booking['id']) ?></p>
                                </div>
                                <div class="verified-actions-row" style="display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap;">
                                    <a href="view_email.php?ref=<?= urlencode($booking['id']) ?>" target="_blank" class="btn btn-primary"><i class="fa-solid fa-print"></i> View Stay Voucher</a>
                                    <a href="user-dashboard.php" class="btn btn-outline"><i class="fa-solid fa-house-user"></i> My Dashboard</a>
                                </div>
                            </div>

                        <?php elseif ($has_submitted_proof && empty($error_msg) && !isset($_GET['reupload']) && $payment_status !== 'rejected'): ?>
                            <!-- Proof Submitted & Under Review State -->
                            <div>
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 1.25rem;">
                                    <div style="width: 40px; height: 40px; background: #fef3c7; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class="fa-solid fa-clock-rotate-left" style="color: #d97706; font-size: 1.2rem;"></i>
                                    </div>
                                    <div>
                                        <h3 style="margin: 0; font-family: 'Outfit', sans-serif; color: #92400e; font-size: 1.15rem;">Proof Under Verification</h3>
                                        <span style="font-size: 0.8rem; color: #b45309;">Payment proof submitted</span>
                                    </div>
                                </div>

                                <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 1rem; margin-bottom: 1.25rem; font-size: 0.88rem; color: #92400e;">
                                    <p style="margin: 0 0 0.5rem 0;">
                                        <strong>GCash Reference #:</strong> 
                                        <code style="font-family: monospace; font-weight: 800; font-size: 1.05rem; color: #005ce6; background: #ffffff; padding: 2px 6px; border-radius: 4px; border: 1px solid #fed7aa;">
                                             <?= htmlspecialchars($booking['gcash_ref'] ?? 'Pending') ?>
                                        </code>
                                    </p>
                                    <p style="margin: 0; font-size: 0.82rem;">
                                        Your booking status will automatically update once our front desk staff finishes reviewing your receipt.
                                    </p>
                                </div>

                                <?php if (!empty($booking['payment_proof'])): ?>
                                    <div style="text-align: center; border: 1.5px dashed #cbd5e1; border-radius: 10px; padding: 1rem; margin-bottom: 1.25rem;">
                                        <span style="font-size: 0.78rem; font-weight: 700; color: #64748b; display: block; margin-bottom: 0.5rem;">Uploaded Receipt Proof:</span>
                                        <img src="<?= htmlspecialchars($booking['payment_proof']) ?>" alt="Uploaded Receipt" style="max-height: 200px; max-width: 100%; object-fit: contain; border-radius: 6px; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.1);" onclick="openPictureModal('<?= htmlspecialchars($booking['payment_proof']) ?>', 'GCash Receipt - Ref #<?= htmlspecialchars($booking['id']) ?>')">
                                    </div>
                                <?php endif; ?>

                                <div class="proof-actions-row" style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                                    <a href="payment_method.php?ref=<?= urlencode($booking['id']) ?>&reupload=1" class="btn btn-outline btn-sm" style="flex: 1; text-align: center;">
                                        <i class="fa-solid fa-camera"></i> Upload New Receipt
                                    </a>
                                    <a href="user-dashboard.php" class="btn btn-primary btn-sm" style="flex: 1; text-align: center;">
                                        <i class="fa-solid fa-arrow-right"></i> Go to Dashboard
                                    </a>
                                </div>
                            </div>

                        <?php else: ?>
                            <!-- Upload Form for First Submission or Re-Upload after Rejection -->
                            <div>
                                <?php if ($payment_status === 'rejected'): ?>
                                    <!-- Rejection Banner with Reason -->
                                    <div style="background: #fef2f2; border: 1.5px solid #fecaca; border-radius: 12px; padding: 1.15rem 1.25rem; margin-bottom: 1.5rem; color: #7f1d1d; box-shadow: 0 2px 8px rgba(239, 68, 68, 0.08);">
                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 0.6rem; flex-wrap: wrap;">
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <div style="width: 36px; height: 36px; background: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #dc2626; font-size: 1.15rem; flex-shrink: 0;">
                                                    <i class="fa-solid fa-circle-xmark"></i>
                                                </div>
                                                <div>
                                                    <h3 style="margin: 0; font-family: 'Outfit', sans-serif; color: #991b1b; font-size: 1.15rem;">Previous Payment Proof Rejected</h3>
                                                    <span style="font-size: 0.8rem; color: #b91c1c;">Your previous receipt was not accepted by our resort team.</span>
                                                </div>
                                            </div>
                                            <span style="font-size: 0.72rem; background: #fee2e2; color: #b91c1c; padding: 3px 8px; border-radius: 4px; border: 1px solid #fca5a5; font-weight: 800;">ACTION REQUIRED</span>
                                        </div>

                                        <div style="background: #ffffff; border: 1px solid #fecaca; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 0.75rem; font-size: 0.88rem;">
                                            <strong style="color: #991b1b; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 2px;">
                                                <i class="fa-solid fa-circle-exclamation"></i> Rejection Reason from Front Desk:
                                            </strong>
                                            <div style="color: #b91c1c; font-weight: 700; font-size: 0.95rem;">
                                                <?= htmlspecialchars(!empty($booking['rejection_reason']) ? $booking['rejection_reason'] : (!empty($booking['prev_rejection_reason']) ? $booking['prev_rejection_reason'] : 'Proof could not be verified by resort staff.')) ?>
                                            </div>
                                        </div>

                                        <p style="margin: 0; font-size: 0.82rem; color: #7f1d1d; line-height: 1.45;">
                                            Please verify your transaction details, enter your new 13-digit GCash Reference Number, and upload a new clear screenshot of your payment receipt below.
                                        </p>
                                    </div>
                                <?php endif; ?>

                                <h3 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-top: 0; margin-bottom: 0.4rem; font-size: 1.25rem;">
                                    <i class="fa-solid fa-<?= ($payment_status === 'rejected') ? 'arrow-up-from-bracket' : 'upload' ?>"></i> 
                                    <?= ($payment_status === 'rejected') ? 'Resubmit New GCash Payment Proof' : 'Submit GCash Payment Proof' ?>
                                </h3>
                                <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.25rem;">
                                    <?= ($payment_status === 'rejected') ? 'Enter your new GCash confirmation details to resubmit for verification.' : 'Enter the transaction details from your GCash confirmation receipt.' ?>
                                </p>

                                <form action="payment_method.php?ref=<?= urlencode($booking['id']) ?>" method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="ref_no" value="<?= htmlspecialchars($booking['id']) ?>">
                                    <input type="hidden" name="submit_payment" value="1">

                                    <div class="form-group">
                                        <label for="inputGcashRef" style="font-weight: 700; font-size: 0.88rem; color: #1e293b;">
                                            <i class="fa-solid fa-receipt" style="color: #005ce6;"></i> 13-Digit GCash Reference Number *
                                        </label>
                                        <input type="text" id="inputGcashRef" name="gcash_ref" class="form-control" placeholder="e.g. 1002938475612 (13 digits)" value="<?= ($payment_status === 'rejected') ? '' : htmlspecialchars(substr(preg_replace('/[^0-9]/', '', $booking['gcash_ref'] ?? ''), 0, 13)) ?>" required maxlength="13" minlength="13" inputmode="numeric" pattern="[0-9]{13}" title="Please enter a 13-digit GCash reference number" autocomplete="off" onkeypress="return (event.key >= '0' && event.key <= '9');" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 13);" style="font-size: 1rem; font-family: monospace; letter-spacing: 0.5px;">
                                        <small style="color: #64748b; font-size: 0.75rem; margin-top: 4px; display: block;">Found at the top or bottom of your GCash receipt after sending.</small>
                                    </div>

                                    <div class="form-group" style="margin-top: 1.25rem;">
                                        <label for="proof_image_file" style="font-weight: 700; font-size: 0.88rem; color: #1e293b;">
                                            <i class="fa-solid fa-image" style="color: var(--primary-purple);"></i> Screenshot / Photo of GCash Receipt *
                                        </label>
                                        <input type="file" id="proof_image_file" name="proof_image" class="form-control" accept="image/*" required onchange="previewReceipt(this)">
                                        <small style="color: #64748b; font-size: 0.75rem; margin-top: 4px; display: block;">Make sure the Reference No., Date, and Amount are clearly legible.</small>

                                        <!-- Live Image Preview Card -->
                                        <div id="receiptPreviewBox" style="display: none; margin-top: 0.85rem; border: 1.5px solid #d8b4fe; border-radius: 10px; padding: 0.75rem; background: #faf5ff; text-align: center;">
                                            <img id="receiptPreviewImg" src="" alt="Receipt Preview" style="max-height: 220px; max-width: 100%; object-fit: contain; border-radius: 6px; box-shadow: 0 2px 6px rgba(0,0,0,0.15);">
                                            <div id="receiptFileName" style="margin-top: 0.5rem; font-size: 0.8rem; font-weight: 700; color: var(--primary-purple);"></div>
                                        </div>
                                    </div>

                                    <button type="submit" class="btn <?= ($payment_status === 'rejected') ? 'btn-primary' : 'btn-gold' ?> btn-lg" style="width: 100%; margin-top: 1.5rem; font-weight: 800; border: none; padding: 0.95rem; font-size: 1.05rem; <?= ($payment_status === 'rejected') ? 'background: linear-gradient(135deg, #7c3aed, #a855f7);' : '' ?> box-shadow: 0 4px 15px rgba(124, 58, 237, 0.35);">
                                        <i class="fa-solid fa-check-circle"></i> <?= ($payment_status === 'rejected') ? 'Resubmit GCash Payment Proof' : 'Submit GCash Payment Proof' ?>
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>

                    </div>

                </div>

            </div>

            <?php if ($payment_is_submitted && $payment_status !== 'rejected'): ?>
                <!-- ======================================================== -->
                <!-- RIDE & ARRIVAL GUIDE (UNLOCKED AFTER PAYMENT SUBMISSION) -->
                <!-- ======================================================== -->
                <?php include __DIR__ . '/includes/ride_guide_widget.php'; ?>
            <?php endif; ?>

        <?php endif; ?>

    </div>

    <!-- Enlarge QR Modal -->
    <div id="qrModal" class="lightbox-modal" onclick="if(event.target === this) closeQrModal()">
        <div class="lightbox-content" style="text-align: center; padding: 2rem;">
            <button type="button" onclick="closeQrModal()" style="position: absolute; right: 15px; top: 15px; background: none; border: none; font-size: 1.5rem; color: #64748b; cursor: pointer;">&times;</button>
            <h3 style="font-family: 'Outfit', sans-serif; color: #005ce6; margin-top: 0; margin-bottom: 0.5rem;">Official Resort GCash QR</h3>
            <p style="color: #64748b; font-size: 0.85rem; margin-bottom: 1.25rem;">Scan using your GCash app to transfer payment directly.</p>
            <img src="assets/gcash_qr.jpg" alt="Official GCash QR Large" style="width: 280px; height: auto; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); margin: 0 auto; display: block;">
            <div style="margin-top: 1.25rem; font-weight: 800; font-size: 1.1rem; color: #1e293b;">
                PURPLE BAMBOO RESORT
            </div>
            <div style="font-family: monospace; font-size: 1.3rem; color: #005ce6; font-weight: 800; margin-top: 0.25rem;">
                0956 697 8679
            </div>
            <button type="button" class="btn btn-outline btn-sm" onclick="closeQrModal()" style="margin-top: 1.25rem;">Close</button>
        </div>
    </div>

    <!-- Generic Picture Lightbox Modal -->
    <div id="pictureModal" class="lightbox-modal" onclick="if(event.target === this) closePictureModal()">
        <div class="lightbox-content" style="text-align: center; padding: 1.5rem; max-width: 600px;">
            <button type="button" onclick="closePictureModal()" style="position: absolute; right: 15px; top: 15px; background: none; border: none; font-size: 1.5rem; color: #64748b; cursor: pointer;">&times;</button>
            <h4 id="pictureModalTitle" style="margin-top: 0; color: var(--primary-purple); margin-bottom: 1rem;">Receipt Image</h4>
            <img id="pictureModalImg" src="" alt="Picture Preview" style="max-height: 70vh; max-width: 100%; object-fit: contain; border-radius: 8px;">
            <div style="margin-top: 1rem;">
                <button type="button" class="btn btn-outline btn-sm" onclick="closePictureModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Leave Payment Confirmation Modal -->
    <div id="leavePaymentModal" class="lightbox-modal" onclick="if(event.target === this) closeLeavePaymentModal()">
        <div class="lightbox-content" style="max-width: 440px; text-align: center; padding: 2rem 1.75rem; border-radius: 18px; box-shadow: 0 20px 45px rgba(0,0,0,0.35); position: relative;">
            <div style="width: 58px; height: 58px; background: #fef3c7; color: #d97706; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.65rem; margin: 0 auto 1.25rem;">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <h3 style="font-family: 'Outfit', sans-serif; font-size: 1.4rem; font-weight: 800; color: #1e1b4b; margin: 0 0 0.6rem 0;">
                Leave Payment?
            </h3>
            <p style="color: #64748b; font-size: 0.95rem; line-height: 1.5; margin: 0 0 1.75rem 0;">
                Your GCash payment proof has already been submitted. Are you sure you want to return to the previous step?
            </p>
            <div style="display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap;">
                <button type="button" class="btn btn-outline" onclick="closeLeavePaymentModal()" style="flex: 1; min-width: 130px; font-weight: 700; padding: 0.75rem 1rem;">
                    Stay on Payment
                </button>
                <button type="button" class="btn btn-primary" onclick="confirmGoBackToStep2()" style="flex: 1; min-width: 130px; font-weight: 700; padding: 0.75rem 1rem; background: #7c3aed; border-color: #7c3aed;">
                    Go Back
                </button>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>

    <script>
        const hasSubmittedProof = <?= $payment_is_submitted ? 'true' : 'false' ?>;
        const step2BackUrl = <?= json_encode($step2_back_url) ?>;

        function handleBackNavigation() {
            if (hasSubmittedProof) {
                openLeavePaymentModal();
            } else {
                window.location.href = step2BackUrl;
            }
        }

        function openLeavePaymentModal() {
            const modal = document.getElementById('leavePaymentModal');
            if (modal) modal.classList.add('active');
        }

        function closeLeavePaymentModal() {
            const modal = document.getElementById('leavePaymentModal');
            if (modal) modal.classList.remove('active');
        }

        function confirmGoBackToStep2() {
            window.location.href = step2BackUrl;
        }

        // Intercept all sidebar, brand, and header navigation links during GCash payment step
        document.addEventListener('DOMContentLoaded', function() {
            const navLinks = document.querySelectorAll('#appSidebar a, .sidebar-brand a, .hs-top-header a, .app-sidebar a');
            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    const href = this.getAttribute('href') || '';
                    if (href === '#' || href.startsWith('javascript:')) {
                        return;
                    }
                    e.preventDefault();
                    e.stopPropagation();
                    handleBackNavigation();
                });
            });

            // Handle browser back button (popstate)
            try {
                window.history.pushState({ page: 'payment_step' }, '', window.location.href);
                window.addEventListener('popstate', function(e) {
                    if (hasSubmittedProof) {
                        window.history.pushState({ page: 'payment_step' }, '', window.location.href);
                        openLeavePaymentModal();
                    } else {
                        window.location.href = step2BackUrl;
                    }
                });
            } catch (err) {}
        });

        function openQrModal() {
            document.getElementById('qrModal').classList.add('active');
        }
        function closeQrModal() {
            document.getElementById('qrModal').classList.remove('active');
        }

        function openPictureModal(src, title) {
            document.getElementById('pictureModalImg').src = src;
            document.getElementById('pictureModalTitle').textContent = title || 'Receipt Image';
            document.getElementById('pictureModal').classList.add('active');
        }
        function closePictureModal() {
            document.getElementById('pictureModal').classList.remove('active');
        }

        function copyGcashNumber() {
            navigator.clipboard.writeText('09566978679').then(() => {
                alert('GCash number copied: 0956 697 8679');
            }).catch(() => {
                prompt('Copy GCash number:', '09566978679');
            });
        }

        function previewReceipt(input) {
            const previewBox = document.getElementById('receiptPreviewBox');
            const previewImg = document.getElementById('receiptPreviewImg');
            const nameEl = document.getElementById('receiptFileName');

            if (input.files && input.files[0]) {
                const file = input.files[0];
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    nameEl.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
                    previewBox.style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                previewBox.style.display = 'none';
            }
        }

        // GCash Reference Number: strictly digits only, max 13 digits
        const gcashRefInput = document.getElementById('inputGcashRef');
        if (gcashRefInput) {
            gcashRefInput.addEventListener('keydown', function(e) {
                // Allow control & navigation keys
                const allowedKeys = ['Backspace', 'Delete', 'Tab', 'Escape', 'Enter', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'];
                if (allowedKeys.includes(e.key) || (e.ctrlKey || e.metaKey)) {
                    return;
                }
                // Disallow anything other than digits 0-9
                if (!/^[0-9]$/.test(e.key)) {
                    e.preventDefault();
                }
            });

            gcashRefInput.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '').slice(0, 13);
            });

            gcashRefInput.addEventListener('paste', function(e) {
                e.preventDefault();
                const paste = (e.clipboardData || window.clipboardData).getData('text') || '';
                const digits = paste.replace(/[^0-9]/g, '');
                const start = this.selectionStart || 0;
                const end = this.selectionEnd || 0;
                const current = this.value || '';
                const combined = (current.slice(0, start) + digits + current.slice(end)).replace(/[^0-9]/g, '').slice(0, 13);
                this.value = combined;
                this.dispatchEvent(new Event('input'));
            });

            gcashRefInput.addEventListener('drop', function(e) {
                e.preventDefault();
                const text = e.dataTransfer.getData('text') || '';
                const digits = text.replace(/[^0-9]/g, '').slice(0, 13);
                this.value = digits;
            });
        }
    </script>
</body>
</html>
