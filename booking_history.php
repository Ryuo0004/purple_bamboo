<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/booking_store.php';

// Strict RBAC: Only Admin and Staff accounts permitted
if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), ['admin', 'staff'])) {
    $err = (isset($_SESSION['role']) && $_SESSION['role'] === 'guest') ? 'guest_restricted' : 'unauthorized';
    header("Location: admin-login.php?error=" . $err);
    exit();
}

$user_role = $_SESSION['role'];
$admin_full_name = $_SESSION['full_name'] ?? 'Super Admin';
$admin_current_page = 'booking_history.php'; // Required for admin-nav.php active state
$is_admin_layout = true;

// Helper to extract booking archive
define('BOOKING_ARCHIVE_FILE', __DIR__ . '/data/booking_archive.json');

function getHistoryArchive(): array {
    global $pdo;
    $archive = [];
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT * FROM booking_archive ORDER BY archived_at DESC");
            $archive = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {}
    }
    if (file_exists(BOOKING_ARCHIVE_FILE)) {
        $raw = @file_get_contents(BOOKING_ARCHIVE_FILE);
        $fileArch = json_decode($raw, true) ?: [];
        $existing_ids = array_column($archive, 'id');
        foreach ($fileArch as $fa) {
            if (!in_array($fa['id'] ?? '', $existing_ids)) {
                $archive[] = $fa;
            }
        }
    }
    if (!empty($_SESSION['booking_history_archive']) && is_array($_SESSION['booking_history_archive'])) {
        $existing_ids = array_column($archive, 'id');
        foreach ($_SESSION['booking_history_archive'] as $sa) {
            if (!in_array($sa['id'] ?? '', $existing_ids)) {
                $archive[] = $sa;
                $existing_ids[] = $sa['id'] ?? '';
            }
        }
    }
    return $archive;
}

// Action Handlers (e.g. restore from history, clear history)
$action_msg = '';
$action_type = 'success';

if (isset($_GET['action'])) {
    $act = $_GET['action'];
    $target_id = trim($_GET['id'] ?? '');

    if ($act === 'restore' && !empty($target_id)) {
        // Restore booking to active approved status
        BookingStore::updateBookingStatus($target_id, 'approved', 'verified', null, null, 'Restored from history by admin');
        
        // Remove from persistent JSON archive if present
        $archive = getHistoryArchive();
        $archive = array_values(array_filter($archive, function($a) use ($target_id) {
            return ($a['id'] ?? '') !== $target_id;
        }));
        @file_put_contents(BOOKING_ARCHIVE_FILE, json_encode($archive, JSON_PRETTY_PRINT));
        $_SESSION['booking_history_archive'] = $archive;

        log_activity("BOOKING_RESTORED", "Booking #{$target_id} was restored from history by {$admin_full_name}");
        $action_msg = "Booking <strong>#{$target_id}</strong> has been successfully restored to Active status.";
    } elseif ($act === 'delete_permanent' && !empty($target_id)) {
        if ($user_role === 'admin') {
            // Remove from archive
            $archive = getHistoryArchive();
            $archive = array_values(array_filter($archive, function($a) use ($target_id) {
                return ($a['id'] ?? '') !== $target_id;
            }));
            @file_put_contents(BOOKING_ARCHIVE_FILE, json_encode($archive, JSON_PRETTY_PRINT));
            $_SESSION['booking_history_archive'] = $archive;

            // Delete from database / booking store
            BookingStore::deleteBooking($target_id);

            log_activity("BOOKING_PERMANENT_DELETE", "Booking #{$target_id} permanently deleted by {$admin_full_name}");
            $action_msg = "Booking record <strong>#{$target_id}</strong> has been permanently removed.";
        }
    }
}

// Helper to determine reason for completed / cancelled record
function getHistoryReasonText($b): array {
    $status = strtolower($b['booking_status'] ?? '');
    $reason = '';
    $type = 'neutral';

    if (!empty($b['archive_reason'])) {
        $reason = $b['archive_reason'];
    } elseif (!empty($b['rejection_reason'])) {
        $reason = 'Payment Proof Rejected: ' . $b['rejection_reason'];
        $type = 'danger';
    } elseif (!empty($b['special_notes']) && stripos($b['special_notes'], 'Cancelled:') !== false) {
        $reason = $b['special_notes'];
        $type = 'danger';
    }

    if (empty($reason)) {
        if (in_array($status, ['checked_out', 'completed'])) {
            $reason = !empty($b['special_notes']) ? $b['special_notes'] : 'Stay completed & keys surrendered on time (Checked out)';
            $type = 'success';
        } elseif (in_array($status, ['cancelled', 'rejected', 'forfeited'])) {
            $reason = !empty($b['special_notes']) ? $b['special_notes'] : 'Reservation cancelled by guest / admin';
            $type = 'danger';
        } else {
            $reason = 'Archived record';
            $type = 'neutral';
        }
    } else {
        if (in_array($status, ['checked_out', 'completed'])) {
            $type = 'success';
        } else {
            $type = 'danger';
        }
    }

    return ['text' => $reason, 'type' => $type];
}

