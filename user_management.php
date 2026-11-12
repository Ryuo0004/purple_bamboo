<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';

// Strict RBAC: Only Administrator accounts permitted (Staff restricted)
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    if (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'staff') {
        header("Location: bookings.php?error=admin_only");
        exit();
    }
    $err = (isset($_SESSION['role']) && $_SESSION['role'] === 'guest') ? 'guest_restricted' : 'unauthorized';
    header("Location: admin-login.php?error=" . $err);
    exit();
}

$user_role = $_SESSION['role'];
$admin_full_name = $_SESSION['full_name'] ?? 'Super Admin';
$admin_current_page = 'user_management.php'; // Required for admin-nav.php active state
$is_admin_layout = true;

$action_msg = '';
$action_type = 'success';

// Handle Block, Deactivate, & Activate/Unblock POST actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    verify_csrf_token();
    $target_id = intval($_POST['user_id'] ?? 0);
    $target_name = trim($_POST['user_name'] ?? 'User');
    $target_role = strtolower(trim($_POST['user_role'] ?? 'guest'));
    $target_email = strtolower(trim($_POST['user_email'] ?? ''));

    if ($_POST['action'] === 'block_user') {
        if ($target_id === intval($_SESSION['user_id'] ?? 0) && ($target_role === strtolower($_SESSION['role'] ?? ''))) {
            $action_msg = "Security Protection: You cannot block your own currently logged-in account.";
            $action_type = 'danger';
        } else {
            $reason = trim($_POST['reason'] ?? '');
            $other_reason = trim($_POST['other_reason'] ?? '');
            if ($reason === 'Others' || empty($reason)) {
                $reason = !empty($other_reason) ? $other_reason : 'Violation of resort policies';
            }

            Auth::blockUser($target_id, $reason, $admin_full_name, $target_role, $target_email);
            log_activity("USER_BLOCKED", "User #{$target_id} ({$target_name}, {$target_role}) blocked by {$admin_full_name}. Reason: {$reason}");
            $action_msg = "Account for <strong>" . htmlspecialchars($target_name) . "</strong> has been <strong style='color:#f43f5e;'>BLOCKED</strong>. Access is immediately revoked.";
            $action_type = 'danger';
        }
    } elseif ($_POST['action'] === 'deactivate_user') {
        if ($target_id === intval($_SESSION['user_id'] ?? 0) && ($target_role === strtolower($_SESSION['role'] ?? ''))) {
            $action_msg = "Security Protection: You cannot deactivate your own currently logged-in account.";
            $action_type = 'danger';
        } else {
            $reason = trim($_POST['reason'] ?? '');
            $other_reason = trim($_POST['other_reason'] ?? '');
            if ($reason === 'Others' || empty($reason)) {
                $reason = !empty($other_reason) ? $other_reason : 'Account deactivated by administrator';
            }

            Auth::deactivateUser($target_id, $reason, $admin_full_name, $target_role, $target_email);
            log_activity("USER_DEACTIVATED", "User #{$target_id} ({$target_name}, {$target_role}) deactivated by {$admin_full_name}. Reason: {$reason}");
            $action_msg = "Account for <strong>" . htmlspecialchars($target_name) . "</strong> has been <strong style='color:#f59e0b;'>DEACTIVATED</strong>. Account access has been suspended.";
            $action_type = 'warning';
        }
    } elseif ($_POST['action'] === 'unblock_user' || $_POST['action'] === 'activate_user') {
        Auth::activateUser($target_id, $target_role, $target_email);
        log_activity("USER_ACTIVATED", "User #{$target_id} ({$target_name}, {$target_role}) restored to active by {$admin_full_name}");
        $action_msg = "Account for <strong>" . htmlspecialchars($target_name) . "</strong> has been <strong style='color:#10b981;'>RESTORED TO ACTIVE</strong>. The user can now log in.";
        $action_type = 'success';
    }
}

// Fetch all users from Database with separated tables support
$users = [];
$db_users = [];
if (isset($pdo) && $pdo !== null) {
    try {
        $stmt = $pdo->query("
            SELECT COALESCE(id, user_id) AS id, COALESCE(profile_id, CONCAT('USR-', LPAD(COALESCE(id, user_id), 5, '0'))) AS profile_id, CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name, NULLIF(suffix, '')) AS full_name, first_name, last_name, email, phone, role, status, is_blocked, block_reason, blocked_at, blocked_by, emergency_contact_name, emergency_contact_relationship, emergency_contact_phone, id_type, id_number, health_conditions, created_at FROM users
            UNION ALL
            SELECT id, profile_id, CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name, NULLIF(suffix, '')) AS full_name, first_name, last_name, email, phone, 'staff' AS role, status, (CASE WHEN status IN ('blocked', 'deactivated') THEN 1 ELSE 0 END) AS is_blocked, block_reason, blocked_at, blocked_by, '' AS emergency_contact_name, '' AS emergency_contact_relationship, '' AS emergency_contact_phone, 'Staff ID' AS id_type, '' AS id_number, '' AS health_conditions, created_at FROM staff
            UNION ALL
            SELECT id, profile_id, CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name, NULLIF(suffix, '')) AS full_name, first_name, last_name, email, phone, 'admin' AS role, status, (CASE WHEN status IN ('blocked', 'deactivated') THEN 1 ELSE 0 END) AS is_blocked, block_reason, blocked_at, blocked_by, '' AS emergency_contact_name, '' AS emergency_contact_relationship, '' AS emergency_contact_phone, 'System Admin' AS id_type, '' AS id_number, '' AS health_conditions, created_at FROM admins
            ORDER BY created_at DESC
        ");
        $db_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $db_users = [];
    }
}

$file_users = Auth::getUsersFromFile();

if (!empty($db_users)) {
    // If DB is primary, cross-reference status with file if not yet synced
    $users = $db_users;
    $fileMap = [];
    foreach ($file_users as $fu) {
        $fileMap[$fu['id'] ?? ''] = $fu;
    }
    foreach ($users as &$u) {
        $uid = $u['id'];
        if (isset($fileMap[$uid])) {
            $f = $fileMap[$uid];
            if ($u['status'] === 'active' && (!empty($f['is_blocked']) || in_array(strtolower($f['status'] ?? ''), ['blocked', 'deactivated']))) {
                $u['status'] = strtolower($f['status'] ?? 'blocked');
                $u['is_blocked'] = 1;
                $u['block_reason'] = $f['block_reason'] ?? '';
            }
        }
    }
} else {
    // Fallback directly to file store
    $users = $file_users;
}

