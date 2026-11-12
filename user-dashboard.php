<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/booking_store.php';
require_once __DIR__ . '/auth.php';

// Require logged-in guest session
if (empty($_SESSION['user_id'])) {
    header("Location: login.php?msg=" . urlencode("Please sign in first to access your Guest Portal.") . "&redirect=user-dashboard.php");
    exit();
}

// Disallow blocked user
if ($block_reason = Auth::isUserBlocked($_SESSION['user_id'])) {
    Auth::logout();
    header("Location: login.php?msg=" . urlencode("Your account has been deactivated/blocked by management: " . $block_reason));
    exit();
}

$user_id = $_SESSION['user_id'];
$profile_id = $_SESSION['profile_id'] ?? sprintf("PBR-GST-%05d", $user_id);
$profile_success_msg = '';
$profile_error_msg = '';

// Handle Profile Edit POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $res = Auth::updateProfile($user_id, $_POST, $_FILES['profile_picture_file'] ?? null);
    if (!empty($res['success'])) {
        $profile_success_msg = $res['message'] ?? 'Your profile and avatar picture have been updated successfully!';
    } else {
        $profile_error_msg = $res['error'] ?? 'Unable to update profile. Please try again.';
    }
}

// Sync & ensure latest profile details from database
if (!empty($pdo) && !empty($user_id)) {
    try {
        $stmtU = $pdo->prepare("SELECT *, CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name, NULLIF(suffix, '')) AS computed_full_name FROM users WHERE id = :id LIMIT 1");
        $stmtU->execute([':id' => $user_id]);
        $dbUser = $stmtU->fetch(PDO::FETCH_ASSOC);
        if ($dbUser) {
            if (!empty($dbUser['first_name'])) $_SESSION['first_name'] = $dbUser['first_name'];
            if (isset($dbUser['middle_name'])) $_SESSION['middle_name'] = $dbUser['middle_name'];
            if (!empty($dbUser['last_name'])) $_SESSION['last_name'] = $dbUser['last_name'];
            if (isset($dbUser['suffix'])) $_SESSION['suffix'] = $dbUser['suffix'];
            $cName = trim($dbUser['computed_full_name'] ?? '');
            if (!empty($cName)) {
                $_SESSION['full_name'] = $cName;
                $_SESSION['username'] = $cName;
            }
            if (!empty($dbUser['profile_id'])) $_SESSION['profile_id'] = $dbUser['profile_id'];
            if (!empty($dbUser['email'])) $_SESSION['email'] = $dbUser['email'];
            if (!empty($dbUser['phone'])) $_SESSION['phone'] = $dbUser['phone'];
            if (!empty($dbUser['profile_picture'])) $_SESSION['profile_picture'] = $dbUser['profile_picture'];
        }
    } catch (PDOException $e) {}
}

$first_name = trim($_SESSION['first_name'] ?? '');
if (empty($first_name) || strtolower($first_name) === 'guest') {
    if (!empty($_SESSION['full_name']) && strtolower(trim($_SESSION['full_name'])) !== 'guest visitor') {
        $first_name = explode(' ', trim($_SESSION['full_name']))[0];
    } elseif (!empty($_SESSION['email'])) {
        $first_name = ucwords(explode('@', $_SESSION['email'])[0]);
    } else {
        $first_name = 'Member';
    }
}
$middle_name = trim($_SESSION['middle_name'] ?? '');
$last_name = trim($_SESSION['last_name'] ?? '');
$suffix = trim($_SESSION['suffix'] ?? '');
$full_name = !empty(trim($_SESSION['full_name'] ?? '')) && strtolower(trim($_SESSION['full_name'])) !== 'guest visitor' 
    ? trim($_SESSION['full_name']) 
    : trim("{$first_name} {$middle_name} {$last_name} {$suffix}");

