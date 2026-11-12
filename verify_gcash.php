<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/booking_store.php';
require_once __DIR__ . '/security.php';  // CSRF protection (BUG-14 fix)

// Strict RBAC: verify_gcash.php is the Staff & Admin Verification Desk. Guests are redirected to payment_method.php.
$user_role = strtolower($_SESSION['role'] ?? '');
if ($user_role !== 'admin' && $user_role !== 'staff') {
    $ref_param = !empty($_GET['ref']) ? '?ref=' . urlencode($_GET['ref']) : '';
    header("Location: payment_method.php" . $ref_param);
    exit();
}
$is_admin_layout = true;

// Handle POST Actions: Approve / Reject via CSRF-protected form (BUG-14 fix: was GET, now POST-only)
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_action']) && isset($_POST['quick_ref'])) {
    verify_csrf_token();  // reject any forged cross-site requests
    $g_action = $_POST['quick_action'];
    $g_ref    = trim($_POST['quick_ref']);
    if ($g_action === 'approve') {
        BookingStore::updateBookingStatus($g_ref, 'approved', 'verified');
        $msg = 'GCash payment for Ref #' . htmlspecialchars($g_ref) . ' has been APPROVED and booking marked as verified.';
    } elseif ($g_action === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === 'Others' || empty($reason)) {
            $reason = trim($_POST['other_reason'] ?? 'Proof was rejected by admin');
        }
        BookingStore::updateBookingStatus($g_ref, 'pending', 'rejected', null, null, null, $reason);
        $msg = 'GCash payment proof for Ref #' . htmlspecialchars($g_ref) . ' was marked as REJECTED.';
    }
}

// Handle POST Submissions (Proof Upload or Staff Verification)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ref_no = $_POST['ref_no'] ?? ($_GET['ref'] ?? '');
    if (isset($_POST['submit_proof'])) {
        $gref = trim($_POST['gcash_ref'] ?? '');
        $proof_path = 'uploads/receipts/sample_receipt.jpg';

        if (isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/receipts/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $ext = pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION);
            $new_filename = 'receipt_' . preg_replace('/[^A-Za-z0-9_-]/', '', $ref_no) . '_' . time() . '.' . strtolower($ext);
            $target_file = $upload_dir . $new_filename;
            if (move_uploaded_file($_FILES['proof_image']['tmp_name'], $target_file)) {
                $proof_path = 'uploads/receipts/' . $new_filename;
            }
        }

        BookingStore::updateBookingStatus($ref_no, 'pending', 'proof_submitted', $gref, $proof_path);
        $msg = 'GCash payment proof for Ref #' . htmlspecialchars($ref_no) . ' submitted successfully! Awaiting staff verification.';
    } elseif (isset($_POST['verify_action'])) {
        $action = $_POST['verify_action'];
        if ($action === 'approved') {
            BookingStore::updateBookingStatus($ref_no, 'approved', 'verified');
            $msg = 'Payment for Ref #' . htmlspecialchars($ref_no) . ' has been APPROVED and booking marked as verified.';
        } else {
            $reason = trim($_POST['reject_reason'] ?? 'Proof was rejected by admin');
            if ($reason === 'Others' || empty($reason)) {
                $reason = trim($_POST['other_reject_reason'] ?? 'Proof was rejected by admin');
            }
            BookingStore::updateBookingStatus($ref_no, 'pending', 'rejected', null, null, null, $reason);
            $msg = 'Payment proof for Ref #' . htmlspecialchars($ref_no) . ' was REJECTED and marked as unverified/rejected.';
        }
    }
}

// Retrieve all GCash submissions for the verification queue
$all_bookings = BookingStore::getAllBookings();
$all_gcash_bookings = array_filter($all_bookings, function($b) {
    $pstatus = strtolower($b['payment_status'] ?? '');
    $proof   = $b['payment_proof'] ?? '';
    $gcash   = trim($b['gcash_ref'] ?? '');

    // Always show bookings with a meaningful payment status
    if (in_array($pstatus, ['proof_submitted', 'verified', 'rejected'])) {
        return true;
    }
    // Show if they have a real gcash ref AND a real proof (not the placeholder)
    $is_real_proof = !empty($proof) && $proof !== 'uploads/receipts/sample_receipt.jpg';
    if (!empty($gcash) && $is_real_proof) {
        return true;
    }
    return false;
});

// Sort to prioritize pending submissions first, then newest submissions/resubmissions
usort($all_gcash_bookings, function($a, $b) {
    $a_p = (strtolower($a['payment_status'] ?? '') === 'proof_submitted') ? 0 : 1;
    $b_p = (strtolower($b['payment_status'] ?? '') === 'proof_submitted') ? 0 : 1;
    if ($a_p !== $b_p) {
        return $a_p <=> $b_p;
    }
    // Check resubmitted_at, updated_at, or created_at
    $a_time = !empty($a['resubmitted_at']) ? $a['resubmitted_at'] : (!empty($a['updated_at']) ? $a['updated_at'] : ($a['created_at'] ?? ''));
    $b_time = !empty($b['resubmitted_at']) ? $b['resubmitted_at'] : (!empty($b['updated_at']) ? $b['updated_at'] : ($b['created_at'] ?? ''));
    return strcmp($b_time, $a_time);
});

// Determine active selected booking
$ref = $_GET['ref'] ?? '';
if (empty($ref)) {
    if (!empty($all_gcash_bookings)) {
        $first_pending = null;
        foreach ($all_gcash_bookings as $b) {
            if (strtolower($b['payment_status'] ?? '') === 'proof_submitted') {
                $first_pending = $b['id'];
                break;
            }
        }
        $ref = $first_pending ?: reset($all_gcash_bookings)['id'];
    }
}

