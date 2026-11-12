<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/booking_store.php';
require_once __DIR__ . '/inventory_store.php';

$user_role = $_SESSION['role'] ?? 'guest';
$is_admin_layout = ($user_role === 'admin' || $user_role === 'staff');

$all_kubos = InventoryStore::getAllKubos();
$room_filter = isset($_GET['room_id']) ? intval($_GET['room_id']) : 0;

$all_bookings = BookingStore::getAllBookings();

// Build a date lookup table [ 'YYYY-MM-DD' => [ bookings ] ]
$calendar_map = [];
foreach ($all_bookings as $b) {
    if (($b['booking_status'] ?? '') === 'cancelled') continue;
    if ($room_filter > 0 && intval($b['item_id'] ?? 0) !== $room_filter) continue;

    $start = strtotime($b['check_in']);
    $end = !empty($b['check_out']) ? strtotime($b['check_out']) : $start;

    for ($curr = $start; $curr <= $end; $curr = strtotime('+1 day', $curr)) {
        $dStr = date('Y-m-d', $curr);
        $role = 'stay';
        if ($dStr === $b['check_in']) {
            $role = 'checkin';
        } elseif ($dStr === $b['check_out']) {
            $role = 'checkout';
        }
        $bCopy = $b;
        $bCopy['role_in_stay'] = $role;
        $calendar_map[$dStr][] = $bCopy;
    }
}

// Get current month/year from URL or default to current
$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// If month is out of range, reset
if ($month < 1 || $month > 12) {
    $month = date('m');
    $year = date('Y');
}

// Get first day of month and total days
$firstDay = strtotime("$year-$month-01");
$totalDays = date('t', $firstDay);
$startDayOfWeek = date('w', $firstDay); // 0 = Sunday, 6 = Saturday
$monthName = date('F Y', $firstDay);

// Previous and Next month links
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear = $year - 1;
}
$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear = $year + 1;
}

