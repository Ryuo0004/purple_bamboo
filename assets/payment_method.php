<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/booking_store.php';

// Verify that user is logged in
if (empty($_SESSION['user_id'])) {
    $ref_param = !empty($_GET['ref']) ? '?redirect=' . urlencode('payment_method.php?ref=' . $_GET['ref']) : '';
    header("Location: login.php" . $ref_param);
    exit();
}

$ref = trim($_GET['ref'] ?? ($_POST['ref_no'] ?? ''));
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {
    $gref = trim($_POST['gcash_ref'] ?? '');
    $ref_no = trim($_POST['ref_no'] ?? $ref);

    // Validate GCash Reference Number format
    $clean_gref = preg_replace('/[^0-9]/', '', $gref);
    if (empty($clean_gref) || strlen($clean_gref) < 10) {
        $error_msg = 'Please enter a valid GCash Reference Number (typically 13 digits, e.g. 1002938475612).';
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
                BookingStore::updateBookingStatus($ref_no, 'pending', 'proof_submitted', $clean_gref, $proof_path);
                
                // Refresh booking details
                $booking = BookingStore::getBookingById($ref_no);
                $success_msg = 'Your GCash payment proof has been submitted successfully! Our Front Desk team is currently verifying it for immediate confirmation.';
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
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1.25rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        .step-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            font-weight: 700;
            color: #94a3b8;
        }
        .step-item.active {
            color: var(--primary-purple);
        }
        .step-item.done {
            color: #10b981;
        }
        .step-num {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.78rem;
            background: #e2e8f0;
            color: #475569;
        }
        .step-item.active .step-num {
            background: var(--primary-purple);
            color: #ffffff;
            box-shadow: 0 0 10px rgba(124, 58, 237, 0.35);
        }
        .step-item.done .step-num {
            background: #10b981;
            color: #ffffff;
        }
        .step-divider {
            color: #cbd5e1;
            font-size: 0.8rem;
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
    </style>
</head>
<body>

    <?php include 'nav.php'; ?>

    <div class="container" style="max-width: 1060px; padding: 2.5rem 1rem 4rem 1rem;">
        
        <!-- Reservation Flow Progress Tracker -->
        <div class="step-indicator">
            <div class="step-item done">
                <span class="step-num"><i class="fa-solid fa-check"></i></span>
                <span>Choose Accommodation</span>
            </div>
            <div class="step-divider"><i class="fa-solid fa-chevron-right"></i></div>
            <div class="step-item done">
                <span class="step-num"><i class="fa-solid fa-check"></i></span>
                <span>Dates & Details</span>
            </div>
            <div class="step-divider"><i class="fa-solid fa-chevron-right"></i></div>
            <div class="step-item active">
                <span class="step-num">3</span>
                <span>Payment Method (GCash)</span>
            </div>
            <div class="step-divider"><i class="fa-solid fa-chevron-right"></i></div>
            <div class="step-item <?= $is_already_paid ? 'active' : '' ?>">
                <span class="step-num"><?= $is_already_paid ? '<i class="fa-solid fa-check"></i>' : '4' ?></span>
                <span>Confirmation</span>
            </div>
        </div>

        <div class="page-header" style="margin-bottom: 2rem;">
            <div>
                <span class="badge badge-gold" style="margin-bottom: 0.4rem;"><i class="fa-solid fa-wallet"></i> Secured Payment Gateway</span>
                <h1 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin: 0.2rem 0;">GCash Express Payment</h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">Pay directly using GCash for instant confirmation of your resort reservation.</p>
            </div>
            <div>
                <a href="user-dashboard.php" class="btn btn-outline btn-sm">
                    <i class="fa-solid fa-arrow-left"></i> My Guest Portal
                </a>
            </div>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div style="background: rgba(16, 185, 129, 0.12); color: #047857; padding: 1.25rem 1.5rem; border-radius: 12px; margin-bottom: 2rem; border: 1.5px solid #6ee7b7; display: flex; align-items: flex-start; gap: 12px;">
                <i class="fa-solid fa-circle-check" style="font-size: 1.6rem; color: #10b981; margin-top: 2px;"></i>
                <div>
                    <strong style="font-size: 1.05rem; display: block; margin-bottom: 0.25rem;">Payment Proof Submitted!</strong>
                    <span><?= htmlspecialchars($success_msg) ?></span>
                    <div style="margin-top: 0.75rem; display: flex; gap: 0.75rem; flex-wrap: wrap;">
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
                            <div style="display: flex; justify-content: space-between; align-items: center;">
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
                    <div class="card" style="background: #faf5ff; border: 2px solid var(--purple-accent); border-radius: 14px; padding: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; border-bottom: 1px dashed #d8b4fe; padding-bottom: 1rem;">
                            <div>
                                <span class="badge badge-gold" style="font-size: 0.75rem; margin-bottom: 0.3rem;">
                                    Ref #<?= htmlspecialchars($booking['id']) ?>
                                </span>
                                <h3 style="margin: 0.2rem 0; font-family: 'Outfit', sans-serif; color: var(--primary-purple); font-size: 1.25rem;">
                                    <?= htmlspecialchars($booking['item_name']) ?>
                                </h3>
                                <span style="font-size: 0.82rem; color: #64748b;">
                                    <i class="fa-regular fa-calendar"></i> <?= date('M d, Y', strtotime($booking['check_in'])) ?> 
                                    <?php if (!empty($booking['check_out']) && $booking['check_out'] !== $booking['check_in']): ?>
                                        &rarr; <?= date('M d, Y', strtotime($booking['check_out'])) ?>
                                    <?php endif; ?>
                                </span>
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

                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span style="font-size: 0.82rem; color: #64748b; font-weight: 700; text-transform: uppercase; display: block;">Total Amount Due:</span>
                                <span style="font-size: 0.8rem; color: var(--primary-purple);">Taxes and resort fees included</span>
                            </div>
                            <div class="amount-highlight" style="color: var(--primary-purple);">
                                ₱<?= number_format(floatval($booking['total_amount']), 2) ?>
                            </div>
                        </div>
                    </div>

                    <!-- Submission / Status Form Card -->
                    <div class="card" style="border-radius: 14px; padding: 1.75rem; box-shadow: 0 4px 20px rgba(0,0,0,0.06);">
                        
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
                                <div style="display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap;">
                                    <a href="view_email.php?ref=<?= urlencode($booking['id']) ?>" target="_blank" class="btn btn-primary"><i class="fa-solid fa-print"></i> View Stay Voucher</a>
                                    <a href="user-dashboard.php" class="btn btn-outline"><i class="fa-solid fa-house-user"></i> My Dashboard</a>
                                </div>
                            </div>

                        <?php elseif ($has_submitted_proof && empty($error_msg) && !isset($_GET['reupload'])): ?>
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

                                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                                    <a href="payment_method.php?ref=<?= urlencode($booking['id']) ?>&reupload=1" class="btn btn-outline btn-sm" style="flex: 1; text-align: center;">
                                        <i class="fa-solid fa-camera"></i> Upload New Receipt
                                    </a>
                                    <a href="user-dashboard.php" class="btn btn-primary btn-sm" style="flex: 1; text-align: center;">
                                        <i class="fa-solid fa-arrow-right"></i> Go to Dashboard
                                    </a>
                                </div>
                            </div>

                        <?php else: ?>
                            <!-- Upload Form for First Submission or Re-Upload -->
                            <div>
                                <h3 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-top: 0; margin-bottom: 0.4rem; font-size: 1.25rem;">
                                    <i class="fa-solid fa-upload"></i> Submit GCash Payment Proof
                                </h3>
                                <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.25rem;">
                                    Enter the transaction details from your GCash confirmation receipt.
                                </p>

                                <form action="payment_method.php?ref=<?= urlencode($booking['id']) ?>" method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="ref_no" value="<?= htmlspecialchars($booking['id']) ?>">
                                    <input type="hidden" name="submit_payment" value="1">

                                    <div class="form-group">
                                        <label for="inputGcashRef" style="font-weight: 700; font-size: 0.88rem; color: #1e293b;">
                                            <i class="fa-solid fa-receipt" style="color: #005ce6;"></i> GCash Reference Number *
                                        </label>
                                        <input type="text" id="inputGcashRef" name="gcash_ref" class="form-control" placeholder="e.g. 1002938475612 (13 digits)" value="<?= htmlspecialchars(substr(preg_replace('/[^0-9]/', '', $booking['gcash_ref'] ?? ''), 0, 13)) ?>" required maxlength="13" minlength="13" inputmode="numeric" pattern="[0-9]{13}" title="Please enter a 13-digit GCash reference number" autocomplete="off" onkeypress="return (event.key >= '0' && event.key <= '9');" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 13);" style="font-size: 1rem; font-family: monospace; letter-spacing: 0.5px;">
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

                                    <button type="submit" class="btn btn-gold btn-lg" style="width: 100%; margin-top: 1.5rem; font-weight: 800; border: none; padding: 0.95rem; font-size: 1.05rem; box-shadow: 0 4px 15px rgba(245, 158, 11, 0.35);">
                                        <i class="fa-solid fa-check-circle"></i> Submit GCash Payment Proof
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>

                    </div>

                </div>

            </div>

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

    <?php include 'footer.php'; ?>

    <script>
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
    </script>
</body>
</html>