// Ensure full_name, id, and profile_id are properly populated and normalize status
foreach ($users as &$u) {
    $u['id'] = !empty($u['id']) ? intval($u['id']) : (!empty($u['user_id']) ? intval($u['user_id']) : 0);
    $u_role = strtolower($u['role'] ?? 'guest');
    if (empty($u['profile_id'])) {
        $prefix = ($u_role === 'admin') ? 'PBR-ADM-' : (($u_role === 'staff') ? 'PBR-STF-' : 'USR-');
        $u['profile_id'] = $prefix . str_pad((string)$u['id'], 5, '0', STR_PAD_LEFT);
    }
    if (empty($u['full_name'])) {
        $u['full_name'] = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        if (empty($u['full_name'])) {
            $u['full_name'] = 'Registered User';
        }
    }
    // Normalize status: 'active', 'deactivated', 'blocked'
    $curr_status = strtolower($u['status'] ?? 'active');
    if (!in_array($curr_status, ['active', 'blocked', 'deactivated'])) {
        $curr_status = !empty($u['is_blocked']) ? 'blocked' : 'active';
    }
    if (!empty($u['is_blocked']) && $curr_status === 'active') {
        $curr_status = 'blocked';
    }
    $u['status'] = $curr_status;
    $u['is_blocked'] = ($curr_status !== 'active') ? 1 : 0;
}
unset($u);

// Calculate metrics
$total_users = count($users);
$active_users_count = 0;
$deactivated_users_count = 0;
$blocked_users_count = 0;
$guest_users_count = 0;
$staff_admin_count = 0;

foreach ($users as $u) {
    if ($u['status'] === 'active') {
        $active_users_count++;
    } elseif ($u['status'] === 'deactivated') {
        $deactivated_users_count++;
    } elseif ($u['status'] === 'blocked') {
        $blocked_users_count++;
    }

    $r = strtolower($u['role'] ?? 'guest');
    if ($r === 'guest') {
        $guest_users_count++;
    } else {
        $staff_admin_count++;
    }
}

// Filters
$filter_status = strtolower(trim($_GET['status'] ?? 'all'));
$filter_role   = strtolower(trim($_GET['role'] ?? 'all'));
$search_query  = trim($_GET['search'] ?? '');

