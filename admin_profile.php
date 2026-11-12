<?php
/**
 * admin_profile.php — Purple Bamboo Resort Personnel Profile Management
 * 
 * Provides unified, secure self-service profile & credentials management
 * for both Administrators and Staff members.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/staff_store.php';

// Strict Role-Based Access Control: Admin & Staff only
if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), ['admin', 'staff'])) {
    $err = (isset($_SESSION['role']) && $_SESSION['role'] === 'guest') ? 'guest_restricted' : 'unauthorized';
    header("Location: admin-login.php?error=" . $err);
    exit();
}

$viewer_role = strtolower($_SESSION['role']);
if ($viewer_role === 'admin') {
    header("Location: settings.php?tab=profile");
    exit();
} else {
    header("Location: staff_profile.php");
    exit();
}

/**
 * Profile Flash message helper
 */
function set_profile_flash($type, $msg) {
    $_SESSION['profile_flash'] = [
        'type' => $type,
        'msg'  => $msg
    ];
}

function get_profile_flash() {
    $f = $_SESSION['profile_flash'] ?? null;
    unset($_SESSION['profile_flash']);
    return $f;
}

// ============================================================
// POST REQUEST PROCESSING
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $action = $_POST['action'] ?? '';

    // 1. UPDATE PERSONAL DETAILS
    if ($action === 'update_profile') {
        $profileData = [
            'first_name'  => trim($_POST['first_name'] ?? ''),
            'middle_name' => trim($_POST['middle_name'] ?? ''),
            'last_name'   => trim($_POST['last_name'] ?? ''),
            'suffix'      => trim($_POST['suffix'] ?? ''),
            'phone'       => trim($_POST['phone'] ?? '')
        ];

        $res = StaffStore::updatePersonnel($viewer_email, $profileData);
        if ($res['success']) {
            // Update active session values
            $_SESSION['first_name']  = $profileData['first_name'];
            $_SESSION['middle_name'] = $profileData['middle_name'];
            $_SESSION['last_name']   = $profileData['last_name'];
            $_SESSION['suffix']      = $profileData['suffix'];
            $_SESSION['phone']       = $profileData['phone'];
            $_SESSION['full_name']   = $res['full_name'] ?? StaffStore::buildFullName($profileData['first_name'], $profileData['middle_name'], $profileData['last_name'], $profileData['suffix']);
            $_SESSION['username']    = $_SESSION['full_name'];

            set_profile_flash('success', 'Your personal profile details have been updated successfully!');
        } else {
            set_profile_flash('error', $res['error'] ?? 'Failed to update profile details.');
        }
        header("Location: admin_profile.php");
        exit();
    }

    // 2. CHANGE PASSWORD
    elseif ($action === 'change_password') {
        $curPass  = $_POST['current_password'] ?? '';
        $newPass  = $_POST['new_password'] ?? '';
        $confPass = $_POST['confirm_password'] ?? '';

        $res = StaffStore::changePassword($viewer_email, $curPass, $newPass, $confPass);
        if ($res['success']) {
            $msg = 'Your login password has been changed successfully!';
            try {
                require_once __DIR__ . '/includes/EmailHelper.php';
                $curUser = StaffStore::findByEmail($viewer_email);
                $userName = !empty($res['full_name']) ? $res['full_name'] : ($curUser['full_name'] ?? $viewer_name);
                $userPid  = !empty($res['profile_id']) ? $res['profile_id'] : ($curUser['profile_id'] ?? ($_SESSION['profile_id'] ?? 'PBR-STF-00001'));
                $emailRes = EmailHelper::sendStaffCredentialsEmail(
                    $viewer_email,
                    $userName,
                    $userPid,
                    $newPass,
                    'updated',
                    $viewer_role
                );
                if (!empty($emailRes['success'])) {
                    $msg .= " An email notification with your updated credentials was dispatched to {$viewer_email}.";
                }
            } catch (Exception $e) {
                error_log("Failed to auto-send password update email: " . $e->getMessage());
            }
            set_profile_flash('success', $msg);
        } else {
            set_profile_flash('error', $res['error'] ?? 'Failed to change password. Please check your current password.');
        }
        header("Location: admin_profile.php");
        exit();
    }
}

