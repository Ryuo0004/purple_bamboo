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

// ============================================================
// HANDLE SESSION ACTIONS (Extend / Complete / Manage)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['session_action'])) {
    $reservation_id = intval($_POST['reservation_id'] ?? 0);
    $action = $_POST['session_action'] ?? '';

    if ($reservation_id > 0 && isset($pdo) && $pdo !== null) {
        try {
            if ($action === 'complete') {
                $upd = $pdo->prepare("UPDATE activity_reservations SET status = 'completed' WHERE reservation_id = ?");
                $upd->execute([$reservation_id]);
                $_SESSION['activity_flash'] = ['type' => 'success', 'msg' => "Session #{$reservation_id} marked as completed."];
            } elseif ($action === 'extend') {
                // Extend the scheduled_time by 30 minutes
                $upd = $pdo->prepare("UPDATE activity_reservations SET scheduled_time = DATE_ADD(scheduled_time, INTERVAL 30 MINUTE) WHERE reservation_id = ?");
                $upd->execute([$reservation_id]);
                $_SESSION['activity_flash'] = ['type' => 'success', 'msg' => "Session #{$reservation_id} extended by 30 minutes."];
            } elseif ($action === 'start') {
                $upd = $pdo->prepare("UPDATE activity_reservations SET status = 'ongoing' WHERE reservation_id = ?");
                $upd->execute([$reservation_id]);
                $_SESSION['activity_flash'] = ['type' => 'success', 'msg' => "Session #{$reservation_id} marked as ongoing."];
            }
        } catch (PDOException $e) {
            $_SESSION['activity_flash'] = ['type' => 'error', 'msg' => "Failed to update session: " . $e->getMessage()];
        }
    }
    header("Location: activity_monitoring.php");
    exit();
}

$flash = $_SESSION['activity_flash'] ?? null;
unset($_SESSION['activity_flash']);

// ============================================================
// FETCH ACTIVE SESSIONS
// ============================================================
if (isset($pdo) && $pdo !== null) {
    try {
        $stmt = $pdo->query("
            SELECT ar.reservation_id, b.booking_reference, u.full_name AS guest,
                   a.activity_name AS activity, ar.scheduled_time, ar.status, ar.scheduled_date
            FROM activity_reservations ar
            JOIN bookings b ON ar.booking_id = b.booking_id
            JOIN users u ON b.guest_id = u.user_id
            JOIN activities a ON ar.activity_id = a.activity_id
            ORDER BY ar.scheduled_date ASC, ar.scheduled_time ASC
        ");
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $status = strtolower($row['status']);

            // Determine badge class
            if ($status === 'scheduled') {
                $badge = 'admin-badge-purple';
            } elseif ($status === 'ongoing') {
                $badge = 'admin-badge-gold';
            } else {
                $badge = 'admin-badge-success';
            }

            // Determine "time remaining" label
            $scheduled_ts = strtotime($row['scheduled_date'] . ' ' . $row['scheduled_time']);
            $now = time();
            $diff_min = round(($scheduled_ts - $now) / 60);

            if ($status === 'completed') {
                $time_remaining = 'Completed';
            } elseif ($status === 'ongoing') {
                $time_remaining = 'Active Session';
            } elseif ($diff_min > 0) {
                if ($diff_min >= 60) {
                    $time_remaining = floor($diff_min / 60) . ' Hr ' . ($diff_min % 60) . ' Mins';
                } else {
                    $time_remaining = $diff_min . ' Mins';
                }
            } else {
                $time_remaining = 'Due Now';
            }

            $active_sessions[] = [
                'id' => 'ACT-' . $row['reservation_id'],
                'db_id' => $row['reservation_id'],
                'booking_ref' => $row['booking_reference'],
                'guest' => $row['guest'],
                'activity' => $row['activity'],
                'date' => date('M j, Y', strtotime($row['scheduled_date'])),
                'slot' => date('h:i A', strtotime($row['scheduled_time'])),
                'time_remaining' => $time_remaining,
                'status' => ucfirst($status),
                'status_raw' => $status,
                'badge' => $badge
            ];
        }
    } catch (PDOException $e) {
        $active_sessions = [];
    }
}



