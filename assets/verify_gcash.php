<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/booking_store.php';

// Strict RBAC: verify_gcash.php is the Staff & Admin Verification Desk. Guests are redirected to payment_method.php.
$user_role = strtolower($_SESSION['role'] ?? '');
if ($user_role !== 'admin' && $user_role !== 'staff') {
    $ref_param = !empty($_GET['ref']) ? '?ref=' . urlencode($_GET['ref']) : '';
    header("Location: payment_method.php" . $ref_param);
    exit();
}
$is_admin_layout = true;

// Handle GET Actions (Approve/Reject)
$msg = '';
if (isset($_GET['action']) && isset($_GET['ref']) && $is_admin_layout) {
    $g_action = $_GET['action'];
    $g_ref = trim($_GET['ref']);
    if ($g_action === 'approve') {
        BookingStore::updateBookingStatus($g_ref, 'approved', 'verified');
        $msg = 'GCash payment for Ref #' . htmlspecialchars($g_ref) . ' has been APPROVED and booking marked as verified.';
    } elseif ($g_action === 'reject') {
        BookingStore::updateBookingStatus($g_ref, 'pending', 'rejected');
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
            BookingStore::updateBookingStatus($ref_no, 'pending', 'rejected');
            $msg = 'Payment proof for Ref #' . htmlspecialchars($ref_no) . ' was REJECTED and marked as unverified/rejected.';
        }
    }
}

// Retrieve all GCash submissions for the verification queue
$all_bookings = BookingStore::getAllBookings();
$all_gcash_bookings = array_filter($all_bookings, function($b) {
    $pstatus = strtolower($b['payment_status'] ?? '');
    return !empty($b['gcash_ref']) || !empty($b['payment_proof']) || in_array($pstatus, ['proof_submitted', 'verified', 'rejected']);
});

// Sort to prioritize pending submissions first, then newest submissions
usort($all_gcash_bookings, function($a, $b) {
    $a_p = (strtolower($a['payment_status'] ?? '') === 'proof_submitted') ? 0 : 1;
    $b_p = (strtolower($b['payment_status'] ?? '') === 'proof_submitted') ? 0 : 1;
    if ($a_p !== $b_p) {
        return $a_p <=> $b_p;
    }
    return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
});

