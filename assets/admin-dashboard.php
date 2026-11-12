<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/booking_store.php';
require_once __DIR__ . '/inventory_store.php';
require_once __DIR__ . '/includes/GuestHelper.php';
require_once __DIR__ . '/security.php';

if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), ['admin', 'staff'])) {
    $err = (isset($_SESSION['role']) && $_SESSION['role'] === 'guest') ? 'guest_restricted' : 'unauthorized';
    header("Location: admin-login.php?error=" . $err);
    exit();
}

$action_msg = '';
$action_type = 'success';

if (isset($_GET['action']) && isset($_GET['ref'])) {
    $ref = trim($_GET['ref']);
    $act = trim($_GET['action']);
    if ($act === 'approve_gcash') {
        BookingStore::updateBookingStatus($ref, 'approved', 'verified');
        $action_msg = "GCash Payment for Booking <strong>#" . htmlspecialchars($ref) . "</strong> has been successfully <strong>APPROVED</strong> and booking verified!";
        $action_type = 'success';
    } elseif ($act === 'reject_gcash') {
        BookingStore::updateBookingStatus($ref, 'pending', 'rejected');
        $action_msg = "GCash Payment proof for Booking <strong>#" . htmlspecialchars($ref) . "</strong> was marked as <strong>REJECTED</strong>.";
        $action_type = 'danger';
    }
}

$today = date('Y-m-d');
$stats = BookingStore::getStats();
$all_bookings = BookingStore::getAllBookings();

// Monthly revenue calculations for financial chart
$current_year = intval(date('Y'));
$monthly_revenue = BookingStore::getMonthlyRevenue($current_year);
$chart_labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$chart_data = array_values($monthly_revenue);

// Today's Expected Arrivals (Check-in = today)
$today_arrivals = array_values(array_filter($all_bookings, function($b) use ($today) {
    return ($b['check_in'] === $today) && in_array($b['booking_status'] ?? '', ['pending', 'approved']);
}));

// Today's Expected Departures (Check-out = today)
$today_departures = array_values(array_filter($all_bookings, function($b) use ($today) {
    return ($b['check_out'] === $today) && ($b['booking_status'] ?? '') === 'checked_in';
}));

// Currently In-House (checked_in)
$in_house_guests = array_values(array_filter($all_bookings, function($b) {
    return ($b['booking_status'] ?? '') === 'checked_in';
}));

// Pending GCash items for quick summary
$pending_gcash_items = array_values(array_filter($all_bookings, function($b) {
    $pstatus = strtolower($b['payment_status'] ?? '');
    return $pstatus === 'proof_submitted' || ($pstatus === 'pending' && !empty($b['payment_proof']));
}));

$pending_gcash_amount = array_reduce($pending_gcash_items, function($carry, $b) {
    return $carry + floatval($b['total_amount'] ?? 0);
}, 0);

// Kubo Inventory Summary
$all_kubos = InventoryStore::getAllKubos();
$total_kubos_count = count($all_kubos);
$occupied_kubos_count = 0;
$available_kubos_count = 0;
$renovation_kubos_count = 0;

foreach ($all_kubos as $k) {
    $kst = strtolower($k['status'] ?? 'available');
    if ($kst === 'occupied') $occupied_kubos_count++;
    elseif ($kst === 'renovation' || $kst === 'maintenance') $renovation_kubos_count++;
    else $available_kubos_count++;
}

// Occupancy rate calculation
$occupancy_rate = ($total_kubos_count > 0) ? round(($occupied_kubos_count / $total_kubos_count) * 100) : 0;