// ============================================================
// STATS CALCULATION (FIXED)
// ============================================================
$ongoing = 0;
$upcoming = 0;
$completed = 0;
foreach ($active_sessions as $s) {
    $status = $s['status_raw'];
    if ($status === 'ongoing') {
        $ongoing++;
    } elseif ($status === 'scheduled') {
        $upcoming++;
    } elseif ($status === 'completed') {
        $completed++;
    }
}
$total_count = count($active_sessions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activities Monitoring | Purple Bamboo Resort</title>
    <?php if ($is_admin_layout): ?>
        <link rel="stylesheet" href="admin-style.css?v=<?= time() ?>">
    <?php else: ?>
        <link rel="stylesheet" href="style.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .activity-flash {
            padding: 1rem 1.25rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
        }
        .activity-flash.success {
            background: rgba(16, 185, 129, 0.15);
            border: 1.5px solid #10b981;
            color: #6ee7b7;
        }
        .activity-flash.error {
            background: rgba(244, 63, 94, 0.15);
            border: 1.5px solid #f43f5e;
            color: #fca5a5;
        }
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .table-responsive table {
            min-width: 780px;
        }
        @media (max-width: 768px) {
            .admin-page-header {
                flex-direction: column;
                align-items: flex-start !important;
            }
        }
    </style>
