<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/booking_store.php';

// Strict RBAC: Only Admin and Staff accounts permitted
if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), ['admin', 'staff'])) {
    $err = (isset($_SESSION['role']) && $_SESSION['role'] === 'guest') ? 'guest_restricted' : 'unauthorized';
    header("Location: admin-login.php?error=" . $err);
    exit();
}

$user_role = $_SESSION['role'];
$is_admin_layout = true;

$msg = '';
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = trim($_GET['id']);
    $act = $_GET['action'];
    $b = BookingStore::getBookingById($id);

    if ($b) {
        if ($act === 'checked_in') {
            BookingStore::updateBookingStatus($id, 'checked_in');
            $msg = "Guest for Booking #{$id} (" . htmlspecialchars($b['guest_name']) . ") has been marked as <strong>CHECKED IN</strong> at " . date('h:i A');
        } elseif ($act === 'checked_out') {
            BookingStore::updateBookingStatus($id, 'checked_out');
            $msg = "Guest for Booking #{$id} (" . htmlspecialchars($b['guest_name']) . ") has been marked as <strong>CHECKED OUT</strong> at " . date('h:i A');
        } elseif ($act === 'cancel') {
            // Walk-in reservations have a strict no-refund / no-return policy
            $is_walkin = (isset($b['gcash_ref']) && stripos($b['gcash_ref'], 'WALK-IN') !== false) 
                      || (isset($b['special_notes']) && stripos($b['special_notes'], 'Walk-In') !== false);
            $pstatus = $is_walkin ? 'cancelled' : (($b['payment_status'] === 'verified') ? 'refund_pending' : 'cancelled');
            $reason = trim($_GET['reason'] ?? 'Front Desk Cancellation');
            BookingStore::updateBookingStatus($id, 'cancelled', $pstatus, null, null, "Cancelled: " . $reason);
            $msg = "Booking <strong>#{$id}</strong> for <strong>" . htmlspecialchars($b['guest_name']) . "</strong> has been <strong style='color:#ef4444;'>CANCELLED</strong>. The accommodation (<strong>" . htmlspecialchars($b['item_name']) . "</strong>) has been <strong>RELEASED</strong> back to available inventory.";
        }
    }
}

