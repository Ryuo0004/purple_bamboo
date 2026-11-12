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
    <title>Refund & Cancellation Policy | The Blessed Purple Bamboo Resort</title>
    <link rel="icon" type="image/jpeg" href="assets/logo.jpg">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
</head>
<body>

    <?php include 'nav.php'; ?>

    <main class="main-content">
        <div class="container" style="max-width: 900px; margin-top: 1.5rem; margin-bottom: 4rem;">
            
            <div style="margin-bottom: 2rem;">
                <span class="admin-badge admin-badge-danger" style="margin-bottom: 0.5rem; background: #fee2e2; color: #b91c1c; border: 1px solid #f87171;">
                    <i class="fa-solid fa-ban"></i> Resort Booking &amp; Cancellation Policy
                </span>
                <h1 style="font-size: 2.2rem; color: var(--purple-dark); margin-bottom: 0.5rem;">
                    Refund &amp; Cancellation Policy
                </h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">
                    Effective Date: September 2026 | Strict No-Refund &amp; No-Return Policy for All Reservations
                </p>
            </div>

            <div class="admin-card" style="padding: 2.5rem; line-height: 1.8;">

                <!-- IMPORTANT: Resort-Wide No Refund & No Return Notice -->
                <div style="background: linear-gradient(135deg, #fee2e2 0%, #fff1f2 100%); border: 2px solid #fca5a5; border-left: 6px solid #ef4444; padding: 1.5rem; border-radius: 10px; margin-bottom: 2rem;">
                    <h3 style="color: #991b1b; margin-bottom: 0.75rem; font-size: 1.2rem; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-ban" style="color: #dc2626; font-size: 1.3rem;"></i> Important: Strict No-Refund &amp; No-Return Notice
                    </h3>
                    <p style="color: #7f1d1d; font-size: 0.95rem; margin: 0; font-weight: 700; line-height: 1.7;">
                        <i class="fa-solid fa-circle-xmark" style="color: #dc2626;"></i> <strong>Walk-In Guests</strong> — All front-desk / walk-in payments and reservation deposits are strictly <strong style="color: #dc2626;">NON-REFUNDABLE</strong>. No returns or cash refunds will be issued under any circumstances.<br>
                        <i class="fa-solid fa-circle-xmark" style="color: #dc2626;"></i> <strong>Online Booking Guests</strong> — All online reservations (GCash / Online Payment) are strictly <strong style="color: #dc2626;">NON-REFUNDABLE</strong>.<br>
                        <i class="fa-solid fa-calendar-check" style="color: #2563eb;"></i> <strong>Re-Scheduling In Lieu of Refund</strong> — Guests may request date re-scheduling subject to availability and advance notice guidelines.
                    </p>
                </div>

                <!-- Walk-In Specific Notice -->
                <div style="background: #fdf4ff; border-left: 4px solid #a855f7; padding: 1.25rem; border-radius: 6px; margin-bottom: 2rem;">
                    <h3 style="color: #6b21a8; margin-bottom: 0.5rem; font-size: 1.1rem; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-person-walking"></i> Walk-In / Front Desk Reservations — No Refund / No Return
                    </h3>
                    <p style="color: #581c87; font-size: 0.92rem; margin: 0;">
                        Payments and deposits made directly at the resort front desk by walk-in guests are <strong>final and strictly non-refundable</strong>. Due to real-time accommodation locking and staff preparation, cash returns or payment refunds will not be issued upon cancellation, change of mind, late arrival, or early departure.
                    </p>
                </div>

                <!-- Online Booking Notice -->
                <div style="background: #f8fafc; border-left: 4px solid #64748b; padding: 1.25rem; border-radius: 6px; margin-bottom: 2rem;">
                    <h3 style="color: #334155; margin-bottom: 0.5rem; font-size: 1.1rem; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-globe"></i> Online Booking Reservations — No Refund Policy
                    </h3>
                    <p style="color: #475569; font-size: 0.92rem; margin: 0;">
                        All reservations made through the online booking system via GCash payment verification are <strong>final and non-refundable</strong>. Completing an online booking and submitting payment constitutes acceptance of this no-refund policy.
                    </p>
                </div>

                <h2 style="color: #4c1d95; font-size: 1.35rem; margin-top: 2rem; margin-bottom: 0.75rem;">1. Resort-Wide Non-Refundable Policy Details</h2>
                <p style="color: var(--text-main);">
                    To preserve accommodation availability and maintain operational standards, The Blessed Purple Bamboo Beach Resort enforces a strict non-refundable policy across all reservation channels:
                </p>
                <ul style="padding-left: 1.5rem; color: var(--text-main); margin-bottom: 1.5rem;">
                    <li><strong>Walk-In Bookings:</strong> All cash, GCash, or card payments collected at the front desk are non-refundable. Official receipts do not entitle guests to cash returns or refunds.</li>
                    <li><strong>Online GCash Bookings:</strong> All online reservations verified via GCash payment are non-refundable.</li>
                    <li><strong>Applicable Services:</strong> This policy applies to House Kubo rentals, day and night tour passes, pool activity passes, and pre-ordered dining.</li>
                    <li><strong>No-Shows &amp; Early Checkouts:</strong> Guests who fail to arrive or choose to check out earlier than reserved forfeit their full payment without refund.</li>
                </ul>

                <!-- Summary Box -->
                <div style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border: 1.5px solid #cbd5e1; border-radius: 10px; padding: 1.25rem; margin-bottom: 2rem;">
                    <h3 style="color: #334155; font-size: 1.05rem; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-clipboard-list"></i> Policy Summary At-A-Glance
                    </h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; font-size: 0.9rem;">
                        <div style="color: #991b1b; background: #fff1f2; padding: 1rem; border-radius: 8px; border: 1px solid #fecdd3;">
                            <strong><i class="fa-solid fa-person-walking" style="color: #dc2626;"></i> Walk-In Guests:</strong>
                            <ul style="padding-left: 1.25rem; margin-top: 0.4rem; margin-bottom: 0;">
                                <li>❌ No refund / No cash return</li>
                                <li>❌ All front desk deposits are final</li>
                                <li>⚠️ Re-scheduling only (with prior notice)</li>
                                <li>❌ No-show = forfeited deposit</li>
                            </ul>
                        </div>
                        <div style="color: #991b1b; background: #fff1f2; padding: 1rem; border-radius: 8px; border: 1px solid #fecdd3;">
                            <strong><i class="fa-solid fa-globe" style="color: #dc2626;"></i> Online Booking Guests:</strong>
                            <ul style="padding-left: 1.25rem; margin-top: 0.4rem; margin-bottom: 0;">
                                <li>❌ No refund / non-refundable</li>
                                <li>❌ All GCash payments are final</li>
                                <li>⚠️ Re-scheduling only (1 time, 3 days before)</li>
                                <li>❌ No-show = forfeited deposit</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <h2 style="color: #4c1d95; font-size: 1.35rem; margin-top: 2rem; margin-bottom: 0.75rem;">2. Emergency Weather &amp; Typhoon Policy (PAGASA Warning)</h2>
                <p style="color: var(--text-main);">
                    In the event of official severe weather warnings, tropical storm signals issued by PAGASA for Pangasinan, or force majeure events preventing travel to Alaminos City:
                </p>
                <ul style="padding-left: 1.5rem; color: var(--text-main); margin-bottom: 1.5rem;">
                    <li><strong>Walk-In Guests:</strong> Eligible for <strong>penalty-free date re-scheduling</strong> within 90 days of the original date (strictly no cash refund).</li>
                    <li><strong>Online Booking Guests:</strong> Eligible for a <strong>one-time free date re-scheduling only</strong> within 90 days of the original booking (strictly no cash refund).</li>
                </ul>

                <h2 style="color: #4c1d95; font-size: 1.35rem; margin-top: 2rem; margin-bottom: 0.75rem;">3. Date Re-Scheduling Guidelines (In Lieu of Refund)</h2>
                <p style="color: var(--text-main);">Guests who are unable to honor their reserved dates may request a date change under the following conditions:</p>
                <ol style="padding-left: 1.5rem; color: var(--text-main); margin-bottom: 1.5rem;">
                    <li><strong>Advance Notice:</strong> Requests must be submitted at least <strong>3 days</strong> before the scheduled check-in date.</li>
                    <li><strong>Availability:</strong> Re-scheduling is subject to kubo and facility availability on your preferred replacement date.</li>
                    <li><strong>Single Re-Schedule:</strong> Re-scheduling is permitted <strong>one time only</strong> per reservation reference.</li>
                    <li><strong>How to Contact:</strong> Email your booking reference code to <strong>theblessedpbbresort23@gmail.com</strong> or call our front desk at <strong>09566978679</strong>.</li>
                </ol>

            </div>
        </div>
    </main>

    <?php include 'footer.php'; ?>

</body>
</html>
