<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/booking_store.php';

// Require logged-in guest session
if (empty($_SESSION['user_id'])) {
    header("Location: login.php?msg=" . urlencode("Please sign in first to access your Guest Portal.") . "&redirect=user-dashboard.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$profile_id = $_SESSION['profile_id'] ?? sprintf("PBR-GST-%05d", $user_id);
$first_name = $_SESSION['first_name'] ?? 'Guest';
$middle_name = $_SESSION['middle_name'] ?? '';
$last_name = $_SESSION['last_name'] ?? '';
$suffix = $_SESSION['suffix'] ?? '';
$full_name = $_SESSION['full_name'] ?? trim("{$first_name} {$middle_name} {$last_name}");
$email = $_SESSION['email'] ?? '';
$phone = $_SESSION['phone'] ?? '';

$emergency_contact_name = $_SESSION['emergency_contact_name'] ?? '';
$emergency_contact_relationship = $_SESSION['emergency_contact_relationship'] ?? '';
$emergency_contact_phone = $_SESSION['emergency_contact_phone'] ?? '';
$emergency_contact_alt_phone = $_SESSION['emergency_contact_alt_phone'] ?? '';

$id_type = $_SESSION['id_type'] ?? '';
$id_number = $_SESSION['id_number'] ?? '';
$id_image = $_SESSION['id_image'] ?? '';
$profile_picture = $_SESSION['profile_picture'] ?? '';

$health_conditions_raw = $_SESSION['health_conditions'] ?? '';
$health_conditions_list = array_filter(array_map('trim', explode(',', $health_conditions_raw)));
$health_info = $_SESSION['health_info'] ?? '';

$all_b = BookingStore::getAllBookings();
$user_bookings = array_values(array_filter($all_b, function($b) use ($user_id, $email, $profile_id) {
    return (($b['user_id'] ?? null) == $user_id) 
        || (!empty($b['profile_id']) && $b['profile_id'] === $profile_id)
        || (!empty($email) && strtolower(trim($b['guest_email'] ?? '')) === strtolower(trim($email)));
}));

$active_booking = null;
$past_bookings = [];

foreach ($user_bookings as $b) {
    $rawStatus = strtolower(trim($b['booking_status'] ?? ''));
    if ((in_array($rawStatus, ['pending', 'approved', 'confirmed', 'checked_in', 'reschedule_requested']) || !empty($b['resched_pending'])) && $active_booking === null) {
        $active_booking = $b;
    } else {
        $past_bookings[] = $b;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Portal & Dashboard | Purple Bamboo Resort</title>
    <!-- Refreshing Light Tropical Resort Theme for Guests -->
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* My Resort History Scrollable Table Container */
        .history-scroll-container {
            max-height: 420px;
            overflow-y: auto;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border: 1.5px solid #e9d5ff;
            border-radius: var(--radius-sm, 8px);
            scrollbar-width: thin;
            scrollbar-color: #a855f7 #f5f3ff;
            background: #ffffff;
        }
        .history-scroll-container::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        .history-scroll-container::-webkit-scrollbar-track {
            background: #f5f3ff;
            border-radius: 4px;
        }
        .history-scroll-container::-webkit-scrollbar-thumb {
            background: #c084fc;
            border-radius: 4px;
            border: 2px solid #f5f3ff;
        }
        .history-scroll-container::-webkit-scrollbar-thumb:hover {
            background: #9333ea;
        }
        .history-scroll-container thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: linear-gradient(135deg, #2e1065, #4c1d95) !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
        }
    </style>
</head>
<body>

    <?php include 'nav.php'; ?>

    <div class="container">
        <!-- Guest Welcome Header Banner -->
        <div class="card user-welcome-card" style="background: linear-gradient(135deg, var(--primary-purple), var(--purple-accent)); color: #ffffff; padding: clamp(1.25rem, 4vw, 2.5rem); margin-bottom: 2rem; position: relative; overflow: hidden; border: none;">
            <div style="position: relative; z-index: 2;">
                <span class="badge badge-gold" style="margin-bottom: 0.75rem;"><i class="fa-solid fa-id-badge"></i> Profile ID: <?= htmlspecialchars($profile_id) ?></span>
                <h1 style="font-family: 'Outfit', sans-serif; font-size: clamp(1.6rem, 5vw, 2.5rem); color: #ffffff; margin-bottom: 0.5rem;">Mabuhay, <?= htmlspecialchars($first_name) ?>! 👋</h1>
                <p style="color: #f3e8ff; font-size: clamp(0.92rem, 2.5vw, 1.1rem); max-width: 650px; margin-bottom: 0;">Manage your stay, emergency contacts, valid ID records, and resort activity passes in your guest portal.</p>
            </div>
            <div style="position: absolute; right: -20px; bottom: -30px; opacity: 0.15; font-size: 14rem; color: #fff; pointer-events: none;">
                🌿
            </div>
        </div>

        <div class="user-dashboard-layout">
            <!-- Guest Profile Info Column -->
            <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                
                <!-- Card 1: Primary Profile Card -->
                <div class="card" style="text-align: center; align-self: start;">
                    <div style="position: relative; width: 90px; height: 90px; margin: 0 auto 1rem;">
                        <?php if (!empty($profile_picture) && file_exists(__DIR__ . '/' . $profile_picture)): ?>
                            <img src="<?= htmlspecialchars($profile_picture) ?>" alt="Profile Avatar" style="width: 90px; height: 90px; border-radius: 50%; object-fit: cover; border: 3px solid var(--bamboo-gold); box-shadow: var(--shadow-md); cursor: pointer;" title="Click to view full picture" onclick="openPictureModal('<?= htmlspecialchars($profile_picture) ?>', 'Guest Profile Avatar')">
                        <?php else: ?>
                            <div style="width: 90px; height: 90px; background: linear-gradient(135deg, var(--primary-purple), var(--bamboo-gold)); color: #fff; font-size: 2.8rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; box-shadow: var(--shadow-md);">
                                <?= strtoupper(substr($first_name, 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <span class="badge badge-purple" style="margin-bottom: 0.5rem;"><i class="fa-solid fa-shield-halved"></i> Guest Profile ID: <?= htmlspecialchars($profile_id) ?></span>
                    <h2 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-top: 0.25rem;"><?= htmlspecialchars($full_name) ?></h2>
                    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1rem;"><?= htmlspecialchars($email) ?></p>

                    <!-- Separated Name Details -->
                    <div style="text-align: left; background: var(--purple-light); padding: 1rem; border-radius: var(--radius-sm); font-size: 0.88rem; margin-bottom: 1rem;">
                        <h4 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); border-bottom: 1px solid rgba(107,33,168,0.2); padding-bottom: 0.3rem; margin-bottom: 0.6rem;">
                            <i class="fa-solid fa-signature"></i> Separated Name Fields
                        </h4>
                        <p style="margin-bottom: 0.3rem;"><strong>Last Name:</strong> <?= htmlspecialchars($last_name) ?></p>
                        <p style="margin-bottom: 0.3rem;"><strong>First Name:</strong> <?= htmlspecialchars($first_name) ?></p>
                        <p style="margin-bottom: 0.3rem;"><strong>Middle Name:</strong> <?= htmlspecialchars($middle_name ?: 'N/A') ?></p>
                        <p style="margin-bottom: 0.3rem;"><strong>Suffix:</strong> <?= htmlspecialchars($suffix ?: 'None') ?></p>
                        <p style="margin-bottom: 0.3rem;"><strong>Phone Number:</strong> <?= htmlspecialchars($phone) ?></p>
                    </div>
                </div>

                <!-- Card 2: Emergency Contact Information -->
                <div class="card" style="border-left: 4px solid #ef4444;">
                    <h3 style="font-family: 'Outfit', sans-serif; color: #b91c1c; font-size: 1.1rem; margin-bottom: 0.75rem;">
                        <i class="fa-solid fa-kit-medical" style="color: #ef4444;"></i> Emergency Contacts
                    </h3>
                    <div style="font-size: 0.88rem;">
                        <p style="margin-bottom: 0.4rem;"><strong><i class="fa-solid fa-user-shield"></i> Contact Person:</strong> <?= htmlspecialchars($emergency_contact_name ?: 'Not Provided') ?></p>
                        <p style="margin-bottom: 0.4rem;"><strong><i class="fa-solid fa-people-arrows"></i> Relationship:</strong> <?= htmlspecialchars($emergency_contact_relationship ?: 'N/A') ?></p>
                        <p style="margin-bottom: 0.4rem;"><strong><i class="fa-solid fa-phone"></i> Primary Phone:</strong> <?= htmlspecialchars($emergency_contact_phone ?: 'N/A') ?></p>
                        <?php if (!empty($emergency_contact_alt_phone)): ?>
                            <p><strong><i class="fa-solid fa-phone-volume"></i> Secondary Alt Phone:</strong> <?= htmlspecialchars($emergency_contact_alt_phone) ?></p>
                        <?php endif; ?>
                    </div>
                </div>


                <!-- Card 4: Health & Medical Safety Details -->
                <div class="card" style="border-left: 4px solid #10b981; background: #f0fdf4;">
                    <h3 style="font-family: 'Outfit', sans-serif; color: #166534; font-size: 1.1rem; margin-bottom: 0.75rem;">
                        <i class="fa-solid fa-heart-pulse" style="color: #10b981;"></i> Health Profile & Safety Alerts
                    </h3>
                    
                    <div style="margin-bottom: 0.75rem;">
                        <span style="font-size: 0.82rem; font-weight: 700; color: #15803d; display: block; margin-bottom: 0.4rem;">Registered Health Alerts:</span>
                        <div style="display: flex; flex-wrap: wrap; gap: 0.4rem;">
                            <?php if (!empty($health_conditions_list)): ?>
                                <?php foreach ($health_conditions_list as $condition): ?>
                                    <span style="background: #dcfce7; color: #15803d; border: 1px solid #86efac; padding: 0.25rem 0.6rem; border-radius: 12px; font-size: 0.78rem; font-weight: 700;">
                                        <i class="fa-solid fa-circle-dot" style="font-size: 0.6rem;"></i> <?= htmlspecialchars($condition) ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span style="color: var(--text-muted); font-size: 0.85rem;">No chronic health conditions flagged.</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($health_info)): ?>
                        <div style="background: #ffffff; padding: 0.75rem; border-radius: var(--radius-sm); border: 1px solid #bbf7d0; font-size: 0.82rem; color: #166534;">
                            <strong><i class="fa-solid fa-notes-medical"></i> Medical Notes / Instructions:</strong>
                            <p style="margin-top: 0.25rem; margin-bottom: 0; line-height: 1.4;"><?= nl2br(htmlspecialchars($health_info)) ?></p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Active Reservation & History Panels -->
            <div>
                <!-- Active Stay Card -->
                <div class="card" style="margin-bottom: 2rem; border-left: 5px solid var(--bamboo-gold);">
                    <h3 style="font-family: 'Outfit', sans-serif; margin-bottom: 1rem; color: var(--primary-purple);">
                        <i class="fa-solid fa-receipt" style="color: var(--bamboo-gold);"></i> Current Active Reservation
                    </h3>
                    
                    <?php if ($active_booking): ?>
                        <div style="border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 1.5rem; background: var(--purple-bg-soft);">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem;">
                                <div>
                                    <span class="badge <?= $active_booking['payment_status'] === 'verified' ? 'badge-success' : 'badge-pending' ?>">
                                        <?= $active_booking['payment_status'] === 'verified' ? '<i class="fa-solid fa-check"></i> Payment Verified' : '<i class="fa-solid fa-clock"></i> Payment Proof Submitted' ?>
                                    </span>
                                    <?php if (!empty($active_booking['resched_pending']) || ($active_booking['booking_status'] ?? '') === 'reschedule_requested'): ?>
                                        <span class="badge" style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; margin-left: 6px;">
                                            <i class="fa-solid fa-arrows-rotate"></i> Reschedule Pending Approval
                                        </span>
                                    <?php endif; ?>
                                    <h3 style="margin-top: 0.5rem; font-family: 'Outfit', sans-serif; color: var(--primary-purple);">Ref # <?= htmlspecialchars($active_booking['id']) ?></h3>
                                    <p style="font-size: 0.95rem; font-weight: 700; color: var(--text-dark); margin-top: 0.2rem;"><?= htmlspecialchars($active_booking['item_name']) ?></p>
                                </div>
                                <div style="text-align: right;">
                                    <span style="font-size: 0.85rem; color: var(--text-muted); display: block;">Total Payable</span>
                                    <span style="font-size: 1.5rem; font-weight: 800; color: var(--bamboo-gold);">₱<?= number_format(floatval($active_booking['total_amount'])) ?></span>
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; font-size: 0.88rem; padding-top: 1rem; border-top: 1px dashed var(--border-color); margin-bottom: 1.25rem;">
                                <div>
                                    <span style="color: var(--text-muted); display: block;"><i class="fa-solid fa-clock-rotate-left"></i> Booked On:</span>
                                    <strong><?= !empty($active_booking['created_at']) ? date('M d, Y g:i A', strtotime($active_booking['created_at'])) : 'Recent' ?></strong>
                                </div>
                                <div>
                                    <span style="color: var(--text-muted); display: block;"><i class="fa-regular fa-calendar"></i> Check-In (2:00 PM):</span>
                                    <strong style="color: var(--primary-purple);"><?= date('M d, Y', strtotime($active_booking['check_in'])) ?></strong>
                                    <span style="display: block; font-size: 0.78rem; color: #d97706; font-weight: 700;">2:00 PM</span>
                                </div>
                                <div>
                                    <span style="color: var(--text-muted); display: block;"><i class="fa-regular fa-calendar-check"></i> Check-Out (12:00 PM):</span>
                                    <strong style="color: var(--primary-purple);"><?= !empty($active_booking['check_out']) ? date('M d, Y', strtotime($active_booking['check_out'])) : 'N/A' ?></strong>
                                    <span style="display: block; font-size: 0.78rem; color: #059669; font-weight: 700;">12:00 PM (Noon)</span>
                                </div>
                                <div>
                                    <span style="color: var(--text-muted); display: block;"><i class="fa-solid fa-users"></i> Guests Pax:</span>
                                    <strong><?= $active_booking['pax'] ?> Pax</strong>
                                </div>
                            </div>

                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                <a href="view_email.php?ref=<?= urlencode($active_booking['id']) ?>" target="_blank" class="btn btn-primary btn-sm"><i class="fa-solid fa-envelope-open-text"></i> View Email Voucher</a>
                                <a href="payment_method.php?ref=<?= urlencode($active_booking['id']) ?>" class="btn btn-gold btn-sm"><i class="fa-solid fa-wallet"></i> Pay / View GCash Receipt</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 2rem 1rem; color: var(--text-muted);">
                            <i class="fa-solid fa-house-user" style="font-size: 3rem; margin-bottom: 0.5rem; color: var(--border-color);"></i>
                            <p>You have no active pending or upcoming resort reservations.</p>
                            <a href="browse_kubos.php" class="btn btn-primary btn-sm" style="margin-top: 0.75rem;">Book Your Bamboo Kubo Stay</a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Past History Card -->
                <div class="card">
                    <h3 style="font-family: 'Outfit', sans-serif; margin-bottom: 1rem; color: var(--primary-purple);">
                        <i class="fa-solid fa-clock-rotate-left"></i> My Resort History
                    </h3>

                    <div class="table-responsive history-scroll-container" style="max-height: 420px; overflow-y: auto; overflow-x: auto; -webkit-overflow-scrolling: touch; border: 1.5px solid #e9d5ff; border-radius: 8px;">
                        <table class="table" style="min-width: 600px;">
                            <thead>
                                <tr>
                                    <th>Ref #</th>
                                    <th>House / Activity</th>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($past_bookings)): ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 1.5rem;">No past reservation history found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($past_bookings as $pb): ?>
                                        <tr>
                                            <td><strong>#<?= htmlspecialchars($pb['id']) ?></strong></td>
                                            <td><?= htmlspecialchars($pb['item_name']) ?></td>
                                            <td><?= date('M d, Y', strtotime($pb['check_in'])) ?></td>
                                            <td><strong style="color: var(--bamboo-gold);">₱<?= number_format(floatval($pb['total_amount'])) ?></strong></td>
                                            <td>
                                                <span class="badge <?= $pb['booking_status'] === 'checked_out' ? 'badge-success' : ($pb['booking_status'] === 'cancelled' ? 'badge-danger' : 'badge-purple') ?>">
                                                    <?= strtoupper($pb['booking_status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Picture Lightbox Modal -->
    <div id="picture_modal" class="picture-modal-overlay" onclick="closePictureModal(event)">
        <div class="picture-modal-container" onclick="event.stopPropagation()">
            <button type="button" class="picture-modal-close" onclick="closePictureModal()">&times;</button>
            <h3 id="picture_modal_title" style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-bottom: 0.5rem;">Uploaded Picture View</h3>
            <img id="picture_modal_img" src="" class="picture-modal-img" alt="Enlarged View">
        </div>
    </div>

    <?php include 'footer.php'; ?>

    <script>
    function openPictureModal(imgSrc, title) {
        if (!imgSrc) return;
        document.getElementById('picture_modal_img').src = imgSrc;
        document.getElementById('picture_modal_title').textContent = title || 'Picture Preview';
        document.getElementById('picture_modal').classList.add('active');
    }

    function closePictureModal(e) {
        document.getElementById('picture_modal').classList.remove('active');
    }
    </script>

</body>
</html>
