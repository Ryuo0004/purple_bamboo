<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/booking_store.php';
require_once __DIR__ . '/inventory_store.php';

$activity_id = intval($_GET['activity_id'] ?? 1);
$all_activities = InventoryStore::getAllActivities();

// Strictly require registered account login to book activities
if (empty($_SESSION['user_id'])) {
    $redirect_url = 'book_activities.php?activity_id=' . $activity_id;
    $login_url = 'login.php?msg=' . urlencode('Please sign in with your registered account before booking an activity. Please sign in or register first.') . '&redirect=' . urlencode($redirect_url);
    header("Location: " . $login_url);
    exit();
}

$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sched_date = trim($_POST['scheduled_date'] ?? '');
    $act_id = intval($_POST['activity_id'] ?? $activity_id);
    $pax = intval($_POST['pax'] ?? 0);
    $slot = trim($_POST['time_slot'] ?? '');
    $today_str = date('Y-m-d');

    $selected = InventoryStore::getActivityById($act_id);

    if (empty($sched_date) || !strtotime($sched_date)) {
        $error_msg = "Please select a valid scheduled date for your activity reservation.";
    } elseif ($sched_date < $today_str) {
        $error_msg = "Scheduled activity date cannot be in the past.";
    } elseif ($pax < 1 || $pax > 20) {
        $error_msg = "Please specify a valid number of participants (1 to 20 pax).";
    } elseif (empty($slot)) {
        $error_msg = "Please select a preferred time slot.";
    } elseif (!$selected) {
        $error_msg = "The selected resort activity was not found or is unavailable.";
    } elseif (empty($_POST['activity_consent'])) {
        $error_msg = "Please accept the refund policy and resort safety rules to proceed.";
    } else {
        $ref_no = 'ACT-' . date('Y') . '-' . rand(1000, 9999);
        $act_name = $selected['activity_name'] ?? 'Resort Activity';
        $act_price = floatval($selected['price'] ?? 350);
        $total_amount = $act_price * $pax;

        $user_id = $_SESSION['user_id'];
        $profile_id = $_SESSION['profile_id'] ?? sprintf("PBR-GST-%05d", $user_id);
        $guest_name = !empty($_SESSION['full_name']) ? $_SESSION['full_name'] : 'Registered Guest';
        $guest_email = !empty($_SESSION['email']) ? $_SESSION['email'] : 'guest@example.com';
        $guest_phone = !empty($_SESSION['phone']) ? $_SESSION['phone'] : '09170000000';

        BookingStore::createBooking([
            'id' => $ref_no,
            'guest_name' => $guest_name,
            'guest_email' => $guest_email,
            'guest_phone' => $guest_phone,
            'type' => 'activity',
            'item_id' => $act_id,
            'item_name' => $act_name,
            'check_in' => $sched_date,
            'check_out' => $sched_date,
            'time_slot' => $slot,
            'pax' => $pax,
            'total_amount' => $total_amount,
            'profile_id' => $profile_id,
            'user_id' => $user_id,
            'payment_status' => 'pending',
            'booking_status' => 'pending',
            'special_notes' => 'Pool activity slot reservation'
        ]);

        header("Location: payment_method.php?ref=" . $ref_no);
        exit();
    }
}
$user_role = $_SESSION['role'] ?? 'guest';
$is_admin_layout = ($user_role === 'admin' || $user_role === 'staff');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reserve Activity Session | Purple Bamboo Resort</title>
    <?php if ($is_admin_layout): ?>
        <link rel="stylesheet" href="admin-style.css">
    <?php else: ?>
        <link rel="stylesheet" href="style.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="<?= $is_admin_layout ? 'admin-layout-body' : '' ?>">

    <?php if ($is_admin_layout): ?>
        <?php include 'admin-nav.php'; ?>
        <div class="admin-main-wrapper">
            <header class="admin-top-header">
                <div class="admin-top-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" placeholder="Search activities..." onkeyup="if(event.key==='Enter') window.location='activity_monitoring.php?search='+encodeURIComponent(this.value)">
                </div>
                <div class="admin-header-actions">
                    <div class="admin-header-profile-pill">
                        <div class="profile-avatar-circle"><?= strtoupper(substr($admin_full_name ?? 'Admin', 0, 1)) ?></div>
                        <div class="profile-meta">
                            <span class="profile-name"><?= htmlspecialchars($admin_full_name ?? 'Admin') ?></span>
                            <span class="profile-status">Online</span>
                        </div>
                    </div>
                </div>
            </header>
            <main class="admin-container">
    <?php else: ?>
        <?php include 'nav.php'; ?>
        <div class="container" style="max-width: 600px;">
    <?php endif; ?>

        <div class="<?= $is_admin_layout ? 'admin-page-header' : 'page-header' ?>">
            <div>
                <h1 class="<?= $is_admin_layout ? 'admin-page-title' : '' ?>"><i class="fa-solid fa-water-ladder" style="color: var(--gold-glow, var(--bamboo-gold));"></i> Reserve Resort Activity</h1>
                <p class="<?= $is_admin_layout ? 'admin-page-subtitle' : '' ?>">Schedule time slot and participant count for pool/water activities.</p>
            </div>
        </div>


        <?php if (!empty($error_msg)): ?>
            <div style="background: rgba(239, 68, 68, 0.15); color: #b91c1c; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <!-- Registered Guest Verification Callout -->
            <div style="background: linear-gradient(135deg, rgba(124, 58, 237, 0.08) 0%, rgba(245, 158, 11, 0.08) 100%); border: 1.5px solid rgba(124, 58, 237, 0.3); border-radius: 10px; padding: 0.85rem 1rem; margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="width: 32px; height: 32px; background: var(--primary-purple); color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.95rem;">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                    <div>
                        <span style="font-size: 0.72rem; text-transform: uppercase; font-weight: 800; color: #16a34a; letter-spacing: 0.5px; display: block;">
                            <i class="fa-solid fa-circle-check"></i> Verified Account
                        </span>
                        <strong style="font-size: 0.9rem; color: #1e1b4b;"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Guest') ?></strong>
                    </div>
                </div>
                <span class="badge badge-purple" style="font-size: 0.72rem;"><?= htmlspecialchars($_SESSION['profile_id'] ?? 'Registered Member') ?></span>
            </div>

            <form action="book_activities.php" method="POST">
                <div class="form-group">
                    <label><i class="fa-solid fa-water-ladder"></i> Select Activity</label>
                    <select name="activity_id" class="form-control">
                        <?php foreach ($all_activities as $act): ?>
                            <?php if (!empty($act['is_available'])): ?>
                                <option value="<?= $act['activity_id'] ?>" <?= $activity_id == $act['activity_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($act['activity_name']) ?> (₱<?= number_format($act['price']) ?> / <?= htmlspecialchars($act['duration'] ?? 'session') ?>)
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fa-regular fa-calendar"></i> Scheduled Date</label>
                    <input type="date" name="scheduled_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="form-group">
                    <label><i class="fa-regular fa-clock"></i> Preferred Time Slot</label>
                    <select name="time_slot" class="form-control">
                        <option value="09:00 AM">09:00 AM - 11:00 AM (Morning Slot)</option>
                        <option value="01:00 PM">01:00 PM - 03:00 PM (Afternoon Slot)</option>
                        <option value="04:00 PM">04:00 PM - 06:00 PM (Sunset Pool Session)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fa-solid fa-users"></i> Number of Participants</label>
                    <input type="number" name="pax" class="form-control" min="1" max="10" value="1" required>
                </div>

                <!-- Activity Reservation Consent Checkbox -->
                <div class="form-group" style="margin-top: 1.25rem; margin-bottom: 1.25rem; background: #faf5ff; padding: 0.85rem 1rem; border-radius: var(--radius-sm); border: 1px solid #e9d5ff;">
                    <label for="activity_consent" style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; color: var(--text-main); font-size: 0.88rem;">
                        <input type="checkbox" id="activity_consent" name="activity_consent" required value="1" style="margin-top: 2px; accent-color: var(--primary-purple); width: 18px; height: 18px;">
                        <span>
                            I accept the resort <a href="refund-policy.php" target="_blank" style="color: var(--primary-purple); font-weight: 700; text-decoration: underline;">Refund Policy</a> and agree to obey pool & lagoon safety rules. *
                        </span>
                    </label>
                </div>

                <button type="submit" class="btn btn-gold" style="width: 100%; margin-top: 0.5rem;">
                    <i class="fa-solid fa-check-circle"></i> Confirm & Proceed to GCash Payment
                </button>
            </form>
    <?php if ($is_admin_layout): ?>
            </main>
        </div>
    <?php else: ?>
        </div>
        <?php include 'footer.php'; ?>
    <?php endif; ?>

</body>
</html>