// Fetch all bookings
$all_bookings = BookingStore::getAllBookings();
$archived_list = getHistoryArchive();

// Merge and extract history items (checked_out, completed, cancelled, rejected, forfeited)
$history_map = [];

// 1. From active store
foreach ($all_bookings as $b) {
    $st = strtolower($b['booking_status'] ?? '');
    if (in_array($st, ['checked_out', 'completed', 'cancelled', 'rejected', 'forfeited'])) {
        $history_map[$b['id']] = $b;
    }
}

// 2. From archive
foreach ($archived_list as $ab) {
    $id = $ab['id'] ?? '';
    if (!empty($id)) {
        if (!isset($history_map[$id])) {
            $history_map[$id] = $ab;
        } else {
            // Merge metadata like archive_reason and archived_at
            if (!empty($ab['archive_reason']) && empty($history_map[$id]['archive_reason'])) {
                $history_map[$id]['archive_reason'] = $ab['archive_reason'];
            }
            if (!empty($ab['archived_at']) && empty($history_map[$id]['archived_at'])) {
                $history_map[$id]['archived_at'] = $ab['archived_at'];
            }
            if (!empty($ab['archived_by']) && empty($history_map[$id]['archived_by'])) {
                $history_map[$id]['archived_by'] = $ab['archived_by'];
            }
        }
    }
}

$history_records = array_values($history_map);

// Sort newest first
usort($history_records, function($a, $b) {
    $tA = strtotime($a['archived_at'] ?? $a['check_out'] ?? $a['check_in'] ?? $a['created_at'] ?? 'now');
    $tB = strtotime($b['archived_at'] ?? $b['check_out'] ?? $b['check_in'] ?? $b['created_at'] ?? 'now');
    return $tB - $tA;
});

// Calculate metrics
$total_history = count($history_records);
$completed_count = 0;
$cancelled_count = 0;
$completed_revenue = 0.0;

foreach ($history_records as $hr) {
    $st = strtolower($hr['booking_status'] ?? '');
    if (in_array($st, ['checked_out', 'completed'])) {
        $completed_count++;
        $completed_revenue += floatval($hr['total_amount'] ?? 0);
    } else {
        $cancelled_count++;
    }
}

// Filters
$filter_status = strtolower(trim($_GET['status'] ?? 'all'));
$search_query  = trim($_GET['search'] ?? '');
$filter_date   = trim($_GET['date'] ?? '');