// Fetch current user details
$flash = get_profile_flash();
$current_user = StaffStore::findByEmail($viewer_email) ?: [
    'first_name'  => $_SESSION['first_name'] ?? ($is_admin ? 'Super' : 'Front Desk'),
    'middle_name' => $_SESSION['middle_name'] ?? '',
    'last_name'   => $_SESSION['last_name'] ?? ($is_admin ? 'Admin' : 'Staff'),
    'suffix'      => $_SESSION['suffix'] ?? '',
    'full_name'   => $viewer_name,
    'email'       => $viewer_email,
    'phone'       => $_SESSION['phone'] ?? '09171234567',
    'profile_id'  => $_SESSION['profile_id'] ?? ($is_admin ? 'PBR-ADM-00001' : 'PBR-STF-00002'),
    'role'        => $viewer_role,
    'department'  => ($viewer_role === 'staff') ? 'Front Desk Operations' : 'Resort Management',
    'created_at'  => date('Y-m-d')
];

$fn = $current_user['first_name'] ?: ($is_admin ? 'A' : 'S');
$ln = $current_user['last_name'] ?: 'P';
$userInitials = strtoupper(substr($fn, 0, 1) . substr($ln, 0, 1));
$displayPid = $current_user['profile_id'] ?: ($is_admin ? 'PBR-ADM-00001' : 'PBR-STF-00002');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_admin ? 'Administrator Profile' : 'Staff Profile' ?> | Purple Bamboo Resort</title>
    
    <!-- Core Admin Styles -->
    <link rel="stylesheet" href="admin-style.css?v=<?= time() ?>">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --pf-gold: #fbbf24;
            --pf-gold-glow: rgba(251, 191, 36, 0.35);
            --pf-purple: #7c3aed;
            --pf-purple-glow: rgba(124, 58, 237, 0.3);
            --pf-navy-dark: #090e1a;
            --pf-card-bg: #ffffff;
            --pf-card-border: #e2e8f0;
            --pf-text-main: #0f172a;
            --pf-text-sub: #64748b;
        }

        body.admin-layout-body {
            background: #f8fafc;
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--pf-text-main);
            margin: 0;
            padding: 0;
        }

        .profile-container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 1.25rem 1.5rem 3.5rem;
            box-sizing: border-box;
        }

        /* Hero Banner Card */
        .profile-hero-card {
            background: linear-gradient(135deg, #090e1a 0%, #1e113a 50%, #0d1527 100%);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 20px;
            padding: 2rem 2.25rem;
            color: #ffffff;
            box-shadow: 0 12px 35px rgba(11, 17, 32, 0.25);
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        .profile-hero-card::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 450px;
            height: 450px;
            background: radial-gradient(circle, rgba(124, 58, 237, 0.25) 0%, transparent 70%);
            pointer-events: none;
        }
        .profile-hero-content {
            display: flex;
            align-items: center;
            gap: 24px;
            position: relative;
            z-index: 2;
            flex-wrap: wrap;
        }
        .profile-avatar-large {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: linear-gradient(135deg, #e0129c 0%, #00c2ff 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Outfit', sans-serif;
            font-size: 2.2rem;
            font-weight: 800;
            border: 4px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 24px rgba(224, 18, 156, 0.35);
            flex-shrink: 0;
            letter-spacing: 1px;
        }
        .profile-meta-block {
            flex: 1;
            min-width: 250px;
        }
        .profile-meta-title-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 6px;
        }
        .profile-user-fullname {
            font-family: 'Outfit', sans-serif;
            font-size: 1.85rem;
            font-weight: 800;
            color: #ffffff;
            margin: 0;
            letter-spacing: -0.02em;
        }
        .profile-badge-role {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .profile-badge-admin {
            background: rgba(251, 191, 36, 0.2);
            color: #fbbf24;
            border: 1px solid rgba(251, 191, 36, 0.4);
        }
        .profile-badge-staff {
            background: rgba(124, 58, 237, 0.25);
            color: #c4b5fd;
            border: 1px solid rgba(124, 58, 237, 0.45);
        }
        .profile-chips-row {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-top: 10px;
            font-size: 0.88rem;
            color: #cbd5e1;
        }
        .profile-chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: rgba(255, 255, 255, 0.07);
            padding: 5px 12px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .profile-chip i {
            color: #fbbf24;
            font-size: 0.85rem;
        }

        /* Form Cards Layout */
        .profile-cards-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.75rem;
        }
        @media (max-width: 900px) {
            .profile-cards-grid {
                grid-template-columns: 1fr;
            }
        }

        .pf-card {
            background: #ffffff;
            border: 1px solid var(--pf-card-border);
            border-radius: 18px;
            padding: 2rem;
            box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.05);
            position: relative;
            transition: box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .pf-card:hover {
            box-shadow: 0 8px 30px -4px rgba(15, 23, 42, 0.08);
            border-color: rgba(203, 213, 225, 0.9);
        }
        .pf-card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .pf-card-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: rgba(124, 58, 237, 0.1);
            color: var(--pf-purple);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        .pf-card-icon.gold {
            background: rgba(245, 158, 11, 0.12);
            color: #d97706;
        }
        .pf-card-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.22rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .pf-card-subtitle {
            font-size: 0.84rem;
            color: var(--pf-text-sub);
            margin: 2px 0 0 0;
        }

        /* Form Controls */
        .pf-form-group {
            margin-bottom: 1.25rem;
        }
        .pf-form-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 500px) {
            .pf-form-row-2 {
                grid-template-columns: 1fr;
            }
        }
        .pf-label {
            display: block;
            font-size: 0.84rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }
        .pf-input, .pf-select {
            width: 100%;
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            padding: 0.68rem 0.95rem;
            font-size: 0.92rem;
            color: #0f172a;
            font-family: inherit;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }
        .pf-input:focus, .pf-select:focus {
            outline: none;
            background: #ffffff;
            border-color: var(--pf-purple);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15);
        }
        .pf-input-readonly {
            background: #f1f5f9 !important;
            color: #64748b !important;
            cursor: not-allowed;
            border-color: #e2e8f0 !important;
        }
        .pf-help {
            font-size: 0.78rem;
            color: #64748b;
            margin-top: 4px;
            display: block;
        }

        /* Password Wrapper with View Eye Toggle */
        .pf-pwd-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        .pf-pwd-wrapper input {
            padding-right: 42px;
        }
        .pf-pwd-toggle {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 0.95rem;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.15s ease;
        }
        .pf-pwd-toggle:hover {
            color: #475569;
        }

        /* Password Strength Bar */
        .pf-pwd-strength-bar {
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            margin-top: 6px;
            overflow: hidden;
        }
        .pf-pwd-strength-fill {
            height: 100%;
            width: 0%;
            transition: width 0.25s ease, background-color 0.25s ease;
            border-radius: 2px;
        }

        /* Buttons */
        .pf-btn-purple {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 0.72rem 1.6rem;
            font-family: 'Outfit', sans-serif;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(124, 58, 237, 0.3);
            transition: all 0.2s ease;
        }
        .pf-btn-purple:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(124, 58, 237, 0.45);
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }
        .pf-btn-gold {
            background: linear-gradient(135deg, #d97706, #f59e0b);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 0.72rem 1.6rem;
            font-family: 'Outfit', sans-serif;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.35);
            transition: all 0.2s ease;
        }
        .pf-btn-gold:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.45);
        }

        /* Alert notifications */
        .pf-alert {
            padding: 1rem 1.25rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.92rem;
            font-weight: 600;
        }
        .pf-alert-success {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }
        .pf-alert-error {
            background: #fff1f2;
            border: 1px solid #fecdd3;
            color: #9f1239;
        }
    </style>