// Recent Bookings (top 5)
$recent_bookings = array_slice($all_bookings, 0, 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0f172a">
    <title>Executive Operations Summary | Purple Bamboo Resort</title>
    <link rel="stylesheet" href="admin-style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
    /* ==========================================================================
       ADMIN DASHBOARD-SPECIFIC STYLES
       ========================================================================== */
    .summary-metric-badge {
        font-size: 0.72rem;
        font-weight: 700;
        padding: 3px 8px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .today-desk-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 360px), 1fr));
        gap: 1.25rem;
        margin-bottom: 1.75rem;
    }

    .desk-card {
        background: var(--admin-card-bg);
        border: 1px solid var(--admin-card-border);
        border-radius: var(--radius-md);
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
    }

    .desk-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.85rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .desk-card-title {
        font-family: 'Outfit', sans-serif;
        font-size: 1.05rem;
        color: #ffffff;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .desk-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.65rem 0.75rem;
        background: #f3e8ff;
        border: 1px solid rgba(168, 85, 247, 0.2);
        border-radius: var(--radius-sm);
        margin-bottom: 0.5rem;
        transition: all 0.2s ease;
    }

    .desk-item:hover {
        background: #e9d5ff;
        border-color: rgba(168, 85, 247, 0.5);
    }

    .inventory-stat-box {
        text-align: center;
        padding: 0.85rem 0.5rem;
        background: #f3e8ff;
        border-radius: var(--radius-sm);
        border: 1px solid rgba(168, 85, 247, 0.2);
    }

    .inventory-stat-value {
        font-size: 1.5rem;
        font-weight: 800;
        font-family: 'Outfit', sans-serif;
        line-height: 1.2;
    }

    .inventory-stat-label {
        font-size: 0.72rem;
        color: var(--text-sub);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: 2px;
    }

    .chart-summary-wrapper {
        position: relative;
        width: 100%;
        height: 280px;
    }

    .chart-summary-wrapper canvas {
        max-height: 100% !important;
    }

    /* ==========================================================================
       RESPONSIVE — DASHBOARD SPECIFIC
       ========================================================================== */
    @media (max-width: 768px) {
        .today-desk-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .desk-card {
            padding: 1rem;
        }

        .desk-card-header {
            margin-bottom: 0.75rem;
            padding-bottom: 0.65rem;
        }

        .desk-card-title {
            font-size: 0.95rem;
        }

        .chart-summary-wrapper {
            height: 240px;
        }

        .inventory-stat-value {
            font-size: 1.3rem;
        }

        /* Stack action buttons in desk items on small phones */
        .desk-item {
            flex-direction: column;
            align-items: stretch;
            gap: 0.5rem;
        }

        .desk-item > a,
        .desk-item > button {
            width: 100%;
            justify-content: center;
        }
    }

    @media (max-width: 480px) {
        .chart-summary-wrapper {
            height: 220px;
        }

        .inventory-stat-value {
            font-size: 1.15rem;
        }

        .inventory-stat-label {
            font-size: 0.65rem;
        }
    }

    /* Booking Details Modal (Light theme override) */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(15, 23, 42, 0.75);
        backdrop-filter: blur(4px);
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .modal-overlay.active {
        display: flex;
    }

    .modal-container {
        width: 100%;
        max-width: 600px;
        margin: 1rem;
        animation: slideUp 0.3s ease;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    }

    @keyframes slideUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .booking-info-row {
        display: flex;
        justify-content: space-between;
        padding: 0.75rem 0;
        border-bottom: 1px solid #f1f5f9;
        align-items: center;
        gap: 0.75rem;
    }

    .booking-info-row:last-child {
        border-bottom: none;
    }

    .booking-info-label {
        color: #64748b;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex: 1 1 45%;
        font-size: 0.85rem;
    }

    .booking-info-value {
        color: #0f172a;
        font-weight: 700;
        text-align: right;
        flex: 1 1 55%;
        word-break: break-word;
        font-size: 0.85rem;
    }

    .status-badge-modern {
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 800;
        text-transform: uppercase;
        display: inline-block;
    }

    @media (max-width: 480px) {
        .booking-info-row {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.25rem;
        }

        .booking-info-value {
            text-align: left;
        }
    }
    </style>