</head>
<body class="<?= $is_admin_layout ? 'admin-layout-body' : '' ?>">

    <?php if ($is_admin_layout): ?>
        <?php include 'admin-nav.php'; ?>
        <div class="admin-main-wrapper">
            <header class="admin-top-header">
                <div class="admin-header-left">
                    <button type="button" class="admin-mobile-toggle-btn" onclick="toggleAdminSidebar()" aria-label="Open Navigation Menu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <div class="admin-header-context">

                        <div class="admin-header-title-row">
                            <span class="admin-header-page-heading" style="font-family: 'Outfit', sans-serif;">Activity monitoring</span>
                        </div>
                    </div>
                </div>

                <div class="admin-header-actions">
                    <a href="admin_profile.php" class="hs-user-badge" title="My Profile">
                    <span class="hs-user-avatar"><?= strtoupper(substr($admin_full_name ?? 'Admin', 0, 1)) ?></span>
                    <span class="hs-user-name"><?= htmlspecialchars($admin_full_name ?? 'Admin') ?></span>
                </a>
                <a href="logout.php" class="hs-btn-logout" onclick="openLogoutModal(event)" title="Sign Out"><i class="fa-solid fa-sign-out-alt"></i></a>
                </div>
            </header>
            <main class="admin-container">
    <?php else: ?>
        <?php include 'nav.php'; ?>
        <div class="container">
    <?php endif; ?>

        <!-- Flash Message -->
        <?php if ($flash): ?>
            <div class="activity-flash <?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>" style="font-size: 1.25rem;"></i>
                <span><?= htmlspecialchars($flash['msg']) ?></span>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="<?= $is_admin_layout ? 'admin-page-header' : 'page-header' ?>">
            <div>
                <h1 class="<?= $is_admin_layout ? 'admin-page-title' : '' ?>">
                    <i class="fa-solid fa-stopwatch" style="color: var(--gold-glow, var(--bamboo-gold));"></i> 
                    Pool & Activity Time Monitoring
                </h1>
                <p class="<?= $is_admin_layout ? 'admin-page-subtitle' : '' ?>">
                    Real-time slot tracker for ongoing guest water sports, pool passes, and spa sessions.
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

        <!-- Stats Summary -->
        <?php if ($is_admin_layout): ?>
        <div class="admin-grid" style="margin-bottom: 2rem;">
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Ongoing Sessions</span>
                <div class="admin-stat-value" style="color: var(--gold-glow);"><?= $ongoing ?></div>
                <span class="admin-badge admin-badge-gold"><i class="fa-solid fa-play"></i> Currently Active</span>
            </div>
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Upcoming</span>
                <div class="admin-stat-value" style="color: var(--primary-glow);"><?= $upcoming ?></div>
                <span class="admin-badge admin-badge-purple"><i class="fa-solid fa-clock"></i> Scheduled</span>
            </div>
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Completed</span>
                <div class="admin-stat-value" style="color: var(--emerald);"><?= $completed ?></div>
                <span class="admin-badge admin-badge-success"><i class="fa-solid fa-check-double"></i> Finished</span>
            </div>
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Total Sessions</span>
                <div class="admin-stat-value" style="color: var(--rose);"><?= $total_count ?></div>
                <span class="admin-badge admin-badge-danger"><i class="fa-solid fa-ticket"></i> All Activity Slots</span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Activity Sessions Table -->
        <div class="<?= $is_admin_layout ? 'admin-table-container' : 'card' ?>" style="padding: 0; overflow: hidden;">
            <div style="padding: 1.25rem 1.5rem; background: <?= $is_admin_layout ? '#0f172a' : 'var(--purple-bg-soft)' ?>; border-bottom: 1px solid <?= $is_admin_layout ? '#1e293b' : 'var(--border-color)' ?>; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
                <h3 style="font-family: 'Outfit', sans-serif; color: <?= $is_admin_layout ? '#ffffff' : 'var(--primary-purple)' ?>; margin: 0;">
                    <i class="fa-solid fa-list"></i> Activity Sessions (<?= $total_count ?> Found)
                </h3>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <span class="<?= $is_admin_layout ? 'admin-badge admin-badge-gold' : 'badge badge-gold' ?>">
                        <i class="fa-solid fa-circle" style="font-size: 0.5rem;"></i> Ongoing
                    </span>
                    <span class="<?= $is_admin_layout ? 'admin-badge admin-badge-purple' : 'badge badge-purple' ?>">
                        <i class="fa-solid fa-circle" style="font-size: 0.5rem;"></i> Scheduled
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
                            <th>Date</th>
                            <th>Time Slot</th>
                            <th>Time Remaining</th>
                            <th>Status</th>
                            <th style="text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($active_sessions)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                                    <i class="fa-solid fa-clock" style="font-size: 2.5rem; margin-bottom: 0.5rem; color: #64748b;"></i>
                                    <p>No active activity sessions found.</p>
                                    <a href="book_activities.php" class="admin-btn admin-btn-gold admin-btn-sm" style="margin-top: 0.5rem;">
                                        <i class="fa-solid fa-plus"></i> Book First Activity
                                    </a>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($active_sessions as $s): ?>
                                <tr>
                                    <td>
                                        <strong style="color: var(--primary-glow, var(--primary-purple)); font-family: 'Outfit', sans-serif;"><?= htmlspecialchars($s['id']) ?></strong>
                                        <br><small style="color: var(--text-muted);"><?= htmlspecialchars($s['booking_ref'] ?? '') ?></small>
                                    </td>
                                    <td><strong><?= htmlspecialchars($s['guest']) ?></strong></td>
                                    <td><?= htmlspecialchars($s['activity']) ?></td>
                                    <td style="font-size: 0.85rem; color: var(--text-muted);"><?= htmlspecialchars($s['date']) ?></td>
                                    <td><?= htmlspecialchars($s['slot']) ?></td>
                                    <td>
                                        <strong style="color: <?= ($s['status_raw'] === 'ongoing') ? 'var(--gold-glow)' : 'var(--text-muted)' ?>;">
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
                                            <div style="display: flex; gap: 0.25rem; justify-content: center; flex-wrap: wrap;">
                                                <?php if ($s['status_raw'] === 'scheduled'): ?>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Start this session now?');">
                                                        <input type="hidden" name="reservation_id" value="<?= $s['db_id'] ?>">
                                                        <input type="hidden" name="session_action" value="start">
                                                        <button type="submit" class="admin-btn admin-btn-gold admin-btn-sm" title="Start Session">
                                                            <i class="fa-solid fa-play"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($s['status_raw'] !== 'completed'): ?>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Extend this session by 30 minutes?');">
                                                        <input type="hidden" name="reservation_id" value="<?= $s['db_id'] ?>">
                                                        <input type="hidden" name="session_action" value="extend">
                                                        <button type="submit" class="admin-btn admin-btn-outline admin-btn-sm" title="Extend 30 Mins">
                                                            <i class="fa-solid fa-clock"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Mark this session as completed?');">
                                                        <input type="hidden" name="reservation_id" value="<?= $s['db_id'] ?>">
                                                        <input type="hidden" name="session_action" value="complete">
                                                        <button type="submit" class="admin-btn admin-btn-outline admin-btn-sm" style="color: #10b981; border-color: #10b981;" title="Mark Complete">
                                                            <i class="fa-solid fa-check"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="admin-badge admin-badge-success" style="font-size: 0.75rem;">
                                                        <i class="fa-solid fa-check-double"></i> Done
                                                    </span>
                                                <?php endif; ?>
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