</head>
<body class="admin-layout-body">

    <!-- Admin Left Navigation Sidebar & System Modals -->
    <?php include __DIR__ . '/admin-nav.php'; ?>

    <div class="admin-main-wrapper">
        <!-- Top Executive Header -->
        <header class="admin-top-header">
            <div class="admin-header-left">
                <button type="button" class="admin-mobile-toggle-btn" onclick="toggleAdminSidebar()" aria-label="Open Navigation Menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="admin-header-context">
                    <div class="admin-header-title-row">
                        <div class="admin-header-title-icon">
                            <i class="fa-solid fa-user-circle" style="color: var(--primary-glow);"></i>
                        </div>
                        <span class="admin-header-page-heading"><?= $is_admin ? 'Administrator Profile' : 'Staff Profile' ?></span>
                    </div>
                </div>
            </div>

            <div class="admin-header-actions">
                <?php if ($is_admin): ?>
                    <a href="settings.php" class="admin-btn admin-btn-outline admin-btn-sm" style="margin-right: 4px;">
                        <i class="fa-solid fa-gear"></i> System Settings
                    </a>
                <?php else: ?>
                    <a href="bookings.php" class="admin-btn admin-btn-outline admin-btn-sm" style="margin-right: 4px;">
                        <i class="fa-solid fa-arrow-left"></i> Bookings Console
                    </a>
                <?php endif; ?>
                <a href="admin_profile.php" class="hs-user-badge" title="My Profile">
                    <span class="hs-user-avatar"><?= $userInitials ?></span>
                    <span class="hs-user-name"><?= htmlspecialchars($viewer_name) ?></span>
                </a>
                <a href="logout.php" class="hs-btn-logout" onclick="openLogoutModal(event)" title="Sign Out">
                    <i class="fa-solid fa-sign-out-alt"></i>
                </a>
            </div>
        </header>

        <main class="profile-container">

            <!-- Flash Alert Notification -->
            <?php if (!empty($flash)): ?>
                <div class="pf-alert pf-alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
                    <i class="fa-solid fa-circle-<?= $flash['type'] === 'success' ? 'check' : 'exclamation' ?>" style="font-size: 1.25rem;"></i>
                    <div style="flex: 1;"><?= htmlspecialchars($flash['msg']) ?></div>
                    <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; color:inherit; cursor:pointer; font-size:1.1rem;">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Hero Profile Overview Banner -->
            <div class="profile-hero-card">
                <div class="profile-hero-content">
                    <div class="profile-avatar-large">
                        <?= $userInitials ?>
                    </div>
                    <div class="profile-meta-block">
                        <div class="profile-meta-title-row">
                            <h1 class="profile-user-fullname"><?= htmlspecialchars($current_user['full_name']) ?></h1>
                            <span class="profile-badge-role <?= $is_admin ? 'profile-badge-admin' : 'profile-badge-staff' ?>">
                                <i class="fa-solid <?= $is_admin ? 'fa-shield-halved' : 'fa-id-card' ?>"></i>
                                <?= $is_admin ? 'Resort Administrator' : 'Front Desk Staff' ?>
                            </span>
                        </div>
                        <div class="profile-chips-row">
                            <span class="profile-chip" title="Personnel Profile ID">
                                <i class="fa-solid fa-fingerprint"></i>
                                <strong><?= htmlspecialchars($displayPid) ?></strong>
                            </span>
                            <span class="profile-chip" title="Email Address">
                                <i class="fa-solid fa-envelope"></i>
                                <?= htmlspecialchars($current_user['email']) ?>
                            </span>
                            <span class="profile-chip" title="Contact Phone">
                                <i class="fa-solid fa-phone"></i>
                                <?= htmlspecialchars($current_user['phone'] ?: 'No phone registered') ?>
                            </span>
                            <span class="profile-chip" title="Account Status" style="color: #34d399;">
                                <i class="fa-solid fa-circle" style="font-size: 0.5rem; color: #10b981;"></i> Active Account
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2-Column Grid: Personal Details & Password Security -->
            <div class="profile-cards-grid">

                <!-- Card 1: Personal Details -->
                <div class="pf-card">
                    <div class="pf-card-header">
                        <div class="pf-card-icon">
                            <i class="fa-solid fa-user-pen"></i>
                        </div>
                        <div>
                            <h2 class="pf-card-title">Personal Information</h2>
                            <p class="pf-card-subtitle">Manage your personnel name and contact phone number.</p>
                        </div>
                    </div>

                    <form action="admin_profile.php" method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_profile">

                        <div class="pf-form-row-2">
                            <div class="pf-form-group">
                                <label class="pf-label">First Name *</label>
                                <input type="text" name="first_name" value="<?= htmlspecialchars($current_user['first_name']) ?>" class="pf-input" required pattern="[A-Za-z\s\-]+" title="Letters only">
                            </div>
                            <div class="pf-form-group">
                                <label class="pf-label">Middle Name</label>
                                <input type="text" name="middle_name" value="<?= htmlspecialchars($current_user['middle_name']) ?>" class="pf-input" placeholder="Optional" pattern="[A-Za-z\s\-]*" title="Letters only">
                            </div>
                        </div>

                        <div class="pf-form-row-2">
                            <div class="pf-form-group">
                                <label class="pf-label">Last Name *</label>
                                <input type="text" name="last_name" value="<?= htmlspecialchars($current_user['last_name']) ?>" class="pf-input" required pattern="[A-Za-z\s\-]+" title="Letters only">
                            </div>
                            <div class="pf-form-group">
                                <label class="pf-label">Suffix</label>
                                <select name="suffix" class="pf-select">
                                    <option value="">None</option>
                                    <option value="Jr." <?= ($current_user['suffix'] === 'Jr.') ? 'selected' : '' ?>>Jr.</option>
                                    <option value="Sr." <?= ($current_user['suffix'] === 'Sr.') ? 'selected' : '' ?>>Sr.</option>
                                    <option value="II" <?= ($current_user['suffix'] === 'II') ? 'selected' : '' ?>>II</option>
                                    <option value="III" <?= ($current_user['suffix'] === 'III') ? 'selected' : '' ?>>III</option>
                                    <option value="IV" <?= ($current_user['suffix'] === 'IV') ? 'selected' : '' ?>>IV</option>
                                    <option value="V" <?= ($current_user['suffix'] === 'V') ? 'selected' : '' ?>>V</option>
                                </select>
                            </div>
                        </div>

                        <div class="pf-form-group">
                            <label class="pf-label">Official Email Address (Read-only)</label>
                            <input type="email" value="<?= htmlspecialchars($current_user['email']) ?>" class="pf-input pf-input-readonly" readonly>
                            <span class="pf-help"><i class="fa-solid fa-lock"></i> Your login email is tied to security authorization and cannot be changed directly here.</span>
                        </div>

                        <div class="pf-form-group">
                            <label class="pf-label">Contact Mobile Phone *</label>
                            <input type="tel" name="phone" value="<?= htmlspecialchars($current_user['phone']) ?>" class="pf-input" placeholder="09171234567" maxlength="11" pattern="09[0-9]{9}" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);" required>
                            <span class="pf-help">Must be exactly 11 digits starting with 09 (e.g. 09171234567).</span>
                        </div>

                        <div style="margin-top: 1.5rem; text-align: right;">
                            <button type="submit" class="pf-btn-purple">
                                <i class="fa-solid fa-floppy-disk"></i> Save Profile Details
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Card 2: Password & Credentials Security -->
                <div class="pf-card">
                    <div class="pf-card-header">
                        <div class="pf-card-icon gold">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <div>
                            <h2 class="pf-card-title">Security & Password</h2>
                            <p class="pf-card-subtitle">Ensure your resort account is protected with a strong, private password.</p>
                        </div>
                    </div>

                    <form action="admin_profile.php" method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="change_password">

                        <div class="pf-form-group">
                            <label class="pf-label">Current Password *</label>
                            <div class="pf-pwd-wrapper">
                                <input type="password" name="current_password" id="cur_pwd" class="pf-input" placeholder="Enter your current password" required>
                                <button type="button" class="pf-pwd-toggle" onclick="togglePfPwd('cur_pwd', this)" aria-label="Toggle password visibility">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="pf-form-group">
                            <label class="pf-label">New Password *</label>
                            <div class="pf-pwd-wrapper">
                                <input type="password" name="new_password" id="new_pwd" class="pf-input" placeholder="Min 9 chars (letters & numbers)" required oninput="evaluatePfStrength(this.value)">
                                <button type="button" class="pf-pwd-toggle" onclick="togglePfPwd('new_pwd', this)" aria-label="Toggle password visibility">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                            <div class="pf-pwd-strength-bar">
                                <div id="pfStrengthFill" class="pf-pwd-strength-fill"></div>
                            </div>
                            <span id="pfStrengthText" class="pf-help">Must be at least 9 characters with uppercase, lowercase, and numbers.</span>
                        </div>

                        <div class="pf-form-group">
                            <label class="pf-label">Confirm New Password *</label>
                            <div class="pf-pwd-wrapper">
                                <input type="password" name="confirm_password" id="conf_pwd" class="pf-input" placeholder="Re-enter your new password" required>
                                <button type="button" class="pf-pwd-toggle" onclick="togglePfPwd('conf_pwd', this)" aria-label="Toggle password visibility">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 10px; padding: 10px 14px; margin-top: 14px; display: flex; align-items: center; gap: 10px; font-size: 0.82rem; color: #92400e;">
                            <i class="fa-solid fa-paper-plane" style="font-size: 1rem; color: #d97706;"></i>
                            <span><strong>Automatic Email Confirmation:</strong> Your updated password will be securely dispatched to your registered email address (<strong><?= htmlspecialchars($viewer_email) ?></strong>).</span>
                        </div>

                        <div style="margin-top: 1.5rem; text-align: right;">
                            <button type="submit" class="pf-btn-gold">
                                <i class="fa-solid fa-key"></i> Update Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($is_admin): ?>
                <!-- Quick Link to Settings for Super Administrators -->
                <div style="margin-top: 2rem; background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 14px; padding: 1.25rem 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <i class="fa-solid fa-sliders" style="font-size: 1.4rem; color: #7c3aed;"></i>
                        <div>
                            <strong style="color: #0f172a; font-size: 0.95rem;">Looking for Resort-Wide Settings &amp; Staff Directory?</strong>
                            <p style="margin: 0; color: #64748b; font-size: 0.84rem;">Manage resort logo, contact info, GCash QR, and add/edit personnel accounts in the Settings console.</p>
                        </div>
                    </div>
                    <a href="settings.php" class="pf-btn-purple" style="padding: 0.6rem 1.25rem; font-size: 0.88rem;">
                        <i class="fa-solid fa-gear"></i> Open System Settings
                    </a>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <script>
        function togglePfPwd(id, btn) {
            const el = document.getElementById(id);
            if (!el) return;
            const icon = btn.querySelector('i');
            if (el.type === 'password') {
                el.type = 'text';
                if (icon) {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                }
            } else {
                el.type = 'password';
                if (icon) {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            }
        }

        function evaluatePfStrength(pwd) {
            const fill = document.getElementById('pfStrengthFill');
            const txt  = document.getElementById('pfStrengthText');
            if (!fill || !txt) return;

            let score = 0;
            if (pwd.length >= 9) score += 25;
            if (pwd.length >= 12) score += 15;
            if (/[a-z]/.test(pwd)) score += 20;
            if (/[A-Z]/.test(pwd)) score += 20;
            if (/[0-9]/.test(pwd)) score += 20;

            if (score <= 25) {
                fill.style.width = '25%';
                fill.style.backgroundColor = '#ef4444';
                txt.textContent = 'Weak password: Must be at least 9 chars with uppercase, lowercase, & numbers.';
                txt.style.color = '#ef4444';
            } else if (score <= 65) {
                fill.style.width = '65%';
                fill.style.backgroundColor = '#f59e0b';
                txt.textContent = 'Moderate strength. Consider adding special characters or greater length.';
                txt.style.color = '#d97706';
            } else {
                fill.style.width = '100%';
                fill.style.backgroundColor = '#10b981';
                txt.textContent = 'Strong password! Meets resort security standards.';
                txt.style.color = '#059669';
            }
        }
    </script>
</body>
</html>
