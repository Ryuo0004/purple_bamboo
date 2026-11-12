<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/booking_store.php';
require_once __DIR__ . '/includes/EmailHelper.php';

$ref = $_GET['ref'] ?? '';
$refClean = preg_replace('/[^A-Za-z0-9_-]/', '', $ref);

$htmlContent = '';
$emailFile = __DIR__ . '/data/emails/' . $refClean . '.html';

if (!empty($refClean) && file_exists($emailFile)) {
    $htmlContent = file_get_contents($emailFile);
} elseif (!empty($refClean)) {
    // Generate on the fly if booking exists
    $booking = BookingStore::getBookingById($refClean);
    if ($booking) {
        EmailHelper::sendBookingConfirmationEmail($booking);
        if (file_exists($emailFile)) {
            $htmlContent = file_get_contents($emailFile);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmation Voucher - <?= htmlspecialchars($refClean) ?> | Purple Bamboo Resort</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            background: #0f172a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .preview-bar {
            background: #1e1035;
            border-bottom: 1.5px solid #4c1d95;
            padding: 0.75rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #ffffff;
            position: sticky;
            top: 0;
            z-index: 999;
            box-shadow: 0 4px 15px rgba(0,0,0,0.4);
        }
        .preview-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 0.95rem;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0.45rem 1rem;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
        }
        .btn-print {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #ffffff;
        }
        .btn-print:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
        }
        .btn-back {
            background: rgba(255,255,255,0.1);
            color: #e2e8f0;
            border: 1px solid rgba(255,255,255,0.2);
        }
        .btn-back:hover {
            background: rgba(255,255,255,0.2);
            color: #ffffff;
        }
        .email-container {
            padding: 2rem 1rem 4rem;
        }
        @media print {
            .preview-bar {
                display: none !important;
            }
            body {
                background: #ffffff !important;
            }
            .email-container {
                padding: 0 !important;
            }
        }
    </style>
</head>
<body>
    <div class="preview-bar">
        <div class="preview-title">
            <i class="fa-solid fa-envelope-open-text" style="color: #fbbf24;"></i>
            <span>Official Booking Email & Schedule Notification (Ref: <?= htmlspecialchars($refClean) ?>)</span>
        </div>
        <div style="display: flex; gap: 0.75rem; align-items: center;">
            <button onclick="window.print()" class="btn-action btn-print">
                <i class="fa-solid fa-print"></i> Print / Save PDF
            </button>
            <?php
            $u_role = strtolower($_SESSION['role'] ?? 'guest');
            $back_dest = ($u_role === 'admin' || $u_role === 'staff') ? 'verify_gcash.php' : 'payment_method.php';
            ?>
            <a href="<?= $back_dest ?>?ref=<?= urlencode($refClean) ?>" class="btn-action btn-back">
                <i class="fa-solid fa-arrow-left"></i> Back to Booking & Payment
            </a>
        </div>
    </div>

    <div class="email-container">
        <?php if (!empty($htmlContent)): ?>
            <?= $htmlContent ?>
        <?php else: ?>
            <div style="max-width: 600px; margin: 3rem auto; background: #ffffff; padding: 2rem; border-radius: 12px; text-align: center; color: #1e293b;">
                <i class="fa-solid fa-circle-exclamation" style="font-size: 3rem; color: #f59e0b; margin-bottom: 1rem;"></i>
                <h2>Email Voucher Not Found</h2>
                <p style="color: #64748b;">No archived email record found for Reference #<?= htmlspecialchars($refClean) ?>.</p>
                <a href="bookings.php" style="display: inline-block; margin-top: 1rem; color: #7c3aed; font-weight: 700;">Return to Bookings</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