</head>
<body class="admin-layout-body">

    <?php include 'admin-nav.php'; ?>

    <div class="admin-main-wrapper">

        <!-- Top Header Navigation Bar (Executive Luxury Design) -->
        <header class="admin-top-header">
            <!-- Left: Breadcrumbs & Live Context -->
            <div class="admin-header-left">
                <button type="button" class="admin-mobile-toggle-btn" onclick="toggleAdminSidebar()" aria-label="Open Navigation Menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="admin-header-context">

                    <div class="admin-header-title-row">
                        <div class="admin-header-title-icon">
                            <i class="fa-solid fa-chart-pie"></i>
                        </div>
                        <span class="admin-header-page-heading">Executive Dashboard</span>
                        <span class="admin-header-live-badge">
                            <span class="pulse-dot"></span>
                            <i class="fa-solid fa-bolt"></i> Live Updates
                        </span>
                    </div>
                </div>
            </div>

            <div class="admin-header-actions">
                <a href="settings.php?tab=profile" class="hs-user-badge" title="View Admin Profile">
                    <span class="hs-user-avatar"><?= strtoupper(substr(!empty(trim($admin_full_name ?? '')) ? trim($admin_full_name) : 'Admin', 0, 1)) ?></span>
                    <span class="hs-user-name"><?= htmlspecialchars(!empty(trim($admin_full_name ?? '')) ? trim($admin_full_name) : 'Super Admin') ?></span>
                </a>
                <a href="logout.php" class="hs-btn-logout" onclick="openLogoutModal(event)" title="Sign Out"><i class="fa-solid fa-sign-out-alt"></i></a>
            </div>
        </header>

        <!-- Main Content Container -->
        <main class="admin-container">

            <!-- Executive Header -->
            <div class="admin-page-header">
                <div>   
                    <h1 class="admin-page-title">
                        <i style="color: var(--primary-glow);"></i>
                        Welcome Back 
                    </h1>
                    <p class="admin-page-subtitle">Real-time resort operations & executive summary dashboard.</p>
                </div>
            </div>

            <?php if (!empty($action_msg)): ?>
                <div style="background: <?= $action_type === 'danger' ? 'rgba(244,63,94,0.15)' : 'rgba(16,185,129,0.15)' ?>; color: <?= $action_type === 'danger' ? '#fb7185' : '#34d399' ?>; padding: 0.85rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1.25rem; font-weight: 600; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; border: 1px solid <?= $action_type === 'danger' ? 'rgba(244,63,94,0.3)' : 'rgba(16,185,129,0.3)' ?>;">
                    <div style="flex: 1; min-width: 180px; font-size: 0.88rem;">
                        <i class="fa-solid <?= $action_type === 'danger' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i> <?= $action_msg ?>
                    </div>
                    <a href="admin-dashboard.php" style="color: inherit; text-decoration: none; font-size: 0.85rem; white-space: nowrap;">
                        <i class="fa-solid fa-xmark"></i> Dismiss
                    </a>
                </div>
            <?php endif; ?>

            <!-- Category 1: Executive KPI Overview -->
            <div class="admin-category-divider" style="margin-top: 0.5rem;">
                <div class="admin-category-line"></div>
                <div class="admin-category-pill">
                    <i class="fa-solid fa-chart-pie"></i>
                    <span>Executive KPIs &amp; Operational Overview</span>
                </div>
                <div class="admin-category-line"></div>
            </div>

            <!-- Executive KPI Summary Cards -->
            <div class="admin-grid" style="margin-bottom: 1.75rem;">
                <div class="admin-card admin-stat-card">
                    <span class="admin-stat-label">Gross Revenue (2026)</span>
                    <div class="admin-stat-value" style="color: var(--purple-accent);">₱<?= number_format($stats['total_revenue']) ?></div>
                    <span class="admin-badge admin-badge-purple"><i class="fa-solid fa-peso-sign"></i> Total Gross</span>
                </div>

                <div class="admin-card admin-stat-card">
                    <span class="admin-stat-label">Total Reservations</span>
                    <div class="admin-stat-value" style="color: var(--gold-glow);"><?= $stats['total_bookings'] ?></div>
                    <span class="admin-badge admin-badge-gold"><i class="fa-solid fa-layer-group"></i> Active Records</span>
                </div>

                <div class="admin-card admin-stat-card">
                    <span class="admin-stat-label">Resort Occupancy</span>
                    <div class="admin-stat-value" style="color: var(--emerald);"><?= $occupancy_rate ?>%</div>
                    <span class="admin-badge admin-badge-success"><i class="fa-solid fa-house-chimney"></i> <?= $occupied_kubos_count ?> / <?= $total_kubos_count ?> Units Active</span>
                </div>

                <div class="admin-card admin-stat-card">
                    <span class="admin-stat-label">Pending Clearances</span>
                    <div class="admin-stat-value" style="color: var(--rose);"><?= $stats['pending_count'] ?></div>
                    <span class="admin-badge admin-badge-danger"><i class="fa-solid fa-clock"></i> ₱<?= number_format($pending_gcash_amount) ?> to Review</span>
                </div>
            </div>

            <!-- Category 2: Front Desk Operations -->
            <div class="admin-category-divider">
                <div class="admin-category-line"></div>
                <div class="admin-category-pill">
                    <i class="fa-solid fa-bell-concierge"></i>
                    <span>Front Desk Daily Arrivals &amp; Stays</span>
                </div>
                <div class="admin-category-line"></div>
            </div>

            <!-- Front Desk Daily Operations Summary -->
            <div class="today-desk-grid">
                <!-- Today's Arrivals -->
                <div class="desk-card">
                    <div class="desk-card-header">
                        <h3 class="desk-card-title">
                            <i class="fa-solid fa-plane-arrival" style="color: var(--primary-glow);"></i>
                            Today's Expected Arrivals
                        </h3>
                        <span class="admin-badge admin-badge-purple"><?= count($today_arrivals) ?> Scheduled</span>
                    </div>

                    <?php if (empty($today_arrivals)): ?>
                        <div style="text-align: center; padding: 1.5rem 0.5rem; color: var(--text-muted); font-size: 0.88rem;">
                            <i class="fa-solid fa-calendar-check" style="font-size: 2rem; opacity: 0.4; margin-bottom: 0.5rem; display: block;"></i>
                            No pending arrivals scheduled for today (<?= date('M d, Y') ?>).
                        </div>
                    <?php else: ?>
                        <div class="admin-compact-list">
                            <?php foreach ($today_arrivals as $arr): ?>
                                <div class="desk-item">
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 2px; flex-wrap: wrap;">
                                            <strong style="color: var(--primary-glow); font-size: 0.85rem;">#<?= htmlspecialchars($arr['id']) ?></strong>
                                            <span style="font-size: 0.72rem; color: var(--text-sub);"><?= htmlspecialchars($arr['item_name']) ?></span>
                                        </div>
                                        <div style="font-weight: 700; color: var(--text-main); font-size: 0.85rem;"><?= htmlspecialchars(GuestHelper::normalizeName($arr['guest_name'])) ?></div>
                                        <div style="font-size: 0.72rem; color: var(--text-muted);"><?= htmlspecialchars(GuestHelper::formatPhone($arr['guest_phone'])) ?></div>
                                    </div>
                                    <a href="bookings.php?action=check_in&id=<?= urlencode($arr['id']) ?>" class="admin-btn admin-btn-gold admin-btn-sm" style="padding: 0.35rem 0.75rem; font-size: 0.75rem;" onclick="return confirm('Check in <?= htmlspecialchars(GuestHelper::normalizeName($arr['guest_name'])) ?>?')">
                                        <i class="fa-solid fa-key"></i> Check In
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top: auto; padding-top: 0.75rem; text-align: right;">
                        <a href="bookings.php" style="color: var(--primary-glow); font-size: 0.8rem; text-decoration: none; font-weight: 600;">
                            Go to Booking Management &rarr;
                        </a>
                    </div>
                </div>

                <!-- Today's Departures & In-House Stays -->
                <div class="desk-card">
                    <div class="desk-card-header">
                        <h3 class="desk-card-title">
                            <i class="fa-solid fa-door-open" style="color: var(--emerald);"></i>
                            In-House Guests & Departures
                        </h3>
                        <span class="admin-badge admin-badge-success"><?= count($in_house_guests) ?> In-House</span>
                    </div>

                    <?php if (empty($in_house_guests) && empty($today_departures)): ?>
                        <div style="text-align: center; padding: 1.5rem 0.5rem; color: var(--text-muted); font-size: 0.88rem;">
                            <i class="fa-solid fa-bed" style="font-size: 2rem; opacity: 0.4; margin-bottom: 0.5rem; display: block;"></i>
                            No guests currently checked in or departing today.
                        </div>
                    <?php else: ?>
                        <div class="admin-compact-list">
                            <?php foreach (array_slice($in_house_guests, 0, 3) as $inh): ?>
                                <div class="desk-item">
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 2px; flex-wrap: wrap;">
                                            <strong style="color: var(--emerald); font-size: 0.85rem;">#<?= htmlspecialchars($inh['id']) ?></strong>
                                            <span style="font-size: 0.72rem; color: var(--text-sub);"><?= htmlspecialchars($inh['item_name']) ?></span>
                                        </div>
                                        <div style="font-weight: 700; color: var(--text-main); font-size: 0.85rem;"><?= htmlspecialchars(GuestHelper::normalizeName($inh['guest_name'])) ?></div>
                                        <div style="font-size: 0.72rem; color: var(--text-muted);">Out: <?= date('M d, Y', strtotime($inh['check_out'] ?? $today)) ?></div>
                                    </div>
                                    <a href="bookings.php?view=checked_in&action=check_out&id=<?= urlencode($inh['id']) ?>" class="admin-btn admin-btn-outline admin-btn-sm" style="color: #f43f5e; padding: 0.35rem 0.65rem; font-size: 0.75rem;" onclick="return confirm('Process check-out for <?= htmlspecialchars(GuestHelper::normalizeName($inh['guest_name'])) ?>?')">
                                        <i class="fa-solid fa-right-from-bracket"></i> Check Out
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top: auto; padding-top: 0.75rem; text-align: right;">
                        <a href="bookings.php?view=checked_in" style="color: var(--emerald); font-size: 0.8rem; text-decoration: none; font-weight: 600;">
                            View All In-House Stays &rarr;
                        </a>
                    </div>
                </div>
            </div>

            <!-- Category 3: Accommodation & Financial Clearance -->
            <div class="admin-category-divider">
                <div class="admin-category-line"></div>
                <div class="admin-category-pill">
                    <i class="fa-solid fa-sliders"></i>
                    <span>Accommodation Status &amp; GCash Clearance</span>
                </div>
                <div class="admin-category-line"></div>
            </div>

            <!-- Mid-Tier Executive Widgets: Inventory Breakdown & GCash Queue Summary -->
            <div class="admin-summary-grid">

                <!-- Widget 1: Resort Kubo Inventory Breakdown -->
                <div class="admin-summary-card">
                    <div class="admin-summary-header">
                        <h3>
                            <i class="fa-solid fa-house-chimney-user" style="color: var(--gold-glow);"></i>
                            Resort Accommodation Status
                        </h3>
                        <a href="browse_kubos.php" class="admin-btn admin-btn-outline admin-btn-sm" style="font-size: 0.75rem;">
                            <i class="fa-solid fa-sliders"></i> Inventory
                        </a>
                    </div>

                    <p style="font-size: 0.82rem; color: var(--text-sub); margin: 0;">Live occupancy status of all <?= $total_kubos_count ?> registered accommodation units.</p>

                    <!-- Segmented Occupancy Bar -->
                    <div class="occupancy-meter-bar" title="Available: <?= $available_kubos_count ?> | Occupied: <?= $occupied_kubos_count ?> | Renovation: <?= $renovation_kubos_count ?>">
                        <?php if ($total_kubos_count > 0): ?>
                            <div class="occupancy-segment-available" style="width: <?= ($available_kubos_count / $total_kubos_count) * 100 ?>%;"></div>
                            <div class="occupancy-segment-occupied" style="width: <?= ($occupied_kubos_count / $total_kubos_count) * 100 ?>%;"></div>
                            <div class="occupancy-segment-renovation" style="width: <?= ($renovation_kubos_count / $total_kubos_count) * 100 ?>%;"></div>
                        <?php endif; ?>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.65rem; margin-top: 0.5rem;">
                        <div class="inventory-stat-box">
                            <div class="inventory-stat-value" style="color: var(--emerald);"><?= $available_kubos_count ?></div>
                            <div class="inventory-stat-label">Available</div>
                        </div>
                        <div class="inventory-stat-box">
                            <div class="inventory-stat-value" style="color: var(--purple-accent);"><?= $occupied_kubos_count ?></div>
                            <div class="inventory-stat-label">Occupied</div>
                        </div>
                        <div class="inventory-stat-box">
                            <div class="inventory-stat-value" style="color: var(--gold-glow);"><?= $renovation_kubos_count ?></div>
                            <div class="inventory-stat-label">Renovation</div>
                        </div>
                    </div>
                </div>

                <!-- Widget 2: GCash Payment Queue Clearance Summary -->
                <div class="admin-summary-card">
                    <div class="admin-summary-header">
                        <h3>
                            <i class="fa-solid fa-mobile-screen-button" style="color: var(--gold-glow);"></i>
                            GCash Verification Summary
                        </h3>
                        <a href="verify_gcash.php" class="admin-btn admin-btn-gold admin-btn-sm" style="font-size: 0.75rem;">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Desk
                        </a>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
                        <div>
                            <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Awaiting Verification:</span>
                            <div style="font-size: 1.4rem; font-weight: 800; color: #fbbf24; font-family: 'Outfit', sans-serif;">
                                <?= count($pending_gcash_items) ?> Proofs (₱<?= number_format($pending_gcash_amount) ?>)
                            </div>
                        </div>
                        <?php if (count($pending_gcash_items) > 0): ?>
                            <span class="admin-badge admin-badge-danger"><i class="fa-solid fa-bell"></i> Action Needed</span>
                        <?php else: ?>
                            <span class="admin-badge admin-badge-success"><i class="fa-solid fa-circle-check"></i> All Clear</span>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($pending_gcash_items)): ?>
                        <div style="text-align: center; padding: 1.5rem 0.5rem; color: var(--text-muted); font-size: 0.85rem;">
                            <i class="fa-solid fa-shield-check" style="font-size: 1.8rem; color: var(--emerald); opacity: 0.6; margin-bottom: 0.5rem; display: block;"></i>
                            All GCash transactions have been cleared & verified!
                        </div>
                    <?php else: ?>
                        <div class="admin-compact-list">
                            <?php foreach (array_slice($pending_gcash_items, 0, 3) as $pg): ?>
                                <div class="desk-item">
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                            <strong style="color: var(--primary-glow); font-size: 0.85rem;">#<?= htmlspecialchars($pg['id']) ?></strong>
                                            <code style="font-size: 0.75rem; color: #fbbf24;"><?= htmlspecialchars($pg['gcash_ref'] ?? 'Receipt Attached') ?></code>
                                        </div>
                                        <div style="font-weight: 700; color: var(--text-main); font-size: 0.85rem;"><?= htmlspecialchars(GuestHelper::normalizeName($pg['guest_name'])) ?></div>
                                    </div>
                                    <div style="text-align: right;">
                                        <strong style="color: var(--gold-glow); font-size: 0.92rem; display: block;">₱<?= number_format(floatval($pg['total_amount'])) ?></strong>
                                        <a href="verify_gcash.php?ref=<?= urlencode($pg['id']) ?>" class="admin-btn admin-btn-outline admin-btn-sm" style="font-size: 0.72rem; padding: 2px 7px; margin-top: 2px;">
                                            Verify &rarr;
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Category 4: Booking Management -->
            <div class="admin-category-divider">
                <div class="admin-category-line"></div>
                <div class="admin-category-pill">
                    <i class="fa-solid fa-book-bookmark"></i>
                    <span>Recent Reservation Manifest</span>
                </div>
                <div class="admin-category-line"></div>
            </div>

            <!-- Recent Reservations Manifest Summary -->
            <div class="admin-card" style="margin-bottom: 1.75rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.85rem;">
                    <div>
                        <h3 style="font-family: 'Outfit', sans-serif; font-size: 1.05rem; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fa-solid fa-list-check" style="color: var(--primary-glow);"></i>
                            Recent Reservation Records
                        </h3>
                        <p style="font-size: 0.78rem; color: var(--text-sub); margin: 3px 0 0 0;">Latest bookings with normalized guest profile records.</p>
                    </div>
                    <a href="bookings.php" class="admin-btn admin-btn-outline admin-btn-sm">
                        <i class="fa-solid fa-arrow-right"></i> Complete Manifest (<?= $stats['total_bookings'] ?>)
                    </a>
                </div>

                <div class="admin-table-container">
                    <table class="admin-table responsive-stack-table">
                        <thead>
                            <tr>
                                <th>Booking Ref</th>
                                <th>Normalized Guest Information</th>
                                <th>Item Reserved</th>
                                <th>Check-In</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th style="text-align: center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_bookings)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-muted);" data-no-label="true">No booking records found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_bookings as $b): ?>
                                    <tr>
                                        <td data-label="Booking Ref">
                                            <strong style="color: var(--primary-glow); font-family: 'Outfit', sans-serif; font-size: 0.95rem;">#<?= htmlspecialchars($b['id']) ?></strong><br>
                                            <span class="admin-badge <?= ($b['type'] ?? 'house') === 'activity' ? 'admin-badge-gold' : 'admin-badge-purple' ?>" style="font-size: 0.65rem;">
                                                <?= strtoupper($b['type'] ?? 'house') ?>
                                            </span>
                                        </td>
                                        <td data-label="Guest Information">
                                            <?= GuestHelper::renderGuestBadge($b) ?>
                                        </td>
                                        <td data-label="Item Reserved">
                                            <span style="font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($b['item_name']) ?></span><br>
                                            <small style="color: var(--text-sub);"><i class="fa-solid fa-users"></i> <?= $b['pax'] ?> Guests</small>
                                        </td>
                                        <td data-label="Check-In">
                                            <span style="font-weight: 600;"><?= date('M d, Y', strtotime($b['check_in'])) ?></span>
                                        </td>
                                        <td data-label="Amount">
                                            <strong style="color: var(--gold-glow); font-family: 'Outfit', sans-serif;">₱<?= number_format(floatval($b['total_amount'])) ?></strong>
                                        </td>
                                        <td data-label="Status">
                                            <?php
                                            $st = $b['booking_status'] ?? 'pending';
                                            if ($st === 'approved'):
                                            ?>
                                                <span class="admin-badge admin-badge-purple"><i class="fa-solid fa-circle-check"></i> Approved</span>
                                            <?php elseif ($st === 'checked_in'): ?>
                                                <span class="admin-badge admin-badge-gold"><i class="fa-solid fa-key"></i> In-House</span>
                                            <?php elseif ($st === 'checked_out'): ?>
                                                <span class="admin-badge admin-badge-success"><i class="fa-solid fa-circle-check"></i> Completed</span>
                                            <?php else: ?>
                                                <span class="admin-badge admin-badge-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Action" style="text-align: center;">
                                            <button type="button" onclick='showBookingDetails(<?= json_encode($b) ?>)' class="admin-btn admin-btn-outline admin-btn-sm" title="View Booking Record" style="min-height: 36px;">
                                                <i class="fa-solid fa-eye"></i> View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Category 5: Analytics & Operations Console -->
            <div class="admin-category-divider">
                <div class="admin-category-line"></div>
                <div class="admin-category-pill">
                    <i class="fa-solid fa-chart-line"></i>
                    <span>Financial Analytics &amp; Operations Hub</span>
                </div>
                <div class="admin-category-line"></div>
            </div>

            <!-- Financial Trends Chart & Quick Action Launchers -->
            <div class="admin-ops-layout">
                <!-- Revenue Chart -->
                <div class="admin-card">
                    <h3 style="font-family: 'Outfit', sans-serif; font-size: 1rem; color: var(--text-main); margin-bottom: 0.85rem;">
                        <i class="fa-solid fa-chart-line" style="color: var(--emerald);"></i> Monthly Revenue Performance (<?= $current_year ?>)
                    </h3>
                    <div class="chart-summary-wrapper">
                        <canvas id="adminRevenueChart"></canvas>
                    </div>
                </div>

    </div>

    <!-- Booking Details Modal -->
    <div id="detailsModal" class="modal-overlay" onclick="if(event.target === this) closeModal('detailsModal');">
        <div class="modal-container" style="max-width: 600px; background: #ffffff; border-radius: 12px; padding: 0;">
            <div style="padding: 1.25rem 1.5rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0;">
                <h3 style="margin: 0; color: #1e293b; font-family: 'Outfit', sans-serif; font-size: 1.15rem; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-calendar-check" style="color: #3b82f6;"></i>
                    Booking Record #<span id="modalRecordId" style="color: #334155;"></span>
                </h3>
                <button class="modal-close" style="background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer;" onclick="closeModal('detailsModal')">&times;</button>
            </div>

            <div id="modalDetailsBody" style="padding: 1.5rem; max-height: 70vh; overflow-y: auto; color: #334155; font-size: 0.9rem;">
                <!-- Content injected via JS -->
            </div>

            <div style="padding: 1rem 1.5rem; display: flex; justify-content: flex-end; border-top: 1px solid #e2e8f0; background: #f8fafc; border-radius: 0 0 12px 12px;">
                <button onclick="closeModal('detailsModal')" style="padding: 0.5rem 1.25rem; background: #3b82f6; color: #fff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer;">Close</button>
            </div>
        </div>
    </div>

    <script>
    (function() {
        'use strict';

        // ==================== MODAL HANDLERS ====================
        window.closeModal = function(id) {
            document.getElementById(id).classList.remove('active');
            document.body.style.overflow = '';
        };

                function formatBookingDateTime(dtStr, fallbackTime) {
            if (!dtStr) return { dateStr: 'N/A', timeStr: fallbackTime };
            const parts = dtStr.trim().split(' ');
            const datePart = parts[0];
            const timePart = parts[1];

            let dateFormatted = datePart;
            if (datePart && datePart.includes('-')) {
                const [y, m, d] = datePart.split('-');
                const dt = new Date(parseInt(y, 10), parseInt(m, 10) - 1, parseInt(d, 10));
                if (!isNaN(dt.getTime())) {
                    dateFormatted = dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                }
            }

            let timeFormatted = fallbackTime;
            if (timePart && timePart !== '00:00:00' && timePart !== '00:00') {
                const t = timePart.split(':');
                let h = parseInt(t[0], 10);
                const min = t[1] || '00';
                const ampm = h >= 12 ? 'PM' : 'AM';
                h = h % 12 || 12;
                timeFormatted = `${h}:${min} ${ampm}`;
            }

            return { dateStr: dateFormatted, timeStr: timeFormatted };
        }

        window.showBookingDetails = function(b) {
            const cinInfo = formatBookingDateTime(b.check_in, '2:00 PM');
            const coutInfo = formatBookingDateTime(b.check_out, '12:00 PM');
            document.getElementById('modalRecordId').innerText = b.id;

            const pStatus = (b.payment_status || 'unpaid').toLowerCase();
            let paymentBadgeStyle = "background: #fee2e2; color: #ef4444;";
            if (pStatus === 'verified') paymentBadgeStyle = "background: #dcfce7; color: #22c55e;";
            else if (pStatus === 'proof_submitted') paymentBadgeStyle = "background: #fef9c3; color: #eab308;";

            const bStatus = (b.booking_status || 'pending').toLowerCase();
            let bookingBadgeStyle = "background: #fef9c3; color: #eab308;";
            if (bStatus === 'approved' || bStatus === 'checked_in' || bStatus === 'checked_out') bookingBadgeStyle = "background: #dcfce7; color: #22c55e;";
            else if (bStatus === 'cancelled' || bStatus === 'rejected') bookingBadgeStyle = "background: #fee2e2; color: #ef4444;";

            const html = `
                <div class="booking-info-row">
                    <span class="booking-info-label"><i class="fa-solid fa-hashtag" style="color: #cbd5e1; width: 16px;"></i> Reference Number</span>
                    <span class="booking-info-value">${b.id}</span>
                </div>
                <div class="booking-info-row">
                    <span class="booking-info-label"><i class="fa-solid fa-layer-group" style="color: #cbd5e1; width: 16px;"></i> Type</span>
                    <span class="booking-info-value">${(b.type || 'house').toUpperCase()}</span>
                </div>
                <div class="booking-info-row">
                    <span class="booking-info-label"><i class="fa-solid fa-house" style="color: #cbd5e1; width: 16px;"></i> Item</span>
                    <span class="booking-info-value">${b.item_name}</span>
                </div>
                <div class="booking-info-row">
                    <span class="booking-info-label"><i class="fa-solid fa-user" style="color: #cbd5e1; width: 16px;"></i> Guest</span>
                    <span class="booking-info-value">${b.guest_name}</span>
                </div>
                <div class="booking-info-row">
                    <span class="booking-info-label"><i class="fa-solid fa-envelope" style="color: #cbd5e1; width: 16px;"></i> Email</span>
                    <span class="booking-info-value">${b.guest_email || 'N/A'}</span>
                </div>
                <div class="booking-info-row">
                    <span class="booking-info-label"><i class="fa-solid fa-phone" style="color: #cbd5e1; width: 16px;"></i> Contact</span>
                    <span class="booking-info-value">${b.guest_phone || 'N/A'}</span>
                </div>
                <div class="booking-info-row">
                    <span class="booking-info-label"><i class="fa-solid fa-peso-sign" style="color: #cbd5e1; width: 16px;"></i> Total Amount</span>
                    <span class="booking-info-value">₱${parseFloat(b.total_amount).toLocaleString('en-US', {minimumFractionDigits:2})}</span>
                </div>
                <div class="booking-info-row">
                    <span class="booking-info-label"><i class="fa-solid fa-calendar-plus" style="color: #cbd5e1; width: 16px;"></i> Check In Date</span>
                    <span class="booking-info-value"><strong>${cinInfo.dateStr}</strong> <span style="font-size:0.78rem; color:#d97706; margin-left:6px;"><i class="fa-regular fa-clock"></i> ${cinInfo.timeStr}</span></span>
                </div>
                <div class="booking-info-row">
                    <span class="booking-info-label"><i class="fa-solid fa-calendar-check" style="color: #cbd5e1; width: 16px;"></i> Check Out Date</span>
                    <span class="booking-info-value"><strong>${coutInfo.dateStr}</strong> <span style="font-size:0.78rem; color:#059669; margin-left:6px;"><i class="fa-regular fa-clock"></i> ${coutInfo.timeStr}</span></span>
                </div>
                <div class="booking-info-row">
                    <span class="booking-info-label"><i class="fa-solid fa-users" style="color: #cbd5e1; width: 16px;"></i> Number of Pax</span>
                    <span class="booking-info-value">${b.pax}</span>
                </div>
                <div class="booking-info-row">
                    <span class="booking-info-label"><i class="fa-solid fa-clipboard-list" style="color: #cbd5e1; width: 16px;"></i> Guest List</span>
                    <span class="booking-info-value" style="font-size: 0.82rem; font-weight: 500; white-space: pre-wrap;">${b.guest_list ? b.guest_list : 'No list provided'}</span>
                </div>
                <div class="booking-info-row">
                    <span class="booking-info-label"><i class="fa-solid fa-credit-card" style="color: #cbd5e1; width: 16px;"></i> Payment Status</span>
                    <span class="booking-info-value"><span class="status-badge-modern" style="${paymentBadgeStyle}"><i class="fa-solid fa-check-circle"></i> ${pStatus}</span></span>
                </div>
                <div class="booking-info-row">
                    <span class="booking-info-label"><i class="fa-solid fa-receipt" style="color: #cbd5e1; width: 16px;"></i> GCash Reference</span>
                    <span class="booking-info-value" style="color: #059669; font-family: monospace;"># ${b.gcash_ref || 'N/A'}</span>
                </div>
                <div class="booking-info-row">
                    <span class="booking-info-label"><i class="fa-solid fa-circle-info" style="color: #cbd5e1; width: 16px;"></i> Booking Status</span>
                    <span class="booking-info-value"><span class="status-badge-modern" style="${bookingBadgeStyle}"><i class="fa-solid fa-check-circle"></i> ${bStatus}</span></span>
                </div>
            `;

            document.getElementById('modalDetailsBody').innerHTML = html;
            document.getElementById('detailsModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        };

        // ESC key closes modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                window.closeModal('detailsModal');
            }
        });

        // ==================== REVENUE CHART ====================
        const chartCanvas = document.getElementById('adminRevenueChart');
        if (chartCanvas && typeof Chart !== 'undefined') {
            const ctx = chartCanvas.getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($chart_labels) ?>,
                    datasets: [{
                        label: 'Monthly Revenue (₱)',
                        data: <?= json_encode($chart_data) ?>,
                        borderColor: '#a855f7',
                        borderWidth: 3,
                        backgroundColor: 'rgba(168, 85, 247, 0.15)',
                        fill: true,
                        tension: 0.35,
                        pointBackgroundColor: '#f59e0b',
                        pointRadius: window.innerWidth < 768 ? 3 : 5,
                        pointHoverRadius: 7
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return '₱' + Number(context.parsed.y).toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: 'rgba(148, 163, 184, 0.15)' },
                            ticks: {
                                color: '#64748b',
                                font: { size: window.innerWidth < 480 ? 10 : 12 }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            min: 0,
                            suggestedMax: 10000,
                            grid: { color: 'rgba(148, 163, 184, 0.15)' },
                            ticks: {
                                color: '#64748b',
                                font: { size: window.innerWidth < 480 ? 10 : 12 },
                                callback: function(value) {
                                    return '₱' + (value >= 1000 ? (value / 1000) + 'k' : value);
                                }
                            }
                        }
                    }
                }
            });
        }
    })();
    </script>
</body>
</html>