// Count total bookings for stats
$total_bookings = count($all_bookings);
$active_bookings = 0;
foreach ($all_bookings as $b) {
    if (($b['booking_status'] ?? '') !== 'cancelled') {
        $active_bookings++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservation Calendar | Purple Bamboo Resort</title>
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
                    <input type="text" placeholder="Search bookings..." onkeyup="if(event.key==='Enter') window.location='bookings.php?search='+encodeURIComponent(this.value)">
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
                    <i class="fa-solid fa-calendar-days" style="color: var(--gold-glow, var(--bamboo-gold));"></i> 
                    Resort Reservation Calendar
                </h1>
                <p class="<?= $is_admin_layout ? 'admin-page-subtitle' : '' ?>">
                    <?= $is_admin_layout ? 'Interactive schedule view for all House Kubos and Pool Activities.' : 'Interactive schedule view for all House Kubos and Pool Activities.' ?>
                </p>
            </div>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <?php if ($is_admin_layout): ?>
                    <a href="bookings.php" class="admin-btn admin-btn-gold">
                        <i class="fa-solid fa-book"></i> Booking Manifest
                    </a>
                    <a href="book_house.php" class="admin-btn admin-btn-outline">
                        <i class="fa-solid fa-plus"></i> Add Booking
                    </a>
                <?php else: ?>
                    <a href="browse_kubos.php" class="btn btn-primary">
                        <i class="fa-solid fa-house-chimney"></i> Reserve a Kubo
                    </a>
                    <a href="browse_activities.php" class="btn btn-gold">
                        <i class="fa-solid fa-water-ladder"></i> Reserve Activity
                    </a>
                    <?php if (isset($_SESSION['user_id']) || isset($_SESSION['guest_id'])): ?>
                        <a href="user-dashboard.php" class="btn btn-outline" style="background: #fff;">
                            <i class="fa-solid fa-user-clock"></i> My Reservations
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats Summary for Admin -->
        <?php if ($is_admin_layout): ?>
        <div class="admin-grid" style="margin-bottom: 2rem;">
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Total Bookings</span>
                <div class="admin-stat-value" style="color: var(--primary-glow);"><?= $total_bookings ?></div>
                <span class="admin-badge admin-badge-purple"><i class="fa-solid fa-book"></i> All Records</span>
            </div>
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Active Reservations</span>
                <div class="admin-stat-value" style="color: var(--gold-glow);"><?= $active_bookings ?></div>
                <span class="admin-badge admin-badge-gold"><i class="fa-solid fa-check-circle"></i> Active</span>
            </div>
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Current Month</span>
                <div class="admin-stat-value" style="color: var(--emerald);"><?= date('F', $firstDay) ?></div>
                <span class="admin-badge admin-badge-success"><i class="fa-solid fa-calendar"></i> <?= $year ?></span>
            </div>
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Days with Bookings</span>
                <div class="admin-stat-value" style="color: var(--rose);"><?= count($calendar_map) ?></div>
                <span class="admin-badge admin-badge-danger"><i class="fa-solid fa-clock"></i> Occupied Days</span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Calendar Card -->
        <div class="<?= $is_admin_layout ? 'admin-card' : 'card' ?>" style="overflow: hidden;">
            <!-- Calendar Navigation Header -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <h2 style="font-family: 'Outfit', sans-serif; color: <?= $is_admin_layout ? '#ffffff' : 'var(--primary-purple)' ?>; margin: 0;">
                        <i class="fa-regular fa-calendar-days"></i> <?= $monthName ?>
                    </h2>

                    <!-- Room Filter Dropdown -->
                    <form method="GET" style="display: flex; align-items: center; gap: 6px; margin: 0;">
                        <input type="hidden" name="month" value="<?= $month ?>">
                        <input type="hidden" name="year" value="<?= $year ?>">
                        <select name="room_id" onchange="this.form.submit()" class="form-control" style="background: <?= $is_admin_layout ? '#1e293b' : '#faf5ff' ?>; color: <?= $is_admin_layout ? '#ffffff' : '#1e1b4b' ?>; border: 1.5px solid <?= $is_admin_layout ? '#475569' : '#d8b4fe' ?>; padding: 0.35rem 0.65rem; border-radius: 8px; font-size: 0.85rem; font-weight: 700; height: 38px;">
                            <option value="0">🔍 All Rooms / Kubos</option>
                            <?php foreach ($all_kubos as $k): ?>
                                <option value="<?= $k['kubo_id'] ?>" <?= $room_filter == $k['kubo_id'] ? 'selected' : '' ?>>
                                    🏠 <?= htmlspecialchars($k['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <div style="display: flex; gap: 0.5rem;">
                    <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>&room_id=<?= $room_filter ?>" class="<?= $is_admin_layout ? 'admin-btn admin-btn-outline admin-btn-sm' : 'btn btn-outline btn-sm' ?>">
                        <i class="fa-solid fa-chevron-left"></i> Prev
                    </a>
                    <a href="?month=<?= date('m') ?>&year=<?= date('Y') ?>&room_id=<?= $room_filter ?>" class="<?= $is_admin_layout ? 'admin-btn admin-btn-gold admin-btn-sm' : 'btn btn-primary btn-sm' ?>">
                        Today
                    </a>
                    <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>&room_id=<?= $room_filter ?>" class="<?= $is_admin_layout ? 'admin-btn admin-btn-outline admin-btn-sm' : 'btn btn-outline btn-sm' ?>">
                        Next <i class="fa-solid fa-chevron-right"></i>
                    </a>
                </div>
            </div>

            <!-- Calendar Grid -->
            <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; text-align: center;">
                <!-- Day Headers -->
                <div style="font-weight: 700; background: <?= $is_admin_layout ? '#7c3aed' : 'var(--primary-purple)' ?>; color: #fff; padding: 0.5rem; border-radius: 4px;">Sun</div>
                <div style="font-weight: 700; background: <?= $is_admin_layout ? '#7c3aed' : 'var(--primary-purple)' ?>; color: #fff; padding: 0.5rem; border-radius: 4px;">Mon</div>
                <div style="font-weight: 700; background: <?= $is_admin_layout ? '#7c3aed' : 'var(--primary-purple)' ?>; color: #fff; padding: 0.5rem; border-radius: 4px;">Tue</div>
                <div style="font-weight: 700; background: <?= $is_admin_layout ? '#7c3aed' : 'var(--primary-purple)' ?>; color: #fff; padding: 0.5rem; border-radius: 4px;">Wed</div>
                <div style="font-weight: 700; background: <?= $is_admin_layout ? '#7c3aed' : 'var(--primary-purple)' ?>; color: #fff; padding: 0.5rem; border-radius: 4px;">Thu</div>
                <div style="font-weight: 700; background: <?= $is_admin_layout ? '#7c3aed' : 'var(--primary-purple)' ?>; color: #fff; padding: 0.5rem; border-radius: 4px;">Fri</div>
                <div style="font-weight: 700; background: <?= $is_admin_layout ? '#7c3aed' : 'var(--primary-purple)' ?>; color: #fff; padding: 0.5rem; border-radius: 4px;">Sat</div>

                <!-- Empty days before first day of month -->
                <?php for ($i = 0; $i < $startDayOfWeek; $i++): ?>
                    <div style="min-height: 95px; background: <?= $is_admin_layout ? '#0f172a' : '#f8fafc' ?>; border: 1px solid <?= $is_admin_layout ? '#1e293b' : '#e2e8f0' ?>; border-radius: 6px;"></div>
                <?php endfor; ?>

                <!-- Calendar Days -->
                <?php for ($day = 1; $day <= $totalDays; $day++):
                    $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $dayBookings = $calendar_map[$dateKey] ?? [];
                    $has_booking = !empty($dayBookings);
                    $is_today = ($dateKey === date('Y-m-d'));
                    
                    if ($is_admin_layout) {
                        $bg_color = $has_booking ? '#1e293b' : '#0f172a';
                        $border = $has_booking ? '1px solid #8b5cf6' : '1px solid #1e293b';
                        if ($is_today) {
                            $bg_color = '#2e1065';
                            $border = '2px solid #f59e0b';
                        }
                    } else {
                        $bg_color = $has_booking ? 'var(--purple-light)' : '#f8fafc';
                        $border = $has_booking ? '1px solid var(--purple-accent)' : '1px solid #e2e8f0';
                        if ($is_today) {
                            $bg_color = '#fef3c7';
                            $border = '2px solid var(--bamboo-gold)';
                        }
                    }
                ?>
                    <div style="min-height: 95px; background: <?= $bg_color ?>; border: <?= $border ?>; border-radius: 6px; padding: 6px; text-align: left; position: relative;">
                        <span style="font-weight: 700; font-size: 0.85rem; color: <?= $is_admin_layout ? '#f8fafc' : 'var(--text-dark)' ?>;">
                            <?= $day ?>
                            <?php if ($is_today): ?>
                                <span style="font-size: 0.6rem; background: #f59e0b; color: #fff; padding: 1px 6px; border-radius: 10px; margin-left: 4px;">Today</span>
                            <?php endif; ?>
                        </span>
                        
                        <?php foreach ($dayBookings as $b): ?>
                            <?php
                            $stayRole = $b['role_in_stay'] ?? 'stay';
                            $prefixTag = '';
                            if ($stayRole === 'checkin') {
                                $prefixTag = '🟢 IN: ';
                            } elseif ($stayRole === 'checkout') {
                                $prefixTag = '🟠 OUT: ';
                            } else {
                                $prefixTag = '🔒 ';
                            }

                            if ($is_admin_layout) {
                                $chipBg = '#78350f';
                                $chipColor = '#fbbf24';
                                if (($b['type'] ?? '') === 'activity') {
                                    $chipBg = '#064e3b';
                                    $chipColor = '#34d399';
                                } elseif (($b['booking_status'] ?? '') === 'checked_in') {
                                    $chipBg = '#4c1d95';
                                    $chipColor = '#c084fc';
                                } elseif (($b['booking_status'] ?? '') === 'checked_out') {
                                    $chipBg = '#065f46';
                                    $chipColor = '#34d399';
                                }
                            } else {
                                $chipBg = '#fbbf24';
                                $chipColor = '#78350f';
                                if (($b['type'] ?? '') === 'activity') {
                                    $chipBg = '#a7f3d0';
                                    $chipColor = '#065f46';
                                } elseif (($b['booking_status'] ?? '') === 'checked_in') {
                                    $chipBg = '#e9d5ff';
                                    $chipColor = '#581c87';
                                }
                            }
                            ?>
                            <?php if ($is_admin_layout): ?>
                                <a href="bookings.php?search=<?= urlencode($b['id']) ?>" 
                                   style="display: block; background: <?= $chipBg ?>; color: <?= $chipColor ?>; font-size: 0.68rem; padding: 2px 4px; border-radius: 3px; margin-top: 3px; font-weight: 700; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; text-decoration: none; cursor: pointer;"
                                   title="<?= htmlspecialchars($b['item_name'] . ' - ' . $b['guest_name'] . ' (' . ucfirst($stayRole) . ') | Ref: ' . $b['id']) ?>">
                                    <?= $prefixTag ?><?= htmlspecialchars($b['item_name']) ?>
                                </a>
                            <?php else: ?>
                                <div style="display: block; background: <?= $chipBg ?>; color: <?= $chipColor ?>; font-size: 0.68rem; padding: 2px 4px; border-radius: 3px; margin-top: 3px; font-weight: 700; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; cursor: default;"
                                     title="<?= htmlspecialchars($b['item_name'] . ' (' . ucfirst($stayRole) . ')') ?>">
                                    <?= $prefixTag ?><?= htmlspecialchars($b['item_name']) ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Legend -->
        <div class="<?= $is_admin_layout ? 'admin-card' : 'card' ?>" style="margin-top: 1.5rem;">
            <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; align-items: center;">
                <span style="font-weight: 600; color: <?= $is_admin_layout ? '#94a3b8' : 'var(--text-dark)' ?>;">
                    <i class="fa-solid fa-circle-info"></i> Legend:
                </span>
                <span style="display: flex; align-items: center; gap: 6px;">
                    <span style="display: inline-block; width: 16px; height: 16px; background: <?= $is_admin_layout ? '#1e293b' : 'var(--purple-light)' ?>; border: 1px solid <?= $is_admin_layout ? '#8b5cf6' : 'var(--purple-accent)' ?>; border-radius: 3px;"></span>
                    <span style="color: <?= $is_admin_layout ? '#94a3b8' : 'var(--text-dark)' ?>; font-size: 0.85rem;">Has Booking</span>
                </span>
                <span style="display: flex; align-items: center; gap: 6px;">
                    <span style="display: inline-block; width: 16px; height: 16px; background: <?= $is_admin_layout ? '#2e1065' : '#fef3c7' ?>; border: 2px solid #f59e0b; border-radius: 3px;"></span>
                    <span style="color: <?= $is_admin_layout ? '#94a3b8' : 'var(--text-dark)' ?>; font-size: 0.85rem;">Today</span>
                </span>
                <span style="display: flex; align-items: center; gap: 6px;">
                    <span style="display: inline-block; width: 16px; height: 16px; background: #fbbf24; border-radius: 3px;"></span>
                    <span style="color: <?= $is_admin_layout ? '#94a3b8' : 'var(--text-dark)' ?>; font-size: 0.85rem;">House Kubo</span>
                </span>
                <span style="display: flex; align-items: center; gap: 6px;">
                    <span style="display: inline-block; width: 16px; height: 16px; background: #a7f3d0; border-radius: 3px;"></span>
                    <span style="color: <?= $is_admin_layout ? '#94a3b8' : 'var(--text-dark)' ?>; font-size: 0.85rem;">Activity</span>
                </span>
                <span style="display: flex; align-items: center; gap: 6px;">
                    <span style="display: inline-block; width: 16px; height: 16px; background: <?= $is_admin_layout ? '#4c1d95' : '#e9d5ff' ?>; border-radius: 3px;"></span>
                    <span style="color: <?= $is_admin_layout ? '#94a3b8' : 'var(--text-dark)' ?>; font-size: 0.85rem;">Checked In</span>
                </span>
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