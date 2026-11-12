<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms & Conditions | The Blessed Purple Bamboo Resort</title>
    <link rel="icon" type="image/jpeg" href="assets/logo.jpg">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
</head>
<body>

    <?php include 'nav.php'; ?>

    <main class="main-content">
        <div class="container" style="max-width: 900px; margin-top: 1.5rem; margin-bottom: 4rem;">
            
            <div style="margin-bottom: 2rem;">
                <span class="admin-badge admin-badge-gold" style="margin-bottom: 0.5rem;">
                    <i class="fa-solid fa-file-contract"></i> Guest Agreement & Resort Rules
                </span>
                <h1 style="font-size: 2.2rem; color: var(--purple-dark); margin-bottom: 0.5rem;">
                    Terms & Conditions
                </h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">
                    Effective Date: September 2026 | Governing Resort Stay, GCash Booking & Facility Use
                </p>
            </div>

            <div class="admin-card" style="padding: 2.5rem; line-height: 1.8;">

                <div style="background: #fef3c7; border-left: 4px solid #d97706; padding: 1.25rem; border-radius: 6px; margin-bottom: 2rem;">
                    <h3 style="color: #92400e; margin-bottom: 0.5rem; font-size: 1.1rem;">
                        <i class="fa-solid fa-handshake"></i> Agreement Notice
                    </h3>
                    <p style="color: #78350f; font-size: 0.92rem; margin: 0;">
                        By creating an account, reserving a House Kubo, purchasing activity day passes, or using facilities at <strong>The Blessed Purple Bamboo Beach Resort</strong>, you agree to comply with and be bound by the following terms and resort regulations.
                    </p>
                </div>

                <h2 style="color: #4c1d95; font-size: 1.35rem; margin-top: 2rem; margin-bottom: 0.75rem;">1. Registration & Account Integrity</h2>
                <ul style="padding-left: 1.5rem; color: var(--text-main); margin-bottom: 1.5rem;">
                    <li>Guests registering an account must be at least 18 years of age and possess valid government-issued identification.</li>
                    <li>You agree to provide accurate, complete, and truthful personal details during account registration and stay reservation.</li>
                    <li>Account credentials must be kept confidential. You are responsible for all activities executed under your registered guest portal.</li>
                </ul>

                <h2 style="color: #4c1d95; font-size: 1.35rem; margin-top: 2rem; margin-bottom: 0.75rem;">2. Check-In & Check-Out Policy</h2>
                <ul style="padding-left: 1.5rem; color: var(--text-main); margin-bottom: 1.5rem;">
                    <li><strong>Standard Check-In Time:</strong> 2:00 PM</li>
                    <li><strong>Standard Check-Out Time:</strong> 12:00 PM (Noon)</li>
                    <li>Early check-in or late check-out is subject to availability and prior confirmation with resort administration. Additional fees may apply.</li>
                    <li>Guests must present valid ID and their verified GCash booking reference code upon arrival.</li>
                </ul>

                <h2 style="color: #4c1d95; font-size: 1.35rem; margin-top: 2rem; margin-bottom: 0.75rem;">3. Payments & GCash Verification</h2>
                <ul style="padding-left: 1.5rem; color: var(--text-main); margin-bottom: 1.5rem;">
                    <li>All online reservations require GCash deposit or full payment verification.</li>
                    <li>Reference numbers and payment transaction receipts must be genuine. Submitting fraudulent transaction proofs will result in immediate reservation cancellation and blacklisting.</li>
                    <li>All prices are quoted in Philippine Pesos (PHP) inclusive of applicable taxes.</li>
                </ul>

                <h2 style="color: #4c1d95; font-size: 1.35rem; margin-top: 2rem; margin-bottom: 0.75rem;">4. Resort Guidelines & Guest Conduct</h2>
                <ul style="padding-left: 1.5rem; color: var(--text-main); margin-bottom: 1.5rem;">
                    <li><strong>Swimming Pool & Lagoon Safety:</strong> Proper swimwear is required. Children must be accompanied by adults at all times in pool areas and kayaks.</li>
                    <li><strong>Quiet Hours:</strong> Respect other guests by observing quiet hours from 10:00 PM to 6:00 AM.</li>
                    <li><strong>Damage & Loss:</strong> Guests are liable for any physical damage caused to bamboo kubos, furniture, appliances, or resort property during their stay.</li>
                    <li><strong>Prohibited Items:</strong> Illegal substances, unauthorized firearms, and hazardous materials are strictly prohibited within resort premises.</li>
                </ul>

                <h2 style="color: #4c1d95; font-size: 1.35rem; margin-top: 2rem; margin-bottom: 0.75rem;">5. Limitation of Liability</h2>
                <p style="color: var(--text-main);">
                    The Blessed Purple Bamboo Beach Resort takes every reasonable precaution to ensure guest security and safety. However, the resort management is not liable for personal belongings left unattended or injuries resulting from disregard of posted safety signs and lifeguard instructions.
                </p>

                <h2 style="color: #4c1d95; font-size: 1.35rem; margin-top: 2rem; margin-bottom: 0.75rem;">6. Contact & Legal Inquiries</h2>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 1.25rem; border-radius: 8px; margin-top: 1rem;">
                    <p style="margin: 0 0 0.4rem 0; font-weight: 700; color: #1e293b;">The Blessed Purple Bamboo Beach Resort Management</p>
                    <p style="margin: 0 0 0.3rem 0; color: #475569;"><i class="fa-solid fa-location-dot" style="color: #d97706; width: 18px;"></i> Zone 4, Magamo-gamo, Telbang, Alaminos City, Pangasinan, Philippines 2404</p>
                    <p style="margin: 0; color: #475569;"><i class="fa-solid fa-envelope" style="color: #d97706; width: 18px;"></i> Email: <strong>theblessedpbbresort23@gmail.com</strong></p>
                </div>

            </div>
        </div>
    </main>

    <?php include 'footer.php'; ?>

</body>
</html>