$filtered_users = array_filter($users, function($u) use ($filter_status, $filter_role, $search_query) {
    $st = $u['status'];

    if ($filter_status === 'active' && $st !== 'active') return false;
    if ($filter_status === 'deactivated' && $st !== 'deactivated') return false;
    if ($filter_status === 'blocked' && $st !== 'blocked') return false;

    $r = strtolower($u['role'] ?? 'guest');
    if ($filter_role === 'guest' && $r !== 'guest') return false;
    if ($filter_role === 'staff' && $r !== 'staff') return false;
    if ($filter_role === 'admin' && $r !== 'admin') return false;

    if (!empty($search_query)) {
        $q = strtolower($search_query);
        $haystack = strtolower(
            ($u['profile_id'] ?? '') . ' ' .
            ($u['full_name'] ?? '') . ' ' .
            ($u['email'] ?? '') . ' ' .
            ($u['phone'] ?? '') . ' ' .
            ($u['role'] ?? '') . ' ' .
            ($u['status'] ?? '') . ' ' .
            ($u['block_reason'] ?? '')
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
    <title>Manage Users | Purple Bamboo Resort</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="stylesheet" href="admin-style.css?v=<?= time() ?>">
    <style>
        .user-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .user-stat-card {
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
        .user-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.06);
        }
        .user-stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }
        .icon-blue { background: rgba(59, 130, 246, 0.12); color: #3b82f6; }
        .icon-emerald { background: rgba(16, 185, 129, 0.12); color: #10b981; }
        .icon-amber { background: rgba(245, 158, 11, 0.12); color: #f59e0b; }
        .icon-rose { background: rgba(244, 63, 94, 0.12); color: #f43f5e; }
        .icon-purple { background: rgba(124, 58, 237, 0.12); color: #7c3aed; }

        .user-filter-bar {
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
        .filter-tab-btn.active.tab-active-users {
            background: #10b981;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
            color: #ffffff;
        }
        .filter-tab-btn.active.tab-deactivated-users {
            background: #f59e0b;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
            color: #ffffff;
        }
        .filter-tab-btn.active.tab-blocked-users {
            background: #f43f5e;
            box-shadow: 0 4px 12px rgba(244, 63, 94, 0.25);
            color: #ffffff;
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

        .table-responsive { overflow-x: auto; }
        .admin-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: var(--admin-card-bg);
            border-radius: 12px;
            border: 1px solid var(--admin-card-border);
        }
        .admin-table th {
            background: #f8fafc;
            padding: 0.9rem 1.1rem;
            text-align: left;
            font-family: 'Outfit', sans-serif;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-sub);
            border-bottom: 1px solid var(--admin-card-border);
        }
        .admin-table td {
            padding: 1rem 1.1rem;
            font-size: 0.92rem;
            border-bottom: 1px solid var(--admin-card-border);
            color: var(--text-main);
            vertical-align: middle;
        }
        .admin-table tr:last-child td { border-bottom: none; }
        .admin-table tr:hover td { background: #faf5ff; }

        .role-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .role-admin { background: rgba(244, 63, 94, 0.12); color: #e11d48; border: 1px solid rgba(244, 63, 94, 0.25); }
        .role-staff { background: rgba(56, 189, 248, 0.12); color: #0284c7; border: 1px solid rgba(56, 189, 248, 0.25); }
        .role-guest { background: rgba(167, 139, 250, 0.12); color: #7c3aed; border: 1px solid rgba(167, 139, 250, 0.25); }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .status-active {
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
        }
        .status-deactivated {
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
        }
        .status-blocked {
            background: #fff1f2;
            color: #e11d48;
            border: 1px solid #fecdd3;
        }

        .block-reason-preview {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 7px;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 600;
            margin-top: 4px;
            max-width: 220px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .block-reason-preview.blocked {
            background: rgba(244, 63, 94, 0.08);
            border: 1px solid rgba(244, 63, 94, 0.2);
            color: #be123c;
        }
        .block-reason-preview.deactivated {
            background: rgba(245, 158, 11, 0.08);
            border: 1px solid rgba(245, 158, 11, 0.25);
            color: #b45309;
        }

        body.admin-layout-body {
            margin: 0 !important;
            padding: 0 !important;
        }
        .admin-main-wrapper {
            margin-left: 260px !important;
            width: calc(100% - 260px) !important;
            max-width: calc(100% - 260px) !important;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
            box-sizing: border-box;
            background: #f8fafc;
        }
        .admin-top-header {
            padding: 0 1.5rem !important;
        }
        .admin-container {
            width: 100% !important;
            max-width: 100% !important;
            margin: 0 !important;
            padding: 1.25rem 1.5rem 2.5rem !important;
            box-sizing: border-box !important;
            flex: 1;
        }
        @media (max-width: 991px) {
            .admin-main-wrapper {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            .admin-container {
                padding: 1rem 1rem 2rem !important;
            }
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
                        <span class="admin-header-page-heading" style="font-family: 'Outfit', sans-serif;">Manage Users</span>
                    </div>
                </div>
            </div>
            <div class="admin-header-actions">
                <a href="admin_profile.php" class="hs-user-badge" title="View Admin Profile">
                    <span class="hs-user-avatar"><?= strtoupper(substr($admin_full_name ?? 'Admin', 0, 1)) ?></span>
                    <span class="hs-user-name"><?= htmlspecialchars($admin_full_name ?? 'Admin') ?></span>
                </a>
                <a href="logout.php" class="hs-btn-logout" onclick="openLogoutModal(event)" title="Sign Out"><i class="fa-solid fa-sign-out-alt"></i></a>
            </div>
        </header>

        <main class="admin-container">
            <!-- Flash Notification -->
            <?php if (!empty($action_msg)): 
                $alert_bg = ($action_type === 'danger') ? '#fff1f2' : (($action_type === 'warning') ? '#fffbeb' : '#ecfdf5');
                $alert_border = ($action_type === 'danger') ? '#fecdd3' : (($action_type === 'warning') ? '#fde68a' : '#a7f3d0');
                $alert_color = ($action_type === 'danger') ? '#9f1239' : (($action_type === 'warning') ? '#92400e' : '#065f46');
                $alert_icon_color = ($action_type === 'danger') ? '#f43f5e' : (($action_type === 'warning') ? '#f59e0b' : '#10b981');
                $alert_icon = ($action_type === 'danger') ? 'fa-triangle-exclamation' : (($action_type === 'warning') ? 'fa-circle-exclamation' : 'fa-circle-check');
            ?>
                <div style="background: <?= $alert_bg ?>; border: 1px solid <?= $alert_border ?>; color: <?= $alert_color ?>; padding: 1rem 1.25rem; border-radius: 10px; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid <?= $alert_icon ?>" style="font-size: 1.25rem; color: <?= $alert_icon_color ?>;"></i>
                        <span><?= $action_msg ?></span>
                    </div>
                    <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer; color: inherit;">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Page Title Row -->
            <div class="admin-page-header">
                <div>
                    <h1 class="admin-page-title" style="font-family: 'Outfit', sans-serif; color: var(--text-main); margin: 0; font-size: 1.75rem; display: flex; align-items: center; gap: 12px;">
                        <i class="fa-solid fa-users-gear" style="color: #60a5fa;"></i> Manage Users
                    </h1>
                    <p class="admin-page-subtitle" style="color: var(--text-sub); margin: 5px 0 0 0; font-size: 0.95rem;">
                        Administer resort guest accounts and personnel. Block abusive accounts, deactivate dormant users, and manage permissions.
                    </p>
                </div>
                <div style="display: flex; gap: 0.5rem;">
                    <a href="booking_history.php" class="admin-btn admin-btn-outline" style="font-size: 0.85rem;">
                        <i class="fa-solid fa-clock-rotate-left"></i> Booking History
                    </a>
                </div>
            </div>

            <!-- Metric Summary Cards -->
            <div class="user-stat-grid">
                <div class="user-stat-card">
                    <div class="user-stat-icon icon-blue">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.78rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">Total Accounts</div>
                        <div style="font-family: 'Outfit', sans-serif; font-size: 1.75rem; font-weight: 800; color: var(--text-main);"><?= number_format($total_users) ?></div>
                    </div>
                </div>

                <div class="user-stat-card">
                    <div class="user-stat-icon icon-emerald">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.78rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">Active Accounts</div>
                        <div style="font-family: 'Outfit', sans-serif; font-size: 1.75rem; font-weight: 800; color: #10b981;"><?= number_format($active_users_count) ?></div>
                    </div>
                </div>

                <div class="user-stat-card">
                    <div class="user-stat-icon icon-amber">
                        <i class="fa-solid fa-user-clock"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.78rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">Deactivated</div>
                        <div style="font-family: 'Outfit', sans-serif; font-size: 1.75rem; font-weight: 800; color: #f59e0b;"><?= number_format($deactivated_users_count) ?></div>
                    </div>
                </div>

                <div class="user-stat-card">
                    <div class="user-stat-icon icon-rose">
                        <i class="fa-solid fa-ban"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.78rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">Blocked</div>
                        <div style="font-family: 'Outfit', sans-serif; font-size: 1.75rem; font-weight: 800; color: #f43f5e;"><?= number_format($blocked_users_count) ?></div>
                    </div>
                </div>

                <div class="user-stat-card">
                    <div class="user-stat-icon icon-purple">
                        <i class="fa-solid fa-user-tag"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.78rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;">Guests</div>
                        <div style="font-family: 'Outfit', sans-serif; font-size: 1.75rem; font-weight: 800; color: var(--primary-purple);"><?= number_format($guest_users_count) ?></div>
                    </div>
                </div>
            </div>

            <!-- Toolbar & Filter Tabs -->
            <div class="user-filter-bar">
                <div class="filter-tabs">
                    <a href="?status=all<?= !empty($search_query) ? '&search='.urlencode($search_query) : '' ?><?= !empty($filter_role) ? '&role='.urlencode($filter_role) : '' ?>" 
                       class="filter-tab-btn <?= $filter_status === 'all' ? 'active' : '' ?>">
                        <i class="fa-solid fa-list-ul"></i> All Users
                        <span class="tab-counter"><?= $total_users ?></span>
                    </a>
                    <a href="?status=active<?= !empty($search_query) ? '&search='.urlencode($search_query) : '' ?><?= !empty($filter_role) ? '&role='.urlencode($filter_role) : '' ?>" 
                       class="filter-tab-btn tab-active-users <?= $filter_status === 'active' ? 'active' : '' ?>">
                        <i class="fa-solid fa-circle-check"></i> Active
                        <span class="tab-counter"><?= $active_users_count ?></span>
                    </a>
                    <a href="?status=deactivated<?= !empty($search_query) ? '&search='.urlencode($search_query) : '' ?><?= !empty($filter_role) ? '&role='.urlencode($filter_role) : '' ?>" 
                       class="filter-tab-btn tab-deactivated-users <?= $filter_status === 'deactivated' ? 'active' : '' ?>">
                        <i class="fa-solid fa-user-clock"></i> Deactivated
                        <span class="tab-counter"><?= $deactivated_users_count ?></span>
                    </a>
                    <a href="?status=blocked<?= !empty($search_query) ? '&search='.urlencode($search_query) : '' ?><?= !empty($filter_role) ? '&role='.urlencode($filter_role) : '' ?>" 
                       class="filter-tab-btn tab-blocked-users <?= $filter_status === 'blocked' ? 'active' : '' ?>">
                        <i class="fa-solid fa-ban"></i> Blocked
                        <span class="tab-counter"><?= $blocked_users_count ?></span>
                    </a>
                </div>

                <form method="GET" action="user_management.php" class="search-input-group">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                    <div style="position: relative; width: 100%;">
                        <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
                        <input type="text" name="search" class="search-field" placeholder="Search by Name, Email, Phone, Profile ID..." value="<?= htmlspecialchars($search_query) ?>">
                    </div>
                    <?php if (!empty($search_query)): ?>
                        <a href="user_management.php?status=<?= urlencode($filter_status) ?>" class="admin-btn admin-btn-outline" title="Reset Search" style="padding: 0.55rem 0.8rem;">
                            <i class="fa-solid fa-xmark"></i>
                        </a>
                    <?php endif; ?>
                    <button type="submit" class="admin-btn admin-btn-primary" style="padding: 0.55rem 1rem; flex-shrink: 0;">
                        Filter
                    </button>
                </form>
            </div>

            <!-- Users Table -->
            <div class="admin-card" style="padding: 0; overflow: hidden;">
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th style="width: 140px;">Profile ID</th>
                                <th>User Profile</th>
                                <th>Contact Information</th>
                                <th style="width: 110px;">Role</th>
                                <th style="width: 140px;">Account Status</th>
                                <th style="width: 130px;">Registered</th>
                                <th style="width: 220px; text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($filtered_users)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                                        <i class="fa-solid fa-user-xmark" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem; display: block;"></i>
                                        <p style="font-size: 1.05rem; font-weight: 600; margin: 0; color: var(--text-sub);">No accounts found matching your filter criteria.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($filtered_users as $u): 
                                    $st = $u['status'];
                                    $r = strtolower($u['role'] ?? 'guest');
                                    $rc = $r === 'admin' ? 'role-admin' : ($r === 'staff' ? 'role-staff' : 'role-guest');
                                    $isSelf = intval($u['id'] ?? 0) === intval($_SESSION['user_id'] ?? 0) && ($r === strtolower($_SESSION['role'] ?? ''));
                                ?>
                                    <tr>
                                        <!-- Profile ID -->
                                        <td>
                                            <span style="font-family: monospace; font-weight: 700; color: var(--primary-purple); font-size: 0.95rem;">
                                                <?= htmlspecialchars($u['profile_id'] ?? ('USR-'.$u['id'])) ?>
                                            </span>
                                            <?php if ($isSelf): ?>
                                                <div style="margin-top: 3px;">
                                                    <span class="admin-badge admin-badge-pending" style="font-size: 0.65rem;">You (Active)</span>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- User Profile -->
                                        <td>
                                            <div style="font-weight: 700; color: var(--text-main); font-size: 0.95rem;">
                                                <?= htmlspecialchars($u['full_name']) ?>
                                            </div>
                                        </td>

                                        <!-- Contact Information -->
                                        <td>
                                            <div style="font-size: 0.88rem; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
                                                <i class="fa-solid fa-envelope" style="color: var(--text-muted); font-size: 0.8rem;"></i>
                                                <?= htmlspecialchars($u['email']) ?>
                                            </div>
                                            <?php if (!empty($u['phone'])): ?>
                                                <div style="font-size: 0.8rem; color: var(--text-sub); margin-top: 3px; display: flex; align-items: center; gap: 6px;">
                                                    <i class="fa-solid fa-phone" style="color: var(--text-muted); font-size: 0.8rem;"></i>
                                                    <?= htmlspecialchars($u['phone']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Role -->
                                        <td>
                                            <span class="role-badge <?= $rc ?>">
                                                <?= htmlspecialchars($r) ?>
                                            </span>
                                        </td>

                                        <!-- Account Status -->
                                        <td>
                                            <?php if ($st === 'blocked'): ?>
                                                <span class="status-badge status-blocked">
                                                    <i class="fa-solid fa-ban"></i> Blocked
                                                </span>
                                                <?php if (!empty($u['block_reason'])): ?>
                                                    <div class="block-reason-preview blocked" title="<?= htmlspecialchars($u['block_reason']) ?>">
                                                        <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($u['block_reason']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php elseif ($st === 'deactivated'): ?>
                                                <span class="status-badge status-deactivated">
                                                    <i class="fa-solid fa-user-clock"></i> Deactivated
                                                </span>
                                                <?php if (!empty($u['block_reason'])): ?>
                                                    <div class="block-reason-preview deactivated" title="<?= htmlspecialchars($u['block_reason']) ?>">
                                                        <i class="fa-solid fa-note-sticky"></i> <?= htmlspecialchars($u['block_reason']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="status-badge status-active">
                                                    <i class="fa-solid fa-circle-check"></i> Active
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Joined Date -->
                                        <td>
                                            <span style="font-size: 0.85rem; color: var(--text-sub);">
                                                <?= !empty($u['created_at']) ? date('M d, Y', strtotime($u['created_at'])) : '—' ?>
                                            </span>
                                        </td>

                                        <!-- Actions -->
                                        <td style="text-align: right;">
                                            <div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center; flex-wrap: nowrap;">
                                                <!-- View Profile -->
                                                <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" style="padding: 5px 9px;" title="View Profile" data-user="<?= htmlspecialchars(json_encode($u, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>" onclick="triggerViewUser(this)">
                                                    <i class="fa-solid fa-eye"></i>
                                                </button>

                                                <?php if ($isSelf): ?>
                                                    <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" style="padding: 5px 9px; opacity: 0.5; cursor: not-allowed;" title="Cannot block or deactivate own account" disabled>
                                                        <i class="fa-solid fa-shield"></i>
                                                    </button>
                                                <?php elseif ($st === 'active'): ?>
                                                    <!-- Deactivate Option -->
                                                    <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" style="padding: 5px 9px; color: #d97706; border-color: #fde68a;" title="Deactivate Account" data-user-id="<?= intval($u['id']) ?>" data-user-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8') ?>" data-user-email="<?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>" data-profile-id="<?= htmlspecialchars($u['profile_id'], ENT_QUOTES, 'UTF-8') ?>" data-user-role="<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>" onclick="triggerDeactivateModal(this)">
                                                        <i class="fa-solid fa-user-clock"></i> Deactivate
                                                    </button>

                                                    <!-- Block Option -->
                                                    <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" style="padding: 5px 9px; color: #f43f5e; border-color: #fecdd3;" title="Block Account" data-user-id="<?= intval($u['id']) ?>" data-user-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8') ?>" data-user-email="<?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>" data-profile-id="<?= htmlspecialchars($u['profile_id'], ENT_QUOTES, 'UTF-8') ?>" data-user-role="<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>" onclick="triggerBlockModal(this)">
                                                        <i class="fa-solid fa-ban"></i> Block
                                                    </button>

                                                <?php elseif ($st === 'deactivated'): ?>
                                                    <!-- Reactivate Option -->
                                                    <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" style="padding: 5px 9px; color: #10b981; border-color: #a7f3d0;" title="Reactivate Account" data-user-id="<?= intval($u['id']) ?>" data-user-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8') ?>" data-user-email="<?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>" data-user-role="<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>" data-prev-status="deactivated" onclick="triggerActivateModal(this)">
                                                        <i class="fa-solid fa-circle-check"></i> Activate
                                                    </button>

                                                    <!-- Block Option -->
                                                    <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" style="padding: 5px 9px; color: #f43f5e; border-color: #fecdd3;" title="Escalate to Block Account" data-user-id="<?= intval($u['id']) ?>" data-user-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8') ?>" data-user-email="<?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>" data-profile-id="<?= htmlspecialchars($u['profile_id'], ENT_QUOTES, 'UTF-8') ?>" data-user-role="<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>" onclick="triggerBlockModal(this)">
                                                        <i class="fa-solid fa-ban"></i> Block
                                                    </button>

                                                <?php elseif ($st === 'blocked'): ?>
                                                    <!-- Unblock Option -->
                                                    <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" style="padding: 5px 9px; color: #10b981; border-color: #a7f3d0;" title="Unblock Account" data-user-id="<?= intval($u['id']) ?>" data-user-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8') ?>" data-user-email="<?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>" data-user-role="<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>" data-prev-status="blocked" onclick="triggerActivateModal(this)">
                                                        <i class="fa-solid fa-unlock"></i> Unblock
                                                    </button>

                                                    <!-- Change to Deactivated -->
                                                    <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" style="padding: 5px 9px; color: #d97706; border-color: #fde68a;" title="Change to Deactivated" data-user-id="<?= intval($u['id']) ?>" data-user-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8') ?>" data-user-email="<?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>" data-profile-id="<?= htmlspecialchars($u['profile_id'], ENT_QUOTES, 'UTF-8') ?>" data-user-role="<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>" onclick="triggerDeactivateModal(this)">
                                                        <i class="fa-solid fa-user-clock"></i> Deactivate
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
        </main>
    </div>

    <!-- User View Details Modal -->
    <div class="modal-overlay" id="userModal" onclick="if(event.target===this) closeUserModal()">
        <div class="modal-container" style="max-width: 540px;">
            <div class="modal-header">
                <h3 class="modal-title" style="display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-id-card-clip" style="color: #60a5fa;"></i> User Details
                </h3>
                <button type="button" class="modal-close" onclick="closeUserModal()">&times;</button>
            </div>
            <div class="modal-body" id="userModalBody" style="padding: 1.5rem;"></div>
            <div style="padding: 1rem 1.5rem; background: #f8fafc; border-top: 1px solid var(--admin-card-border); display: flex; justify-content: space-between; align-items: center;" id="userModalFooter">
                <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" onclick="closeUserModal()">Close</button>
                <div id="userModalQuickActions" style="display: flex; gap: 8px;"></div>
            </div>
        </div>
    </div>

    <!-- Block Account Confirmation Modal -->
    <div class="modal-overlay" id="blockModal" onclick="if(event.target===this) closeBlockModal()">
        <div class="modal-container" style="max-width: 520px;">
            <div class="modal-header" style="background: #fff1f2; border-bottom: 1px solid #fecdd3;">
                <h3 class="modal-title" style="color: #e11d48; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-ban"></i> Block User Account
                </h3>
                <button type="button" class="modal-close" onclick="closeBlockModal()">&times;</button>
            </div>
            <form action="user_management.php" method="POST" id="blockUserForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="block_user">
                <input type="hidden" name="user_id" id="blockModalUserId" value="">
                <input type="hidden" name="user_name" id="blockModalUserName" value="">
                <input type="hidden" name="user_role" id="blockModalUserRole" value="guest">
                <input type="hidden" name="user_email" id="blockModalUserEmail" value="">

                <div class="modal-body" style="padding: 1.5rem;">
                    <div style="background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; padding: 1rem; margin-bottom: 1.25rem;">
                        <div style="font-size: 0.95rem; font-weight: 700; color: #9f1239; margin-bottom: 4px;" id="blockModalUserLabel"></div>
                        <div style="font-size: 0.82rem; color: #be123c;" id="blockModalUserMeta"></div>
                    </div>

                    <div style="font-size: 0.88rem; color: var(--text-sub); line-height: 1.45; margin-bottom: 1.25rem;">
                        <i class="fa-solid fa-triangle-exclamation" style="color: #f43f5e;"></i>
                        Blocking this account will immediately <strong>bar the user from logging in</strong>, revoke active guest sessions, and forbid any reservations.
                    </div>

                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label style="font-size: 0.85rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.4rem; display: block;">
                            Reason for Blocking Account: *
                        </label>
                        <select name="reason" id="blockReasonSelect" style="width: 100%; padding: 0.6rem 0.8rem; border: 1px solid var(--admin-card-border); border-radius: 8px; font-size: 0.9rem;" onchange="toggleBlockOtherReason(this)">
                            <option value="Violation of resort terms and policies">Violation of resort terms and policies</option>
                            <option value="Fraudulent payment attempts / Invalid GCash receipts">Fraudulent payment attempts / Invalid GCash receipts</option>
                            <option value="Multiple unpaid bookings / repeated no-shows">Multiple unpaid bookings / repeated no-shows</option>
                            <option value="Disruptive or abusive behavior on resort premises">Disruptive or abusive behavior on resort premises</option>
                            <option value="Security compromise / suspicious activity">Security compromise / suspicious activity</option>
                            <option value="Others">Others (Specify below)</option>
                        </select>
                    </div>

                    <div id="blockOtherReasonGroup" style="display: none;">
                        <label style="font-size: 0.85rem; font-weight: 700; color: #f43f5e; margin-bottom: 0.4rem; display: block;">
                            <i class="fa-solid fa-pen"></i> Specify Custom Reason: *
                        </label>
                        <input type="text" name="other_reason" id="blockOtherReasonInput" style="width: 100%; padding: 0.6rem 0.8rem; border: 1px solid #f43f5e; border-radius: 8px; font-size: 0.9rem;" placeholder="e.g. Repeated violation of noise curfews...">
                    </div>
                </div>

                <div style="padding: 1rem 1.5rem; background: #f8fafc; border-top: 1px solid var(--admin-card-border); display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" onclick="closeBlockModal()">Cancel</button>
                    <button type="submit" class="admin-btn admin-btn-danger admin-btn-sm" style="background: #f43f5e; border-color: #f43f5e; color: #fff;">
                        <i class="fa-solid fa-ban"></i> Confirm Block Account
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Deactivate Account Confirmation Modal -->
    <div class="modal-overlay" id="deactivateModal" onclick="if(event.target===this) closeDeactivateModal()">
        <div class="modal-container" style="max-width: 520px;">
            <div class="modal-header" style="background: #fffbeb; border-bottom: 1px solid #fde68a;">
                <h3 class="modal-title" style="color: #b45309; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-user-clock"></i> Deactivate User Account
                </h3>
                <button type="button" class="modal-close" onclick="closeDeactivateModal()">&times;</button>
            </div>
            <form action="user_management.php" method="POST" id="deactivateUserForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="deactivate_user">
                <input type="hidden" name="user_id" id="deactivateModalUserId" value="">
                <input type="hidden" name="user_name" id="deactivateModalUserName" value="">
                <input type="hidden" name="user_role" id="deactivateModalUserRole" value="guest">
                <input type="hidden" name="user_email" id="deactivateModalUserEmail" value="">

                <div class="modal-body" style="padding: 1.5rem;">
                    <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 1rem; margin-bottom: 1.25rem;">
                        <div style="font-size: 0.95rem; font-weight: 700; color: #92400e; margin-bottom: 4px;" id="deactivateModalUserLabel"></div>
                        <div style="font-size: 0.82rem; color: #b45309;" id="deactivateModalUserMeta"></div>
                    </div>

                    <div style="font-size: 0.88rem; color: var(--text-sub); line-height: 1.45; margin-bottom: 1.25rem;">
                        <i class="fa-solid fa-circle-info" style="color: #f59e0b;"></i>
                        Deactivating this account will temporarily suspend login permissions and booking privileges until reactivated by management.
                    </div>

                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label style="font-size: 0.85rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.4rem; display: block;">
                            Reason for Deactivating Account: *
                        </label>
                        <select name="reason" id="deactivateReasonSelect" style="width: 100%; padding: 0.6rem 0.8rem; border: 1px solid var(--admin-card-border); border-radius: 8px; font-size: 0.9rem;" onchange="toggleDeactivateOtherReason(this)">
                            <option value="Customer requested account deactivation">Customer requested account deactivation</option>
                            <option value="Temporary account suspension / administrative hold">Temporary account suspension / administrative hold</option>
                            <option value="Prolonged account inactivity / dormant account">Prolonged account inactivity / dormant account</option>
                            <option value="Pending identity verification / incomplete profile">Pending identity verification / incomplete profile</option>
                            <option value="Administrative review">Administrative review</option>
                            <option value="Others">Others (Specify below)</option>
                        </select>
                    </div>

                    <div id="deactivateOtherReasonGroup" style="display: none;">
                        <label style="font-size: 0.85rem; font-weight: 700; color: #d97706; margin-bottom: 0.4rem; display: block;">
                            <i class="fa-solid fa-pen"></i> Specify Custom Reason: *
                        </label>
                        <input type="text" name="other_reason" id="deactivateOtherReasonInput" style="width: 100%; padding: 0.6rem 0.8rem; border: 1px solid #f59e0b; border-radius: 8px; font-size: 0.9rem;" placeholder="e.g. Account placed on temporary seasonal hold...">
                    </div>
                </div>

                <div style="padding: 1rem 1.5rem; background: #f8fafc; border-top: 1px solid var(--admin-card-border); display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" onclick="closeDeactivateModal()">Cancel</button>
                    <button type="submit" class="admin-btn admin-btn-warning admin-btn-sm" style="background: #f59e0b; border-color: #f59e0b; color: #fff;">
                        <i class="fa-solid fa-user-clock"></i> Confirm Deactivate
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Activate / Unblock Confirmation Modal -->
    <div class="modal-overlay" id="activateModal" onclick="if(event.target===this) closeActivateModal()">
        <div class="modal-container" style="max-width: 480px;">
            <div class="modal-header" style="background: #ecfdf5; border-bottom: 1px solid #a7f3d0;">
                <h3 class="modal-title" style="color: #059669; display: flex; align-items: center; gap: 8px;" id="activateModalTitle">
                    <i class="fa-solid fa-circle-check"></i> Restore Account to Active
                </h3>
                <button type="button" class="modal-close" onclick="closeActivateModal()">&times;</button>
            </div>
            <form action="user_management.php" method="POST" id="activateUserForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="activate_user">
                <input type="hidden" name="user_id" id="activateModalUserId" value="">
                <input type="hidden" name="user_name" id="activateModalUserName" value="">
                <input type="hidden" name="user_role" id="activateModalUserRole" value="guest">
                <input type="hidden" name="user_email" id="activateModalUserEmail" value="">

                <div class="modal-body" style="padding: 1.5rem;">
                    <p style="margin: 0 0 1rem 0; font-size: 0.95rem; color: var(--text-main);">
                        Are you sure you want to restore the account for <strong id="activateModalUserLabel"></strong> back to <strong>ACTIVE</strong>?
                    </p>
                    <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 0.85rem 1rem; font-size: 0.85rem; color: #065f46;">
                        <i class="fa-solid fa-circle-check"></i>
                        The user will be immediately permitted to sign in, manage their reservations, and book resort accommodations.
                    </div>
                </div>

                <div style="padding: 1rem 1.5rem; background: #f8fafc; border-top: 1px solid var(--admin-card-border); display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" onclick="closeActivateModal()">Cancel</button>
                    <button type="submit" class="admin-btn admin-btn-success admin-btn-sm" style="background: #10b981; border-color: #10b981; color: #fff;" id="activateModalSubmitBtn">
                        <i class="fa-solid fa-circle-check"></i> Confirm Restore Active
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Current user context
    const currentAdminId = <?= intval($_SESSION['user_id'] ?? 0) ?>;
    const currentAdminRole = '<?= strtolower($_SESSION['role'] ?? '') ?>';
    let currentModalUserData = null;

    // Safe button event handlers
    function triggerViewUser(btn) {
        if (!btn || !btn.dataset.user) return;
        try {
            const u = JSON.parse(btn.dataset.user);
            viewUser(u);
        } catch (e) {
            console.error("Failed to parse user data", e);
        }
    }

    function triggerBlockModal(btn) {
        if (!btn) return;
        const d = btn.dataset;
        openBlockModal(d.userId, d.userName, d.userEmail, d.profileId, d.userRole);
    }

    function triggerDeactivateModal(btn) {
        if (!btn) return;
        const d = btn.dataset;
        openDeactivateModal(d.userId, d.userName, d.userEmail, d.profileId, d.userRole);
    }

    function triggerActivateModal(btn) {
        if (!btn) return;
        const d = btn.dataset;
        openActivateModal(d.userId, d.userName, d.userRole, d.userEmail, d.prevStatus);
    }

    function openBlockModalFromCurrent() {
        if (!currentModalUserData) return;
        const u = currentModalUserData;
        openBlockModal(u.id, u.full_name || '', u.email || '', u.profile_id || ('USR-' + u.id), u.role || 'guest');
    }

    function openDeactivateModalFromCurrent() {
        if (!currentModalUserData) return;
        const u = currentModalUserData;
        openDeactivateModal(u.id, u.full_name || '', u.email || '', u.profile_id || ('USR-' + u.id), u.role || 'guest');
    }

    function openActivateModalFromCurrent(prevStatus) {
        if (!currentModalUserData) return;
        const u = currentModalUserData;
        openActivateModal(u.id, u.full_name || '', u.role || 'guest', u.email || '', prevStatus);
    }

    // --- USER PROFILE MODAL ---
    function viewUser(u) {
        currentModalUserData = u;
        let status = (u.status || 'active').toLowerCase();
        let role = (u.role || 'guest').toLowerCase();
        let roleBadges = {
            'admin': '<span class="role-badge role-admin">admin</span>',
            'staff': '<span class="role-badge role-staff">staff</span>',
            'guest': '<span class="role-badge role-guest">guest</span>'
        };
        let roleHtml = roleBadges[role] || roleBadges['guest'];

        let statusHtml = '';
        if (status === 'blocked') {
            statusHtml = '<span class="status-badge status-blocked"><i class="fa-solid fa-ban"></i> Blocked</span>';
        } else if (status === 'deactivated') {
            statusHtml = '<span class="status-badge status-deactivated"><i class="fa-solid fa-user-clock"></i> Deactivated</span>';
        } else {
            statusHtml = '<span class="status-badge status-active"><i class="fa-solid fa-circle-check"></i> Active</span>';
        }

        let safeName = (u.full_name || '').replace(/</g, "&lt;");
        let safeEmail = (u.email || '').replace(/</g, "&lt;");
        let safePhone = (u.phone || 'N/A').replace(/</g, "&lt;");
        let safeEmergency = (u.emergency_contact_name || 'N/A').replace(/</g, "&lt;");
        let safeEmPhone = (u.emergency_contact_phone || 'N/A').replace(/</g, "&lt;");
        let safeIdType = (u.id_type || 'N/A').replace(/</g, "&lt;");
        let safeIdNum = (u.id_number || 'N/A').replace(/</g, "&lt;");

        let statusBanner = '';
        if (status === 'blocked') {
            let reason = (u.block_reason || 'Policy violation').replace(/</g, "&lt;");
            let blockedBy = (u.blocked_by || 'Admin').replace(/</g, "&lt;");
            statusBanner = `
                <div style="background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; padding: 0.85rem 1rem; margin-top: 1rem;">
                    <div style="font-weight: 700; color: #9f1239; font-size: 0.85rem; margin-bottom: 2px;">
                        <i class="fa-solid fa-ban"></i> Account Blocked
                    </div>
                    <div style="font-size: 0.85rem; color: #be123c;">
                        <strong>Reason:</strong> ${reason}
                    </div>
                    <div style="font-size: 0.75rem; color: #9f1239; margin-top: 4px;">
                        Action by: <strong>${blockedBy}</strong> ${u.blocked_at ? ' on ' + u.blocked_at : ''}
                    </div>
                </div>
            `;
        } else if (status === 'deactivated') {
            let reason = (u.block_reason || 'Account deactivated by administrator').replace(/</g, "&lt;");
            let deactivatedBy = (u.blocked_by || 'Admin').replace(/</g, "&lt;");
            statusBanner = `
                <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 0.85rem 1rem; margin-top: 1rem;">
                    <div style="font-weight: 700; color: #92400e; font-size: 0.85rem; margin-bottom: 2px;">
                        <i class="fa-solid fa-user-clock"></i> Account Deactivated
                    </div>
                    <div style="font-size: 0.85rem; color: #b45309;">
                        <strong>Reason:</strong> ${reason}
                    </div>
                    <div style="font-size: 0.75rem; color: #92400e; margin-top: 4px;">
                        Action by: <strong>${deactivatedBy}</strong> ${u.blocked_at ? ' on ' + u.blocked_at : ''}
                    </div>
                </div>
            `;
        }

        document.getElementById('userModalBody').innerHTML = `
            <div style="margin-bottom: 1.25rem; padding-bottom: 1rem; border-bottom: 1px solid var(--admin-card-border);">
                <div>
                    <h4 style="margin: 0; font-size: 1.15rem; color: var(--text-main); font-family: 'Outfit', sans-serif;">${safeName}</h4>
                    <div style="display: flex; gap: 6px; margin-top: 6px; align-items: center;">
                        <span style="font-family: monospace; font-size: 0.82rem; color: var(--primary-purple); font-weight: 700;">${u.profile_id || ('USR-'+u.id)}</span>
                        ${roleHtml}
                        ${statusHtml}
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div style="background: var(--admin-bg); padding: 0.75rem 1rem; border-radius: 8px;">
                    <small style="color: var(--text-muted); display: block; font-size: 0.75rem; text-transform: uppercase;">Email Address</small>
                    <span style="color: var(--text-main); font-size: 0.88rem; word-break: break-all;">${safeEmail}</span>
                </div>
                <div style="background: var(--admin-bg); padding: 0.75rem 1rem; border-radius: 8px;">
                    <small style="color: var(--text-muted); display: block; font-size: 0.75rem; text-transform: uppercase;">Phone Number</small>
                    <span style="color: var(--text-main); font-size: 0.88rem;">${safePhone}</span>
                </div>
                <div style="background: var(--admin-bg); padding: 0.75rem 1rem; border-radius: 8px;">
                    <small style="color: var(--text-muted); display: block; font-size: 0.75rem; text-transform: uppercase;">Emergency Contact</small>
                    <span style="color: var(--text-main); font-size: 0.88rem;">${safeEmergency}</span>
                    <small style="display: block; color: var(--text-muted); font-size: 0.75rem;">${safeEmPhone}</small>
                </div>
                <div style="background: var(--admin-bg); padding: 0.75rem 1rem; border-radius: 8px;">
                    <small style="color: var(--text-muted); display: block; font-size: 0.75rem; text-transform: uppercase;">Identification</small>
                    <span style="color: var(--text-main); font-size: 0.88rem;">${safeIdType}</span>
                    <small style="display: block; color: var(--text-muted); font-size: 0.75rem;">${safeIdNum}</small>
                </div>
            </div>

            ${statusBanner}
        `;

        // Configure Quick Actions in Modal Footer
        let isSelf = (parseInt(u.id) === currentAdminId && role === currentAdminRole);
        let quickActionsEl = document.getElementById('userModalQuickActions');
        quickActionsEl.innerHTML = '';

        if (!isSelf) {
            if (status === 'active') {
                quickActionsEl.innerHTML = `
                    <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" style="color: #d97706; border-color: #fde68a;" onclick="closeUserModal(); openDeactivateModalFromCurrent()">
                        <i class="fa-solid fa-user-clock"></i> Deactivate
                    </button>
                    <button type="button" class="admin-btn admin-btn-danger admin-btn-sm" style="background:#f43f5e; border-color:#f43f5e; color:#fff;" onclick="closeUserModal(); openBlockModalFromCurrent()">
                        <i class="fa-solid fa-ban"></i> Block
                    </button>
                `;
            } else if (status === 'deactivated') {
                quickActionsEl.innerHTML = `
                    <button type="button" class="admin-btn admin-btn-success admin-btn-sm" style="background:#10b981; border-color:#10b981; color:#fff;" onclick="closeUserModal(); openActivateModalFromCurrent('deactivated')">
                        <i class="fa-solid fa-circle-check"></i> Activate
                    </button>
                    <button type="button" class="admin-btn admin-btn-danger admin-btn-sm" style="background:#f43f5e; border-color:#f43f5e; color:#fff;" onclick="closeUserModal(); openBlockModalFromCurrent()">
                        <i class="fa-solid fa-ban"></i> Block
                    </button>
                `;
            } else if (status === 'blocked') {
                quickActionsEl.innerHTML = `
                    <button type="button" class="admin-btn admin-btn-success admin-btn-sm" style="background:#10b981; border-color:#10b981; color:#fff;" onclick="closeUserModal(); openActivateModalFromCurrent('blocked')">
                        <i class="fa-solid fa-unlock"></i> Unblock
                    </button>
                    <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" style="color: #d97706; border-color: #fde68a;" onclick="closeUserModal(); openDeactivateModalFromCurrent()">
                        <i class="fa-solid fa-user-clock"></i> Deactivate
                    </button>
                `;
            }
        }

        document.getElementById('userModal').classList.add('active');
    }

    function closeUserModal() {
        document.getElementById('userModal').classList.remove('active');
    }

    // --- BLOCK MODAL FUNCTIONS ---
    function openBlockModal(userId, userName, userEmail, profileId, userRole) {
        document.getElementById('blockModalUserId').value = userId;
        document.getElementById('blockModalUserName').value = userName;
        document.getElementById('blockModalUserRole').value = userRole || 'guest';
        document.getElementById('blockModalUserEmail').value = userEmail || '';
        document.getElementById('blockModalUserLabel').textContent = userName + ' (' + profileId + ') [' + (userRole || 'guest').toUpperCase() + ']';
        document.getElementById('blockModalUserMeta').textContent = 'Email: ' + userEmail;
        document.getElementById('blockReasonSelect').value = 'Violation of resort terms and policies';
        document.getElementById('blockOtherReasonGroup').style.display = 'none';
        document.getElementById('blockOtherReasonInput').value = '';
        document.getElementById('blockModal').classList.add('active');
    }

    function closeBlockModal() {
        document.getElementById('blockModal').classList.remove('active');
    }

    function toggleBlockOtherReason(selectEl) {
        let otherGroup = document.getElementById('blockOtherReasonGroup');
        if (selectEl.value === 'Others') {
            otherGroup.style.display = 'block';
            document.getElementById('blockOtherReasonInput').focus();
        } else {
            otherGroup.style.display = 'none';
        }
    }

    // --- DEACTIVATE MODAL FUNCTIONS ---
    function openDeactivateModal(userId, userName, userEmail, profileId, userRole) {
        document.getElementById('deactivateModalUserId').value = userId;
        document.getElementById('deactivateModalUserName').value = userName;
        document.getElementById('deactivateModalUserRole').value = userRole || 'guest';
        document.getElementById('deactivateModalUserEmail').value = userEmail || '';
        document.getElementById('deactivateModalUserLabel').textContent = userName + ' (' + profileId + ') [' + (userRole || 'guest').toUpperCase() + ']';
        document.getElementById('deactivateModalUserMeta').textContent = 'Email: ' + userEmail;
        document.getElementById('deactivateReasonSelect').value = 'Customer requested account deactivation';
        document.getElementById('deactivateOtherReasonGroup').style.display = 'none';
        document.getElementById('deactivateOtherReasonInput').value = '';
        document.getElementById('deactivateModal').classList.add('active');
    }

    function closeDeactivateModal() {
        document.getElementById('deactivateModal').classList.remove('active');
    }

    function toggleDeactivateOtherReason(selectEl) {
        let otherGroup = document.getElementById('deactivateOtherReasonGroup');
        if (selectEl.value === 'Others') {
            otherGroup.style.display = 'block';
            document.getElementById('deactivateOtherReasonInput').focus();
        } else {
            otherGroup.style.display = 'none';
        }
    }

    // --- ACTIVATE / UNBLOCK MODAL FUNCTIONS ---
    function openActivateModal(userId, userName, userRole, userEmail, previousStatus) {
        document.getElementById('activateModalUserId').value = userId;
        document.getElementById('activateModalUserName').value = userName;
        document.getElementById('activateModalUserRole').value = userRole || 'guest';
        document.getElementById('activateModalUserEmail').value = userEmail || '';
        document.getElementById('activateModalUserLabel').textContent = userName + ' (' + (userRole || 'guest').toUpperCase() + ')';
        
        let titleEl = document.getElementById('activateModalTitle');
        let submitBtnEl = document.getElementById('activateModalSubmitBtn');

        if (previousStatus === 'blocked') {
            titleEl.innerHTML = '<i class="fa-solid fa-unlock"></i> Unblock User Account';
            submitBtnEl.innerHTML = '<i class="fa-solid fa-unlock"></i> Confirm Unblock';
        } else {
            titleEl.innerHTML = '<i class="fa-solid fa-circle-check"></i> Restore Account to Active';
            submitBtnEl.innerHTML = '<i class="fa-solid fa-circle-check"></i> Confirm Activate';
        }

        document.getElementById('activateModal').classList.add('active');
    }

    function closeActivateModal() {
        document.getElementById('activateModal').classList.remove('active');
    }
    </script>
</body>
</html>