<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Strict RBAC: Only Admin and Staff accounts permitted
if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), ['admin', 'staff'])) {
    $err = (isset($_SESSION['role']) && $_SESSION['role'] === 'guest') ? 'guest_restricted' : 'unauthorized';
    header("Location: admin-login.php?error=" . $err);
    exit();
}

require_once __DIR__ . '/dining_store.php';

// Handle Kitchen Status Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $order_id = $_POST['order_id'] ?? '';
    $new_status = $_POST['new_status'] ?? 'preparing';
    $kitchen_notes = trim($_POST['kitchen_notes'] ?? '');

    if (!empty($order_id)) {
        DiningStore::updateOrderStatus($order_id, $new_status, $kitchen_notes);
        header("Location: dining_monitoring.php?updated=1");
        exit();
    }
}

// Handle Payment Status Updates (Verify GCash / Mark Cash Paid / Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_payment_status') {
    $order_id = $_POST['order_id'] ?? '';
    $payment_status = $_POST['payment_status'] ?? 'verified';
    $payment_notes = trim($_POST['payment_notes'] ?? '');
    $staff_name = $_SESSION['user_name'] ?? ($_SESSION['role'] === 'admin' ? 'Resort Admin' : 'Staff');

    if (!empty($order_id)) {
        DiningStore::updatePaymentStatus($order_id, $payment_status, $payment_notes, $staff_name);
        header("Location: dining_monitoring.php?payment_updated=1");
        exit();
    }
}

$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

$all_orders = DiningStore::getAllOrders([
    'search' => $search
]);

if ($status_filter !== 'all') {
    $all_orders = array_values(array_filter($all_orders, function($o) use ($status_filter) {
        $st = strtolower(trim($o['order_status'] ?? ''));
        if ($status_filter === 'pending') {
            return in_array($st, ['pending', 'accepted']);
        } elseif ($status_filter === 'preparing') {
            return in_array($st, ['preparing', 'ready']);
        } elseif ($status_filter === 'delivering') {
            return in_array($st, ['out_for_serving', 'delivering']);
        } elseif ($status_filter === 'served') {
            return in_array($st, ['served', 'completed']);
        }
        return $st === $status_filter;
    }));
}

