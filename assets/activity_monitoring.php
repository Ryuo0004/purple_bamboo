<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Connect to Database
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';

// Strict RBAC: Only Admin and Staff accounts permitted
if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), ['admin', 'staff'])) {
    $err = (isset($_SESSION['role']) && $_SESSION['role'] === 'guest') ? 'guest_restricted' : 'unauthorized';
    header("Location: admin-login.php?error=" . $err);
    exit();
}

$user_role = $_SESSION['role'];
$is_admin_layout = true;
$active_sessions = [];

// Try fetching from database if $pdo connection is active
if (isset($pdo) && $pdo !== null) {
    try {
        $stmt = $pdo->query("
            SELECT ar.reservation_id, b.booking_reference, u.full_name AS guest, 
                   a.activity_name AS activity, ar.scheduled_time, ar.status, ar.scheduled_date
            FROM activity_reservations ar
            JOIN bookings b ON ar.booking_id = b.booking_id
            JOIN users u ON b.guest_id = u.user_id
            JOIN activities a ON ar.activity_id = a.activity_id
            ORDER BY ar.scheduled_date DESC, ar.scheduled_time ASC
        ");
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $badge = ($row['status'] === 'scheduled') ? 'admin-badge-gold' : 'admin-badge-purple';
            $active_sessions[] = [
                'id' => 'ACT-' . $row['reservation_id'],
                'guest' => $row['guest'],
                'activity' => $row['activity'],
                'slot' => date('h:i A', strtotime($row['scheduled_time'])),
                'time_remaining' => ($row['status'] === 'scheduled') ? 'Active Session' : 'Completed',
                'status' => ucfirst($row['status']),
                'badge' => $badge
            ];
        }
    } catch (PDOException $e) {
        // Fallback to sample array if tables are not initialized yet
        $active_sessions = [];
    }
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activities Monitoring | Purple Bamboo Resort</title>
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
                    <input type="text" placeholder="Search activities or guest..." onkeyup="if(event.key==='Enter') window.location='activity_monitoring.php?search='+encodeURIComponent(this.value)">
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
                    <i class="fa-solid fa-stopwatch" style="color: var(--gold-glow, var(--bamboo-gold));"></i> 
                    Pool & Activity Time Monitoring
                </h1>
                <p class="<?= $is_admin_layout ? 'admin-page-subtitle' : '' ?>">
                    <?= $is_admin_layout ? 'Real-time slot tracker for ongoing guest water sports, pool passes, and spa sessions.' : 'Real-time slot tracker for ongoing guest water sports, pool passes, and spa sessions.' ?>
                </p>
            </div>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <?php if ($is_admin_layout): ?>
                    <a href="book_activities.php" class="admin-btn admin-btn-gold">
                        <i class="fa-solid fa-plus"></i> Add New Activity Slot
                    </a>
                    <a href="browse_activities.php" class="admin-btn admin-btn-outline">
                        <i class="fa-solid fa-water-ladder"></i> View Activities
                    </a>
                <?php else: ?>
                    <a href="book_activities.php" class="btn btn-gold">
                        <i class="fa-solid fa-plus"></i> Add New Activity Slot
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats Summary for Admin -->
        <?php if ($is_admin_layout): 
            $ongoing = 0;
            $upcoming = 0;
            $completed = 0;
            foreach ($active_sessions as $s) {
                $status = strtolower($s['status']);
                if ($status === 'ongoing' || $status === 'scheduled') {
                    $ongoing++;
                } elseif ($status === 'upcoming') {
                    $upcoming++;
                } elseif ($status === 'completed') {
                    $completed++;
                }
            }
        ?>
        <div class="admin-grid" style="margin-bottom: 2rem;">
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Active Sessions</span>
                <div class="admin-stat-value" style="color: var(--gold-glow);"><?= $ongoing ?></div>
                <span class="admin-badge admin-badge-gold"><i class="fa-solid fa-play"></i> Currently Ongoing</span>
            </div>
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Upcoming</span>
                <div class="admin-stat-value" style="color: var(--primary-glow);"><?= $upcoming ?></div>
                <span class="admin-badge admin-badge-purple"><i class="fa-solid fa-clock"></i> Scheduled Today</span>
            </div>
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Completed</span>
                <div class="admin-stat-value" style="color: var(--emerald);"><?= $completed ?></div>
                <span class="admin-badge admin-badge-success"><i class="fa-solid fa-check-double"></i> Finished Sessions</span>
            </div>
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Total Bookings</span>
                <div class="admin-stat-value" style="color: var(--rose);"><?= count($active_sessions) ?></div>
                <span class="admin-badge admin-badge-danger"><i class="fa-solid fa-ticket"></i> Total Activity Slots</span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Activity Sessions Table -->
        <div class="<?= $is_admin_layout ? 'admin-table-container' : 'card' ?>" style="padding: 0; overflow: hidden;">
            <div style="padding: 1.25rem 1.5rem; background: <?= $is_admin_layout ? '#0f172a' : 'var(--purple-bg-soft)' ?>; border-bottom: 1px solid <?= $is_admin_layout ? '#1e293b' : 'var(--border-color)' ?>; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-family: 'Outfit', sans-serif; color: <?= $is_admin_layout ? '#ffffff' : 'var(--primary-purple)' ?>; margin: 0;">
                    <i class="fa-solid fa-list"></i> Activity Sessions (<?= count($active_sessions) ?> Found)
                </h3>
                <div style="display: flex; gap: 0.5rem;">
                    <span class="<?= $is_admin_layout ? 'admin-badge admin-badge-gold' : 'badge badge-gold' ?>">
                        <i class="fa-solid fa-circle" style="font-size: 0.5rem;"></i> Ongoing
                    </span>
                    <span class="<?= $is_admin_layout ? 'admin-badge admin-badge-purple' : 'badge badge-purple' ?>">
                        <i class="fa-solid fa-circle" style="font-size: 0.5rem;"></i> Upcoming
                    </span>
                    <span class="<?= $is_admin_layout ? 'admin-badge admin-badge-success' : 'badge badge-success' ?>">
                        <i class="fa-solid fa-circle" style="font-size: 0.5rem;"></i> Completed
                    </span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="<?= $is_admin_layout ? 'admin-table' : 'table' ?>" style="margin: 0;">
                    <thead>
                        <tr>
                            <th>Ticket ID</th>
                            <th>Guest Name</th>
                            <th>Activity</th>
                            <th>Time Slot</th>
                            <th>Time Remaining</th>
                            <th>Session Status</th>
                            <th style="text-align: center;">Monitor Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($active_sessions)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                                    <i class="fa-solid fa-clock" style="font-size: 2.5rem; margin-bottom: 0.5rem; color: #64748b;"></i>
                                    <p>No active activity sessions found.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($active_sessions as $s): ?>
                                <tr>
                                    <td>
                                        <strong style="color: var(--primary-glow, var(--primary-purple)); font-family: 'Outfit', sans-serif;"><?= htmlspecialchars($s['id']) ?></strong>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($s['guest']) ?></strong>
                                    </td>
                                    <td><?= htmlspecialchars($s['activity']) ?></td>
                                    <td><?= htmlspecialchars($s['slot']) ?></td>
                                    <td>
                                        <strong style="color: <?= strpos(strtolower($s['time_remaining']), 'active') !== false ? 'var(--gold-glow)' : 'var(--text-muted)' ?>;">
                                            <?= htmlspecialchars($s['time_remaining']) ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <span class="<?= $is_admin_layout ? 'admin-badge' : 'badge' ?> <?= htmlspecialchars($s['badge']) ?>">
                                            <?= htmlspecialchars($s['status']) ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if ($is_admin_layout): ?>
                                            <div style="display: flex; gap: 0.25rem; justify-content: center;">
                                                <button class="admin-btn admin-btn-outline admin-btn-sm" onclick="alert('Session status updated for <?= htmlspecialchars($s['id']) ?>');" title="Extend or Complete Session">
                                                    <i class="fa-solid fa-stopwatch"></i> Manage
                                                </button>
                                                <button class="admin-btn admin-btn-gold admin-btn-sm" onclick="alert('Timer extended for <?= htmlspecialchars($s['id']) ?>');" title="Extend Time">
                                                    <i class="fa-solid fa-clock-plus"></i>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <button class="btn btn-outline btn-sm" onclick="alert('Session status updated for <?= htmlspecialchars($s['id']) ?>');">
                                                <i class="fa-solid fa-stopwatch"></i> Extend / Complete
                                            </button>
                                        <?php endif; ?>
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