// Fetch all bookings for front desk manager
$all_bookings = BookingStore::getAllBookings();
// Filter out cancelled bookings
$guests_list = array_filter($all_bookings, function($b) {
    return ($b['booking_status'] ?? '') !== 'cancelled';
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Check-In & Check-Out | Purple Bamboo Resort</title>
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
                    <input type="text" placeholder="Search guest name or ref #..." onkeyup="if(event.key==='Enter') window.location='checkin_checkout.php?search='+encodeURIComponent(this.value)">
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
        <div class="container">
    <?php endif; ?>

        <!-- Page Header -->
        <div class="<?= $is_admin_layout ? 'admin-page-header' : 'page-header' ?>">
            <div>
                <h1 class="<?= $is_admin_layout ? 'admin-page-title' : '' ?>">
                    <i class="fa-solid fa-key" style="color: var(--gold-glow, var(--bamboo-gold));"></i> 
                    Front Desk Check-In & Check-Out
                </h1>
                <p class="<?= $is_admin_layout ? 'admin-page-subtitle' : '' ?>">
                    Monitor guest arrivals, assign room keys, and process check-out releases.
                </p>
            </div>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <a href="bookings.php" class="<?= $is_admin_layout ? 'admin-btn admin-btn-outline' : 'btn btn-outline' ?>">
                    <i class="fa-solid fa-book"></i> Booking Manifest
                </a>
                <a href="admin-dashboard.php" class="<?= $is_admin_layout ? 'admin-btn admin-btn-primary' : 'btn btn-primary' ?>">
                    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Alert Message -->
        <?php if ($msg): ?>
            <div style="background: rgba(16,185,129,0.15); color: #34d399; padding: 1rem 1.25rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; justify-content: space-between; border: 1px solid rgba(16,185,129,0.3);">
                <div>
                    <i class="fa-solid fa-circle-check"></i> <?= $msg ?>
                </div>
                <a href="checkin_checkout.php" style="color: inherit; text-decoration: none; font-size: 0.9rem;"><i class="fa-solid fa-xmark"></i> Dismiss</a>
            </div>
        <?php endif; ?>

        <!-- Stats Summary -->
        <?php 
        $total_pending = 0;
        $total_checked_in = 0;
        $total_checked_out = 0;
        foreach ($guests_list as $g) {
            $st = $g['booking_status'] ?? 'pending';
            if ($st === 'checked_in') $total_checked_in++;
            elseif ($st === 'checked_out') $total_checked_out++;
            elseif ($st !== 'cancelled') $total_pending++;
        }
        ?>
        <div class="<?= $is_admin_layout ? 'admin-grid' : 'grid' ?>" style="margin-bottom: 2rem;">
            <div class="<?= $is_admin_layout ? 'admin-card admin-stat-card' : 'card' ?>">
                <span class="admin-stat-label">Pending Arrivals</span>
                <div class="admin-stat-value" style="color: var(--rose);"><?= $total_pending ?></div>
                <span class="admin-badge admin-badge-pending"><i class="fa-solid fa-clock"></i> Awaiting Check-In</span>
            </div>
            <div class="<?= $is_admin_layout ? 'admin-card admin-stat-card' : 'card' ?>">
                <span class="admin-stat-label">Currently Checked In</span>
                <div class="admin-stat-value" style="color: var(--gold-glow);"><?= $total_checked_in ?></div>
                <span class="admin-badge admin-badge-gold"><i class="fa-solid fa-key"></i> Active Guests</span>
            </div>
            <div class="<?= $is_admin_layout ? 'admin-card admin-stat-card' : 'card' ?>">
                <span class="admin-stat-label">Checked Out Today</span>
                <div class="admin-stat-value" style="color: var(--emerald);"><?= $total_checked_out ?></div>
                <span class="admin-badge admin-badge-success"><i class="fa-solid fa-check-double"></i> Completed</span>
            </div>
        </div>

        <!-- Guest List Table -->
        <div class="<?= $is_admin_layout ? 'admin-table-container' : 'card' ?>" style="padding: 0; overflow: hidden;">
            <div style="padding: 1.25rem 1.5rem; background: <?= $is_admin_layout ? '#0f172a' : 'var(--purple-bg-soft)' ?>; border-bottom: 1px solid <?= $is_admin_layout ? '#1e293b' : 'var(--border-color)' ?>; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-family: 'Outfit', sans-serif; color: <?= $is_admin_layout ? '#ffffff' : 'var(--primary-purple)' ?>; margin: 0;">
                    <i class="fa-solid fa-users"></i> Guest Arrivals (<?= count($guests_list) ?>)
                </h3>
            </div>

            <div class="table-responsive">
                <table class="<?= $is_admin_layout ? 'admin-table' : 'table' ?>" style="margin: 0;">
                    <thead>
                        <tr>
                            <th>Ref #</th>
                            <th>Guest Information</th>
                            <th>Assigned Kubo / Activity</th>
                            <th>Stay Period</th>
                            <th>Status</th>
                            <th style="text-align: center;">Front Desk Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($guests_list)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                                    <i class="fa-solid fa-door-open" style="font-size: 2.5rem; margin-bottom: 0.5rem; color: #64748b;"></i>
                                    <p>No active guest arrivals registered today.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($guests_list as $g): ?>
                                <?php
                                $st = $g['booking_status'] ?? 'pending';
                                $badge = 'admin-badge-pending';
                                $st_text = 'Pending Arrival';
                                if ($st === 'checked_in') {
                                    $badge = 'admin-badge-gold';
                                    $st_text = 'Checked In';
                                } elseif ($st === 'checked_out') {
                                    $badge = 'admin-badge-success';
                                    $st_text = 'Checked Out';
                                } elseif ($st === 'approved') {
                                    $badge = 'admin-badge-purple';
                                    $st_text = 'Approved';
                                } elseif ($st === 'cancelled') {
                                    $badge = 'admin-badge-danger';
                                    $st_text = 'Cancelled';
                                }
                                ?>
                                <tr>
                                    <td>
                                        <strong style="color: var(--primary-glow, var(--primary-purple)); font-family: 'Outfit', sans-serif;"><?= htmlspecialchars($g['id']) ?></strong>
                                    </td>
                                    <td>
                                        <?= GuestHelper::renderGuestBadge($g) ?>
                                    </td>
                                    <td><?= htmlspecialchars($g['item_name']) ?></td>
                                    <td>
                                        <span style="font-weight: 600;"><?= date('M d, Y', strtotime($g['check_in'])) ?></span>
                                        <?php if (!empty($g['check_out']) && $g['check_out'] !== $g['check_in']): ?>
                                            → <span style="font-weight: 600;"><?= date('M d, Y', strtotime($g['check_out'])) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="admin-badge <?= $badge ?>"><?= $st_text ?></span>
                                    </td>
                                    <td style="text-align: center; white-space: nowrap;">
                                        <div style="display: inline-flex; gap: 6px; align-items: center; justify-content: center; flex-wrap: wrap;">
                                            <?php if ($st === 'checked_in'): ?>
                                                <a href="checkin_checkout.php?action=checked_out&id=<?= urlencode($g['id']) ?>" 
                                                   class="admin-btn admin-btn-outline admin-btn-sm" 
                                                   style="color: #f43f5e;"
                                                   onclick="return confirm('Process check-out for <?= htmlspecialchars(addslashes($g['guest_name'])) ?>?')">
                                                    <i class="fa-solid fa-right-from-bracket"></i> Check-Out
                                                </a>
                                            <?php elseif ($st === 'approved' || $st === 'pending'): ?>
                                                <a href="checkin_checkout.php?action=checked_in&id=<?= urlencode($g['id']) ?>" 
                                                   class="admin-btn admin-btn-gold admin-btn-sm"
                                                   onclick="return confirm('Process check-in for <?= htmlspecialchars(addslashes($g['guest_name'])) ?>?')">
                                                    <i class="fa-solid fa-key"></i> Check-In
                                                </a>
                                            <?php elseif ($st === 'checked_out'): ?>
                                                <span style="color: var(--emerald); font-weight: 600; font-size: 0.85rem;">
                                                    <i class="fa-solid fa-check-circle"></i> Completed
                                                </span>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted); font-size: 0.85rem;">
                                                    <i class="fa-solid fa-ban"></i> N/A
                                                </span>
                                            <?php endif; ?>

                                            <?php if ($st !== 'cancelled' && $st !== 'checked_out'): ?>
                                                <a href="checkin_checkout.php?action=cancel&id=<?= urlencode($g['id']) ?>" 
                                                   class="admin-btn admin-btn-danger admin-btn-sm" 
                                                   style="background: #ef4444; border-color: #ef4444; color: #ffffff; font-weight: 700; text-decoration: none;"
                                                   onclick="return confirm('⚠️ Are you sure you want to CANCEL reservation #<?= htmlspecialchars(addslashes($g['id'])) ?> for <?= htmlspecialchars(addslashes($g['guest_name'])) ?>?\n\nThis will mark the reservation as Cancelled and release <?= htmlspecialchars(addslashes($g['item_name'])) ?> back to resort availability.')"
                                                   title="Cancel this reservation and release room">
                                                    <i class="fa-solid fa-ban"></i> Cancel
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php if ($is_admin_layout): ?>
            </main>
        </div>
    <?php else: ?>
        </div>
    <?php endif; ?>

    <?php if (!$is_admin_layout): ?>
        <?php include 'footer.php'; ?>
    <?php endif; ?>

</body>
</html>