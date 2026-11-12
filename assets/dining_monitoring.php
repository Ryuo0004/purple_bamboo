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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitchen Dining & Serve Time Monitoring Console | Purple Bamboo Resort</title>
    <!-- Executive Admin/Staff Operations Layout -->
    <link rel="stylesheet" href="admin-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="admin-layout-body">

    <?php include 'admin-nav.php'; ?>

    <div class="admin-main-wrapper">
        
        <!-- Header -->
        <header class="admin-top-header">
            <div class="admin-top-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" placeholder="Search dining ref #, guest name, or kubo location..." value="<?= htmlspecialchars($search) ?>" onkeyup="if(event.key==='Enter') window.location='dining_monitoring.php?search='+encodeURIComponent(this.value)">
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

            <!-- Status Filter Tabs -->
            <div style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
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
                                <th>Kitchen Workflow Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($all_orders)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: var(--text-sub); padding: 2rem;">No dining orders match the selected filter.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($all_orders as $ord): ?>
                                    <tr>
                                        <td>
                                            <strong style="color: var(--purple-accent); display: block;">#<?= htmlspecialchars($ord['id']) ?></strong>
                                            <span style="font-weight: 700; color: var(--text-main, #0f172a); font-size: 0.88rem;"><i class="fa-solid fa-location-dot" style="color: var(--purple-accent, #7c3aed);"></i> <?= htmlspecialchars($ord['kubo_location']) ?></span>
                                            <small style="color: var(--text-sub); display: block;">Requested: <?= htmlspecialchars($ord['requested_serve_time']) ?></small>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($ord['guest_name']) ?></strong>
                                            <div style="font-size: 0.78rem; color: var(--text-sub);"><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($ord['guest_phone']) ?></div>
                                        </td>
                                        <td>
                                            <div style="font-size: 0.85rem;">
                                                <?php foreach ($ord['items_parsed'] as $it): ?>
                                                    <div>• <?= $it['qty'] ?>x <strong><?= htmlspecialchars($it['name']) ?></strong></div>
                                                <?php endforeach; ?>
                                            </div>
                                            <strong style="color: #d97706; font-size: 0.92rem; display: block; margin-top: 0.3rem;">₱<?= number_format(floatval($ord['total_amount']), 2) ?></strong>
                                        </td>
                                        <td>
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