$booking_item = !empty($ref) ? BookingStore::getBookingById($ref) : null;
if (!$booking_item && !empty($all_gcash_bookings)) {
    $booking_item = reset($all_gcash_bookings);
}
$proof_src = !empty($booking_item['payment_proof']) ? $booking_item['payment_proof'] : '';
$stats = BookingStore::getStats();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GCash Payment Verification Queue | Purple Bamboo Resort</title>
    <?php if ($is_admin_layout): ?>
        <link rel="stylesheet" href="admin-style.css?v=<?= time() ?>">
    <?php else: ?>
        <link rel="stylesheet" href="style.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* GCash Verification Queue Theme-Aligned Styles */
        .admin-table-container {
            position: relative;
            overflow-x: auto;
            border-radius: var(--radius-md, 14px);
            background: var(--admin-card-bg, #ffffff);
            border: 1px solid var(--admin-card-border, #e2e8f0);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        }

        .vg-queue-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
            background: #ffffff;
        }

        .vg-queue-table th {
            background: #f8fafc;
            padding: 0.9rem 0.85rem;
            text-align: left;
            font-family: 'Outfit', sans-serif;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-sub, #475569);
            border-bottom: 1.5px solid var(--admin-card-border, #e2e8f0);
            white-space: nowrap;
        }

        .vg-queue-table td {
            padding: 0.85rem 0.85rem !important;
            vertical-align: middle;
            border-bottom: 1px solid var(--admin-card-border, #f1f5f9);
            color: var(--text-main, #0f172a);
            background: #ffffff;
        }

        .vg-table-row {
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .vg-table-row:hover td {
            background: #f8fafc !important;
        }

        .vg-table-row.is-current td {
            background: #f5f3ff !important;
        }

        /* Column Specific Layouts */
        .vg-col-ref {
            min-width: 220px;
        }

        .vg-col-guest {
            min-width: 180px;
            max-width: 250px;
        }

        .vg-col-gcash {
            min-width: 140px;
            white-space: nowrap;
        }

        .vg-col-proof {
            width: 65px;
            text-align: center;
        }

        .vg-col-amount {
            min-width: 100px;
            white-space: nowrap;
        }

        .vg-col-status {
            min-width: 110px;
            white-space: nowrap;
        }

        /* Sticky Desk Actions column: perfectly matching the light card theme */
        .vg-col-actions {
            position: sticky;
            right: 0;
            background: #ffffff !important;
            box-shadow: -4px 0 10px rgba(0, 0, 0, 0.06);
            z-index: 5;
            min-width: 145px;
            text-align: center;
            white-space: nowrap;
            border-left: 1px solid var(--admin-card-border, #f1f5f9);
        }

        .vg-queue-table th.vg-col-actions {
            background: #f8fafc !important;
            z-index: 6;
        }

        .vg-table-row:hover td.vg-col-actions {
            background: #f8fafc !important;
        }

        .vg-table-row.is-current td.vg-col-actions {
            background: #f5f3ff !important;
        }

        .vg-inspect-pill-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.28rem 0.65rem;
            border-radius: 6px;
            font-size: 0.74rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(124, 58, 237, 0.15);
            white-space: nowrap;
        }
        .vg-inspect-pill-btn:hover {
            transform: translateY(-1px);
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
                            <span class="admin-header-page-heading" style="font-family: 'Outfit', sans-serif;">GCash Payment Verification</span>
                        </div>
                    </div>
                </div>

                <div class="admin-header-actions">
                    <?php $prof_link = ($user_role === 'staff') ? 'staff_profile.php' : 'settings.php?tab=profile'; ?>
                    <a href="<?= $prof_link ?>" class="hs-user-badge" title="My Profile">
                        <span class="hs-user-avatar"><?= strtoupper(substr($admin_full_name ?? 'Admin', 0, 1)) ?></span>
                        <span class="hs-user-name"><?= htmlspecialchars($admin_full_name ?? 'Admin') ?></span>
                    </a>
                    <a href="logout.php" class="hs-btn-logout" onclick="openLogoutModal(event)" title="Sign Out"><i class="fa-solid fa-sign-out-alt"></i></a>
                </div>
            </header>
            <main class="admin-container">
    <?php else: ?>
        <?php include 'nav.php'; ?>
        <div class="container" style="max-width: 900px;">
    <?php endif; ?>

        <div class="<?= $is_admin_layout ? 'admin-page-header' : 'page-header' ?>">
            <div>
                <h1 class="<?= $is_admin_layout ? 'admin-page-title' : '' ?>"><i class="fa-solid fa-receipt" style="color: var(--gold-glow, var(--bamboo-gold));"></i> GCash Payment Verification & Queue Desk</h1>
                <p class="<?= $is_admin_layout ? 'admin-page-subtitle' : '' ?>">Live monitoring of GCash reference numbers, receipts, and accounts from registered guests.</p>
            </div>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <a href="admin-dashboard.php" class="<?= $is_admin_layout ? 'admin-btn admin-btn-outline' : 'btn btn-outline' ?>"><i class="fa-solid fa-arrow-left"></i> Command Center</a>
                <a href="bookings.php" class="<?= $is_admin_layout ? 'admin-btn admin-btn-primary' : 'btn btn-primary' ?>"><i class="fa-solid fa-book-bookmark"></i> All Manifest</a>
            </div>
        </div>

        <?php if ($msg): ?>
            <div style="background: rgba(16,185,129,0.15); color: #34d399; padding: 1rem 1.25rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem; font-weight: 600; border: 1px solid rgba(16,185,129,0.3); display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <i class="fa-solid fa-circle-check"></i> <?= $msg ?>
                </div>
                <a href="verify_gcash.php?ref=<?= urlencode($ref) ?>" style="color: inherit; text-decoration: none;"><i class="fa-solid fa-xmark"></i></a>
            </div>
        <?php endif; ?>

        <?php if ($is_admin_layout): ?>
            <!-- Admin KPI Stat Cards for GCash Operations -->
            <div class="admin-grid" style="margin-bottom: 2rem;">
                <div class="admin-card admin-stat-card">
                    <span class="admin-stat-label">Total GCash Records</span>
                    <div class="admin-stat-value" style="color: var(--gold-glow);"><?= count($all_gcash_bookings) ?> Payments</div>
                    <span class="admin-badge admin-badge-gold"><i class="fa-solid fa-mobile-screen-button"></i> Mobile Channel</span>
                </div>

                <div class="admin-card admin-stat-card">
                    <span class="admin-stat-label">Pending Verification</span>
                    <div class="admin-stat-value" style="color: var(--rose);"><?= $stats['gcash_pending_count'] ?> Proofs</div>
                    <span class="admin-badge admin-badge-danger"><i class="fa-solid fa-clock"></i> Action Required</span>
                </div>

                <div class="admin-card admin-stat-card">
                    <span class="admin-stat-label">Gross Revenue Verified</span>
                    <div class="admin-stat-value" style="color: var(--emerald);">₱<?= number_format($stats['total_revenue']) ?></div>
                    <span class="admin-badge admin-badge-success"><i class="fa-solid fa-circle-check"></i> Confirmed Gross</span>
                </div>
            </div>

            <!-- Master GCash Verification Monitoring Table Queue -->
            <div class="admin-card" style="margin-bottom: 2.5rem; border: 1px solid var(--admin-card-border); padding: 1.5rem; background: var(--admin-card-bg, #ffffff);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 1rem; border-bottom: 1px solid var(--admin-card-border); padding-bottom: 1rem;">
                    <div>
                        <h3 style="font-family: 'Outfit', sans-serif; font-size: 1.2rem; color: var(--text-main, #0f172a); margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fa-solid fa-list-check" style="color: var(--primary-purple, #7c3aed);"></i> Registered Accounts GCash Submission Queue
                        </h3>
                        <p style="font-size: 0.84rem; color: var(--text-sub, #475569); margin-top: 0.25rem;">
                            Click <strong style="color: var(--primary-purple);"><i class="fa-solid fa-magnifying-glass"></i> Inspect</strong> next to any booking (or click anywhere on the row) to load their proof below.
                        </p>
                    </div>

                    <div style="display: inline-flex; background: #f1f5f9; padding: 4px; border-radius: 8px; border: 1px solid var(--admin-card-border);">
                        <button type="button" class="admin-btn admin-btn-sm" id="vgFilterAll" onclick="filterVgQueue('all', this)" style="background: var(--primary-purple); color: #fff; padding: 0.35rem 0.85rem; font-size: 0.78rem; font-weight: 600; border: none; border-radius: 6px;">All (<?= count($all_gcash_bookings) ?>)</button>
                        <button type="button" class="admin-btn admin-btn-sm" id="vgFilterPending" onclick="filterVgQueue('proof_submitted', this)" style="background: transparent; color: #b45309; padding: 0.35rem 0.85rem; font-size: 0.78rem; font-weight: 600; border: none; border-radius: 6px;"><i class="fa-solid fa-clock"></i> Pending (<?= $stats['gcash_pending_count'] ?>)</button>
                        <button type="button" class="admin-btn admin-btn-sm" id="vgFilterVerified" onclick="filterVgQueue('verified', this)" style="background: transparent; color: #059669; padding: 0.35rem 0.85rem; font-size: 0.78rem; font-weight: 600; border: none; border-radius: 6px;"><i class="fa-solid fa-circle-check"></i> Verified</button>
                    </div>
                </div>

                <div class="admin-table-container" style="border: none;">
                    <table class="admin-table vg-queue-table">
                        <thead>
                            <tr>
                                <th class="vg-col-ref">Booking Ref & Quick Inspect</th>
                                <th class="vg-col-guest">Registered Guest</th>
                                <th class="vg-col-gcash">GCash Ref #</th>
                                <th class="vg-col-proof">Proof</th>
                                <th class="vg-col-amount">Amount</th>
                                <th class="vg-col-status">Status</th>
                                <th class="vg-col-actions" style="text-align: center;">Desk Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($all_gcash_bookings)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-muted);">No GCash transactions recorded.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($all_gcash_bookings as $agb): ?>
                                    <?php
                                     $is_current = (($agb['id'] ?? '') === $ref);
                                     $agb_status = strtolower($agb['payment_status'] ?? 'proof_submitted');
                                     $agb_proof = !empty($agb['payment_proof']) ? $agb['payment_proof'] : 'uploads/receipts/sample_receipt.jpg';
                                     $agb_pid = $agb['profile_id'] ?? 'PBR-GST-00101';
                                     $agb_is_reg = !empty($agb['is_registered']);
                                    ?>
                                    <tr class="vg-table-row <?= $is_current ? 'is-current' : '' ?>" data-ref="<?= htmlspecialchars($agb['id']) ?>" data-status="<?= htmlspecialchars($agb_status) ?>" style="<?= $is_current ? 'background: rgba(139, 92, 246, 0.18); border-left: 4px solid var(--gold-glow);' : '' ?>">
                                        <td class="vg-col-ref">
                                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.4rem; margin-bottom: 0.25rem;">
                                                <a href="verify_gcash.php?ref=<?= urlencode($agb['id']) ?>#inspection-console" style="color: var(--primary-glow); font-family: 'Outfit', sans-serif; font-weight: 700; text-decoration: none; font-size: 1.05rem;" title="Inspect <?= htmlspecialchars($agb['id']) ?>">
                                                    <?= htmlspecialchars($agb['id']) ?>
                                                </a>
                                                <a href="verify_gcash.php?ref=<?= urlencode($agb['id']) ?>#inspection-console" class="vg-inspect-pill-btn <?= $is_current ? 'admin-btn-primary' : 'admin-btn-gold' ?>" title="Inspect this proof">
                                                    <i class="fa-solid fa-magnifying-glass"></i> <?= $is_current ? 'Inspecting' : 'Inspect' ?>
                                                </a>
                                            </div>
                                            <div style="font-size: 0.86rem; color: var(--text-main); font-weight: 600;"><?= htmlspecialchars($agb['item_name']) ?></div>
                                            <div style="font-size: 0.78rem; color: var(--text-sub); margin-top: 0.15rem;"><?= !empty($agb['check_in']) ? @date('M d, Y', strtotime($agb['check_in'])) : '—' ?></div>
                                            <?php if ((!empty($agb['resubmitted_at']) || !empty($agb['prev_rejection_reason'])) && $agb_status === 'proof_submitted'): ?>
                                                <div style="font-size: 0.72rem; color: #b45309; font-weight: 700; margin-top: 3px; display: inline-flex; align-items: center; gap: 4px;">
                                                    <i class="fa-solid fa-arrow-rotate-right"></i> Resubmitted Proof
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="vg-col-guest">
                                            <?php if ($agb_is_reg): ?>
                                                <span class="admin-badge admin-badge-purple" style="margin-bottom: 4px; font-size: 0.7rem;"><i class="fa-solid fa-id-badge"></i> <?= htmlspecialchars($agb_pid) ?></span>
                                                <span class="admin-badge admin-badge-success" style="font-size: 0.65rem; margin-bottom: 4px;"><i class="fa-solid fa-user-check"></i> Registered</span>
                                            <?php else: ?>
                                                <span class="admin-badge admin-badge-gold" style="margin-bottom: 4px; font-size: 0.7rem;"><i class="fa-solid fa-user"></i> Guest</span>
                                            <?php endif; ?>
                                            <div style="font-weight: 700; color: var(--text-main); font-size: 0.92rem; margin-top: 0.2rem;"><?= htmlspecialchars($agb['guest_name']) ?></div>
                                            <div style="font-size: 0.8rem; color: var(--text-sub); margin-top: 0.15rem; word-break: break-all;"><?= htmlspecialchars($agb['guest_email']) ?></div>
                                        </td>
                                        <td class="vg-col-gcash">
                                            <?php if (!empty($agb['gcash_ref'])): ?>
                                                <code style="font-family: monospace; font-size: 0.92rem; font-weight: 800; color: #d97706; background: rgba(245, 158, 11, 0.12); padding: 4px 8px; border-radius: 6px; border: 1px solid rgba(245, 158, 11, 0.3); display: inline-block; letter-spacing: 0.5px;">
                                                    <?= htmlspecialchars($agb['gcash_ref']) ?>
                                                </code>
                                            <?php else: ?>
                                                <span style="font-size: 0.78rem; color: var(--text-muted);">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="vg-col-proof">
                                            <img src="<?= htmlspecialchars($agb_proof) ?>" alt="Proof" style="width: 44px; height: 44px; object-fit: cover; border-radius: 6px; border: 1.5px solid var(--admin-card-border); background: #ffffff; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.08)'" onmouseout="this.style.transform='scale(1)'" onclick="event.stopPropagation(); openPictureModal('<?= htmlspecialchars($agb_proof) ?>', 'GCash Receipt - Ref #<?= htmlspecialchars($agb['id']) ?>')">
                                        </td>
                                        <td class="vg-col-amount">
                                            <strong style="color: var(--gold-glow); font-family: 'Outfit', sans-serif; font-size: 1.05rem;">₱<?= number_format(floatval($agb['total_amount'])) ?></strong>
                                        </td>
                                        <td class="vg-col-status">
                                            <?php if ($agb_status === 'verified'): ?>
                                                <span class="admin-badge admin-badge-success"><i class="fa-solid fa-circle-check"></i> Verified</span>
                                            <?php elseif ($agb_status === 'rejected'): ?>
                                                <span class="admin-badge admin-badge-danger"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>
                                            <?php elseif (!empty($agb['resubmitted_at']) || !empty($agb['prev_rejection_reason'])): ?>
                                                <span class="admin-badge" style="background: #fef3c7; color: #b45309; border: 1px solid #fde68a;"><i class="fa-solid fa-arrow-rotate-right"></i> Resubmitted</span>
                                            <?php else: ?>
                                                <span class="admin-badge admin-badge-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="vg-col-actions" style="text-align: center;">
                                            <div style="display: flex; gap: 0.35rem; justify-content: center; align-items: center;">
                                                <a href="verify_gcash.php?ref=<?= urlencode($agb['id']) ?>#inspection-console" class="admin-btn <?= $is_current ? 'admin-btn-primary' : 'admin-btn-outline' ?> admin-btn-sm" style="padding: 0.32rem 0.65rem; font-size: 0.74rem;" title="Inspect in Console">
                                                    <i class="fa-solid fa-magnifying-glass"></i> <?= $is_current ? 'Inspecting' : 'Inspect' ?>
                                                </a>
                                                <?php if ($agb_status !== 'verified'): ?>
                                                    <!-- Quick Approve with CSRF -->
                                                    <form method="POST" action="verify_gcash.php" style="display:inline; margin: 0;" onsubmit="event.stopPropagation(); return confirm('Approve payment for <?= htmlspecialchars(addslashes($agb['id'])) ?>?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="quick_action" value="approve">
                                                        <input type="hidden" name="quick_ref" value="<?= htmlspecialchars($agb['id']) ?>">
                                                        <button type="submit" class="admin-btn admin-btn-gold admin-btn-sm" style="padding: 0.32rem 0.55rem; font-size: 0.74rem;" title="Quick Approve">
                                                            <i class="fa-solid fa-check"></i>
                                                        </button>
                                                    </form>
                                                    <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" style="padding: 0.32rem 0.55rem; font-size: 0.74rem; color: #f43f5e;" onclick="event.stopPropagation(); openRejectModal('<?= htmlspecialchars(addslashes($agb['id'])) ?>', '<?= htmlspecialchars(addslashes($agb['guest_name'])) ?>', '<?= number_format(floatval($agb['total_amount'])) ?>')" title="Quick Reject">
                                                        <i class="fa-solid fa-xmark"></i>
                                                    </button>
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
        <?php endif; ?>

        <!-- Detailed Single Verification Console Anchor -->
        <div id="inspection-console"></div>

        <?php if (!empty($booking_item)): ?>
        <!-- Booking Schedule Highlights (for guest or active inspection) -->
        <?php
        $booked_at_fmt = !empty($booking_item['created_at']) 
            ? date('l, M d, Y \a\t g:i A', strtotime($booking_item['created_at'])) 
            : date('l, M d, Y \a\t g:i A');
        $checkin_fmt = !empty($booking_item['check_in']) 
            ? date('M d, Y', strtotime($booking_item['check_in'])) 
            : date('M d, Y');
        $checkout_fmt = !empty($booking_item['check_out']) 
            ? date('M d, Y', strtotime($booking_item['check_out'])) 
            : date('M d, Y', strtotime('+1 day'));
        ?>
        <div class="<?= $is_admin_layout ? 'admin-card' : 'card' ?>" style="margin-bottom: 1.5rem; background: #ffffff; border: 1px solid var(--admin-card-border); box-shadow: 0 1px 3px rgba(0,0,0,0.05); padding: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem; border-bottom: 1px solid var(--admin-card-border); padding-bottom: 0.85rem;">
                <div>
                    <?php if (!empty($booking_item['is_registered'])): ?>
                        <span class="admin-badge admin-badge-purple" style="margin-bottom: 0.4rem; display: inline-block;">
                            <i class="fa-solid fa-id-badge"></i> Profile ID: <?= htmlspecialchars($booking_item['profile_id'] ?? 'PBR-GST-00101') ?>
                        </span>
                    <?php endif; ?>
                    <span class="admin-badge admin-badge-gold" style="margin-bottom: 0.4rem; display: inline-block;">
                        <i class="fa-solid fa-envelope-circle-check"></i> Stay Voucher Ref #<?= htmlspecialchars($booking_item['id']) ?>
                    </span>
                    <h2 style="font-size: 1.3rem; margin: 0; color: var(--primary-purple); font-family: 'Outfit', sans-serif;">
                        Selected Stay Review: <?= htmlspecialchars($booking_item['guest_name']) ?> (<?= htmlspecialchars($booking_item['item_name']) ?>)
                    </h2>
                    <p style="color: var(--text-sub); font-size: 0.88rem; margin: 3px 0 0 0;">
                        Guest Email: <strong><?= htmlspecialchars($booking_item['guest_email'] ?? 'guest@example.com') ?></strong> | Phone: <strong><?= htmlspecialchars($booking_item['guest_phone'] ?? '0917-000-0000') ?></strong>
                    </p>
                </div>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <a href="view_email.php?ref=<?= urlencode($booking_item['id']) ?>" target="_blank" class="admin-btn admin-btn-outline admin-btn-sm" style="text-decoration: none;">
                        <i class="fa-solid fa-envelope-open-text"></i>
                        <span>View / Print Voucher</span>
                        <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.75rem;"></i>
                    </a>
                </div>
            </div>

            <!-- Schedule Highlights Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem;">
                <div style="background: #f8fafc; padding: 0.85rem 1rem; border-radius: 10px; border: 1px solid var(--admin-card-border);">
                    <span style="font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: var(--primary-purple); display: block; margin-bottom: 3px;">
                        <i class="fa-solid fa-calendar-check"></i> Date & Time Booked
                    </span>
                    <strong style="font-size: 0.95rem; color: var(--text-main);"><?= $booked_at_fmt ?></strong>
                </div>

                <div style="background: #f8fafc; padding: 0.85rem 1rem; border-radius: 10px; border: 1px solid var(--admin-card-border);">
                    <span style="font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: #b45309; display: block; margin-bottom: 3px;">
                        <i class="fa-solid fa-door-open"></i> Check-In Schedule
                    </span>
                    <strong style="font-size: 0.95rem; color: var(--text-main);"><?= $checkin_fmt ?></strong>
                    <?php
                    $vg_cin_t = (!empty($booking_item['check_in']) && strpos($booking_item['check_in'], ':') !== false && date('H:i', strtotime($booking_item['check_in'])) !== '00:00') ? ('Time: ' . date('g:i A', strtotime($booking_item['check_in']))) : 'Time: 2:00 PM (Standard)';
                    $vg_cout_t = (!empty($booking_item['check_out']) && strpos($booking_item['check_out'], ':') !== false && date('H:i', strtotime($booking_item['check_out'])) !== '00:00') ? ('Time: ' . date('g:i A', strtotime($booking_item['check_out']))) : 'Time: 12:00 PM Noon (Standard)';
                    ?>
                    <span style="display: block; font-size: 0.82rem; font-weight: 700; color: #d97706;"><?= $vg_cin_t ?></span>
                </div>

                <div style="background: #f8fafc; padding: 0.85rem 1rem; border-radius: 10px; border: 1px solid var(--admin-card-border);">
                    <span style="font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: #047857; display: block; margin-bottom: 3px;">
                        <i class="fa-solid fa-person-walking-dashed-line-arrow-right"></i> Check-Out Schedule
                    </span>
                    <strong style="font-size: 0.95rem; color: var(--text-main);"><?= $checkout_fmt ?></strong>
                    <span style="display: block; font-size: 0.82rem; font-weight: 700; color: #059669;"><?= $vg_cout_t ?></span>
                </div>

                <div style="background: #f8fafc; padding: 0.85rem 1rem; border-radius: 10px; border: 1px solid var(--admin-card-border);">
                    <span style="font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: var(--text-sub); display: block; margin-bottom: 3px;">
                        <i class="fa-solid fa-bed"></i> Accommodation
                    </span>
                    <strong style="font-size: 0.95rem; color: var(--text-main);"><?= htmlspecialchars($booking_item['item_name']) ?></strong>
                    <span style="display: block; font-size: 0.82rem; color: var(--text-sub);"><?= $booking_item['pax'] ?? 2 ?> Guests</span>
                </div>
            </div>
        </div>

        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1.5rem;">
            <!-- Official Resort GCash Details -->
            <div class="<?= $is_admin_layout ? 'admin-card' : 'card' ?>" style="background: linear-gradient(135deg, #005ce6, #003db3); color: #fff; padding: 1.5rem;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                    <h2 style="font-family: 'Outfit', sans-serif; color: #fff;"><i class="fa-solid fa-mobile-screen-button"></i> Official GCash QR</h2>
                    <span style="background: rgba(255,255,255,0.2); padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.8rem; font-weight: 700;">Verified Merchant</span>
                </div>

                <div style="text-align: center; background: #fff; padding: 1.5rem; border-radius: var(--radius-sm); color: #1e293b; margin-bottom: 1rem;">
                    <img src="assets/gcash_qr.jpg" alt="GCash QR Code" style="width: 180px; height: auto; cursor: pointer; border-radius: 6px; object-fit: contain; background: #fff;" onclick="openPictureModal('assets/gcash_qr.jpg', 'Official Purple Bamboo Resort GCash QR')">
                    <p style="font-weight: 700; margin-top: 0.5rem; font-size: 1.1rem; color: #005ce6;">PURPLE BAMBOO RESORT</p>
                    <p style="font-size: 1.2rem; font-weight: 800; letter-spacing: 1px; color: #1e293b;">0956 697 8679</p>
                    <div style="font-size: 0.78rem; color: #005ce6; font-weight: 700; cursor: pointer;" onclick="openPictureModal('assets/gcash_qr.jpg', 'Official Purple Bamboo Resort GCash QR')"><i class="fa-solid fa-expand"></i> Click to Enlarge QR</div>
                </div>

                <div style="font-size: 0.85rem; opacity: 0.95;">
                    <p><i class="fa-solid fa-circle-info"></i> Please send exact total amount and keep your GCash reference number & screenshot for verification.</p>
                </div>
            </div>

            <!-- Verification Action Form / Upload Form -->
            <div class="<?= $is_admin_layout ? 'admin-card' : 'card' ?>" style="background: #ffffff; border: 1px solid var(--admin-card-border); box-shadow: 0 1px 3px rgba(0,0,0,0.05); padding: 1.5rem;">
                <?php if ($user_role === 'admin' || $user_role === 'staff'): ?>
                    <h3 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-bottom: 1rem;">
                        <i class="fa-solid fa-user-check" style="color: var(--primary-purple);"></i> Staff Verification Desk (Ref #<?= htmlspecialchars($booking_item['id']) ?>)
                    </h3>
                    
                    <?php if (!empty($booking_item['resubmitted_at']) || !empty($booking_item['prev_rejection_reason'])): ?>
                        <div style="background: #fffbeb; border: 1.5px solid #fde68a; border-radius: var(--radius-sm); padding: 0.85rem 1rem; margin-bottom: 1rem; color: #92400e; font-size: 0.85rem;">
                            <div style="font-weight: 800; color: #b45309; display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                <span style="display: flex; align-items: center; gap: 6px;">
                                    <i class="fa-solid fa-arrow-rotate-right"></i> Updated Payment Proof Resubmitted
                                </span>
                                <span style="font-size: 0.7rem; background: #fef3c7; color: #b45309; padding: 2px 7px; border-radius: 4px; border: 1px solid #fde68a; font-weight: 800;">RESUBMISSION</span>
                            </div>
                            <div style="color: #78350f; font-size: 0.83rem; line-height: 1.4;">
                                Guest has uploaded a new payment receipt and reference number after previous rejection.
                            </div>
                            <?php if (!empty($booking_item['prev_rejection_reason'])): ?>
                                <div style="margin-top: 6px; padding-top: 6px; border-top: 1px dashed #fde68a; font-size: 0.82rem; color: #991b1b;">
                                    <strong>Previous Rejection Reason:</strong> <em><?= htmlspecialchars($booking_item['prev_rejection_reason']) ?></em>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($booking_item['resubmitted_at'])): ?>
                                <div style="margin-top: 4px; font-size: 0.74rem; color: #b45309;">
                                    <i class="fa-solid fa-clock"></i> Resubmitted on: <?= date('M d, Y · g:i A', strtotime($booking_item['resubmitted_at'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php elseif (($booking_item['payment_status'] ?? '') === 'rejected' && !empty($booking_item['rejection_reason'])): ?>
                        <div style="background: #fef2f2; border: 1.5px solid #fecaca; border-radius: var(--radius-sm); padding: 0.85rem 1rem; margin-bottom: 1rem; color: #991b1b; font-size: 0.85rem;">
                            <strong style="display: flex; align-items: center; gap: 6px; margin-bottom: 3px;">
                                <i class="fa-solid fa-circle-xmark"></i> Currently Marked as Rejected
                            </strong>
                            <div>Reason: <strong><?= htmlspecialchars($booking_item['rejection_reason']) ?></strong></div>
                            <small style="color: #7f1d1d; display: block; margin-top: 3px;">Awaiting guest to upload new receipt proof.</small>
                        </div>
                    <?php endif; ?>

                    <div style="background: var(--purple-soft, #f5f3ff); padding: 1rem; border-radius: var(--radius-sm); margin-bottom: 1rem; border: 1px solid var(--purple-border, #ddd6fe); color: var(--text-main);">
                        <?php if (!empty($booking_item['is_registered'])): ?>
                            <p style="margin-bottom: 0.35rem;"><span class="admin-badge admin-badge-purple"><i class="fa-solid fa-id-badge"></i> <?= htmlspecialchars($booking_item['profile_id']) ?> (Registered Guest)</span></p>
                        <?php endif; ?>
                        <p style="margin-bottom: 0.25rem;"><strong>Booking Ref:</strong> <span style="font-weight: 700; color: var(--primary-purple);"><?= htmlspecialchars($booking_item['id']) ?></span></p>
                        <p style="margin-bottom: 0.25rem;"><strong>Item / Accommodation:</strong> <?= htmlspecialchars($booking_item['item_name']) ?></p>
                        <p style="margin-bottom: 0.25rem;">
                            <strong>Submitted GCash Ref #:</strong> 
                            <code style="color: #92400e; font-weight: 800; font-size: 1.05rem; background: #fef3c7; border: 1px solid #fde68a; padding: 2px 8px; border-radius: 4px;"><?= htmlspecialchars($booking_item['gcash_ref'] ?? 'None') ?></code>
                            <?php if (!empty($booking_item['resubmitted_at']) || !empty($booking_item['prev_rejection_reason'])): ?>
                                <span style="font-size: 0.72rem; color: #16a34a; font-weight: 700; margin-left: 6px;"><i class="fa-solid fa-check-circle"></i> Newly Resubmitted</span>
                            <?php endif; ?>
                        </p>
                        <p style="margin-bottom: 0.25rem;"><strong>Sender Account:</strong> <?= htmlspecialchars($booking_item['guest_name']) ?> (<?= htmlspecialchars($booking_item['guest_email'] ?? '') ?> - <?= htmlspecialchars($booking_item['guest_phone']) ?>)</p>
                        <p style="margin-bottom: 0.25rem;"><strong>Amount Payable:</strong> <span style="color: #b45309; font-weight: 700; font-size: 1.15rem;">₱<?= number_format(floatval($booking_item['total_amount'])) ?></span></p>
                        <p style="margin-bottom: 0;"><strong>Payment Status:</strong> 
                            <?php if (($booking_item['payment_status'] ?? '') === 'verified'): ?>
                                <span class="admin-badge admin-badge-success"><i class="fa-solid fa-circle-check"></i> Verified</span>
                            <?php elseif (($booking_item['payment_status'] ?? '') === 'rejected'): ?>
                                <span class="admin-badge admin-badge-danger"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>
                            <?php elseif (!empty($booking_item['resubmitted_at']) || !empty($booking_item['prev_rejection_reason'])): ?>
                                <span class="admin-badge" style="background: #fef3c7; color: #b45309; border: 1px solid #fde68a;"><i class="fa-solid fa-arrow-rotate-right"></i> Resubmitted (Pending Verification)</span>
                            <?php else: ?>
                                <span class="admin-badge admin-badge-pending"><i class="fa-solid fa-clock"></i> Proof Submitted</span>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div style="margin-bottom: 1rem; text-align: center; border: 1.5px dashed var(--purple-border, #ddd6fe); padding: 0.85rem; border-radius: var(--radius-sm); background: #faf5ff;">
                        <p style="font-size: 0.82rem; color: var(--text-sub); margin-bottom: 0.5rem; font-weight: 600;"><i class="fa-solid fa-file-invoice-dollar" style="color: var(--primary-purple);"></i> <?= (!empty($booking_item['resubmitted_at']) || !empty($booking_item['prev_rejection_reason'])) ? 'New Resubmitted GCash Receipt Proof:' : 'Uploaded Payment Proof Screenshot:' ?></p>
                        <img src="<?= htmlspecialchars($proof_src) ?>" alt="Receipt Proof" style="max-width: 100%; max-height: 240px; object-fit: contain; border-radius: 6px; cursor: pointer; border: 1px solid var(--admin-card-border); background: #fff;" onclick="openPictureModal('<?= htmlspecialchars($proof_src) ?>', 'Submitted GCash Receipt Proof - Ref #<?= htmlspecialchars($booking_item['id']) ?>')">
                        <div style="margin-top: 0.4rem; font-size: 0.78rem; color: var(--primary-purple); font-weight: 700; cursor: pointer;" onclick="openPictureModal('<?= htmlspecialchars($proof_src) ?>', 'Submitted GCash Receipt Proof - Ref #<?= htmlspecialchars($booking_item['id']) ?>')">
                            <i class="fa-solid fa-magnifying-glass-plus"></i> Click to Enlarge Receipt Image
                        </div>
                    </div>

                    <form action="verify_gcash.php?ref=<?= urlencode($booking_item['id']) ?>" method="POST" style="display: flex; gap: 0.5rem;">
                        <input type="hidden" name="ref_no" value="<?= htmlspecialchars($booking_item['id']) ?>">
                        <button type="submit" name="verify_action" value="approved" class="<?= $is_admin_layout ? 'admin-btn admin-btn-gold' : 'btn btn-success' ?>" style="flex: 1;">
                            <i class="fa-solid fa-check"></i> Approve Payment
                        </button>
                        <button type="button" class="<?= $is_admin_layout ? 'admin-btn admin-btn-outline' : 'btn btn-danger' ?>" style="flex: 1; color: #f43f5e;" onclick="openRejectModal('<?= htmlspecialchars(addslashes($booking_item['id'])) ?>', '<?= htmlspecialchars(addslashes($booking_item['guest_name'])) ?>', '<?= number_format(floatval($booking_item['total_amount'])) ?>')">
                            <i class="fa-solid fa-xmark"></i> Reject Receipt
                        </button>
                    </form>

                <?php else: ?>
                    <h3 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-bottom: 1rem;">
                        <i class="fa-solid fa-cloud-arrow-up"></i> Upload Payment Proof
                    </h3>

                    <form action="verify_gcash.php?ref=<?= urlencode($ref) ?>" method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label>Booking Reference Number</label>
                            <input type="text" name="ref_no" class="form-control" value="<?= htmlspecialchars($ref) ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label>GCash Transaction Reference No.</label>
                            <input type="text" name="gcash_ref" class="form-control" placeholder="13-digit GCash Ref (e.g. 1002938475612)" required maxlength="13" minlength="13" inputmode="numeric" pattern="[0-9]{13}" title="Please enter a 13-digit GCash reference number" autocomplete="off" onkeypress="return (event.key >= '0' && event.key <= '9');" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 13);">
                        </div>

                        <div class="form-group">
                            <label>Upload Screenshot / Receipt Photo</label>
                            <input type="file" id="proof_image_input" name="proof_image" class="form-control" accept="image/*" required onchange="handleFilePreview(this, 'proof_image_preview', 'proof_image_name', 'proof_image_thumb')">
                            
                            <div id="proof_image_preview" class="image-preview-card" style="display: none; margin-top: 0.5rem;">
                                <img id="proof_image_thumb" src="" class="image-preview-thumb" alt="Payment Receipt Preview" onclick="openPictureModal(this.src, 'GCash Payment Receipt Proof')">
                                <div class="image-preview-details">
                                    <div id="proof_image_name" class="image-preview-name">receipt_proof.jpg</div>
                                    <div class="image-preview-actions">
                                        <button type="button" class="btn btn-outline btn-sm" style="padding: 0.2rem 0.5rem; font-size: 0.78rem;" onclick="openPictureModal(document.getElementById('proof_image_thumb').src, 'GCash Payment Receipt Proof')">
                                            <i class="fa-solid fa-eye"></i> View Full Picture
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" name="submit_proof" class="btn btn-gold" style="width: 100%;">
                            <i class="fa-solid fa-paper-plane"></i> Submit Receipt For Verification
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
            <div class="<?= $is_admin_layout ? 'admin-card' : 'card' ?>" style="text-align: center; padding: 3.5rem 1.5rem; background: #ffffff; border: 1px solid var(--admin-card-border); border-radius: var(--radius-md); margin-bottom: 2rem;">
                <div style="width: 64px; height: 64px; margin: 0 auto 1rem; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 1.8rem;">
                    <i class="fa-solid fa-receipt"></i>
                </div>
                <h3 style="font-size: 1.2rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.35rem; font-family: 'Outfit', sans-serif;">No GCash Transactions Selected</h3>
                <p style="color: var(--text-sub); font-size: 0.9rem; margin: 0 auto; max-width: 480px;">There are currently no bookings with submitted GCash receipts or the selected booking does not exist in the database.</p>
            </div>
        <?php endif; ?>

    <?php if ($is_admin_layout): ?>
            </main>
        </div>
    <?php else: ?>
        </div>
    <?php endif; ?>

    <!-- Picture Lightbox Modal -->
    <div id="picture_modal" class="picture-modal-overlay" onclick="closePictureModal(event)">
        <div class="picture-modal-container" onclick="event.stopPropagation()">
            <button type="button" class="picture-modal-close" onclick="closePictureModal()">&times;</button>
            <h3 id="picture_modal_title" style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-bottom: 0.5rem;">Uploaded Picture View</h3>
            <img id="picture_modal_img" src="" class="picture-modal-img" alt="Enlarged View">
        </div>
    </div>

    <!-- Reject Payment Modal -->
    <div id="rejectModal" class="picture-modal-overlay" onclick="if(event.target === this) closeRejectModal();">
        <div class="picture-modal-container" style="max-width: 520px; text-align: left; background: #ffffff; border: 1px solid var(--admin-card-border); border-radius: var(--radius-md, 14px); color: var(--text-main, #0f172a); overflow: hidden; padding: 0; box-shadow: 0 20px 40px rgba(0,0,0,0.15);">
            <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--rose-border, #fecdd3); display: flex; justify-content: space-between; align-items: center; background: var(--rose-soft, #fff1f2);">
                <h3 style="margin: 0; font-family: 'Outfit', sans-serif; color: var(--rose, #e11d48); display: flex; align-items: center; gap: 8px; font-size: 1.15rem;">
                    <i class="fa-solid fa-triangle-exclamation"></i> Reject Payment Proof
                </h3>
                <button type="button" class="picture-modal-close" style="position: static; color: var(--text-sub); background: transparent; border: none; font-size: 1.5rem; cursor: pointer;" onclick="closeRejectModal()">&times;</button>
            </div>
            <form action="verify_gcash.php" method="POST" id="rejectForm">
                <?= csrf_field() ?>
                <input type="hidden" name="quick_action" value="reject">
                <input type="hidden" name="quick_ref" id="rejectModalRefNo" value="">
                
                <div style="padding: 1.5rem;">
                    <div style="background: #f8fafc; border: 1px dashed var(--admin-card-border); padding: 1rem; border-radius: 8px; margin-bottom: 1.25rem;">
                        <p style="margin: 0 0 0.4rem 0; color: var(--text-sub); font-size: 0.88rem;"><strong>Ref #:</strong> <span id="rejectModalRefDisplay" style="color: #b45309; font-family: monospace; font-weight: 700;"></span></p>
                        <p style="margin: 0 0 0.4rem 0; color: var(--text-sub); font-size: 0.88rem;"><strong>Guest Name:</strong> <span id="rejectModalGuest" style="color: var(--text-main); font-weight: 700;"></span></p>
                        <p style="margin: 0; color: var(--text-sub); font-size: 0.88rem;"><strong>Amount:</strong> <span id="rejectModalAmount" style="color: #047857; font-weight: 700;"></span></p>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="font-size: 0.85rem; color: var(--text-main); font-weight: 700; margin-bottom: 0.4rem; display: block;">Reason for Rejection: *</label>
                        <select name="reason" id="rejectReasonSelect" class="form-control" style="background: #ffffff; color: var(--text-main); border: 1px solid var(--admin-card-border); width: 100%; border-radius: 8px; padding: 0.6rem;" onchange="toggleRejectOtherReason(this)" required>
                            <option value="">-- Select a reason --</option>
                            <option value="Proof is blurry or unreadable">Proof is blurry or unreadable</option>
                            <option value="Incorrect amount paid">Incorrect amount paid</option>
                            <option value="Invalid reference number">Invalid reference number</option>
                            <option value="Fake or tampered receipt">Fake or tampered receipt</option>
                            <option value="Others">Others (Please specify)</option>
                        </select>
                    </div>

                    <div id="rejectOtherReasonGroup" style="display: none; margin-top: 0.85rem;">
                        <label style="font-size: 0.85rem; color: #b45309; font-weight: 700; margin-bottom: 0.4rem; display: block;">
                            <i class="fa-solid fa-pen"></i> Specify Custom Reason: *
                        </label>
                        <input type="text" name="other_reason" id="rejectOtherReasonInput" class="form-control" style="background: #ffffff; color: var(--text-main); border: 1.5px solid #f59e0b; width: 100%; padding: 0.6rem 0.8rem; border-radius: 8px;" placeholder="Type custom rejection reason...">
                    </div>
                </div>
                
                <div style="padding: 1rem 1.5rem; background: #f8fafc; border-top: 1px solid var(--admin-card-border); display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button type="button" onclick="closeRejectModal()" class="admin-btn admin-btn-outline admin-btn-sm">Cancel</button>
                    <button type="submit" class="admin-btn admin-btn-danger admin-btn-sm" style="background: var(--rose, #e11d48); border-color: var(--rose, #e11d48); color: #ffffff;">
                        <i class="fa-solid fa-xmark"></i> Confirm Rejection
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$is_admin_layout): ?>
        <?php include 'footer.php'; ?>
    <?php endif; ?>

    <script>
    function filterVgQueue(status, btn) {
        const rows = document.querySelectorAll('.vg-table-row');
        rows.forEach(r => {
            const rowStatus = r.getAttribute('data-status');
            if (status === 'all' || rowStatus === status) {
                r.style.display = '';
            } else {
                r.style.display = 'none';
            }
        });

        const btnAll = document.getElementById('vgFilterAll');
        const btnPending = document.getElementById('vgFilterPending');
        const btnVerified = document.getElementById('vgFilterVerified');
        if (btnAll) { btnAll.style.background = 'transparent'; btnAll.style.color = 'var(--text-sub, #475569)'; }
        if (btnPending) { btnPending.style.background = 'transparent'; btnPending.style.color = '#b45309'; }
        if (btnVerified) { btnVerified.style.background = 'transparent'; btnVerified.style.color = '#059669'; }

        if (btn) {
            if (status === 'proof_submitted') {
                btn.style.background = 'var(--gold-soft, #fef3c7)';
                btn.style.color = '#b45309';
            } else if (status === 'verified') {
                btn.style.background = 'var(--emerald-soft, #ecfdf5)';
                btn.style.color = '#059669';
            } else {
                btn.style.background = 'var(--primary-purple, #7c3aed)';
                btn.style.color = '#ffffff';
            }
        }
    }

    function handleFilePreview(input, cardId, nameId, thumbId) {
        const file = input.files[0];
        const card = document.getElementById(cardId);
        const name = document.getElementById(nameId);
        const thumb = document.getElementById(thumbId);
        if (!card || !name || !thumb) return;

        if (file && file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                thumb.src = e.target.result;
                name.textContent = file.name;
                card.style.display = 'flex';
            };
            reader.readAsDataURL(file);
        } else if (file) {
            name.textContent = file.name;
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    }

    function openPictureModal(imgSrc, title) {
        if (!imgSrc) return;
        document.getElementById('picture_modal_img').src = imgSrc;
        document.getElementById('picture_modal_title').textContent = title || 'Picture Preview';
        document.getElementById('picture_modal').classList.add('active');
    }

    function closePictureModal(e) {
        document.getElementById('picture_modal').classList.remove('active');
    }

    function openRejectModal(ref, guest, amount) {
        document.getElementById('rejectModalRefNo').value = ref;
        document.getElementById('rejectModalRefDisplay').textContent = ref;
        document.getElementById('rejectModalGuest').textContent = guest;
        document.getElementById('rejectModalAmount').textContent = '₱' + amount;
        
        document.getElementById('rejectReasonSelect').value = '';
        document.getElementById('rejectOtherReasonGroup').style.display = 'none';
        document.getElementById('rejectOtherReasonInput').required = false;

        document.getElementById('rejectModal').classList.add('active');
    }

    function closeRejectModal() {
        document.getElementById('rejectModal').classList.remove('active');
    }

    function toggleRejectOtherReason(select) {
        const group = document.getElementById('rejectOtherReasonGroup');
        const input = document.getElementById('rejectOtherReasonInput');
        if (select.value === 'Others') {
            group.style.display = 'block';
            input.required = true;
        } else {
            group.style.display = 'none';
            input.required = false;
        }
    }

    // Allow clicking anywhere on a table row to inspect that booking
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.vg-table-row').forEach(row => {
            row.addEventListener('click', function(e) {
                // Ignore clicks on buttons, links, inputs, selects, or modal triggers
                if (e.target.closest('button, a, input, select, textarea, form, img')) {
                    return;
                }
                const ref = this.getAttribute('data-ref');
                if (ref) {
                    window.location.href = 'verify_gcash.php?ref=' + encodeURIComponent(ref) + '#inspection-console';
                }
            });
        });
    });
    </script>

</body>
</html>