if (empty($full_name) || strtolower($full_name) === 'guest visitor') {
    if (!empty($_SESSION['email'])) {
        $full_name = ucwords(str_replace(['.', '_', '-'], ' ', explode('@', $_SESSION['email'])[0]));
    } else {
        $full_name = $first_name;
    }
}
$display_name = !empty($first_name) && strtolower($first_name) !== 'guest' ? $first_name : $full_name;
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

foreach ($user_bookings as &$b) {
    if (!empty($b['rebook_deadline']) && empty($b['is_forfeited'])) {
        if (strtotime($b['rebook_deadline']) < time()) {
            $b['is_forfeited'] = 1;
            BookingStore::updateBookingStatus($b['id'], null, null, null, null, null, null, null, 1);
        }
    }
}
unset($b);

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
            border: 1.5px solid #eddcf2;
            border-radius: var(--radius-sm, 8px);
            scrollbar-width: thin;
            scrollbar-color: #e0129c #fdf4ff;
            background: #ffffff;
        }
        .history-scroll-container::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        .history-scroll-container::-webkit-scrollbar-track {
            background: #fdf4ff;
            border-radius: 4px;
        }
        .history-scroll-container::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #e0129c, #00c2ff);
            border-radius: 4px;
            border: 2px solid #fdf4ff;
        }
        .history-scroll-container::-webkit-scrollbar-thumb:hover {
            background: #e0129c;
        }
        .history-scroll-container thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: linear-gradient(135deg, #200424, #3d0947) !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
        }
    </style>