// Determine active selected booking
$ref = $_GET['ref'] ?? '';
if (empty($ref)) {
    if ($is_admin_layout && !empty($all_gcash_bookings)) {
        $first_pending = null;
        foreach ($all_gcash_bookings as $b) {
            if (strtolower($b['payment_status'] ?? '') === 'proof_submitted') {
                $first_pending = $b['id'];
                break;
            }
        }
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
                    <input type="text" placeholder="Search Ref #, Guest, GCash No..." value="<?= htmlspecialchars($ref) ?>" onkeyup="if(event.key==='Enter') window.location='verify_gcash.php?ref='+encodeURIComponent(this.value)">
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
            <div class="admin-card" style="margin-bottom: 2.5rem; border: 1.5px solid var(--admin-card-border);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 1rem; border-bottom: 1px solid var(--admin-card-border); padding-bottom: 1rem;">
                    <div>
                        <h3 style="font-family: 'Outfit', sans-serif; font-size: 1.2rem; color: #ffffff; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fa-solid fa-list-check" style="color: var(--primary-glow);"></i> Registered Accounts GCash Submission Queue
                        </h3>
                        <p style="font-size: 0.82rem; color: var(--text-sub); margin-top: 0.2rem;">Click "Inspect" on any guest record to load and review their proof in the verification desk below.</p>
                    </div>

                    <div style="display: inline-flex; background: #0f172a; padding: 3px; border-radius: 8px; border: 1px solid var(--admin-card-border);">
                        <button type="button" class="admin-btn admin-btn-sm" id="vgFilterAll" onclick="filterVgQueue('all', this)" style="background: var(--purple-accent); color: #fff; padding: 0.35rem 0.8rem; font-size: 0.78rem;">All (<?= count($all_gcash_bookings) ?>)</button>
                        <button type="button" class="admin-btn admin-btn-sm" id="vgFilterPending" onclick="filterVgQueue('proof_submitted', this)" style="background: transparent; color: #fbbf24; padding: 0.35rem 0.8rem; font-size: 0.78rem;"><i class="fa-solid fa-clock"></i> Pending (<?= $stats['gcash_pending_count'] ?>)</button>
                        <button type="button" class="admin-btn admin-btn-sm" id="vgFilterVerified" onclick="filterVgQueue('verified', this)" style="background: transparent; color: #34d399; padding: 0.35rem 0.8rem; font-size: 0.78rem;"><i class="fa-solid fa-circle-check"></i> Verified</button>
                    </div>
                </div>

                <div class="admin-table-container" style="border: none;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Booking Ref</th>
                                <th>Registered Guest Account</th>
                                <th>GCash Ref #</th>
                                <th>Proof Thumbnail</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th style="text-align: center;">Desk Actions</th>
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
                                    <tr class="vg-table-row" data-status="<?= htmlspecialchars($agb_status) ?>" style="<?= $is_current ? 'background: rgba(139, 92, 246, 0.15); border-left: 3px solid var(--gold-glow);' : '' ?>">
                                        <td>
                                            <strong style="color: var(--primary-glow); font-family: 'Outfit', sans-serif;"><?= htmlspecialchars($agb['id']) ?></strong>
                                            <?php if ($is_current): ?>
                                                <span class="admin-badge admin-badge-gold" style="font-size: 0.65rem; margin-left: 4px;">Inspecting</span>
                                            <?php endif; ?><br>
                                            <span style="font-size: 0.82rem; color: #fff; font-weight: 600;"><?= htmlspecialchars($agb['item_name']) ?></span><br>
                                            <span style="font-size: 0.75rem; color: var(--text-sub);"><?= date('M d, Y', strtotime($agb['check_in'])) ?></span>
                                        </td>
                                        <td>
                                            <?php if ($agb_is_reg): ?>
                                                <span class="admin-badge admin-badge-purple" style="margin-bottom: 2px;"><i class="fa-solid fa-id-badge"></i> <?= htmlspecialchars($agb_pid) ?></span>
                                                <span class="admin-badge admin-badge-success" style="font-size: 0.65rem; margin-bottom: 2px;"><i class="fa-solid fa-user-check"></i> Registered</span>
                                            <?php else: ?>
                                                <span class="admin-badge admin-badge-gold" style="margin-bottom: 2px;"><i class="fa-solid fa-user"></i> Guest</span>
                                            <?php endif; ?>
                                            <div style="font-weight: 700; color: #ffffff;"><?= htmlspecialchars($agb['guest_name']) ?></div>
                                            <div style="font-size: 0.78rem; color: var(--text-sub);"><?= htmlspecialchars($agb['guest_email']) ?></div>
                                        </td>
                                        <td>
                                            <?php if (!empty($agb['gcash_ref'])): ?>
                                                <code style="font-family: monospace; font-size: 0.95rem; font-weight: 800; color: #fbbf24; background: rgba(245, 158, 11, 0.12); padding: 4px 8px; border-radius: 4px; border: 1px solid rgba(245, 158, 11, 0.3); white-space: nowrap; display: inline-block; letter-spacing: 1.5px;">
                                                    <?= htmlspecialchars($agb['gcash_ref']) ?>
                                                </code>
                                            <?php else: ?>
                                                <span style="font-size: 0.78rem; color: var(--text-muted);">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <img src="<?= htmlspecialchars($agb_proof) ?>" alt="Proof" style="width: 42px; height: 42px; object-fit: cover; border-radius: 6px; border: 1.5px solid var(--admin-card-border); background: #ffffff; cursor: pointer;" onclick="openPictureModal('<?= htmlspecialchars($agb_proof) ?>', 'GCash Receipt - Ref #<?= htmlspecialchars($agb['id']) ?>')">
                                        </td>
                                        <td>
                                            <strong style="color: var(--gold-glow); font-family: 'Outfit', sans-serif; font-size: 1.05rem;">₱<?= number_format(floatval($agb['total_amount'])) ?></strong>
                                        </td>
                                        <td>
                                            <?php if ($agb_status === 'verified'): ?>
                                                <span class="admin-badge admin-badge-success"><i class="fa-solid fa-circle-check"></i> Verified</span>
                                            <?php elseif ($agb_status === 'rejected'): ?>
                                                <span class="admin-badge admin-badge-danger"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>
                                            <?php else: ?>
                                                <span class="admin-badge admin-badge-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <div style="display: flex; gap: 0.3rem; justify-content: center; flex-wrap: wrap;">
                                                <a href="verify_gcash.php?ref=<?= urlencode($agb['id']) ?>#inspection-console" class="admin-btn <?= $is_current ? 'admin-btn-primary' : 'admin-btn-outline' ?> admin-btn-sm" style="padding: 0.35rem 0.7rem; font-size: 0.75rem;" title="Inspect in Console">
                                                    <i class="fa-solid fa-magnifying-glass"></i> <?= $is_current ? 'Inspecting' : 'Inspect' ?>
                                                </a>
                                                <?php if ($agb_status !== 'verified'): ?>
                                                    <a href="verify_gcash.php?action=approve&ref=<?= urlencode($agb['id']) ?>" class="admin-btn admin-btn-gold admin-btn-sm" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;" title="Quick Approve">
                                                        <i class="fa-solid fa-check"></i>
                                                    </a>
                                                    <a href="verify_gcash.php?action=reject&ref=<?= urlencode($agb['id']) ?>" class="admin-btn admin-btn-outline admin-btn-sm" style="padding: 0.35rem 0.65rem; font-size: 0.75rem; color: #f43f5e;" onclick="return confirm('Reject this proof?');" title="Quick Reject">
                                                        <i class="fa-solid fa-xmark"></i>
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
        <?php endif; ?>

        <!-- Detailed Single Verification Console Anchor -->
        <div id="inspection-console"></div>

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
        <div class="<?= $is_admin_layout ? 'admin-card' : 'card' ?>" style="margin-bottom: 1.5rem; background: <?= $is_admin_layout ? 'linear-gradient(135deg, #1e1035 0%, #0f172a 100%)' : '#ffffff' ?>; border: 1.5px solid <?= $is_admin_layout ? '#6b21a8' : '#e9d5ff' ?>; box-shadow: 0 4px 20px rgba(0,0,0,0.06);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem; border-bottom: 1px solid <?= $is_admin_layout ? '#334155' : '#f1f5f9' ?>; padding-bottom: 0.85rem;">
                <div>
                    <?php if (!empty($booking_item['is_registered'])): ?>
                        <span class="admin-badge admin-badge-purple" style="margin-bottom: 0.4rem; display: inline-block;">
                            <i class="fa-solid fa-id-badge"></i> Profile ID: <?= htmlspecialchars($booking_item['profile_id'] ?? 'PBR-GST-00101') ?>
                        </span>
                    <?php endif; ?>
                    <span class="<?= $is_admin_layout ? 'admin-badge admin-badge-gold' : 'badge badge-purple' ?>" style="margin-bottom: 0.4rem; display: inline-block;">
                        <i class="fa-solid fa-envelope-circle-check"></i> Stay Voucher Ref #<?= htmlspecialchars($booking_item['id']) ?>
                    </span>
                    <h2 style="font-size: 1.3rem; margin: 0; color: <?= $is_admin_layout ? '#fbbf24' : 'var(--primary-purple)' ?>; font-family: 'Outfit', sans-serif;">
                        Selected Stay Review: <?= htmlspecialchars($booking_item['guest_name']) ?> (<?= htmlspecialchars($booking_item['item_name']) ?>)
                    </h2>
                    <p style="color: <?= $is_admin_layout ? '#94a3b8' : 'var(--text-muted)' ?>; font-size: 0.88rem; margin: 3px 0 0 0;">
                        Guest Email: <strong><?= htmlspecialchars($booking_item['guest_email'] ?? 'guest@example.com') ?></strong> | Phone: <strong><?= htmlspecialchars($booking_item['guest_phone'] ?? '0917-000-0000') ?></strong>
                    </p>
                </div>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <a href="view_email.php?ref=<?= urlencode($booking_item['id']) ?>" target="_blank" class="btn-book-kubo btn-sm" style="text-decoration: none;">
                        <i class="fa-solid fa-envelope-open-text btn-icon"></i>
                        <span>View / Print Voucher</span>
                        <i class="fa-solid fa-arrow-up-right-from-square btn-arrow"></i>
                    </a>
                </div>
            </div>

            <!-- Schedule Highlights Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem;">
                <div style="background: <?= $is_admin_layout ? '#0f172a' : '#f8fafc' ?>; padding: 0.85rem 1rem; border-radius: 10px; border: 1px solid <?= $is_admin_layout ? '#1e293b' : '#e2e8f0' ?>;">
                    <span style="font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: <?= $is_admin_layout ? '#c084fc' : '#7c3aed' ?>; display: block; margin-bottom: 3px;">
                        <i class="fa-solid fa-calendar-check"></i> Date & Time Booked
                    </span>
                    <strong style="font-size: 0.95rem; color: <?= $is_admin_layout ? '#ffffff' : '#0f172a' ?>;"><?= $booked_at_fmt ?></strong>
                </div>

                <div style="background: <?= $is_admin_layout ? '#0f172a' : '#f8fafc' ?>; padding: 0.85rem 1rem; border-radius: 10px; border: 1px solid <?= $is_admin_layout ? '#1e293b' : '#e2e8f0' ?>;">
                    <span style="font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: <?= $is_admin_layout ? '#fbbf24' : '#d97706' ?>; display: block; margin-bottom: 3px;">
                        <i class="fa-solid fa-door-open"></i> Check-In Schedule
                    </span>
                    <strong style="font-size: 0.95rem; color: <?= $is_admin_layout ? '#ffffff' : '#0f172a' ?>;"><?= $checkin_fmt ?></strong>
                    <span style="display: block; font-size: 0.82rem; font-weight: 800; color: #f59e0b;">Time: 2:00 PM (Standard)</span>
                </div>

                <div style="background: <?= $is_admin_layout ? '#0f172a' : '#f8fafc' ?>; padding: 0.85rem 1rem; border-radius: 10px; border: 1px solid <?= $is_admin_layout ? '#1e293b' : '#e2e8f0' ?>;">
                    <span style="font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: <?= $is_admin_layout ? '#34d399' : '#059669' ?>; display: block; margin-bottom: 3px;">
                        <i class="fa-solid fa-person-walking-dashed-line-arrow-right"></i> Check-Out Schedule
                    </span>
                    <strong style="font-size: 0.95rem; color: <?= $is_admin_layout ? '#ffffff' : '#0f172a' ?>;"><?= $checkout_fmt ?></strong>
                    <span style="display: block; font-size: 0.82rem; font-weight: 800; color: #10b981;">Time: 12:00 PM Noon (Standard)</span>
                </div>

                <div style="background: <?= $is_admin_layout ? '#0f172a' : '#f8fafc' ?>; padding: 0.85rem 1rem; border-radius: 10px; border: 1px solid <?= $is_admin_layout ? '#1e293b' : '#e2e8f0' ?>;">
                    <span style="font-size: 0.75rem; font-weight: 800; text-transform: uppercase; color: <?= $is_admin_layout ? '#94a3b8' : '#64748b' ?>; display: block; margin-bottom: 3px;">
                        <i class="fa-solid fa-bed"></i> Accommodation
                    </span>
                    <strong style="font-size: 0.95rem; color: <?= $is_admin_layout ? '#ffffff' : '#0f172a' ?>;"><?= htmlspecialchars($booking_item['item_name']) ?></strong>
                    <span style="display: block; font-size: 0.82rem; color: <?= $is_admin_layout ? '#94a3b8' : '#64748b' ?>;"><?= $booking_item['pax'] ?? 2 ?> Guests</span>
                </div>
            </div>
        </div>

        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1.5rem;">
            <!-- Official Resort GCash Details -->
            <div class="<?= $is_admin_layout ? 'admin-card' : 'card' ?>" style="background: linear-gradient(135deg, #005ce6, #003db3); color: #fff;">
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
            <div class="<?= $is_admin_layout ? 'admin-card' : 'card' ?>">
                <?php if ($user_role === 'admin' || $user_role === 'staff'): ?>
                    <h3 style="font-family: 'Outfit', sans-serif; color: <?= $is_admin_layout ? '#fff' : 'var(--primary-purple)' ?>; margin-bottom: 1rem;">
                        <i class="fa-solid fa-user-check" style="color: var(--primary-glow);"></i> Staff Verification Desk (Ref #<?= htmlspecialchars($booking_item['id']) ?>)
                    </h3>
                    
                    <div style="background: <?= $is_admin_layout ? '#0f172a' : 'var(--purple-light)' ?>; padding: 1rem; border-radius: var(--radius-sm); margin-bottom: 1rem; border: 1px solid <?= $is_admin_layout ? '#1e293b' : 'transparent' ?>;">
                        <?php if (!empty($booking_item['is_registered'])): ?>
                            <p style="margin-bottom: 0.35rem;"><span class="admin-badge admin-badge-purple"><i class="fa-solid fa-id-badge"></i> <?= htmlspecialchars($booking_item['profile_id']) ?> (Registered Guest)</span></p>
                        <?php endif; ?>
                        <p><strong>Booking Ref:</strong> <?= htmlspecialchars($booking_item['id']) ?></p>
                        <p><strong>Item / Accommodation:</strong> <?= htmlspecialchars($booking_item['item_name']) ?></p>
                        <p><strong>Submitted GCash Ref #:</strong> <code style="color: #fbbf24; font-weight: 800; font-size: 1.05rem; background: rgba(245,158,11,0.15); padding: 2px 6px; border-radius: 4px;"><?= htmlspecialchars($booking_item['gcash_ref'] ?? 'None') ?></code></p>
                        <p><strong>Sender Account:</strong> <?= htmlspecialchars($booking_item['guest_name']) ?> (<?= htmlspecialchars($booking_item['guest_email'] ?? '') ?> - <?= htmlspecialchars($booking_item['guest_phone']) ?>)</p>
                        <p><strong>Amount Payable:</strong> <span style="color: var(--gold-glow, var(--bamboo-gold)); font-weight: 700; font-size: 1.15rem;">₱<?= number_format(floatval($booking_item['total_amount'])) ?></span></p>
                        <p><strong>Payment Status:</strong> 
                            <?php if (($booking_item['payment_status'] ?? '') === 'verified'): ?>
                                <span class="admin-badge admin-badge-success"><i class="fa-solid fa-circle-check"></i> Verified</span>
                            <?php elseif (($booking_item['payment_status'] ?? '') === 'rejected'): ?>
                                <span class="admin-badge admin-badge-danger"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>
                            <?php else: ?>
                                <span class="admin-badge admin-badge-pending"><i class="fa-solid fa-clock"></i> Proof Submitted</span>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div style="margin-bottom: 1rem; text-align: center; border: 1px dashed <?= $is_admin_layout ? '#1e293b' : 'var(--border-color)' ?>; padding: 0.75rem; border-radius: var(--radius-sm); background: <?= $is_admin_layout ? 'rgba(15,23,42,0.6)' : '#faf5ff' ?>;">
                        <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 0.5rem; font-weight: 600;"><i class="fa-solid fa-file-invoice-dollar"></i> Uploaded Payment Proof Screenshot:</p>
                        <img src="<?= htmlspecialchars($proof_src) ?>" alt="Receipt Proof" style="max-width: 100%; max-height: 240px; object-fit: contain; border-radius: 6px; cursor: pointer; border: 1px solid var(--border-color); background: #fff;" onclick="openPictureModal('<?= htmlspecialchars($proof_src) ?>', 'Submitted GCash Receipt Proof - Ref #<?= htmlspecialchars($booking_item['id']) ?>')">
                        <div style="margin-top: 0.4rem; font-size: 0.78rem; color: var(--gold-glow, var(--primary-purple)); font-weight: 700; cursor: pointer;" onclick="openPictureModal('<?= htmlspecialchars($proof_src) ?>', 'Submitted GCash Receipt Proof - Ref #<?= htmlspecialchars($booking_item['id']) ?>')">
                            <i class="fa-solid fa-magnifying-glass-plus"></i> Click to Enlarge Receipt Image
                        </div>
                    </div>

                    <form action="verify_gcash.php?ref=<?= urlencode($booking_item['id']) ?>" method="POST" style="display: flex; gap: 0.5rem;">
                        <input type="hidden" name="ref_no" value="<?= htmlspecialchars($booking_item['id']) ?>">
                        <button type="submit" name="verify_action" value="approved" class="<?= $is_admin_layout ? 'admin-btn admin-btn-gold' : 'btn btn-success' ?>" style="flex: 1;">
                            <i class="fa-solid fa-check"></i> Approve Payment
                        </button>
                        <button type="submit" name="verify_action" value="rejected" class="<?= $is_admin_layout ? 'admin-btn admin-btn-outline' : 'btn btn-danger' ?>" style="flex: 1; color: #f43f5e;" onclick="return confirm('Reject this payment proof?');">
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
                            <input type="text" name="gcash_ref" class="form-control" placeholder="13-digit GCash Ref (e.g. 100293847561)" required>
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
        if (btnAll) { btnAll.style.background = 'transparent'; btnAll.style.color = '#cbd5e1'; }
        if (btnPending) { btnPending.style.background = 'transparent'; btnPending.style.color = '#fbbf24'; }
        if (btnVerified) { btnVerified.style.background = 'transparent'; btnVerified.style.color = '#34d399'; }

        if (btn) {
            if (status === 'proof_submitted') {
                btn.style.background = 'rgba(245, 158, 11, 0.2)';
                btn.style.color = '#fbbf24';
            } else if (status === 'verified') {
                btn.style.background = 'rgba(16, 185, 129, 0.2)';
                btn.style.color = '#34d399';
            } else {
                btn.style.background = 'var(--purple-accent)';
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
    </script>

</body>
</html>
