<?php
// includes/EmailHelper.php

require_once __DIR__ . '/Mailer.php';

class EmailHelper {
    
    /**
     * Send OTP for Password Reset
     */
    public static function sendPasswordResetEmail($toEmail, $toName, $otp) {
        $subject = '🔐 Password Reset OTP - Purple Bamboo Resort';
        $htmlBody = '
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; background: #f5f7fa; padding: 20px; }
                    .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 40px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
                    .header { text-align: center; padding-bottom: 20px; border-bottom: 2px solid #e8f0fe; }
                    .header h2 { color: #0B2447; margin: 0; }
                    .otp-box { background: #f0f7fb; padding: 25px; text-align: center; font-size: 36px; font-weight: bold; letter-spacing: 12px; color: #0B2447; border-radius: 10px; margin: 25px 0; border: 2px dashed #4DA6D9; }
                    .info { color: #64748b; font-size: 14px; line-height: 1.6; }
                    .warning { background: #fef3c7; padding: 12px 20px; border-radius: 8px; color: #92400e; font-size: 13px; margin: 20px 0; }
                    .footer { text-align: center; padding-top: 20px; border-top: 1px solid #e8f0fe; color: #94a3b8; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h2>🔐 Password Reset Request</h2>
                    </div>
                    <p class="info">Dear <strong>' . htmlspecialchars($toName) . '</strong>,</p>
                    <p class="info">We received a request to reset your password. Use the OTP code below to verify your identity:</p>
                    
                    <div class="otp-box">' . $otp . '</div>
                    
                    <div class="warning">
                        <strong>⏰ This code will expire in 15 minutes.</strong>
                    </div>
                    
                    <p class="info">If you didn\'t request this password reset, please ignore this email.</p>
                    <br>
                    <p class="info">Best regards,<br><strong>Purple Bamboo Resort Team</strong></p>
                    
                    <div class="footer">
                        &copy; ' . date('Y') . ' Purple Bamboo Resort. All rights reserved.
                    </div>
                </div>
            </body>
            </html>
            ';
            
        $plainText = "Password Reset Request\n\n" .
                     "Dear " . $toName . ",\n\n" .
                     "We received a request to reset your password. Use the OTP code below:\n\n" .
                     "OTP Code: " . $otp . "\n\n" .
                     "This code will expire in 15 minutes.\n\n" .
                     "If you didn't request this, please ignore this email.\n\n" .
                     "Best regards,\nPurple Bamboo Resort Team";

        return Mailer::send(
            $toEmail,
            $toName,
            $subject,
            $htmlBody,
            $plainText,
            SMTP_FROM_EMAIL,
            'Support'
        );
    }

    /**
     * Send Booking Confirmation & Schedule Notification Email
     * Includes exact booked date/time, check-in time (2:00 PM), and check-out time (12:00 PM)
     */
    public static function sendBookingConfirmationEmail($booking) {
        $refId = $booking['id'] ?? ('PBR-' . date('Y') . '-' . rand(1000, 9999));
        $toEmail = !empty($booking['guest_email']) ? trim($booking['guest_email']) : 'guest@example.com';
        $toName = !empty($booking['guest_name']) ? trim($booking['guest_name']) : 'Valued Guest';
        $phone = $booking['guest_phone'] ?? 'N/A';
        $type = strtolower($booking['type'] ?? 'house');
        $itemName = $booking['item_name'] ?? 'Purple Bamboo Resort Accommodation';
        $pax = $booking['pax'] ?? 2;
        $totalAmount = floatval($booking['total_amount'] ?? 0);
        $notes = !empty($booking['special_notes']) ? htmlspecialchars($booking['special_notes']) : 'None specified';

        // When did they book (Booking Schedule timestamp)
        $bookedTimestamp = !empty($booking['created_at']) ? strtotime($booking['created_at']) : time();
        $bookedDateFormatted = date('l, F j, Y \a\t g:i A', $bookedTimestamp);

        // Check-in and Check-out details
        $rawCheckIn = $booking['check_in'] ?? date('Y-m-d');
        $rawCheckOut = $booking['check_out'] ?? date('Y-m-d', strtotime('+1 day'));
        $checkInFormatted = date('l, F j, Y', strtotime($rawCheckIn));
        $checkOutFormatted = date('l, F j, Y', strtotime($rawCheckOut));

        $checkInTime = '2:00 PM';
        $checkOutTime = '12:00 PM (Noon)';

        if ($type === 'activity') {
            $scheduleSubtitle = "Activity Session Reservation";
            $sessionSlot = !empty($booking['time_slot']) ? $booking['time_slot'] : '09:00 AM';
            $staySummaryText = "Scheduled Date: <strong>{$checkInFormatted}</strong><br>Activity Slot: <strong>{$sessionSlot}</strong>";
        } else {
            $scheduleSubtitle = "House Kubo Overnight Stay";
            $d1 = new DateTime($rawCheckIn);
            $d2 = new DateTime($rawCheckOut);
            $nights = max(1, $d1->diff($d2)->days);
            $staySummaryText = "Stay Duration: <strong>{$nights} Night(s)</strong> ({$checkInFormatted} to {$checkOutFormatted})";
        }

        $subject = "🌿 Booking Confirmation & Schedule - [{$refId}] | Purple Bamboo Resort";

        $htmlBody = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($subject) . '</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 25px 15px; color: #1e293b; }
        .email-card { max-width: 620px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; }
        .hero-banner { background: linear-gradient(135deg, #3b0764 0%, #581c87 50%, #7c3aed 100%); padding: 35px 25px; text-align: center; color: #ffffff; }
        .resort-tag { display: inline-block; background: rgba(245, 158, 11, 0.25); border: 1px solid #f59e0b; color: #fbbf24; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; padding: 4px 14px; border-radius: 20px; margin-bottom: 12px; }
        .hero-title { margin: 0 0 8px 0; font-size: 26px; font-weight: 800; color: #ffffff; letter-spacing: -0.5px; }
        .hero-sub { margin: 0; font-size: 14px; color: #e9d5ff; }
        
        .content-body { padding: 30px 25px; }
        .greeting { font-size: 15px; line-height: 1.6; color: #334155; margin-bottom: 22px; }
        
        /* Highlight Schedule Box */
        .schedule-box { background: #fdf4ff; border: 1.5px solid #f0abfc; border-radius: 14px; padding: 18px 20px; margin-bottom: 25px; }
        .box-heading { font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; color: #86198f; margin-bottom: 12px; display: flex; align-items: center; gap: 6px; }
        .booked-on-badge { background: #fae8ff; border: 1px solid #e879f9; color: #701a75; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 13px; display: inline-block; margin-bottom: 8px; }
        
        /* Check-In / Check-Out Grid */
        .times-grid { display: table; width: 100%; margin-top: 15px; border-collapse: separate; border-spacing: 10px 0; }
        .time-col { display: table-cell; width: 50%; background: #ffffff; border-radius: 10px; padding: 14px 16px; border: 1px solid #f5d0fe; vertical-align: top; box-shadow: 0 2px 6px rgba(0,0,0,0.03); }
        .time-label { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: #6b21a8; margin-bottom: 4px; }
        .time-date { font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
        .time-hour { font-size: 16px; font-weight: 900; color: #d97706; }
        
        /* Details Table */
        .details-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
        .details-table td { padding: 10px 12px; font-size: 14px; border-bottom: 1px solid #f1f5f9; }
        .details-table td.lbl { color: #64748b; font-weight: 600; width: 38%; }
        .details-table td.val { color: #0f172a; font-weight: 700; }
        
        /* Payment Callout */
        .payment-box { background: #fffbeb; border: 1.5px solid #fcd34d; border-radius: 12px; padding: 16px 20px; margin-bottom: 25px; }
        .total-row { display: flex; justify-content: space-between; align-items: center; font-size: 18px; font-weight: 900; color: #92400e; margin-bottom: 6px; }
        .total-amount { font-size: 22px; color: #b45309; }
        
        /* Guidelines */
        .guidelines-box { background: #f8fafc; border-left: 4px solid #7c3aed; padding: 14px 18px; border-radius: 0 10px 10px 0; margin-bottom: 25px; font-size: 13px; color: #475569; line-height: 1.6; }
        .guidelines-box strong { color: #1e1b4b; }
        
        .footer { background: #0f172a; padding: 25px; text-align: center; color: #94a3b8; font-size: 12px; line-height: 1.6; }
        .footer a { color: #fbbf24; text-decoration: none; }
    </style>
</head>
<body>
    <div class="email-card">
        <!-- Header -->
        <div class="hero-banner">
            <span class="resort-tag">🌿 Purple Bamboo Resort</span>
            <h1 class="hero-title">Booking Confirmation</h1>
            <p class="hero-sub">Ref Code: <strong>' . htmlspecialchars($refId) . '</strong></p>
        </div>

        <div class="content-body">
            <p class="greeting">
                Hello <strong>' . htmlspecialchars($toName) . '</strong>,<br>
                Great news! Your booking and payment have been <strong>officially confirmed</strong> by Purple Bamboo Resort. Below is your official schedule and reservation summary:
            </p>

            <!-- Booking Schedule & Times -->
            <div class="schedule-box">
                <div class="box-heading">📅 Official Reservation Schedule</div>
                <div>
                    <span style="font-size: 13px; color: #4c1d95;">Date & Time You Booked:</span><br>
                    <strong class="booked-on-badge">🗓️ ' . $bookedDateFormatted . '</strong>
                </div>

                <!-- Check-in and Check-out Times -->
                <div class="times-grid">
                    <div class="time-col">
                        <div class="time-label">🚪 Check-In Schedule</div>
                        <div class="time-date">' . $checkInFormatted . '</div>
                        <div class="time-hour">🕒 ' . $checkInTime . '</div>
                        <span style="font-size: 11px; color: #64748b;">(Front Desk Check-In begins)</span>
                    </div>
                    <div class="time-col">
                        <div class="time-label">🏃 Check-Out Schedule</div>
                        <div class="time-date">' . $checkOutFormatted . '</div>
                        <div class="time-hour">🕒 ' . $checkOutTime . '</div>
                        <span style="font-size: 11px; color: #64748b;">(Standard Room Key Return)</span>
                    </div>
                </div>
            </div>

            <!-- Accommodation & Stay Details -->
            <table class="details-table">
                <tr>
                    <td class="lbl">Accommodation / Item:</td>
                    <td class="val">' . htmlspecialchars($itemName) . '</td>
                </tr>
                <tr>
                    <td class="lbl">Reservation Type:</td>
                    <td class="val">' . htmlspecialchars($scheduleSubtitle) . '</td>
                </tr>
                <tr>
                    <td class="lbl">Stay Duration:</td>
                    <td class="val">' . $staySummaryText . '</td>
                </tr>
                <tr>
                    <td class="lbl">Registered Guests:</td>
                    <td class="val">' . htmlspecialchars($pax) . ' Pax</td>
                </tr>
                <tr>
                    <td class="lbl">Guest Contact:</td>
                    <td class="val">' . htmlspecialchars($phone) . ' (' . htmlspecialchars($toEmail) . ')</td>
                </tr>
                <tr>
                    <td class="lbl">Special Notes:</td>
                    <td class="val">' . $notes . '</td>
                </tr>
            </table>

            <!-- Payment Breakdown -->
            <div class="payment-box">
                <div class="total-row">
                    <span>Total Amount Paid:</span>
                    <span class="total-amount">₱' . number_format($totalAmount, 2) . '</span>
                </div>
                <div style="font-size: 13px; color: #166534; background: #dcfce7; border: 1px solid #86efac; border-radius: 8px; padding: 10px 14px; margin-top: 8px; font-weight: 600;">
                    ✅ Payment Status: <strong>PAID & VERIFIED</strong>' . (!empty($booking['gcash_ref']) ? '<br><span style="font-size: 12px; color: #15803d; font-weight: normal;">Payment Reference / GCash: <strong>' . htmlspecialchars($booking['gcash_ref']) . '</strong></span>' : '') . '
                </div>
            </div>

            <!-- Important Resort Reminders -->
            <div class="guidelines-box">
                <strong>📌 Important Check-In Reminders:</strong><br>
                • <strong>Standard Check-In Time:</strong> Starts at <strong>2:00 PM</strong>. Please present this confirmation email voucher and a valid government ID upon arrival at the Front Desk.<br>
                • <strong>Standard Check-Out Time:</strong> Strictly by <strong>12:00 PM (Noon)</strong> to allow thorough cleaning and sanitation for incoming guests.<br>
                • <strong>Early Arrival / Late Check-Out:</strong> Subject to room availability and resort management confirmation.
            </div>

            <p style="font-size: 13px; color: #64748b; margin: 0 0 10px 0;">
                Need assistance? Feel free to contact our Front Desk at <strong>09566978679</strong> or reply directly to this email.
            </p>
        </div>

        <!-- Footer -->
        <div class="footer">
            <strong>The Blessed Purple Bamboo Beach Resort</strong><br>
            Zone 4 Magamo-gamo, Telbang, Alaminos City, Pangasinan<br>
            Hotline: 09566978679 | Email: theblessedpbbresort23@gmail.com<br>
            <span style="font-size: 11px; opacity: 0.8; margin-top: 8px; display: block;">
                This is an automated booking confirmation. Please save or present this email upon arrival.
            </span>
        </div>
    </div>
</body>
</html>
';

        $plainText = "PURPLE BAMBOO RESORT - OFFICIAL BOOKING & PAYMENT CONFIRMATION\n" .
                     "Reference Code: {$refId}\n\n" .
                     "Dear {$toName},\n" .
                     "Great news! Your booking and payment have been officially confirmed by Purple Bamboo Resort.\n\n" .
                     "BOOKING SCHEDULE:\n" .
                     "• Date & Time Booked: {$bookedDateFormatted}\n" .
                     "• Check-In Date & Time: {$checkInFormatted} at {$checkInTime}\n" .
                     "• Check-Out Date & Time: {$checkOutFormatted} at {$checkOutTime}\n" .
                     "• Accommodation: {$itemName}\n" .
                     "• Number of Pax: {$pax}\n" .
                     "• Total Amount Paid: PHP " . number_format($totalAmount, 2) . "\n" .
                     "• Payment Status: PAID & VERIFIED" . (!empty($booking['gcash_ref']) ? " (Ref: " . $booking['gcash_ref'] . ")" : "") . "\n\n" .
                     "CHECK-IN REMINDER:\n" .
                     "Standard check-in starts at 2:00 PM and check-out is by 12:00 PM (Noon).\n" .
                     "Please present this booking reference code and a valid ID at the Front Desk.\n\n" .
                     "Resort Address: Zone 4 Magamo-gamo, Telbang, Alaminos City, Pangasinan\n" .
                     "Hotline / Contact: 09566978679\n\n" .
                     "Best regards,\n" .
                     "Purple Bamboo Resort Team";

        $sendSuccess = false;
        $errorMessage = '';
        try {
            $sendSuccess = Mailer::send(
                $toEmail,
                $toName,
                $subject,
                $htmlBody,
                $plainText,
                SMTP_FROM_EMAIL,
                'Purple Bamboo Support'
            );
            if (!$sendSuccess) {
                $errorMessage = 'PHPMailer failed to send the message.';
            }
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            error_log("Booking email sending error: " . $errorMessage);
        }

        // 2. Always persist to local sent emails archive (so guest & admin can always preview the exact email voucher!)
        self::logSentEmail($refId, $toEmail, $subject, $htmlBody, $sendSuccess, $errorMessage, [
            'guest_name' => $toName,
            'item_name' => $itemName,
            'booked_at' => $bookedDateFormatted,
            'check_in' => "{$checkInFormatted} at {$checkInTime}",
            'check_out' => "{$checkOutFormatted} at {$checkOutTime}",
            'total_amount' => $totalAmount
        ]);

        return [
            'success' => $sendSuccess,
            'error' => $errorMessage,
            'ref' => $refId,
            'to' => $toEmail
        ];
    }

    /**
     * Archive sent email for offline preview and logging in MySQL
     */
    public static function logSentEmail($refId, $toEmail, $subject, $htmlBody, $sentSuccess, $errorMessage = '', $meta = []) {
        $emailsDir = __DIR__ . '/../data/emails';
        if (!is_dir($emailsDir)) {
            @mkdir($emailsDir, 0777, true);
        }

        // Save standalone HTML file for voucher rendering
        $htmlFilePath = $emailsDir . '/' . preg_replace('/[^A-Za-z0-9_-]/', '', $refId) . '.html';
        @file_put_contents($htmlFilePath, $htmlBody);

        // Persist strictly to MySQL sent_emails table
        global $pdo;
        if (!$pdo) {
            require_once __DIR__ . '/../database.php';
        }
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO sent_emails (ref_id, to_email, subject, sent_status, error_message, sent_at, meta_json, html_file)
                    VALUES (:ref_id, :to_email, :subject, :sent_status, :error_message, NOW(), :meta_json, :html_file)
                    ON DUPLICATE KEY UPDATE sent_status = :sent_status2, error_message = :error_message2, sent_at = NOW(), meta_json = :meta_json2, html_file = :html_file2
                ");
                $metaJson = !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
                $sentStatus = $sentSuccess ? 'delivered' : 'logged_locally';
                $htmlFile = 'data/emails/' . basename($htmlFilePath);
                $stmt->execute([
                    ':ref_id' => $refId,
                    ':to_email' => $toEmail,
                    ':subject' => $subject,
                    ':sent_status' => $sentStatus,
                    ':error_message' => $errorMessage ?: null,
                    ':meta_json' => $metaJson,
                    ':html_file' => $htmlFile,
                    ':sent_status2' => $sentStatus,
                    ':error_message2' => $errorMessage ?: null,
                    ':meta_json2' => $metaJson,
                    ':html_file2' => $htmlFile
                ]);
            } catch (PDOException $e) {
                error_log("EmailHelper::logSentEmail MySQL error: " . $e->getMessage());
            }
        }
    }
}
?>