</head>
<body>

    <?php include 'nav.php'; ?>

    <div class="container">
        <!-- Registration Welcome Banner -->
        <?php if (isset($_GET['registered'])): ?>
            <div class="card" style="background: linear-gradient(135deg, #ecfdf5, #d1fae5); border: 1.5px solid #10b981; color: #065f46; padding: 1.15rem 1.4rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 14px; border-radius: var(--radius-md); box-shadow: 0 4px 15px rgba(16, 185, 129, 0.12);">
                <i class="fa-solid fa-circle-check" style="font-size: 1.6rem; color: #10b981; flex-shrink: 0;"></i>
                <div>
                    <div style="font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 1.12rem; color: #047857;">Welcome to Purple Bamboo Resort, <?= htmlspecialchars($display_name) ?>! 🎉</div>
                    <div style="font-size: 0.9rem; color: #065f46; margin-top: 3px;">Your account registration was successful. You are now logged in and can manage your reservations, activities, and guest profile below.</div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Profile Flash Notifications -->
        <?php if ($profile_success_msg): ?>
            <div class="card" style="background: #dcfce7; border: 1.5px solid #22c55e; color: #15803d; padding: 1rem 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 10px; border-radius: var(--radius-md);">
                <i class="fa-solid fa-circle-check" style="font-size: 1.3rem;"></i>
                <span style="font-weight: 700;"><?= htmlspecialchars($profile_success_msg) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($profile_error_msg): ?>
            <div class="card" style="background: #fee2e2; border: 1.5px solid #ef4444; color: #b91c1c; padding: 1rem 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 10px; border-radius: var(--radius-md);">
                <i class="fa-solid fa-circle-exclamation" style="font-size: 1.3rem;"></i>
                <span style="font-weight: 700;"><?= htmlspecialchars($profile_error_msg) ?></span>
            </div>
        <?php endif; ?>

        <!-- Guest Welcome Header Banner -->
        <div class="card user-welcome-card" style="background: linear-gradient(135deg, var(--primary-purple), var(--purple-accent)); color: #ffffff; padding: clamp(1.25rem, 4vw, 2.5rem); margin-bottom: 2rem; position: relative; overflow: hidden; border: none;">
            <div style="position: relative; z-index: 2;">
                <span class="badge badge-gold" style="margin-bottom: 0.75rem;"><i class="fa-solid fa-id-badge"></i> Profile ID: <?= htmlspecialchars($profile_id) ?></span>
                <h1 style="font-family: 'Outfit', sans-serif; font-size: clamp(1.6rem, 5vw, 2.5rem); color: #ffffff; margin-bottom: 0.5rem;">Mabuhay, <?= htmlspecialchars($display_name) ?>! 👋</h1>
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
                                <?= strtoupper(substr($display_name, 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                    </div>
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

                    <button type="button" class="btn btn-purple btn-sm" style="width: 100%; justify-content: center; font-weight: 700;" onclick="openEditProfileModal()">
                        <i class="fa-solid fa-user-pen"></i> Edit Profile &amp; Avatar
                    </button>
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
                                    <?php if ($active_booking['payment_status'] === 'verified'): ?>
                                        <span class="badge badge-success"><i class="fa-solid fa-check"></i> Payment Verified</span>
                                    <?php elseif ($active_booking['payment_status'] === 'rejected'): ?>
                                        <span class="badge" style="background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca;"><i class="fa-solid fa-circle-xmark"></i> Payment Rejected - Action Required</span>
                                    <?php elseif (!empty($active_booking['resubmitted_at']) || !empty($active_booking['prev_rejection_reason'])): ?>
                                        <span class="badge" style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;"><i class="fa-solid fa-arrow-rotate-right"></i> Resubmitted Proof Under Review</span>
                                    <?php else: ?>
                                        <span class="badge badge-pending"><i class="fa-solid fa-clock"></i> Payment Proof Submitted</span>
                                    <?php endif; ?>
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
                                <?php
                                $act_in_ts = !empty($active_booking['check_in']) ? strtotime($active_booking['check_in']) : false;
                                $act_out_ts = !empty($active_booking['check_out']) ? strtotime($active_booking['check_out']) : false;
                                $act_in_t = ($act_in_ts && date('H:i', $act_in_ts) !== '00:00') ? date('g:i A', $act_in_ts) : '2:00 PM';
                                $act_out_t = ($act_out_ts && date('H:i', $act_out_ts) !== '00:00') ? date('g:i A', $act_out_ts) : '12:00 PM';
                                ?>
                                <div>
                                    <span style="color: var(--text-muted); display: block;"><i class="fa-regular fa-calendar"></i> Check-In (<?= $act_in_t ?>):</span>
                                    <strong style="color: var(--primary-purple);"><?= date('M d, Y', strtotime($active_booking['check_in'])) ?></strong>
                                    <span style="display: block; font-size: 0.78rem; color: #d97706; font-weight: 700;"><?= $act_in_t ?></span>
                                </div>
                                <div>
                                    <span style="color: var(--text-muted); display: block;"><i class="fa-regular fa-calendar-check"></i> Check-Out (<?= $act_out_t ?>):</span>
                                    <strong style="color: var(--primary-purple);"><?= !empty($active_booking['check_out']) ? date('M d, Y', strtotime($active_booking['check_out'])) : 'N/A' ?></strong>
                                    <span style="display: block; font-size: 0.78rem; color: #059669; font-weight: 700;"><?= $act_out_t ?></span>
                                </div>
                                <div>
                                    <span style="color: var(--text-muted); display: block;"><i class="fa-solid fa-users"></i> Guests Pax:</span>
                                    <strong><?= $active_booking['pax'] ?> Pax</strong>
                                </div>
                            </div>

                            <?php if ($active_booking['payment_status'] === 'rejected'): ?>
                                <div style="background: #fef2f2; border: 1.5px solid #fecaca; border-radius: 10px; padding: 0.85rem 1rem; margin-bottom: 1.25rem; font-size: 0.88rem; color: #7f1d1d;">
                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 4px;">
                                        <strong style="color: #b91c1c; display: flex; align-items: center; gap: 6px;">
                                            <i class="fa-solid fa-triangle-exclamation"></i> Payment Proof Rejected by Resort Desk
                                        </strong>
                                        <span style="font-size: 0.7rem; background: #fee2e2; color: #b91c1c; padding: 2px 6px; border-radius: 4px; border: 1px solid #fca5a5; font-weight: 800;">ACTION REQUIRED</span>
                                    </div>
                                    <div style="margin-bottom: 4px;">
                                        Reason: <strong style="color: #991b1b;"><?= htmlspecialchars(!empty($active_booking['rejection_reason']) ? $active_booking['rejection_reason'] : 'Proof could not be verified by resort staff') ?></strong>
                                    </div>
                                    <small style="color: #64748b; font-size: 0.76rem; display: block;">Please send a new receipt screenshot and 13-digit reference number to proceed.</small>
                                </div>
                            <?php endif; ?>

                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                <a href="view_email.php?ref=<?= urlencode($active_booking['id']) ?>" target="_blank" class="btn btn-primary btn-sm"><i class="fa-solid fa-envelope-open-text"></i> View Email Voucher</a>
                                <?php if ($active_booking['payment_status'] === 'rejected'): ?>
                                    <a href="payment_method.php?ref=<?= urlencode($active_booking['id']) ?>&reupload=1" class="btn btn-sm" style="background: #ef4444; color: white; border: 1px solid #dc2626; font-weight: 700;"><i class="fa-solid fa-arrow-up-from-bracket"></i> Resubmit Payment Proof</a>
                                <?php else: ?>
                                    <a href="payment_method.php?ref=<?= urlencode($active_booking['id']) ?>" class="btn btn-gold btn-sm"><i class="fa-solid fa-wallet"></i> Pay / View GCash Receipt</a>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!($active_booking['payment_status'] === 'rejected') && in_array(strtolower($active_booking['payment_status'] ?? ''), ['verified', 'proof_submitted'])): ?>
                                <div style="margin-top: 1.5rem; border-top: 1px dashed var(--border-color); padding-top: 1.25rem;">
                                    <h4 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-bottom: 1rem; font-size: 1.1rem;">
                                        <i class="fa-solid fa-map-location-dot" style="color: var(--primary-magenta);"></i> Resort Ride &amp; Arrival Guide
                                    </h4>
                                    <div style="background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: var(--shadow-sm);">
                                        <?php include __DIR__ . '/includes/ride_guide_widget.php'; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
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
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
                        <h3 style="font-family: 'Outfit', sans-serif; margin: 0; color: var(--primary-purple);">
                            <i class="fa-solid fa-clock-rotate-left"></i> My Bookings History
                        </h3>
                        <a href="history.php" class="btn btn-outline btn-sm" style="font-size: 0.8rem; font-weight: 700;">
                            Manage in My Bookings <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>

                    <!-- Wrapped for scrollbar and responsive viewing -->
                    <div class="table-responsive-wrapper history-scroll-container" style="max-height: 420px; overflow-y: auto; overflow-x: auto; -webkit-overflow-scrolling: touch; border: 1.5px solid #eddcf2; border-radius: 8px;">
                        <table class="table booking-history-table" style="width:100%; border-collapse: collapse; min-width: 600px;">
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
                                            <td data-label="Ref #"><strong>#<?= htmlspecialchars($pb['id']) ?></strong></td>
                                            <td data-label="Stay / Item"><?= htmlspecialchars($pb['item_name'] ?? 'Resort Stay') ?></td>
                                            <td data-label="Dates">
                                                <?= date('M d, Y', strtotime($pb['check_in'])) ?>
                                                <?php if (!empty($pb['check_out']) && $pb['check_out'] !== $pb['check_in']): ?>
                                                    &rarr; <?= date('M d, Y', strtotime($pb['check_out'])) ?>
                                                <?php endif; ?><br>
                                                <?php
                                                $pb_in_ts  = !empty($pb['check_in'])  ? strtotime($pb['check_in'])  : false;
                                                $pb_out_ts = !empty($pb['check_out']) ? strtotime($pb['check_out']) : false;
                                                $pb_in_t   = ($pb_in_ts  && date('H:i', $pb_in_ts)  !== '00:00') ? date('g:i A', $pb_in_ts)  : '2:00 PM';
                                                $pb_out_t  = ($pb_out_ts && date('H:i', $pb_out_ts) !== '00:00') ? date('g:i A', $pb_out_ts) : '12:00 PM';
                                                ?>
                                                <small style="color: var(--text-muted); font-size: 0.72rem;">In: <?= $pb_in_t ?> | Out: <?= $pb_out_t ?></small>
                                            </td>
                                            <td data-label="Amount"><strong style="color: var(--bamboo-gold);">&#8369;<?= number_format(floatval($pb['total_amount'])) ?></strong></td>
                                            <td data-label="Status">
                                                <span class="badge <?= $pb['booking_status'] === 'checked_out' ? 'badge-success' : ($pb['booking_status'] === 'cancelled' ? 'badge-danger' : 'badge-purple') ?>">
                                                    <?= strtoupper($pb['booking_status']) ?>
                                                </span>
                                                <?php if (in_array(strtolower($pb['booking_status'] ?? ''), ['completed', 'checked_out'])): ?>
                                                    <div style="margin-top: 6px;">
                                                        <a href="reviews.php?booking_id=<?= urlencode($pb['id']) ?>#write-review" class="btn btn-sm btn-gold" style="font-size: 0.7rem; padding: 3px 8px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                                            <i class="fa-solid fa-star"></i> Review Stay
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($pb['rejection_reason'])): ?>
                                                    <div style="font-size: 0.72rem; color: #ef4444; margin-top: 4px;">Reason: <?= htmlspecialchars($pb['rejection_reason']) ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($pb['payment_status']) && $pb['payment_status'] === 'rejected'): ?>
                                                    <div style="margin-top: 6px;">
                                                        <a href="payment_method.php?ref=<?= urlencode($pb['id']) ?>&reupload=1" class="btn btn-sm" style="font-size: 0.7rem; padding: 3px 8px; font-weight: 700; background: #ef4444; color: #fff; border: 1px solid #dc2626;"><i class="fa-solid fa-arrow-up-from-bracket"></i> Resend Proof</a>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($pb['rebook_deadline'])): ?>
                                                    <?php if (!empty($pb['is_forfeited'])): ?>
                                                        <div style="font-size: 0.72rem; color: #ef4444; margin-top: 4px; font-weight: bold;"><i class="fa-solid fa-circle-exclamation"></i> Payment Forfeited (Booking Fee)</div>
                                                    <?php else: ?>
                                                        <div style="margin-top: 6px;">
                                                            <a href="rebook.php?ref=<?= urlencode($pb['id']) ?>" class="btn btn-sm btn-gold" style="font-size: 0.7rem; padding: 3px 8px; font-weight: 700;"><i class="fa-solid fa-rotate"></i> Rebook Now</a>
                                                        </div>
                                                        <div style="font-size: 0.7rem; color: #d97706; margin-top: 3px;">Expires: <?= date('M d, h:i A', strtotime($pb['rebook_deadline'])) ?></div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
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

    <!-- Edit Profile Modal Dialog -->
    <div id="edit_profile_modal" class="picture-modal-overlay" onclick="closeEditProfileModal(event)">
        <div class="picture-modal-container" onclick="event.stopPropagation()" style="max-width: 650px; text-align: left; max-height: 90vh; overflow-y: auto; padding: 2rem;">
            <button type="button" class="picture-modal-close" onclick="closeEditProfileModal()">&times;</button>
            
            <div style="border-bottom: 1.5px solid #e2e8f0; padding-bottom: 1rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 12px;">
                <div style="width: 42px; height: 42px; border-radius: 10px; background: rgba(107, 33, 168, 0.1); color: var(--primary-purple); display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                    <i class="fa-solid fa-user-pen"></i>
                </div>
                <div>
                    <h3 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin: 0; font-size: 1.35rem;">Edit Guest Profile &amp; Avatar</h3>
                    <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0;">Update your name, contact numbers, emergency details, and profile photo.</p>
                </div>
            </div>

            <form action="user-dashboard.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile">

                <!-- Avatar Upload Section -->
                <div style="display: flex; align-items: center; gap: 1.25rem; background: var(--purple-light); padding: 1.25rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem;">
                    <div style="position: relative;">
                        <img id="avatar_preview_img" src="<?= (!empty($profile_picture) && file_exists(__DIR__ . '/' . $profile_picture)) ? htmlspecialchars($profile_picture) : 'assets/logo.jpg' ?>" alt="Avatar Preview" style="width: 76px; height: 76px; border-radius: 50%; object-fit: cover; border: 3px solid var(--bamboo-gold); box-shadow: var(--shadow-sm);">
                    </div>
                    <div style="flex: 1;">
                        <label style="display: block; font-weight: 700; font-size: 0.88rem; color: var(--primary-purple); margin-bottom: 0.3rem;">
                            <i class="fa-solid fa-camera"></i> Change Profile Picture
                        </label>
                        <input type="file" name="profile_picture_file" id="profile_picture_file" accept="image/png,image/jpeg,image/webp" onchange="previewProfilePic(this)" style="font-size: 0.82rem; width: 100%;">
                        <span style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-top: 0.3rem;">Accepted formats: JPG, PNG, WEBP. Max size: 5MB.</span>
                    </div>
                </div>

                <!-- Name Fields Grid -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 0.75rem; margin-bottom: 1rem;">
                    <div>
                        <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.3rem; color: var(--text-dark);">First Name *</label>
                        <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($first_name) ?>" required style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #cbd5e1; border-radius: var(--radius-sm); font-size: 0.9rem;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.3rem; color: var(--text-dark);">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control" value="<?= htmlspecialchars($middle_name) ?>" style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #cbd5e1; border-radius: var(--radius-sm); font-size: 0.9rem;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.3rem; color: var(--text-dark);">Last Name *</label>
                        <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($last_name) ?>" required style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #cbd5e1; border-radius: var(--radius-sm); font-size: 0.9rem;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.3rem; color: var(--text-dark);">Suffix</label>
                        <select name="suffix" class="form-control" style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #cbd5e1; border-radius: var(--radius-sm); font-size: 0.9rem;">
                            <option value="">None</option>
                            <option value="Jr." <?= ($suffix === 'Jr.') ? 'selected' : '' ?>>Jr.</option>
                            <option value="Sr." <?= ($suffix === 'Sr.') ? 'selected' : '' ?>>Sr.</option>
                            <option value="II" <?= ($suffix === 'II') ? 'selected' : '' ?>>II</option>
                            <option value="III" <?= ($suffix === 'III') ? 'selected' : '' ?>>III</option>
                            <option value="IV" <?= ($suffix === 'IV') ? 'selected' : '' ?>>IV</option>
                            <option value="V" <?= ($suffix === 'V') ? 'selected' : '' ?>>V</option>
                        </select>
                    </div>
                </div>

                <!-- Contact Numbers -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.25rem;">
                    <div>
                        <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.3rem; color: var(--text-dark);">
                            <i class="fa-solid fa-phone"></i> Mobile Phone Number *
                        </label>
                        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($phone) ?>" required style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #cbd5e1; border-radius: var(--radius-sm); font-size: 0.9rem;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 0.3rem; color: var(--text-dark);">
                            <i class="fa-solid fa-envelope"></i> Email Address (Account ID)
                        </label>
                        <input type="email" value="<?= htmlspecialchars($email) ?>" disabled style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; background: #f8fafc; color: #64748b; border-radius: var(--radius-sm); font-size: 0.9rem;">
                        <span style="font-size: 0.72rem; color: var(--text-muted);">Email serves as your unique login and cannot be altered directly.</span>
                    </div>
                </div>

                <!-- Emergency Contact Group -->
                <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: var(--radius-sm); padding: 1.25rem; margin-bottom: 1.25rem;">
                    <h4 style="color: #b91c1c; font-size: 0.95rem; font-family: 'Outfit', sans-serif; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-kit-medical"></i> Emergency Contact Information
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem; margin-bottom: 0.75rem;">
                        <div>
                            <label style="display: block; font-size: 0.78rem; font-weight: 700; color: #7f1d1d; margin-bottom: 0.25rem;">Contact Person Name</label>
                            <input type="text" name="emergency_contact_name" class="form-control" value="<?= htmlspecialchars($emergency_contact_name) ?>" placeholder="Full Name" style="width: 100%; padding: 0.45rem 0.65rem; border: 1px solid #fca5a5; border-radius: 4px; font-size: 0.88rem;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.78rem; font-weight: 700; color: #7f1d1d; margin-bottom: 0.25rem;">Relationship</label>
                            <input type="text" name="emergency_contact_relationship" class="form-control" value="<?= htmlspecialchars($emergency_contact_relationship) ?>" placeholder="e.g. Partner, Parent, Sibling" style="width: 100%; padding: 0.45rem 0.65rem; border: 1px solid #fca5a5; border-radius: 4px; font-size: 0.88rem;">
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem;">
                        <div>
                            <label style="display: block; font-size: 0.78rem; font-weight: 700; color: #7f1d1d; margin-bottom: 0.25rem;">Emergency Phone Number</label>
                            <input type="text" name="emergency_contact_phone" class="form-control" value="<?= htmlspecialchars($emergency_contact_phone) ?>" placeholder="09XX XXX XXXX" style="width: 100%; padding: 0.45rem 0.65rem; border: 1px solid #fca5a5; border-radius: 4px; font-size: 0.88rem;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.78rem; font-weight: 700; color: #7f1d1d; margin-bottom: 0.25rem;">Secondary Alt Phone</label>
                            <input type="text" name="emergency_contact_alt_phone" class="form-control" value="<?= htmlspecialchars($emergency_contact_alt_phone) ?>" placeholder="Optional" style="width: 100%; padding: 0.45rem 0.65rem; border: 1px solid #fca5a5; border-radius: 4px; font-size: 0.88rem;">
                        </div>
                    </div>
                </div>

                <!-- Health & Medical Alerts -->
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: var(--radius-sm); padding: 1.25rem; margin-bottom: 1.5rem;">
                    <h4 style="color: #166534; font-size: 0.95rem; font-family: 'Outfit', sans-serif; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-heart-pulse"></i> Health Profile &amp; Medical Notes
                    </h4>
                    <div style="margin-bottom: 0.75rem;">
                        <label style="display: block; font-size: 0.78rem; font-weight: 700; color: #14532d; margin-bottom: 0.25rem;">Known Health Conditions (Comma separated)</label>
                        <input type="text" name="health_conditions" class="form-control" value="<?= htmlspecialchars($health_conditions_raw) ?>" placeholder="e.g. Asthma, Allergies, Hypertension" style="width: 100%; padding: 0.45rem 0.65rem; border: 1px solid #86efac; border-radius: 4px; font-size: 0.88rem;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.78rem; font-weight: 700; color: #14532d; margin-bottom: 0.25rem;">Special Medical Instructions / Dietary Notes</label>
                        <textarea name="health_info" rows="2" class="form-control" placeholder="Any special needs or medications for front desk and safety personnel" style="width: 100%; padding: 0.45rem 0.65rem; border: 1px solid #86efac; border-radius: 4px; font-size: 0.88rem;"><?= htmlspecialchars($health_info) ?></textarea>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div style="display: flex; gap: 0.75rem; justify-content: flex-end; border-top: 1px solid #e2e8f0; padding-top: 1.25rem;">
                    <button type="button" class="btn btn-outline" onclick="closeEditProfileModal()">Cancel</button>
                    <button type="submit" class="btn btn-purple" style="font-weight: 700; padding: 0.6rem 1.5rem;">
                        <i class="fa-solid fa-floppy-disk"></i> Save Profile Changes
                    </button>
                </div>
            </form>
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

    function openEditProfileModal() {
        const modal = document.getElementById('edit_profile_modal');
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeEditProfileModal(e) {
        const modal = document.getElementById('edit_profile_modal');
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    function previewProfilePic(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('avatar_preview_img');
                if (preview) preview.src = e.target.result;
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closePictureModal();
            closeEditProfileModal();
        }
    });
    </script>

</body>
</html>