$filtered_records = array_filter($history_records, function($r) use ($filter_status, $search_query, $filter_date) {
    $st = strtolower($r['booking_status'] ?? '');
    $is_comp = in_array($st, ['checked_out', 'completed']);
    $is_canc = in_array($st, ['cancelled', 'rejected', 'forfeited']);

    if ($filter_status === 'completed' && !$is_comp) return false;
    if ($filter_status === 'cancelled' && !$is_canc) return false;

    if (!empty($filter_date)) {
        $ci = !empty($r['check_in']) ? date('Y-m-d', strtotime($r['check_in'])) : '';
        $co = !empty($r['check_out']) ? date('Y-m-d', strtotime($r['check_out'])) : '';
        $ar = !empty($r['archived_at']) ? date('Y-m-d', strtotime($r['archived_at'])) : '';
        if ($ci !== $filter_date && $co !== $filter_date && $ar !== $filter_date) {
            return false;
        }
    }

    if (!empty($search_query)) {
        $q = strtolower($search_query);
        $reasonData = getHistoryReasonText($r);
        $reasonStr = strtolower($reasonData['text']);
        $haystack = strtolower(
            ($r['id'] ?? '') . ' ' .
            ($r['guest_name'] ?? '') . ' ' .
            ($r['guest_email'] ?? '') . ' ' .
            ($r['guest_phone'] ?? '') . ' ' .
            ($r['item_name'] ?? '') . ' ' .
            ($r['profile_id'] ?? '') . ' ' .
            ($r['gcash_ref'] ?? '') . ' ' .
            $reasonStr
        );
        if (strpos($haystack, $q) === false) {
            return false;
        }
    }

    return true;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking History | Purple Bamboo Resort</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="stylesheet" href="admin-style.css?v=<?= time() ?>">
    <style>
        .history-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .history-stat-card {
            background: var(--admin-card-bg);
            border: 1px solid var(--admin-card-border);
            border-radius: var(--radius-md);
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1.25rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .history-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.06);
        }
        .history-stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        .icon-purple { background: rgba(124, 58, 237, 0.12); color: #7c3aed; }
        .icon-emerald { background: rgba(16, 185, 129, 0.12); color: #10b981; }
        .icon-rose { background: rgba(244, 63, 94, 0.12); color: #f43f5e; }
        .icon-gold { background: rgba(245, 158, 11, 0.12); color: #f59e0b; }

        .history-filter-bar {
            background: var(--admin-card-bg);
            border: 1px solid var(--admin-card-border);
            border-radius: var(--radius-md);
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
            justify-content: space-between;
        }
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .filter-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0.55rem 1rem;
            border-radius: 9999px;
            font-size: 0.88rem;
            font-weight: 600;
            text-decoration: none;
            color: var(--text-sub);
            background: rgba(0,0,0,0.04);
            border: 1px solid transparent;
            transition: all 0.2s ease;
        }
        .filter-tab-btn:hover {
            background: rgba(124, 58, 237, 0.08);
            color: var(--primary-purple);
        }
        .filter-tab-btn.active {
            background: var(--primary-purple);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.25);
        }
        .filter-tab-btn.active.tab-completed {
            background: #10b981;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
        }
        .filter-tab-btn.active.tab-cancelled {
            background: #f43f5e;
            box-shadow: 0 4px 12px rgba(244, 63, 94, 0.25);
        }
        .tab-counter {
            font-size: 0.75rem;
            padding: 2px 7px;
            border-radius: 12px;
            background: rgba(255,255,255,0.25);
            color: inherit;
        }
        .filter-tab-btn:not(.active) .tab-counter {
            background: rgba(0,0,0,0.08);
            color: var(--text-sub);
        }

        .search-input-group {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex: 1;
            max-width: 480px;
        }
        .search-field {
            width: 100%;
            padding: 0.55rem 1rem 0.55rem 2.4rem;
            border: 1px solid var(--admin-card-border);
            border-radius: 10px;
            font-size: 0.9rem;
            outline: none;
            background: #ffffff;
            transition: border-color 0.2s ease;
        }
        .search-field:focus {
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }

        /* Historical Reason Badge / Container */
        .reason-box {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            padding: 6px 9px;
            border-radius: 8px;
            font-size: 0.78rem;
            line-height: 1.3;
            min-width: 0;
            width: 100%;
            box-sizing: border-box;
        }
        .reason-box i {
            font-size: 0.82rem;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .reason-box.success {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }
        .reason-box.danger {
            background: #fff1f2;
            color: #be123c;
            border: 1px solid #fecdd3;
        }
        .reason-box.neutral {
            background: #f8fafc;
            color: #475569;
            border: 1px solid #e2e8f0;
        }
        .reason-box-content {
            flex: 1;
            min-width: 0;
        }
        .reason-box-title {
            display: block;
            font-weight: 700;
            font-size: 0.72rem;
            letter-spacing: 0.3px;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .reason-box.success .reason-box-title { color: #065f46; }
        .reason-box.danger .reason-box-title { color: #9f1239; }
        .reason-box.neutral .reason-box-title { color: #1e293b; }
        .reason-box-text {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            font-weight: 500;
            font-size: 0.76rem;
            line-height: 1.3;
            word-break: break-word;
        }

        .history-action-group {
            display: flex;
            gap: 5px;
            align-items: center;
            justify-content: flex-end;
        }
        .history-action-btn {
            width: 30px;
            height: 30px;
            border-radius: 7px;
            border: 1px solid var(--admin-card-border);
            background: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--text-sub);
            font-size: 0.82rem;
            cursor: pointer;
            transition: all 0.15s ease;
            text-decoration: none;
            box-sizing: border-box;
        }
        .history-action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }
        .history-action-btn.btn-view:hover {
            background: #f5f3ff;
            border-color: #c4b5fd;
            color: #7c3aed;
        }
        .history-action-btn.btn-restore {
            color: #059669;
            border-color: #a7f3d0;
            background: #ecfdf5;
        }
        .history-action-btn.btn-restore:hover {
            background: #d1fae5;
            border-color: #6ee7b7;
            color: #047857;
        }
        .history-action-btn.btn-delete {
            color: #e11d48;
            border-color: #fecdd3;
            background: #fff1f2;
        }
        .history-action-btn.btn-delete:hover {
            background: #ffe4e6;
            border-color: #fda4af;
            color: #be123c;
        }

        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            width: 100%;
        }
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--admin-card-bg);
            margin: 0;
        }
        .admin-table th {
            background: #f8fafc;
            padding: 0.85rem 0.95rem;
            text-align: left;
            font-family: 'Outfit', sans-serif;
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-sub);
            border-bottom: 1.5px solid var(--admin-card-border);
            white-space: nowrap;
        }
        .admin-table td {
            padding: 0.85rem 0.95rem;
            font-size: 0.86rem;
            border-bottom: 1px solid var(--admin-card-border);
            color: var(--text-main);
            vertical-align: middle;
        }
        .admin-table tr:last-child td { border-bottom: none; }
        .admin-table tr:hover td { background: #faf5ff; }

        /* Actions Column */
        .admin-table th.col-actions,
        .admin-table td.col-actions {
            text-align: right;
            padding-right: 1.25rem;
            white-space: nowrap;
        }

        .guest-name-truncate {
            font-weight: 700;
            color: var(--text-main);
            font-family: 'Outfit', sans-serif;
            font-size: 0.88rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 135px;
            display: block;
        }
        .guest-contact-truncate {
            color: var(--text-muted);
            display: block;
            font-size: 0.73rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 135px;
            margin-top: 2px;
        }
        .item-name-truncate {
            font-weight: 700;
            color: var(--text-main);
            font-size: 0.86rem;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 135px;
        }

        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: 9999px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }
        .badge-status-completed {
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
        }
        .badge-status-cancelled {
            background: #fff1f2;
            color: #e11d48;
            border: 1px solid #fecdd3;
        }

        .record-ref-id {
            font-family: 'Outfit', monospace;
            font-weight: 700;
            color: var(--primary-purple);
            font-size: 0.92rem;
            white-space: nowrap;
        }
    </style>
</head>
<body class="admin-layout-body">
    <?php include 'admin-nav.php'; ?>

    <div class="admin-main-wrapper">
        <header class="admin-top-header">
            <div class="admin-header-left">
                <button type="button" class="admin-mobile-toggle-btn" onclick="toggleAdminSidebar()" aria-label="Open Navigation Menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="admin-header-context">
                    <div class="admin-header-title-row">
                        <span class="admin-header-page-heading" style="font-family: 'Outfit', sans-serif;">Booking History</span>
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
            <!-- Alert Banner -->
            <?php if (!empty($action_msg)): ?>
                <div style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 1rem 1.25rem; border-radius: 10px; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-circle-check" style="font-size: 1.25rem; color: #10b981;"></i>
                        <span><?= $action_msg ?></span>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer; color: #065f46;">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Page Title Row -->
            <div class="admin-page-header">
                <div>
                    <h1 class="admin-page-title" style="font-family: 'Outfit', sans-serif; color: var(--text-main); margin: 0; font-size: 1.75rem; display: flex; align-items: center; gap: 12px;">
                        <i class="fa-solid fa-clock-rotate-left" style="color: var(--primary-purple);"></i> Booking History
                    </h1>
                    <p class="admin-page-subtitle" style="color: var(--text-sub); margin: 5px 0 0 0; font-size: 0.95rem;">
                        Inspect all finalized records: completed stays, released accommodations, and cancellations with detailed reason logs.
                    </p>
                </div>
                <div style="display: flex; gap: 0.5rem;">
                    <a href="bookings.php" class="admin-btn admin-btn-outline" style="font-size: 0.85rem;">
                        <i class="fa-solid fa-book"></i> Active Bookings
                    </a>
                    <button type="button" onclick="window.print()" class="admin-btn admin-btn-primary" style="font-size: 0.85rem;">
                        <i class="fa-solid fa-print"></i> Print Report
                    </button>
                </div>
            </div>

            <!-- KPI Summary Cards -->
            <div class="history-stat-grid">
                <div class="history-stat-card">
                    <div class="history-stat-icon icon-purple">
                        <i class="fa-solid fa-box-archive"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">All History</div>
                        <div style="font-family: 'Outfit', sans-serif; font-size: 1.75rem; font-weight: 800; color: var(--text-main);"><?= number_format($total_history) ?></div>
                    </div>
                </div>

                <div class="history-stat-card">
                    <div class="history-stat-icon icon-emerald">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">Completed Stays</div>
                        <div style="font-family: 'Outfit', sans-serif; font-size: 1.75rem; font-weight: 800; color: #10b981;"><?= number_format($completed_count) ?></div>
                    </div>
                </div>

                <div class="history-stat-card">
                    <div class="history-stat-icon icon-rose">
                        <i class="fa-solid fa-ban"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">Cancelled Bookings</div>
                        <div style="font-family: 'Outfit', sans-serif; font-size: 1.75rem; font-weight: 800; color: #f43f5e;"><?= number_format($cancelled_count) ?></div>
                    </div>
                </div>

                <div class="history-stat-card">
                    <div class="history-stat-icon icon-gold">
                        <i class="fa-solid fa-peso-sign"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">Completed Revenue</div>
                        <div style="font-family: 'Outfit', sans-serif; font-size: 1.75rem; font-weight: 800; color: #f59e0b;">₱<?= number_format($completed_revenue, 2) ?></div>
                    </div>
                </div>
            </div>

            <!-- Filter and Search Toolbar -->
            <div class="history-filter-bar">
                <div class="filter-tabs">
                    <a href="?status=all<?= !empty($search_query) ? '&search='.urlencode($search_query) : '' ?><?= !empty($filter_date) ? '&date='.urlencode($filter_date) : '' ?>" 
                       class="filter-tab-btn <?= $filter_status === 'all' ? 'active' : '' ?>">
                        <i class="fa-solid fa-layer-group"></i> All History 
                        <span class="tab-counter"><?= $total_history ?></span>
                    </a>
                    <a href="?status=completed<?= !empty($search_query) ? '&search='.urlencode($search_query) : '' ?><?= !empty($filter_date) ? '&date='.urlencode($filter_date) : '' ?>" 
                       class="filter-tab-btn tab-completed <?= $filter_status === 'completed' ? 'active' : '' ?>">
                        <i class="fa-solid fa-circle-check"></i> Completed 
                        <span class="tab-counter"><?= $completed_count ?></span>
                    </a>
                    <a href="?status=cancelled<?= !empty($search_query) ? '&search='.urlencode($search_query) : '' ?><?= !empty($filter_date) ? '&date='.urlencode($filter_date) : '' ?>" 
                       class="filter-tab-btn tab-cancelled <?= $filter_status === 'cancelled' ? 'active' : '' ?>">
                        <i class="fa-solid fa-ban"></i> Cancelled 
                        <span class="tab-counter"><?= $cancelled_count ?></span>
                    </a>
                </div>

                <form method="GET" action="booking_history.php" class="search-input-group">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                    <div style="position: relative; width: 100%;">
                        <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
                        <input type="text" name="search" class="search-field" placeholder="Search Ref, Guest, Item, or Reason..." value="<?= htmlspecialchars($search_query) ?>">
                    </div>
                    <?php if (!empty($search_query) || !empty($filter_date)): ?>
                        <a href="booking_history.php?status=<?= urlencode($filter_status) ?>" class="admin-btn admin-btn-outline" title="Reset Filters" style="padding: 0.55rem 0.8rem;">
                            <i class="fa-solid fa-xmark"></i>
                        </a>
                    <?php endif; ?>
                    <button type="submit" class="admin-btn admin-btn-primary" style="padding: 0.55rem 1rem; flex-shrink: 0;">
                        Filter
                    </button>
                </form>
            </div>

            <!-- Booking History Data Table -->
            <div class="admin-card" style="padding: 0; overflow: hidden;">
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th style="width: 16%;">Guest Details</th>
                                <th style="width: 16%;">Item Booked</th>
                                <th style="width: 14%;">Dates &amp; Times</th>
                                <th style="width: 10%;">Amount</th>
                                <th style="width: 11%;">Final Status</th>
                                <th style="width: 19%;">Reason &amp; Remarks</th>
                                <th style="width: 12%;">Archived Date</th>
                                <th class="col-actions" style="width: 12%; text-align: right; padding-right: 1.25rem;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($filtered_records)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 3.5rem 1rem; color: var(--text-muted);">
                                        <i class="fa-solid fa-inbox" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                                        <p style="font-size: 1.05rem; font-weight: 600; margin: 0; color: var(--text-sub);">No historical booking records match your criteria.</p>
                                        <small style="color: var(--text-muted);">Completed check-outs and cancelled reservations automatically display here.</small>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($filtered_records as $b): 
                                    $st = strtolower($b['booking_status'] ?? '');
                                    $is_comp = in_array($st, ['checked_out', 'completed']);
                                    $is_canc = in_array($st, ['cancelled', 'rejected', 'forfeited']);
                                    $reasonInfo = getHistoryReasonText($b);
                                    $archivedAt = $b['archived_at'] ?? ($b['check_out'] ?? $b['check_in'] ?? $b['created_at'] ?? '');
                                    $archivedBy = $b['archived_by'] ?? '';
                                ?>
                                    <tr>
                                        <!-- Guest Details -->
                                        <td>
                                            <?php
                                            $rawGuestName = trim($b['guest_name'] ?? 'Unknown Guest');
                                            $displayGuestName = mb_strlen($rawGuestName) > 16 ? mb_substr($rawGuestName, 0, 14) . '...' : $rawGuestName;
                                            ?>
                                            <div class="guest-name-truncate" title="<?= htmlspecialchars($rawGuestName) ?>">
                                                <?= htmlspecialchars($displayGuestName) ?>
                                            </div>
                                            <?php if (!empty($b['guest_phone'])): ?>
                                                <small class="guest-contact-truncate" style="color: var(--text-sub);" title="<?= htmlspecialchars($b['guest_phone']) ?>">
                                                    <i class="fa-solid fa-phone" style="width: 11px;"></i> <?= htmlspecialchars($b['guest_phone']) ?>
                                                </small>
                                            <?php endif; ?>
                                            <?php if (!empty($b['guest_email'])): ?>
                                                <small class="guest-contact-truncate" title="<?= htmlspecialchars($b['guest_email']) ?>">
                                                    <i class="fa-solid fa-envelope" style="width: 11px;"></i> <?= htmlspecialchars($b['guest_email']) ?>
                                                </small>
                                            <?php endif; ?>
                                            <?php if (!empty($b['profile_id'])): ?>
                                                <span class="admin-badge admin-badge-purple" style="font-size: 0.60rem; padding: 1px 5px; margin-top: 3px;">
                                                    <i class="fa-solid fa-id-card"></i> <?= htmlspecialchars($b['profile_id']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Booked Item -->
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 5px; flex-wrap: wrap;">
                                                <strong class="item-name-truncate" title="<?= htmlspecialchars($b['item_name']) ?>"><?= htmlspecialchars($b['item_name']) ?></strong>
                                                <span class="admin-badge <?= ($b['type'] ?? 'house') === 'activity' ? 'admin-badge-gold' : 'admin-badge-purple' ?>" style="font-size: 0.58rem; padding: 1px 5px; flex-shrink: 0;">
                                                    <?= strtoupper($b['type'] ?? 'house') ?>
                                                </span>
                                            </div>
                                            <div style="color: var(--text-muted); font-size: 0.75rem; margin-top: 2px;">
                                                <i class="fa-solid fa-users"></i> <?= intval($b['pax'] ?? 1) ?> Guests
                                            </div>
                                        </td>

                                        <!-- Dates & Schedule -->
                                        <td>
                                            <div style="font-weight: 600; color: var(--text-main); font-size: 0.80rem; white-space: nowrap;">
                                                <?= !empty($b['check_in']) ? date('M d, Y', strtotime($b['check_in'])) : '—' ?>
                                            </div>
                                            <?php if (!empty($b['check_out']) && $b['check_out'] !== $b['check_in']): ?>
                                                <div style="font-size: 0.74rem; color: var(--text-sub); white-space: nowrap;">
                                                    to <?= date('M d, Y', strtotime($b['check_out'])) ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($b['time_slot'])): ?>
                                                <div style="font-size: 0.70rem; color: var(--text-muted); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 120px;" title="<?= htmlspecialchars($b['time_slot']) ?>">
                                                    <i class="fa-solid fa-clock"></i> <?= htmlspecialchars($b['time_slot']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Amount -->
                                        <td>
                                            <strong style="font-family: 'Outfit', sans-serif; font-size: 0.95rem; color: #b45309; display: block; white-space: nowrap;">
                                                ₱<?= number_format(floatval($b['total_amount'] ?? 0)) ?>
                                            </strong>
                                            <div>
                                                <?php 
                                                $ps = strtolower($b['payment_status'] ?? '');
                                                $ps_cls = $ps === 'verified' ? 'admin-badge-success' : ($ps === 'proof_submitted' ? 'admin-badge-pending' : 'admin-badge-danger');
                                                ?>
                                                <span class="admin-badge <?= $ps_cls ?>" style="font-size: 0.62rem; margin-top: 2px; padding: 2px 6px;">
                                                    <?= ucfirst($ps ?: 'unpaid') ?>
                                                </span>
                                            </div>
                                        </td>

                                        <!-- Final Status -->
                                        <td>
                                            <?php if ($is_comp): ?>
                                                <span class="badge-status badge-status-completed">
                                                    <i class="fa-solid fa-circle-check"></i> Completed
                                                </span>
                                            <?php elseif ($is_canc): ?>
                                                <span class="badge-status badge-status-cancelled">
                                                    <i class="fa-solid fa-ban"></i> Cancelled
                                                </span>
                                            <?php else: ?>
                                                <span class="badge-status" style="background: #f1f5f9; color: #475569;">
                                                    <?= ucfirst($st) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Reason & Remarks (PROMINENT COLUMN) -->
                                        <td>
                                            <?php 
                                            $rawReason = trim($reasonInfo['text'] ?? '');
                                            // Determine clean title and strip redundant prefix from text
                                            if ($is_canc) {
                                                $reasonTitle = 'Cancellation Reason';
                                                $cleanedReason = preg_replace('/^(cancelled\s*:\s*|cancellation(\s*reason)?\s*:\s*)/i', '', $rawReason);
                                            } elseif ($is_comp) {
                                                $reasonTitle = 'Completion Note';
                                                $cleanedReason = preg_replace('/^(completed\s*:\s*|completion(\s*note)?\s*:\s*)/i', '', $rawReason);
                                            } else {
                                                $reasonTitle = 'Archive Note';
                                                $cleanedReason = preg_replace('/^(archived?\s*:\s*|note\s*:\s*)/i', '', $rawReason);
                                            }
                                            if (empty($cleanedReason)) {
                                                $cleanedReason = $rawReason ?: ($is_canc ? 'Reservation cancelled' : ($is_comp ? 'Stay completed' : 'Archived record'));
                                            }
                                            $reasonIcon = $reasonInfo['type'] === 'success' ? 'fa-circle-check' : ($reasonInfo['type'] === 'danger' ? 'fa-triangle-exclamation' : 'fa-circle-info');
                                            ?>
                                            <div class="reason-box <?= $reasonInfo['type'] ?>" title="<?= htmlspecialchars($cleanedReason) ?>">
                                                <i class="fa-solid <?= $reasonIcon ?>"></i>
                                                <div class="reason-box-content">
                                                    <span class="reason-box-title"><?= $reasonTitle ?></span>
                                                    <span class="reason-box-text"><?= htmlspecialchars($cleanedReason) ?></span>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Archived Date -->
                                        <td>
                                            <div style="font-size: 0.80rem; font-weight: 600; color: var(--text-main); white-space: nowrap;">
                                                <?= !empty($archivedAt) ? date('M d, Y', strtotime($archivedAt)) : '—' ?>
                                            </div>
                                            <div style="font-size: 0.72rem; color: var(--text-muted); white-space: nowrap;">
                                                <?= !empty($archivedAt) ? date('g:i A', strtotime($archivedAt)) : '' ?>
                                            </div>
                                            <?php if (!empty($archivedBy)): ?>
                                                <small style="font-size: 0.68rem; color: #7c3aed; display: block; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 95px;" title="by <?= htmlspecialchars($archivedBy) ?>">
                                                    by <?= htmlspecialchars($archivedBy) ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Actions -->
                                        <td class="col-actions">
                                            <div class="history-action-group">
                                                <button type="button" class="history-action-btn btn-view" title="View Complete Record Details" onclick='viewHistoryRecord(<?= json_encode($b) ?>, <?= json_encode($cleanedReason) ?>)'>
                                                    <i class="fa-solid fa-eye"></i>
                                                </button>
                                            </div>
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

    <!-- Booking Details Breakdown Modal -->
    <div class="modal-overlay" id="historyModal" onclick="if(event.target===this) closeHistoryModal()">
        <div class="modal-container" style="max-width: 600px;">
            <div class="modal-header">
                <h3 class="modal-title" style="display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-file-invoice" style="color: var(--primary-purple);"></i>
                    Historical Booking Breakdown
                </h3>
                <button type="button" class="modal-close" onclick="closeHistoryModal()">&times;</button>
            </div>
            <div class="modal-body" id="historyModalBody" style="max-height: 75vh; overflow-y: auto; padding: 1.5rem;"></div>
            <div style="padding: 1rem 1.5rem; background: #f8fafc; border-top: 1px solid var(--admin-card-border); display: flex; justify-content: space-between; align-items: center;">
                <button type="button" onclick="window.print()" class="admin-btn admin-btn-outline admin-btn-sm">
                    <i class="fa-solid fa-print"></i> Print Voucher
                </button>
                <button type="button" class="admin-btn admin-btn-primary admin-btn-sm" onclick="closeHistoryModal()">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- Lightbox Modal for Receipts -->
    <div id="receiptModal" class="modal-overlay" onclick="if(event.target===this) closeReceiptModal()">
        <div class="modal-container" style="max-width: 500px; text-align: center;">
            <div class="modal-header">
                <h3 class="modal-title">Payment Receipt Proof</h3>
                <button type="button" class="modal-close" onclick="closeReceiptModal()">&times;</button>
            </div>
            <div style="padding: 1rem;">
                <img id="receiptModalImg" src="" alt="GCash Receipt" style="max-width: 100%; height: auto; border-radius: 8px; border: 1px solid #e2e8f0;">
            </div>
        </div>
    </div>

    <script>
    function viewHistoryRecord(b, reasonText) {
        let isComp = ['checked_out', 'completed'].includes((b.booking_status || '').toLowerCase());
        let statusBadge = isComp 
            ? '<span class="badge-status badge-status-completed"><i class="fa-solid fa-circle-check"></i> Completed</span>'
            : '<span class="badge-status badge-status-cancelled"><i class="fa-solid fa-ban"></i> Cancelled</span>';
        
        let paymentBadge = (b.payment_status === 'verified') 
            ? '<span class="admin-badge admin-badge-success">Verified</span>'
            : '<span class="admin-badge admin-badge-pending">' + (b.payment_status || 'Unpaid') + '</span>';

        let safeRef = (b.id || '').replace(/</g, "&lt;");
        let safeGuest = (b.guest_name || '').replace(/</g, "&lt;");
        let safeEmail = (b.guest_email || '').replace(/</g, "&lt;");
        let safePhone = (b.guest_phone || '').replace(/</g, "&lt;");
        let safeItem = (b.item_name || '').replace(/</g, "&lt;");
        let safeGcashRef = (b.gcash_ref || 'None provided').replace(/</g, "&lt;");
        let safeReason = (reasonText || 'None recorded').replace(/</g, "&lt;");

        let receiptSection = '';
        if (b.payment_proof) {
            receiptSection = `
                <div style="margin-top: 1rem; padding: 1rem; background: #f8fafc; border: 1px solid var(--admin-card-border); border-radius: 8px;">
                    <div style="font-weight: 700; margin-bottom: 0.5rem; color: var(--text-main); font-size: 0.88rem;">
                        <i class="fa-solid fa-receipt"></i> Uploaded GCash Receipt Proof
                    </div>
                    <button type="button" onclick="openReceiptModal('${b.payment_proof}')" class="admin-btn admin-btn-outline admin-btn-sm">
                        <i class="fa-solid fa-image"></i> View Enlarged Receipt
                    </button>
                </div>
            `;
        }

        let html = `
            <div style="background: #faf5ff; border: 1px solid #e9d5ff; border-radius: 10px; padding: 1rem; margin-bottom: 1.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span style="font-family: monospace; font-size: 1.1rem; font-weight: 800; color: #7c3aed;">#${safeRef}</span>
                    <div>${statusBadge}</div>
                </div>
                <div style="font-size: 0.88rem; color: var(--text-sub);">
                    <i class="fa-solid fa-calendar"></i> Stay Period: <strong>${b.check_in || 'N/A'}</strong> ${b.check_out ? 'to <strong>' + b.check_out + '</strong>' : ''}
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1.25rem;">
                <div style="background: var(--admin-bg); padding: 0.75rem 1rem; border-radius: 8px;">
                    <small style="color: var(--text-muted); display: block; font-size: 0.75rem; text-transform: uppercase;">Guest Name</small>
                    <strong style="color: var(--text-main); font-size: 0.95rem;">${safeGuest}</strong>
                </div>
                <div style="background: var(--admin-bg); padding: 0.75rem 1rem; border-radius: 8px;">
                    <small style="color: var(--text-muted); display: block; font-size: 0.75rem; text-transform: uppercase;">Contact</small>
                    <span style="color: var(--text-main); font-size: 0.88rem;">${safePhone}</span>
                </div>
                <div style="background: var(--admin-bg); padding: 0.75rem 1rem; border-radius: 8px;">
                    <small style="color: var(--text-muted); display: block; font-size: 0.75rem; text-transform: uppercase;">Accommodation / Tour</small>
                    <strong style="color: var(--text-main); font-size: 0.92rem;">${safeItem}</strong>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">${b.pax || 1} Pax</div>
                </div>
                <div style="background: var(--admin-bg); padding: 0.75rem 1rem; border-radius: 8px;">
                    <small style="color: var(--text-muted); display: block; font-size: 0.75rem; text-transform: uppercase;">Total Billed</small>
                    <strong style="color: #b45309; font-size: 1.05rem;">₱${parseFloat(b.total_amount || 0).toLocaleString()}</strong>
                    <div style="margin-top: 2px;">${paymentBadge}</div>
                </div>
            </div>

            <!-- PROMINENT REASON CONTAINER -->
            <div style="border-left: 4px solid ${isComp ? '#10b981' : '#f43f5e'}; background: ${isComp ? '#ecfdf5' : '#fff1f2'}; padding: 1rem; border-radius: 4px 8px 8px 4px; margin-bottom: 1rem;">
                <div style="font-weight: 800; font-size: 0.88rem; color: ${isComp ? '#065f46' : '#9f1239'}; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid ${isComp ? 'fa-circle-check' : 'fa-triangle-exclamation'}"></i>
                    ${isComp ? 'Stay Completion & Check-Out Record' : 'Reason for Cancellation / Rejection'}
                </div>
                <p style="margin: 0; font-size: 0.92rem; color: ${isComp ? '#047857' : '#be123c'}; font-weight: 500; line-height: 1.4;">
                    ${safeReason}
                </p>
            </div>

            <div style="padding: 0.75rem 1rem; background: var(--admin-bg); border-radius: 8px; font-size: 0.82rem; color: var(--text-sub);">
                <div><strong>GCash Reference:</strong> <span style="font-family: monospace;">${safeGcashRef}</span></div>
                ${b.archived_at ? '<div style="margin-top: 4px;"><strong>Archived On:</strong> ' + b.archived_at + (b.archived_by ? ' (by ' + b.archived_by + ')' : '') + '</div>' : ''}
            </div>

            ${receiptSection}
        `;

        document.getElementById('historyModalBody').innerHTML = html;
        document.getElementById('historyModal').classList.add('active');
    }

    function closeHistoryModal() {
        document.getElementById('historyModal').classList.remove('active');
    }

    function openReceiptModal(src) {
        document.getElementById('receiptModalImg').src = src;
        document.getElementById('receiptModal').classList.add('active');
    }

    function closeReceiptModal() {
        document.getElementById('receiptModal').classList.remove('active');
    }
    </script>
</body>
</html>