$stats = DiningStore::getStats();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#1e1035">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Kitchen Dining & Serve Time Monitoring Console | Purple Bamboo Resort</title>
    <!-- Executive Admin/Staff Operations Layout -->
    <link rel="stylesheet" href="admin-style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* =====================================================
           RESPONSIVE STYLES — ALL DEVICES
           ===================================================== */

        /* Mobile: sidebar off-canvas, main wrapper takes full width */
        @media (max-width: 991px) {
            .admin-main-wrapper {
                width: 100% !important;
                margin-left: 0 !important;
            }
        }
        @media (min-width: 992px) {
            .admin-main-wrapper {
                width: calc(100% - 260px) !important;
            }
        }

        /* Status filter tabs — horizontal scroll on mobile */
        .dining-filter-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            flex-wrap: nowrap;
            padding-bottom: 4px;
            scrollbar-width: none;
        }
        .dining-filter-tabs::-webkit-scrollbar { height: 3px; }
        .dining-filter-tabs .admin-btn {
            white-space: nowrap;
            flex-shrink: 0;
            min-height: 44px;
        }

        /* Table wrapper — horizontal scroll fallback */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* Table stack on mobile screens */
        @media (max-width: 768px) {
            .admin-table thead tr {
                display: none;
            }
            .admin-table,
            .admin-table tbody,
            .admin-table tr,
            .admin-table td {
                display: block;
                width: 100%;
            }
            .admin-table tr {
                border: 1px solid rgba(168, 85, 247, 0.3);
                border-radius: 10px;
                margin-bottom: 1rem;
                overflow: hidden;
            }
            .admin-table td {
                padding: 0.65rem 1rem 0.65rem 45% !important;
                position: relative;
                text-align: right;
                border-bottom: 1px solid var(--admin-card-border, #e2e8f0) !important;
                min-height: 2.5rem;
                font-size: 0.84rem !important;
                white-space: normal !important;
            }
            .admin-table td:last-child { border-bottom: none !important; }
            .admin-table td::before {
                content: attr(data-label);
                position: absolute;
                top: 50%;
                left: 1rem;
                transform: translateY(-50%);
                width: 40%;
                font-weight: 700;
                font-size: 0.72rem;
                text-transform: uppercase;
                color: #94a3b8;
                text-align: left;
                white-space: nowrap;
            }
            /* Last action cell: left-align, no label */
            .admin-table td:last-child {
                padding-left: 1rem !important;
                text-align: left;
            }
            .admin-table td:last-child::before {
                display: none;
            }
            .admin-table td[data-label="Allergy Alert"] {
                text-align: left !important;
            }
        }

        /* Kitchen Allergy Callout — High-contrast alert */
        .kitchen-allergy-alert {
            background: #fff1f2 !important;
            border: 1.5px solid #fecdd3 !important;
            border-left: 4px solid #e11d48 !important;
            padding: 0.7rem 0.85rem !important;
            border-radius: 8px !important;
            font-size: 0.82rem !important;
            box-shadow: 0 1px 3px rgba(225, 29, 72, 0.08) !important;
            text-align: left !important;
        }
        .kitchen-allergy-alert .allergy-title {
            color: #be123c !important;
            font-weight: 800 !important;
            font-size: 0.78rem !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            display: flex !important;
            align-items: center !important;
            gap: 0.35rem !important;
            margin-bottom: 0.35rem !important;
        }
        .kitchen-allergy-alert .allergy-title i {
            color: #e11d48 !important;
            font-size: 0.9rem !important;
        }
        .kitchen-allergy-alert .allergy-tags {
            color: #881337 !important;
            font-weight: 700 !important;
            font-size: 0.88rem !important;
            line-height: 1.35 !important;
            display: block !important;
            margin-bottom: 0.35rem !important;
        }
        .kitchen-allergy-alert .allergy-note {
            margin-top: 0.35rem !important;
            font-size: 0.8rem !important;
            color: #9f1239 !important;
            background: #ffffff !important;
            border: 1px dashed #fda4af !important;
            border-radius: 6px !important;
            padding: 0.35rem 0.6rem !important;
            font-style: italic !important;
            line-height: 1.35 !important;
        }
        .kitchen-allergy-alert .allergy-note strong {
            font-style: normal !important;
            color: #be123c !important;
            font-weight: 700 !important;
        }

        /* Stat cards: 2-col on tablet, 1-col on small phone */
        @media (max-width: 580px) {
            .admin-grid {
                grid-template-columns: 1fr 1fr !important;
                gap: 0.75rem !important;
            }
            .admin-stat-value {
                font-size: 1.5rem !important;
            }
        }
        @media (max-width: 380px) {
            .admin-grid {
                grid-template-columns: 1fr !important;
            }
        }

        /* Page header & actions wrap on small screens */
        @media (max-width: 640px) {
            .admin-page-header {
                flex-direction: column;
                align-items: stretch;
                gap: 0.85rem;
            }
            .admin-header-actions {
                flex-wrap: wrap;
                gap: 0.5rem;
            }
            .admin-header-actions > * {
                flex: 1;
                justify-content: center;
                min-height: 44px;
            }
        }

        /* Kitchen action buttons — larger tap area on mobile */
        @media (max-width: 480px) {
            .admin-btn-sm {
                min-height: 44px !important;
                font-size: 0.85rem !important;
                padding: 0.55rem 0.9rem !important;
            }
        }
    </style>
</head>

<body class="admin-layout-body">

    <?php include 'admin-nav.php'; ?>

    <div class="admin-main-wrapper">
        
        <!-- Header -->
        <header class="admin-top-header">
                <div class="admin-header-left">
                    <button type="button" class="admin-mobile-toggle-btn" onclick="toggleAdminSidebar()" aria-label="Open Navigation Menu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <div class="admin-header-context">

                        <div class="admin-header-title-row">
                            <div class="admin-header-title-icon"><i class="fa-solid fa-fire-burner"></i></div>
                            <span class="admin-header-page-heading">Kitchen Orders Console</span>
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
            
            <!-- Combined Segmented Tab Switcher (Matching Reference Style) -->
            <div class="dining-segmented-container">
                <div class="dining-segmented-tabs">
                    <a href="dining_monitoring.php" class="dining-segmented-tab active">
                        <i class="fa-solid fa-fire-burner"></i>
                        <span>Kitchen Orders</span>
                        <span class="tab-badge"><?= $stats['preparing_count'] + $stats['pending_count'] ?> Active</span>
                    </a>
                    <a href="manage_menu.php" class="dining-segmented-tab">
                        <i class="fa-solid fa-bowl-food"></i>
                        <span>Dining Menu</span>
                        <span class="tab-badge">Recipes & Dishes</span>
                    </a>
                </div>
                <div class="dining-segmented-actions">
                    <span style="color: #94a3b8; font-size: 0.8rem; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-circle" style="color: #10b981; font-size: 0.55rem;"></i> Live Kitchen Service
                    </span>
                </div>
            </div>

            <div class="admin-page-header">
                <div>
                    <h1 class="admin-page-title">
                        <i class="fa-solid fa-fire-burner" style="color: var(--gold-glow);"></i> Kitchen Food & Serve Time Monitor
                    </h1>
                    <p class="admin-page-subtitle">Real-time order dispatch, guest allergy safety screening, and food preparation duration tracking.</p>
                </div>
            </div>

            <!-- KPI Metric Stat Cards -->
            <div class="admin-grid" style="margin-bottom: 2rem;">
                <div class="admin-card admin-stat-card">
                    <span class="admin-stat-label">Total Dining Orders</span>
                    <div class="admin-stat-value" style="color: var(--purple-accent);"><?= $stats['total_orders'] ?> Orders</div>
                    <span class="admin-badge admin-badge-purple"><i class="fa-solid fa-receipt"></i> Room & Kubo Service</span>
                </div>

                <div class="admin-card admin-stat-card">
                    <span class="admin-stat-label">Active Kitchen Queue</span>
                    <div class="admin-stat-value" style="color: var(--gold-glow);"><?= $stats['preparing_count'] + $stats['pending_count'] ?> Active</div>
                    <span class="admin-badge admin-badge-gold"><i class="fa-solid fa-fire"></i> Cooking & Serving</span>
                </div>

                <div class="admin-card admin-stat-card">
                    <span class="admin-stat-label">Average Serve Duration</span>
                    <div class="admin-stat-value" style="color: var(--emerald);"><?= $stats['avg_serve_mins'] ?> Mins</div>
                    <span class="admin-badge admin-badge-success"><i class="fa-solid fa-stopwatch"></i> Target ~ 20 Mins</span>
                </div>

                <div class="admin-card admin-stat-card">
                    <span class="admin-stat-label">Allergy Safety Callouts</span>
                    <div class="admin-stat-value" style="color: var(--rose);"><?= $stats['allergy_alerts_count'] ?> Alerts</div>
                    <span class="admin-badge admin-badge-danger"><i class="fa-solid fa-triangle-exclamation"></i> High Kitchen Priority</span>
                </div>
            </div>

            <!-- Status Filter Tabs (scrollable on mobile) -->
            <div class="dining-filter-tabs">
                <a href="dining_monitoring.php?status=all" class="admin-btn <?= ($status_filter === 'all') ? 'admin-btn-primary' : 'admin-btn-outline' ?>">All Orders</a>
                <a href="dining_monitoring.php?status=pending" class="admin-btn <?= ($status_filter === 'pending') ? 'admin-btn-primary' : 'admin-btn-outline' ?>">Pending (<?= $stats['pending_count'] ?>)</a>
                <a href="dining_monitoring.php?status=preparing" class="admin-btn <?= ($status_filter === 'preparing') ? 'admin-btn-primary' : 'admin-btn-outline' ?>">Preparing in Kitchen</a>
                <a href="dining_monitoring.php?status=delivering" class="admin-btn <?= ($status_filter === 'delivering') ? 'admin-btn-primary' : 'admin-btn-outline' ?>">Out for Delivery</a>
                <a href="dining_monitoring.php?status=served" class="admin-btn <?= ($status_filter === 'served') ? 'admin-btn-primary' : 'admin-btn-outline' ?>">Served (<?= $stats['served_count'] ?>)</a>
            </div>

            <!-- Active Dining Orders Manifest -->
            <div class="admin-card">
                <h3 style="font-family: 'Outfit', sans-serif; color: var(--text-main, #0f172a); margin-bottom: 1rem;">
                    <i class="fa-solid fa-list-check" style="color: var(--gold-glow);"></i> Active Kitchen Orders Manifest
                </h3>

                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Order Ref & Location</th>
                                <th>Guest Details</th>
                                <th>Order Items & Total</th>
                                <th>Allergy Safety Callouts ⚠️</th>
                                <th>Serve Time Status</th>
                                <th>Payment</th>
                                <th>Kitchen Workflow Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($all_orders)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: var(--text-sub); padding: 2rem;">No dining orders match the selected filter.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($all_orders as $ord): ?>
                                    <tr>
                                        <td data-label="Order Ref">
                                            <strong style="color: var(--purple-accent); display: block;">#<?= htmlspecialchars($ord['id']) ?></strong>
                                            <span style="font-weight: 700; color: var(--text-main, #0f172a); font-size: 0.88rem;"><i class="fa-solid fa-location-dot" style="color: var(--purple-accent, #7c3aed);"></i> <?= htmlspecialchars($ord['kubo_location']) ?></span>
                                            <small style="color: var(--text-sub); display: block;">Requested: <?= htmlspecialchars($ord['requested_serve_time']) ?></small>
                                        </td>
                                        <td data-label="Guest">
                                            <strong><?= htmlspecialchars($ord['guest_name']) ?></strong>
                                            <div style="font-size: 0.78rem; color: var(--text-sub);"><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($ord['guest_phone']) ?></div>
                                        </td>
                                        <td data-label="Order Items">
                                            <div style="font-size: 0.85rem;">
                                                <?php foreach ($ord['items_parsed'] as $it): ?>
                                                    <div>• <?= $it['qty'] ?>x <strong><?= htmlspecialchars($it['name']) ?></strong></div>
                                                <?php endforeach; ?>
                                            </div>
                                            <strong style="color: #d97706; font-size: 0.92rem; display: block; margin-top: 0.3rem;">₱<?= number_format(floatval($ord['total_amount']), 2) ?></strong>
                                        </td>
                                        <td data-label="Allergy Alert">
                                            <?php if (!empty($ord['guest_allergies']) || !empty($ord['allergy_conflicts'])): ?>
                                                <div class="kitchen-allergy-alert" style="background: #fff1f2; border: 1.5px solid #fecdd3; border-left: 4px solid #e11d48; padding: 0.7rem 0.85rem; border-radius: 8px; font-size: 0.82rem; box-shadow: 0 1px 3px rgba(225, 29, 72, 0.08); text-align: left;">
                                                    <div class="allergy-title" style="color: #be123c; font-weight: 800; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 0.35rem; margin-bottom: 0.35rem;">
                                                        <i class="fa-solid fa-triangle-exclamation" style="color: #e11d48; font-size: 0.9rem;"></i> KITCHEN ALLERGY WARNING:
                                                    </div>
                                                    <span class="allergy-tags" style="color: #881337; font-weight: 700; font-size: 0.88rem; line-height: 1.35; display: block; margin-bottom: 0.35rem;">
                                                        <?= htmlspecialchars($ord['guest_allergies'] ?: 'Allergy Sensitivity Flagged') ?>
                                                    </span>
                                                    <?php if (!empty($ord['allergy_notes'])): ?>
                                                        <div class="allergy-note" style="margin-top: 0.35rem; font-size: 0.8rem; color: #9f1239; background: #ffffff; border: 1px dashed #fda4af; border-radius: 6px; padding: 0.35rem 0.6rem; font-style: italic; line-height: 1.35;">
                                                            <strong style="font-style: normal; color: #be123c; font-weight: 700;">Note:</strong> "<?= htmlspecialchars($ord['allergy_notes']) ?>"
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="admin-badge admin-badge-success" style="font-size: 0.75rem;"><i class="fa-solid fa-shield-check"></i> Standard Diet / No Allergies</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Serve Time">
                                            <?php
                                             $curr_status = strtolower(trim($ord['order_status'] ?? 'pending'));
                                            $status_badge_class = 'admin-badge-purple';
                                            $display_status = strtoupper(str_replace('_', ' ', $curr_status));
                                            if ($curr_status === 'delivering') {
                                                $display_status = 'OUT FOR SERVING';
                                            } elseif ($curr_status === 'completed') {
                                                $display_status = 'SERVED';
                                            }

                                            if (in_array($curr_status, ['served', 'completed'])) {
                                                $status_badge_class = 'admin-badge-success';
                                            } elseif (in_array($curr_status, ['preparing', 'ready', 'out_for_serving', 'delivering'])) {
                                                $status_badge_class = 'admin-badge-gold';
                                            } elseif (in_array($curr_status, ['cancelled', 'canceled'])) {
                                                $status_badge_class = 'admin-badge-danger';
                                            }
                                            ?>
                                            <span class="admin-badge <?= $status_badge_class ?>" style="margin-bottom: 0.4rem; display: inline-block;">
                                                <?= htmlspecialchars($display_status) ?>
                                            </span>
                                            <div style="font-size: 0.8rem; color: var(--text-sub);">
                                                <i class="fa-solid fa-stopwatch"></i> <strong><?= htmlspecialchars($ord['serve_duration_text']) ?></strong>
                                                <?php if (!empty($ord['is_overdue'])): ?>
                                                    <span style="color: var(--rose, #e11d48); font-weight: 800; display: block;"><i class="fa-solid fa-triangle-exclamation"></i> OVERDUE (&gt;25 Mins)</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="Payment">
                                            <?php
                                            $ord_pm = strtolower(trim($ord['payment_method'] ?? 'cash'));
                                            $ord_ps = strtolower(trim($ord['payment_status'] ?? 'unpaid'));
                                            ?>
                                            <div style="margin-bottom: 4px;">
                                                <?php if ($ord_pm === 'gcash'): ?>
                                                    <span class="admin-badge admin-badge-purple" style="background: #e0f2fe; color: #0369a1; border-color: #bae6fd; font-size: 0.75rem;">
                                                        <i class="fa-solid fa-mobile-screen"></i> GCash
                                                    </span>
                                                <?php elseif ($ord_pm === 'charge_to_room'): ?>
                                                    <span class="admin-badge admin-badge-purple" style="background: #ede9fe; color: #6d28d9; border-color: #ddd6fe; font-size: 0.75rem;">
                                                        <i class="fa-solid fa-key"></i> Room Folio
                                                    </span>
                                                <?php else: ?>
                                                    <span class="admin-badge admin-badge-outline" style="background: #f8fafc; color: #475569; border-color: #cbd5e1; font-size: 0.75rem;">
                                                        <i class="fa-solid fa-money-bill-wave"></i> Cash
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <?php if ($ord_ps === 'verified' || $ord_ps === 'paid'): ?>
                                                <span style="color: #10b981; font-weight: 800; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 4px;">
                                                    <i class="fa-solid fa-circle-check"></i> Paid / Verified
                                                </span>
                                            <?php elseif ($ord_ps === 'proof_submitted'): ?>
                                                <span style="color: #b45309; font-weight: 800; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 4px;">
                                                    <i class="fa-solid fa-hourglass-half"></i> Proof Uploaded
                                                </span>
                                                <?php if (!empty($ord['gcash_ref'])): ?>
                                                    <div style="font-size: 0.72rem; color: #64748b; margin-top: 2px;">
                                                        Ref: <strong><?= htmlspecialchars($ord['gcash_ref']) ?></strong>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($ord['payment_proof'])): ?>
                                                    <div style="margin-top: 3px;">
                                                        <a href="<?= htmlspecialchars($ord['payment_proof']) ?>" target="_blank" class="admin-btn admin-btn-outline admin-btn-sm" style="font-size: 0.72rem; padding: 2px 7px; display: inline-flex; align-items: center; gap: 4px;">
                                                            <i class="fa-solid fa-image"></i> View Receipt
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                                <form action="dining_monitoring.php" method="POST" style="margin-top: 4px;">
                                                    <input type="hidden" name="action" value="update_payment_status">
                                                    <input type="hidden" name="order_id" value="<?= htmlspecialchars($ord['id']) ?>">
                                                    <input type="hidden" name="payment_status" value="verified">
                                                    <button type="submit" class="admin-btn admin-btn-success admin-btn-sm" style="font-size: 0.72rem; padding: 3px 8px; width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                                        <i class="fa-solid fa-check"></i> Verify GCash
                                                    </button>
                                                </form>
                                            <?php elseif ($ord_pm === 'charge_to_room'): ?>
                                                <span style="color: #6d28d9; font-weight: 700; font-size: 0.78rem; display: block;">
                                                    Bill to Room Folio
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #dc2626; font-weight: 800; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 4px;">
                                                    <i class="fa-solid fa-triangle-exclamation"></i> Unpaid
                                                </span>
                                                <?php if ($ord_pm === 'cash'): ?>
                                                    <form action="dining_monitoring.php" method="POST" style="margin-top: 4px;">
                                                        <input type="hidden" name="action" value="update_payment_status">
                                                        <input type="hidden" name="order_id" value="<?= htmlspecialchars($ord['id']) ?>">
                                                        <input type="hidden" name="payment_status" value="paid">
                                                        <button type="submit" class="admin-btn admin-btn-outline admin-btn-sm" style="font-size: 0.72rem; padding: 3px 8px; width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                                            <i class="fa-solid fa-coins"></i> Mark Cash Paid
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Kitchen Action">
                                            <form action="dining_monitoring.php" method="POST" style="display: flex; flex-direction: column; gap: 0.4rem;">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="order_id" value="<?= htmlspecialchars($ord['id']) ?>">

                                                <?php if ($curr_status === 'pending'): ?>
                                                    <button type="submit" name="new_status" value="accepted" class="admin-btn admin-btn-primary admin-btn-sm">
                                                        <i class="fa-solid fa-check"></i> Accept Order
                                                    </button>
                                                <?php elseif ($curr_status === 'accepted'): ?>
                                                    <button type="submit" name="new_status" value="preparing" class="admin-btn admin-btn-gold admin-btn-sm">
                                                        <i class="fa-solid fa-fire"></i> Start Preparing
                                                    </button>
                                                <?php elseif ($curr_status === 'preparing'): ?>
                                                    <button type="submit" name="new_status" value="ready" class="admin-btn admin-btn-primary admin-btn-sm" style="background: var(--purple-accent, #7c3aed); color: #fff;">
                                                        🍽️ Mark as Ready
                                                    </button>
                                                <?php elseif ($curr_status === 'ready'): ?>
                                                    <button type="submit" name="new_status" value="out_for_serving" class="admin-btn admin-btn-primary admin-btn-sm">
                                                        <i class="fa-solid fa-person-walking-luggage"></i> Out for Serving
                                                    </button>
                                                <?php elseif ($curr_status === 'out_for_serving' || $curr_status === 'delivering'): ?>
                                                    <button type="submit" name="new_status" value="served" class="admin-btn admin-btn-success admin-btn-sm" style="background: var(--emerald, #10b981); color: #fff;">
                                                        <i class="fa-solid fa-check"></i> Mark as Served
                                                    </button>
                                                <?php elseif ($curr_status === 'served' || $curr_status === 'completed'): ?>
                                                    <span style="color: var(--emerald, #10b981); font-weight: 700; font-size: 0.85rem;"><i class="fa-solid fa-circle-check"></i> Completed</span>
                                                <?php elseif ($curr_status === 'cancelled' || $curr_status === 'canceled'): ?>
                                                    <span style="color: var(--rose, #e11d48); font-weight: 700; font-size: 0.85rem;"><i class="fa-solid fa-circle-xmark"></i> Cancelled</span>
                                                <?php else: ?>
                                                    <span style="color: var(--text-sub); font-size: 0.85rem;">Completed</span>
                                                <?php endif; ?>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

</body>
</html>

