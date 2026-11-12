<?php
// Edit Content has been replaced by the Settings page (settings.php).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'staff') {
    if (isset($_GET['tab']) && $_GET['tab'] === 'profile') {
        header("Location: admin_profile.php");
        exit();
    }
    header("Location: bookings.php?error=admin_only");
    exit();
}
$tab = (isset($_GET['tab']) && $_GET['tab'] === 'profile') ? '?tab=profile' : '';
header("Location: settings.php" . $tab, true, 302);
exit();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/content_store.php';

// Strict Role-Based Access Control: Admin & Staff only
if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), ['admin', 'staff'])) {
    $err = (isset($_SESSION['role']) && $_SESSION['role'] === 'guest') ? 'guest_restricted' : 'unauthorized';
    header("Location: admin-login.php?error=" . $err);
    exit();
}

$admin_user = $_SESSION['full_name'] ?? 'Resort Administrator';
$admin_role = ucfirst($_SESSION['role'] ?? 'Admin');
$success_msg = '';
$error_msg = '';

// Handle Reset Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_defaults') {
    ContentStore::resetDefaults($admin_user);
    $success_msg = "Homepage content has been reset to default resort settings.";
}
// Handle Save Action
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_content') {

    // ============================================================
    // QR CODE UPLOAD HANDLER (ENHANCED)
    // ============================================================
    $qr_image_path = trim($_POST['gcash_qr_image'] ?? 'assets/gcash_qr.jpg');

    // Handle QR removal / reset to default
    if (!empty($_POST['remove_qr'])) {
        $qr_image_path = 'assets/gcash_qr.jpg';
    }

    if (!empty($_FILES['gcash_qr_file']['tmp_name']) && is_uploaded_file($_FILES['gcash_qr_file']['tmp_name'])) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['gcash_qr_file']['name'], PATHINFO_EXTENSION));
        $max_size = 5 * 1024 * 1024; // 5MB max

        if (!in_array($ext, $allowed)) {
            $error_msg = "Invalid QR format. Allowed: JPG, JPEG, PNG, WEBP.";
        } elseif ($_FILES['gcash_qr_file']['size'] > $max_size) {
            $error_msg = "QR file is too large. Maximum size is 5MB.";
        } else {
            $qr_dir = __DIR__ . '/uploads/qr';
            if (!is_dir($qr_dir)) {
                @mkdir($qr_dir, 0777, true);
            }
            // Unique filename to prevent collisions
            $new_filename = 'gcash_qr_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
            $destination = $qr_dir . '/' . $new_filename;
            if (move_uploaded_file($_FILES['gcash_qr_file']['tmp_name'], $destination)) {
                $qr_image_path = 'uploads/qr/' . $new_filename;
                // Also overwrite assets/gcash_qr.jpg for backward compatibility across all modules
                @copy($destination, __DIR__ . '/assets/gcash_qr.jpg');
            } else {
                $error_msg = "Failed to upload QR code. Please check folder permissions on /uploads/qr/.";
            }
        }
    }

    // ============================================================
    // RESORT LOGO UPLOAD HANDLER (ENHANCED)
    // ============================================================
    $resort_logo_path = trim($_POST['resort_logo'] ?? 'assets/logo.jpg');

    // Handle logo removal / reset to default
    if (!empty($_POST['remove_logo'])) {
        $resort_logo_path = 'assets/logo.jpg';
    }

    if (!empty($_FILES['resort_logo_file']['tmp_name']) && is_uploaded_file($_FILES['resort_logo_file']['tmp_name'])) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'svg', 'gif'];
        $ext = strtolower(pathinfo($_FILES['resort_logo_file']['name'], PATHINFO_EXTENSION));
        $max_size = 5 * 1024 * 1024; // 5MB max

        if (!in_array($ext, $allowed)) {
            $error_msg = "Invalid logo format. Allowed: JPG, JPEG, PNG, WEBP, SVG, GIF.";
        } elseif ($_FILES['resort_logo_file']['size'] > $max_size) {
            $error_msg = "Logo file is too large. Maximum size is 5MB.";
        } else {
            $brand_dir = __DIR__ . '/uploads/branding';
            if (!is_dir($brand_dir)) {
                @mkdir($brand_dir, 0777, true);
            }
            // Unique filename to prevent collisions
            $new_filename = 'resort_logo_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
            $destination = $brand_dir . '/' . $new_filename;
            if (move_uploaded_file($_FILES['resort_logo_file']['tmp_name'], $destination)) {
                $resort_logo_path = 'uploads/branding/' . $new_filename;
                // Overwrite assets/logo.jpg for backward compatibility across all modules
                @copy($destination, __DIR__ . '/assets/logo.jpg');
                // Also sync to uploads/logo (based on your folder structure)
                @copy($destination, __DIR__ . '/uploads/logo');
            } else {
                $error_msg = "Failed to upload logo. Please check folder permissions on /uploads/branding/.";
            }
        }
    }

    $data = [
        'hero_badge' => trim($_POST['hero_badge'] ?? ''),
        'hero_title' => trim($_POST['hero_title'] ?? ''),
        'hero_subtitle' => trim($_POST['hero_subtitle'] ?? ''),
        'hero_btn1_text' => trim($_POST['hero_btn1_text'] ?? ''),
        'hero_btn1_url' => trim($_POST['hero_btn1_url'] ?? ''),
        'hero_btn2_text' => trim($_POST['hero_btn2_text'] ?? ''),
        'hero_btn2_url' => trim($_POST['hero_btn2_url'] ?? ''),
        'hero_pill1' => trim($_POST['hero_pill1'] ?? ''),
        'hero_pill2' => trim($_POST['hero_pill2'] ?? ''),
        'hero_pill3' => trim($_POST['hero_pill3'] ?? ''),

        'features' => [
            [
                'icon' => trim($_POST['feat_icon_0'] ?? 'fa-house-chimney'),
                'color' => trim($_POST['feat_color_0'] ?? '#7c3aed'),
                'title' => trim($_POST['feat_title_0'] ?? ''),
                'desc' => trim($_POST['feat_desc_0'] ?? '')
            ],
            [
                'icon' => trim($_POST['feat_icon_1'] ?? 'fa-person-swimming'),
                'color' => trim($_POST['feat_color_1'] ?? '#7c3aed'),
                'title' => trim($_POST['feat_title_1'] ?? ''),
                'desc' => trim($_POST['feat_desc_1'] ?? '')
            ],
            [
                'icon' => trim($_POST['feat_icon_2'] ?? 'fa-water'),
                'color' => trim($_POST['feat_color_2'] ?? '#059669'),
                'title' => trim($_POST['feat_title_2'] ?? ''),
                'desc' => trim($_POST['feat_desc_2'] ?? '')
            ],
            [
                'icon' => trim($_POST['feat_icon_3'] ?? 'fa-spa'),
                'color' => trim($_POST['feat_color_3'] ?? '#e11d48'),
                'title' => trim($_POST['feat_title_3'] ?? ''),
                'desc' => trim($_POST['feat_desc_3'] ?? '')
            ]
        ],

        'kubos_section_title' => trim($_POST['kubos_section_title'] ?? ''),
        'kubos_section_subtitle' => trim($_POST['kubos_section_subtitle'] ?? ''),
        'activities_section_title' => trim($_POST['activities_section_title'] ?? ''),
        'activities_section_subtitle' => trim($_POST['activities_section_subtitle'] ?? ''),
        'reviews_section_title' => trim($_POST['reviews_section_title'] ?? ''),
        'reviews_section_subtitle' => trim($_POST['reviews_section_subtitle'] ?? ''),

        'guarantee_badge' => trim($_POST['guarantee_badge'] ?? ''),
        'guarantee_title' => trim($_POST['guarantee_title'] ?? ''),
        'guarantee_subtitle' => trim($_POST['guarantee_subtitle'] ?? ''),
        'guarantee_btn_text' => trim($_POST['guarantee_btn_text'] ?? ''),
        'guarantee_btn_url' => trim($_POST['guarantee_btn_url'] ?? ''),

        'resort_location' => trim($_POST['resort_location'] ?? ''),
        'resort_phone' => trim($_POST['resort_phone'] ?? ''),
        'gcash_name' => trim($_POST['gcash_name'] ?? 'PURPLE BAMBOO RESORT'),
        'gcash_number' => trim($_POST['gcash_number'] ?? '0956 697 8679'),
        'gcash_qr_image' => $qr_image_path,
        'gcash_show_on_home' => !empty($_POST['gcash_show_on_home']),
        'gcash_instructions' => trim($_POST['gcash_instructions'] ?? ''),
        'gcash_card_title' => trim($_POST['gcash_card_title'] ?? 'Official GCash Payment Channel'),
        'gcash_card_subtitle' => trim($_POST['gcash_card_subtitle'] ?? 'Scan with GCash or InstaPay for Instant Reservation Verification'),

        'announcement_enabled' => !empty($_POST['announcement_enabled']),
        'announcement_text' => trim($_POST['announcement_text'] ?? ''),
        'announcement_link' => trim($_POST['announcement_link'] ?? ''),

        'resort_logo' => $resort_logo_path,
        'resort_brand_title' => trim($_POST['resort_brand_title'] ?? 'PURPLE BAMBOO'),
        'resort_brand_subtitle' => trim($_POST['resort_brand_subtitle'] ?? 'BEACH RESORT'),
        'resort_name' => trim($_POST['resort_name'] ?? 'Purple Bamboo Resort')
    ];

    if (ContentStore::saveContent($data, $admin_user)) {
        if (empty($error_msg)) {
            $success_msg = "Homepage content, GCash QR, resort logo, and branding saved & published live!";
        }
    } else {
        $error_msg = "Unable to save content changes. Please check server permissions.";
    }
}

$c = ContentStore::getContent();

// ============================================================
// ADMIN PROFILE & CREDENTIAL DATA HANDLERS
// ============================================================
require_once __DIR__ . '/database.php';

$admin_id = $_SESSION['user_id'] ?? null;
$admin_email = $_SESSION['email'] ?? null;
$admin_data = null;

if (isset($pdo) && $pdo !== null) {
    try {
        if ($admin_id) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$admin_id]);
            $admin_data = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif ($admin_email) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?)");
            $stmt->execute([$admin_email]);
            $admin_data = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
}

// Admin record loaded strictly from MySQL users table


if ($admin_data) {
    $admin_data['full_name'] = trim(($admin_data['first_name'] ?? '') . ' ' . ($admin_data['middle_name'] ?? '') . ' ' . ($admin_data['last_name'] ?? '') . ' ' . ($admin_data['suffix'] ?? ''));
    if (empty($admin_data['full_name'])) {
        $admin_data['full_name'] = $_SESSION['full_name'] ?? 'Resort Administrator';
    }
} else {
    $admin_data = [
        'id' => $admin_id ?? 1,
        'profile_id' => 'PBR-ADM-001',
        'first_name' => 'Super',
        'middle_name' => '',
        'last_name' => 'Admin',
        'suffix' => '',
        'full_name' => $_SESSION['full_name'] ?? 'Super Admin',
        'email' => $_SESSION['email'] ?? 'admin@purplebamboo.com',
        'phone' => '09612613415',
        'role' => $_SESSION['role'] ?? 'admin'
    ];
}

// Handle Profile Info Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_admin_profile') {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $suffix = trim($_POST['suffix'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    $new_full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name . ' ' . $suffix);
    if (empty($new_full_name)) {
        $error_msg = "Please provide at least a first or last name.";
        $active_tab = 'tab-admin-profile';
    } else {
        if (isset($pdo) && $pdo !== null && !empty($admin_data['id'])) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, phone = ? WHERE id = ?");
                $stmt->execute([$first_name, $middle_name, $last_name, $suffix, $phone, $admin_data['id']]);
            } catch (Exception $e) {}
        }
        // Saved directly to MySQL users table
        $_SESSION['full_name'] = $new_full_name;
        $admin_user = $new_full_name;
        $admin_data['first_name'] = $first_name;
        $admin_data['middle_name'] = $middle_name;
        $admin_data['last_name'] = $last_name;
        $admin_data['suffix'] = $suffix;
        $admin_data['full_name'] = $new_full_name;
        $admin_data['phone'] = $phone;

        $success_msg = "Admin profile information updated successfully!";
        $active_tab = 'tab-admin-profile';
    }
}
// Handle Password Change
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_admin_password') {
    $current_pwd = $_POST['current_password'] ?? '';
    $new_pwd = $_POST['new_password'] ?? '';
    $confirm_pwd = $_POST['confirm_password'] ?? '';

    if (empty($current_pwd) || empty($new_pwd) || empty($confirm_pwd)) {
        $error_msg = "Please fill in all password fields.";
        $active_tab = 'tab-admin-profile';
    } elseif ($new_pwd !== $confirm_pwd) {
        $error_msg = "New password and confirmation do not match.";
        $active_tab = 'tab-admin-profile';
    } elseif (strlen($new_pwd) < 6) {
        $error_msg = "New password must be at least 6 characters long.";
        $active_tab = 'tab-admin-profile';
    } else {
        $stored_hash = $admin_data['password_hash'] ?? '';
        $valid_pwd = false;
        if (!empty($stored_hash) && password_verify($current_pwd, $stored_hash)) {
            $valid_pwd = true;
        } elseif (empty($stored_hash)) {
            $valid_pwd = true;
        }

        if (!$valid_pwd) {
            $error_msg = "Current password is incorrect.";
            $active_tab = 'tab-admin-profile';
        } else {
            $new_hash = password_hash($new_pwd, PASSWORD_DEFAULT);
            if (isset($pdo) && $pdo !== null && !empty($admin_data['id'])) {
                try {
                    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $stmt->execute([$new_hash, $admin_data['id']]);
                } catch (Exception $e) {}
            }
            // Password updated directly in MySQL users table
            $admin_data['password_hash'] = $new_hash;
            $success_msg = "Admin password updated successfully!";
            $active_tab = 'tab-admin-profile';
        }
    }
}
$req_tab = $_GET['tab'] ?? '';
$initial_tab = 'tab-hero';
if (isset($active_tab) && $active_tab === 'tab-admin-profile') {
    $initial_tab = 'tab-admin-profile';
} elseif ($req_tab === 'profile') {
    $initial_tab = 'tab-admin-profile';
}
$is_profile_active = ($initial_tab === 'tab-admin-profile');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Content & Admin Profile | Executive Admin Console</title>
    <link rel="stylesheet" href="admin-style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .cms-tab-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94a3b8;
            padding: 0.75rem 1.25rem;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .cms-tab-btn:hover {
            background: rgba(124, 58, 237, 0.15);
            color: #ffffff;
            border-color: #8b5cf6;
        }
        .cms-tab-btn.active {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            color: #ffffff;
            border-color: #a855f7;
            box-shadow: 0 4px 14px rgba(124, 58, 237, 0.35);
        }
        .cms-tab-pane {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        .cms-tab-pane.active {
            display: block;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .cms-input-group {
            margin-bottom: 1.5rem;
        }
        .cms-label {
            display: block;
            font-size: 0.82rem;
            font-weight: 800;
            color: #cbd5e1;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.45rem;
        }
        .cms-label i {
            color: #a855f7;
            margin-right: 4px;
        }
        .cms-hint {
            font-size: 0.78rem;
            color: #64748b;
            margin-top: 0.35rem;
        }
        .cms-input, .cms-textarea {
            width: 100%;
            background: rgba(12, 7, 30, 0.85);
            border: 1.5px solid #2e1a47;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            color: #ffffff;
            font-size: 0.92rem;
            font-family: inherit;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }
        .cms-input:focus, .cms-textarea:focus {
            outline: none;
            border-color: #a855f7;
            background: rgba(12, 7, 30, 0.98);
            box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.25);
        }
        .cms-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
        }
        .cms-feature-box {
            background: rgba(15, 8, 38, 0.6);
            border: 1.5px solid #2e1a47;
            border-radius: 12px;
            padding: 1.25rem;
            transition: all 0.2s ease;
        }
        .cms-feature-box:hover {
            border-color: #8b5cf6;
        }
        .live-preview-box {
            background: linear-gradient(135deg, #180d38 0%, #28114f 50%, #150930 100%);
            border: 1.5px solid #8b5cf6;
            border-radius: 14px;
            padding: 1.75rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 24px rgba(124, 58, 237, 0.15);
            position: relative;
            overflow: hidden;
        }
        .live-preview-box::before {
            content: "LIVE HERO PREVIEW";
            position: absolute;
            top: 12px;
            right: 14px;
            font-size: 0.68rem;
            font-weight: 800;
            background: rgba(251, 191, 36, 0.2);
            color: #fbbf24;
            border: 1px solid rgba(251, 191, 36, 0.4);
            padding: 2px 8px;
            border-radius: 12px;
            letter-spacing: 0.5px;
        }
        .sticky-save-bar {
            position: sticky;
            bottom: 1.5rem;
            z-index: 1000;
            background: rgba(15, 8, 38, 0.95);
            backdrop-filter: blur(10px);
            border: 1.5px solid #8b5cf6;
            border-radius: 14px;
            padding: 1rem 1.5rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.6), 0 0 25px rgba(124, 58, 237, 0.25);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 2rem;
        }
        .upload-dropzone {
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: rgba(124, 58, 237, 0.05);
            border: 2px dashed rgba(168, 85, 247, 0.5);
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .upload-dropzone:hover {
            border-color: #fbbf24;
            background: rgba(251, 191, 36, 0.08);
        }
        .upload-dropzone.dragover {
            border-color: #fbbf24;
            background: rgba(251, 191, 36, 0.12);
            transform: scale(1.01);
        }
    </style>
</head>
<body class="admin-layout-body">

    <!-- Executive Admin Left Sidebar -->
    <?php include 'admin-nav.php'; ?>

    <div class="admin-main-wrapper">

        <!-- Top Admin Header Bar -->
        <header class="admin-top-header">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(124, 58, 237, 0.2); display: flex; align-items: center; justify-content: center; color: var(--gold-glow); font-size: 1.1rem; border: 1px solid rgba(251, 191, 36, 0.3);">
                    <i class="fa-solid fa-sliders"></i>
                </div>
                <div>
                    <h2 style="font-size: 1.15rem; color: #ffffff; margin: 0; font-family: 'Outfit', sans-serif;">Edit Content & Admin Profile</h2>
                    <span style="font-size: 0.75rem; color: var(--text-sub);">Manage homepage content, branding assets, and administrative profile</span>
                </div>
            </div>

            <div class="admin-header-actions">
                <a href="settings.php?tab=profile" class="hs-user-badge" title="View Admin Profile">
                    <span class="hs-user-avatar"><?= strtoupper(substr($admin_full_name ?? 'Admin', 0, 1)) ?></span>
                    <span class="hs-user-name"><?= htmlspecialchars($admin_full_name ?? 'Admin') ?></span>
                </a>
                <a href="logout.php" class="hs-btn-logout" onclick="openLogoutModal(event)" title="Sign Out"><i class="fa-solid fa-sign-out-alt"></i></a>
            </div>
        </header>

        <div class="admin-content-container" style="padding: 2rem;">

            <!-- Flash Notifications -->
            <?php if ($success_msg): ?>
                <div style="background: rgba(16, 185, 129, 0.15); border: 1.5px solid #10b981; color: #6ee7b7; padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; gap: 12px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-circle-check" style="font-size: 1.25rem; color: #10b981;"></i>
                        <span style="font-weight: 700;"><?= htmlspecialchars($success_msg) ?></span>
                    </div>
                    <a href="index.php" target="_blank" style="color: #6ee7b7; font-weight: 700; text-decoration: underline; font-size: 0.88rem;">
                        View Updated Homepage &rarr;
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div style="background: rgba(244, 63, 94, 0.15); border: 1.5px solid #f43f5e; color: #fca5a5; padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-circle-exclamation" style="font-size: 1.25rem; color: #f43f5e;"></i>
                    <span style="font-weight: 700;"><?= htmlspecialchars($error_msg) ?></span>
                </div>
            <?php endif; ?>

            <!-- Live Hero Preview Box -->
            <div class="live-preview-box" id="cmsLivePreviewBox" style="<?= $is_profile_active ? 'display: none;' : '' ?>">
                <div style="display: inline-flex; align-items: center; gap: 6px; background: rgba(124, 58, 237, 0.25); color: #e9d5ff; padding: 4px 12px; border-radius: 20px; font-size: 0.78rem; font-weight: 700; margin-bottom: 0.75rem; border: 1px solid rgba(168, 85, 247, 0.4);">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background: #10b981; display: inline-block;"></span>
                    <span id="prev_hero_badge"><?= htmlspecialchars($c['hero_badge']) ?></span>
                </div>
                <h1 id="prev_hero_title" style="font-size: 1.8rem; color: #ffffff; font-family: 'Outfit', sans-serif; margin-bottom: 0.6rem; font-weight: 800; line-height: 1.2;">
                    <?= htmlspecialchars($c['hero_title']) ?>
                </h1>
                <p id="prev_hero_subtitle" style="color: #cbd5e1; font-size: 0.95rem; line-height: 1.6; margin-bottom: 1.25rem; max-width: 760px;">
                    <?= htmlspecialchars($c['hero_subtitle']) ?>
                </p>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <span class="admin-badge admin-badge-gold" id="prev_pill1"><i class="fa-solid fa-shield-halved"></i> <?= htmlspecialchars($c['hero_pill1']) ?></span>
                    <span class="admin-badge admin-badge-purple" id="prev_pill2"><i class="fa-solid fa-snowflake"></i> <?= htmlspecialchars($c['hero_pill2']) ?></span>
                    <span class="admin-badge admin-badge-success" id="prev_pill3"><i class="fa-solid fa-star"></i> <?= htmlspecialchars($c['hero_pill3']) ?></span>
                </div>
            </div>

            <!-- Tabs Navigation Strip -->
            <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 1.5rem;">
                <button type="button" class="cms-tab-btn <?= !$is_profile_active ? 'active' : '' ?>" onclick="switchCmsTab('tab-hero', this)">
                    <i class="fa-solid fa-star"></i> Hero Banner
                </button>
                <button type="button" class="cms-tab-btn" onclick="switchCmsTab('tab-features', this)">
                    <i class="fa-solid fa-layer-group"></i> 4 Highlight Cards
                </button>
                <button type="button" class="cms-tab-btn" onclick="switchCmsTab('tab-sections', this)">
                    <i class="fa-solid fa-heading"></i> Section Headers
                </button>
                <button type="button" class="cms-tab-btn" onclick="switchCmsTab('tab-gcash', this)">
                    <i class="fa-solid fa-qrcode" style="color: #60a5fa;"></i> GCash Payment & QR
                </button>
                <button type="button" class="cms-tab-btn" onclick="switchCmsTab('tab-guarantee', this)">
                    <i class="fa-solid fa-shield-check"></i> Booking Banner
                </button>
                <button type="button" class="cms-tab-btn" onclick="switchCmsTab('tab-info', this)">
                    <i class="fa-solid fa-map-location-dot"></i> Contact & Info
                </button>
                <button type="button" class="cms-tab-btn" onclick="switchCmsTab('tab-branding', this)" id="btn-tab-branding">
                    <i class="fa-solid fa-id-card-clip" style="color: #fbbf24;"></i> Resort Profile & Logo
                </button>
                <button type="button" class="cms-tab-btn <?= $is_profile_active ? 'active' : '' ?>" onclick="switchCmsTab('tab-admin-profile', this)" id="btn-tab-admin-profile" style="border: 1px solid rgba(167, 139, 250, 0.4); background: <?= $is_profile_active ? '' : 'rgba(124, 58, 237, 0.12)' ?>;">
                    <i class="fa-solid fa-user-gear" style="color: #c084fc;"></i> Admin Profile
                </button>
            </div>

            <!-- Main CMS Edit Form -->
            <form action="edit_homepage.php" method="POST" id="cmsForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_content">

                <!-- ==================== TAB 1: HERO BANNER ==================== -->
                <div class="cms-tab-pane <?= !$is_profile_active ? 'active' : '' ?>" id="tab-hero">
                    <div class="admin-card" style="padding: 1.75rem; margin-bottom: 1.5rem;">
                        <div style="border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding-bottom: 1rem; margin-bottom: 1.5rem;">
                            <h3 style="color: #fbbf24; font-size: 1.2rem; font-family: 'Outfit', sans-serif; margin-bottom: 4px;">
                                <i class="fa-solid fa-wand-magic-sparkles"></i> Main Hero Canvas Banner
                            </h3>
                            <p style="color: var(--text-sub); font-size: 0.85rem;">The top visual section seen immediately by guests upon loading the resort homepage.</p>
                        </div>

                        <div class="cms-input-group">
                            <label class="cms-label"><i class="fa-solid fa-tag"></i> Top Pulse Badge</label>
                            <input type="text" name="hero_badge" class="cms-input" value="<?= htmlspecialchars($c['hero_badge']) ?>" oninput="document.getElementById('prev_hero_badge').textContent = this.value">
                            <div class="cms-hint">Appears in a small glowing capsule pill above the main title.</div>
                        </div>

                        <div class="cms-input-group">
                            <label class="cms-label"><i class="fa-solid fa-heading"></i> Main Homepage Headline Title</label>
                            <input type="text" name="hero_title" class="cms-input" value="<?= htmlspecialchars($c['hero_title']) ?>" oninput="document.getElementById('prev_hero_title').textContent = this.value" required>
                            <div class="cms-hint">The primary resort welcoming title (H1).</div>
                        </div>

                        <div class="cms-input-group">
                            <label class="cms-label"><i class="fa-solid fa-paragraph"></i> Hero Subtitle & Resort Description</label>
                            <textarea name="hero_subtitle" rows="3" class="cms-textarea" oninput="document.getElementById('prev_hero_subtitle').textContent = this.value"><?= htmlspecialchars($c['hero_subtitle']) ?></textarea>
                            <div class="cms-hint">A captivating 2-3 sentence overview highlighting kubos, pools, and location.</div>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
                            <div class="cms-input-group" style="margin-bottom: 0;">
                                <label class="cms-label"><i class="fa-solid fa-arrow-pointer"></i> Primary Button Text</label>
                                <input type="text" name="hero_btn1_text" class="cms-input" value="<?= htmlspecialchars($c['hero_btn1_text']) ?>">
                            </div>
                            <div class="cms-input-group" style="margin-bottom: 0;">
                                <label class="cms-label"><i class="fa-solid fa-link"></i> Primary Button URL</label>
                                <input type="text" name="hero_btn1_url" class="cms-input" value="<?= htmlspecialchars($c['hero_btn1_url']) ?>">
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
                            <div class="cms-input-group" style="margin-bottom: 0;">
                                <label class="cms-label"><i class="fa-solid fa-arrow-pointer"></i> Secondary Button Text</label>
                                <input type="text" name="hero_btn2_text" class="cms-input" value="<?= htmlspecialchars($c['hero_btn2_text']) ?>">
                            </div>
                            <div class="cms-input-group" style="margin-bottom: 0;">
                                <label class="cms-label"><i class="fa-solid fa-link"></i> Secondary Button URL</label>
                                <input type="text" name="hero_btn2_url" class="cms-input" value="<?= htmlspecialchars($c['hero_btn2_url']) ?>">
                            </div>
                        </div>

                        <div style="border-top: 1px solid rgba(255, 255, 255, 0.08); padding-top: 1.25rem;">
                            <label class="cms-label"><i class="fa-solid fa-pills"></i> Highlight Stat Badges (3 Pills)</label>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
                                <div>
                                    <input type="text" name="hero_pill1" class="cms-input" value="<?= htmlspecialchars($c['hero_pill1']) ?>" oninput="document.getElementById('prev_pill1').innerHTML = '<i class=\'fa-solid fa-shield-halved\'></i> ' + this.value">
                                </div>
                                <div>
                                    <input type="text" name="hero_pill2" class="cms-input" value="<?= htmlspecialchars($c['hero_pill2']) ?>" oninput="document.getElementById('prev_pill2').innerHTML = '<i class=\'fa-solid fa-snowflake\'></i> ' + this.value">
                                </div>
                                <div>
                                    <input type="text" name="hero_pill3" class="cms-input" value="<?= htmlspecialchars($c['hero_pill3']) ?>" oninput="document.getElementById('prev_pill3').innerHTML = '<i class=\'fa-solid fa-star\'></i> ' + this.value">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== TAB 2: 4 FEATURES STRIP ==================== -->
                <div class="cms-tab-pane" id="tab-features">
                    <div class="admin-card" style="padding: 1.75rem; margin-bottom: 1.5rem;">
                        <div style="border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding-bottom: 1rem; margin-bottom: 1.5rem;">
                            <h3 style="color: #fbbf24; font-size: 1.2rem; font-family: 'Outfit', sans-serif; margin-bottom: 4px;">
                                <i class="fa-solid fa-layer-group"></i> 4 Interactive Feature Highlight Cards
                            </h3>
                            <p style="color: var(--text-sub); font-size: 0.85rem;">The four value-proposition cards displayed directly beneath the hero canvas.</p>
                        </div>

                        <div class="cms-card-grid">
                            <?php for ($i = 0; $i < 4; $i++): 
                                $feat = $c['features'][$i] ?? ['icon' => 'fa-star', 'color' => '#7c3aed', 'title' => 'Feature ' . ($i + 1), 'desc' => ''];
                            ?>
                                <div class="cms-feature-box">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                                        <span class="admin-badge admin-badge-purple">Card #<?= $i + 1 ?></span>
                                        <div style="width: 34px; height: 34px; border-radius: 8px; background: rgba(255, 255, 255, 0.08); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: <?= htmlspecialchars($feat['color']) ?>;">
                                            <i class="fa-solid <?= htmlspecialchars($feat['icon']) ?>"></i>
                                        </div>
                                    </div>

                                    <div class="cms-input-group">
                                        <label class="cms-label"><i class="fa-solid fa-icons"></i> FontAwesome Icon Class</label>
                                        <input type="text" name="feat_icon_<?= $i ?>" class="cms-input" value="<?= htmlspecialchars($feat['icon']) ?>" placeholder="fa-house-chimney">
                                        <div class="cms-hint">Examples: fa-house-chimney, fa-water, fa-person-swimming, fa-spa</div>
                                    </div>

                                    <div class="cms-input-group">
                                        <label class="cms-label"><i class="fa-solid fa-palette"></i> Icon Accent Color</label>
                                        <input type="text" name="feat_color_<?= $i ?>" class="cms-input" value="<?= htmlspecialchars($feat['color']) ?>" placeholder="#7c3aed">
                                    </div>

                                    <div class="cms-input-group">
                                        <label class="cms-label"><i class="fa-solid fa-heading"></i> Card Title</label>
                                        <input type="text" name="feat_title_<?= $i ?>" class="cms-input" value="<?= htmlspecialchars($feat['title']) ?>" required>
                                    </div>

                                    <div class="cms-input-group" style="margin-bottom: 0;">
                                        <label class="cms-label"><i class="fa-solid fa-comment-dots"></i> Card Description</label>
                                        <input type="text" name="feat_desc_<?= $i ?>" class="cms-input" value="<?= htmlspecialchars($feat['desc']) ?>">
                                    </div>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>

                <!-- ==================== TAB 3: SECTION HEADERS ==================== -->
                <div class="cms-tab-pane" id="tab-sections">
                    <div class="admin-card" style="padding: 1.75rem; margin-bottom: 1.5rem;">
                        <div style="border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding-bottom: 1rem; margin-bottom: 1.5rem;">
                            <h3 style="color: #fbbf24; font-size: 1.2rem; font-family: 'Outfit', sans-serif; margin-bottom: 4px;">
                                <i class="fa-solid fa-heading"></i> Homepage Section Titles & Subtitles
                            </h3>
                            <p style="color: var(--text-sub); font-size: 0.85rem;">Customize the headings for the Kubos, Activities, and Reviews showcases.</p>
                        </div>

                        <!-- Kubos Section -->
                        <div style="background: rgba(15, 8, 38, 0.5); border: 1px solid #2e1a47; border-radius: 10px; padding: 1.25rem; margin-bottom: 1.5rem;">
                            <h4 style="color: #a855f7; font-size: 1rem; margin-bottom: 1rem;"><i class="fa-solid fa-house-chimney-window"></i> House Kubos Showcase</h4>
                            <div class="cms-input-group">
                                <label class="cms-label">Section Title</label>
                                <input type="text" name="kubos_section_title" class="cms-input" value="<?= htmlspecialchars($c['kubos_section_title']) ?>">
                            </div>
                            <div class="cms-input-group" style="margin-bottom: 0;">
                                <label class="cms-label">Section Subtitle / Description</label>
                                <input type="text" name="kubos_section_subtitle" class="cms-input" value="<?= htmlspecialchars($c['kubos_section_subtitle']) ?>">
                            </div>
                        </div>

                        <!-- Activities Section -->
                        <div style="background: rgba(15, 8, 38, 0.5); border: 1px solid #2e1a47; border-radius: 10px; padding: 1.25rem; margin-bottom: 1.5rem;">
                            <h4 style="color: #a855f7; font-size: 1rem; margin-bottom: 1rem;"><i class="fa-solid fa-water-ladder"></i> Pool & Activities Showcase</h4>
                            <div class="cms-input-group">
                                <label class="cms-label">Section Title</label>
                                <input type="text" name="activities_section_title" class="cms-input" value="<?= htmlspecialchars($c['activities_section_title']) ?>">
                            </div>
                            <div class="cms-input-group" style="margin-bottom: 0;">
                                <label class="cms-label">Section Subtitle / Description</label>
                                <input type="text" name="activities_section_subtitle" class="cms-input" value="<?= htmlspecialchars($c['activities_section_subtitle']) ?>">
                            </div>
                        </div>

                        <!-- Reviews Section -->
                        <div style="background: rgba(15, 8, 38, 0.5); border: 1px solid #2e1a47; border-radius: 10px; padding: 1.25rem;">
                            <h4 style="color: #fbbf24; font-size: 1rem; margin-bottom: 1rem;"><i class="fa-solid fa-comments"></i> Guest Reviews Showcase</h4>
                            <div class="cms-input-group">
                                <label class="cms-label">Section Title</label>
                                <input type="text" name="reviews_section_title" class="cms-input" value="<?= htmlspecialchars($c['reviews_section_title']) ?>">
                            </div>
                            <div class="cms-input-group" style="margin-bottom: 0;">
                                <label class="cms-label">Section Subtitle / Description</label>
                                <input type="text" name="reviews_section_subtitle" class="cms-input" value="<?= htmlspecialchars($c['reviews_section_subtitle']) ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== TAB: GCASH PAYMENT & QR (ENHANCED) ==================== -->
                <div class="cms-tab-pane" id="tab-gcash">
                    <div class="admin-card" style="padding: 1.75rem; margin-bottom: 1.5rem;">
                        <div style="border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding-bottom: 1.25rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
                            <div>
                                <h3 style="color: #60a5fa; font-size: 1.25rem; font-family: 'Outfit', sans-serif; margin-bottom: 4px;">
                                    <i class="fa-solid fa-qrcode"></i> GCash Payment & QR Code Settings
                                </h3>
                                <p style="color: var(--text-sub); font-size: 0.85rem;">Control the official resort GCash payment information and QR Code showcased on the homepage.</p>
                            </div>
                            <label style="display: flex; align-items: center; gap: 10px; background: rgba(59, 130, 246, 0.15); border: 1.5px solid rgba(59, 130, 246, 0.4); padding: 8px 16px; border-radius: 10px; cursor: pointer;">
                                <input type="checkbox" name="gcash_show_on_home" value="1" <?= !empty($c['gcash_show_on_home']) ? 'checked' : '' ?> style="width: 20px; height: 20px; accent-color: #3b82f6;">
                                <span style="font-weight: 700; font-size: 0.9rem; color: #93c5fd;">Show GCash QR on Homepage</span>
                            </label>
                        </div>

                        <!-- QR USAGE CHECKLIST -->
                        <div style="background: rgba(59, 130, 246, 0.08); border: 1.5px solid rgba(59, 130, 246, 0.35); border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.75rem;">
                            <h4 style="color: #93c5fd; font-size: 0.95rem; margin: 0 0 0.75rem 0; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-circle-info"></i> Where Your QR Code Appears (System-Wide)
                            </h4>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.6rem; font-size: 0.84rem; color: #cbd5e1;">
                                <div><i class="fa-solid fa-check" style="color: #10b981; margin-right: 6px;"></i> Homepage GCash Payment Card</div>
                                <div><i class="fa-solid fa-check" style="color: #10b981; margin-right: 6px;"></i> Booking Checkout Page</div>
                                <div><i class="fa-solid fa-check" style="color: #10b981; margin-right: 6px;"></i> Payment Confirmation Screen</div>
                                <div><i class="fa-solid fa-check" style="color: #10b981; margin-right: 6px;"></i> Guest Booking Receipts</div>
                                <div><i class="fa-solid fa-check" style="color: #10b981; margin-right: 6px;"></i> Email Payment Instructions</div>
                                <div><i class="fa-solid fa-check" style="color: #10b981; margin-right: 6px;"></i> Admin Payment Verification Panel</div>
                            </div>
                            <p style="font-size: 0.78rem; color: #64748b; margin: 0.75rem 0 0 0;">
                                <i class="fa-solid fa-lightbulb" style="color: #fbbf24;"></i> Uploading a new QR automatically updates <strong>all</strong> these locations. A backward-compatible copy is also saved to <code style="color: #fbbf24;">assets/gcash_qr.jpg</code>.
                            </p>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 2rem;">
                            <!-- QR Code Preview & Enhanced File Uploader -->
                            <div style="background: rgba(15, 8, 38, 0.6); border: 1.5px solid #2e1a47; border-radius: 14px; padding: 1.5rem; text-align: center;">
                                <h4 style="color: #fbbf24; font-size: 1rem; margin-bottom: 1rem;">
                                    <i class="fa-solid fa-image"></i> Official Resort QR Code
                                </h4>
                                
                                <div style="display: inline-block; background: #ffffff; padding: 12px; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.5); margin-bottom: 1.25rem;">
                                    <img id="qr_preview_display" src="<?= htmlspecialchars($c['gcash_qr_image'] ?? 'assets/gcash_qr.jpg') ?>?v=<?= time() ?>" alt="Resort GCash QR Code" style="max-width: 220px; max-height: 220px; object-fit: contain; display: block; border-radius: 6px;">
                                </div>
                                <div style="font-size: 0.78rem; color: #94a3b8; line-height: 1.5; margin-bottom: 1rem;">
                                    Current File: <code style="color: #fbbf24; word-break: break-all;"><?= htmlspecialchars($c['gcash_qr_image'] ?? 'assets/gcash_qr.jpg') ?></code>
                                </div>
                                <input type="hidden" name="gcash_qr_image" value="<?= htmlspecialchars($c['gcash_qr_image'] ?? 'assets/gcash_qr.jpg') ?>">
                                <input type="hidden" name="remove_qr" id="remove_qr_flag" value="">

                                <?php if (!empty($c['gcash_qr_image']) && $c['gcash_qr_image'] !== 'assets/gcash_qr.jpg'): ?>
                                    <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" style="margin-bottom: 1rem; color: #f43f5e; border-color: #f43f5e;" onclick="confirmRemoveQr()">
                                        <i class="fa-solid fa-trash-can"></i> Remove & Use Default
                                    </button>
                                <?php endif; ?>

                                <!-- Drag & Drop Zone for QR -->
                                <div id="qr-dropzone" class="upload-dropzone"
                                     ondragover="event.preventDefault(); this.classList.add('dragover');"
                                     ondragleave="this.classList.remove('dragover');"
                                     ondrop="handleQrDrop(event);"
                                     onclick="document.getElementById('gcash_qr_file').click();">
                                    <div style="text-align: center; margin-bottom: 1rem;">
                                        <i class="fa-solid fa-cloud-arrow-up" style="font-size: 2.5rem; color: #60a5fa; margin-bottom: 0.75rem; display: block;"></i>
                                        <label class="cms-label" style="margin-bottom: 0.4rem; cursor: pointer;">
                                            Upload New QR Code
                                        </label>
                                        <p style="font-size: 0.82rem; color: #cbd5e1; margin: 0 0 0.5rem 0; line-height: 1.5;">
                                            <strong style="color: #60a5fa;">Click to browse</strong> or drag & drop an image here.
                                        </p>
                                    </div>
                                    <input type="file" name="gcash_qr_file" id="gcash_qr_file" accept=".jpg,.jpeg,.png,.webp" class="cms-input" onchange="handleQrUploadPreview(this)" style="padding: 0.5rem; background: rgba(0, 0, 0, 0.25);">
                                    <div class="cms-hint" style="margin-top: 0.6rem; text-align: center;">
                                        <i class="fa-solid fa-circle-check" style="color: #10b981;"></i> Recommended: Square QR image • JPG, PNG, WEBP • Max 5MB
                                    </div>
                                    <div id="qr_upload_status" style="margin-top: 0.75rem; font-size: 0.82rem; text-align: center; display: none;"></div>
                                </div>
                            </div>

                            <!-- Account Details & Copywriting -->
                            <div>
                                <div class="cms-input-group">
                                    <label class="cms-label"><i class="fa-solid fa-id-card"></i> Registered GCash Account Name</label>
                                    <input type="text" name="gcash_name" class="cms-input" value="<?= htmlspecialchars($c['gcash_name'] ?? 'PURPLE BAMBOO RESORT') ?>" required>
                                    <div class="cms-hint">Official recipient name (e.g. PURPLE BAMBOO RESORT) for guest payment verification.</div>
                                </div>

                                <div class="cms-input-group">
                                    <label class="cms-label"><i class="fa-solid fa-mobile-screen"></i> GCash Mobile / Account Number</label>
                                    <input type="text" name="gcash_number" class="cms-input" value="<?= htmlspecialchars($c['gcash_number'] ?? '0956 697 8679') ?>" required>
                                    <div class="cms-hint">Mobile number linked to GCash (includes 1-click copy button on homepage).</div>
                                </div>

                                <div class="cms-input-group">
                                    <label class="cms-label"><i class="fa-solid fa-heading"></i> Showcase Card Title</label>
                                    <input type="text" name="gcash_card_title" class="cms-input" value="<?= htmlspecialchars($c['gcash_card_title'] ?? 'Official GCash Payment Channel') ?>">
                                </div>

                                <div class="cms-input-group">
                                    <label class="cms-label"><i class="fa-solid fa-subscript"></i> Showcase Card Subtitle</label>
                                    <input type="text" name="gcash_card_subtitle" class="cms-input" value="<?= htmlspecialchars($c['gcash_card_subtitle'] ?? 'Scan with GCash or InstaPay for Instant Reservation Verification') ?>">
                                </div>

                                <div class="cms-input-group" style="margin-bottom: 0;">
                                    <label class="cms-label"><i class="fa-solid fa-list-ol"></i> Step-by-Step Payment Instructions</label>
                                    <textarea name="gcash_instructions" rows="4" class="cms-textarea"><?= htmlspecialchars($c['gcash_instructions'] ?? "1. Open your GCash or Banking app.\n2. Tap 'Scan QR' and scan the official resort QR code.\n3. Input the reservation deposit or total amount.\n4. Take a screenshot of the receipt and reference number.") ?></textarea>
                                    <div class="cms-hint">Displayed clearly to guests directly beside the QR code on the homepage.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== TAB 4: GUARANTEE BANNER ==================== -->
                <div class="cms-tab-pane" id="tab-guarantee">
                    <div class="admin-card" style="padding: 1.75rem; margin-bottom: 1.5rem;">
                        <div style="border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding-bottom: 1rem; margin-bottom: 1.5rem;">
                            <h3 style="color: #fbbf24; font-size: 1.2rem; font-family: 'Outfit', sans-serif; margin-bottom: 4px;">
                                <i class="fa-solid fa-shield-check"></i> Instant GCash Booking Call-To-Action Banner
                            </h3>
                            <p style="color: var(--text-sub); font-size: 0.85rem;">The prominent banner located towards the bottom of the homepage.</p>
                        </div>

                        <div class="cms-input-group">
                            <label class="cms-label"><i class="fa-solid fa-badge-check"></i> Badge Label</label>
                            <input type="text" name="guarantee_badge" class="cms-input" value="<?= htmlspecialchars($c['guarantee_badge']) ?>">
                        </div>

                        <div class="cms-input-group">
                            <label class="cms-label"><i class="fa-solid fa-heading"></i> Main Banner Headline</label>
                            <input type="text" name="guarantee_title" class="cms-input" value="<?= htmlspecialchars($c['guarantee_title']) ?>" required>
                        </div>

                        <div class="cms-input-group">
                            <label class="cms-label"><i class="fa-solid fa-paragraph"></i> Banner Subtitle / Assurance Copy</label>
                            <textarea name="guarantee_subtitle" rows="2" class="cms-textarea"><?= htmlspecialchars($c['guarantee_subtitle']) ?></textarea>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem;">
                            <div class="cms-input-group" style="margin-bottom: 0;">
                                <label class="cms-label"><i class="fa-solid fa-arrow-pointer"></i> Button Label</label>
                                <input type="text" name="guarantee_btn_text" class="cms-input" value="<?= htmlspecialchars($c['guarantee_btn_text']) ?>">
                            </div>
                            <div class="cms-input-group" style="margin-bottom: 0;">
                                <label class="cms-label"><i class="fa-solid fa-link"></i> Button Destination URL</label>
                                <input type="text" name="guarantee_btn_url" class="cms-input" value="<?= htmlspecialchars($c['guarantee_btn_url']) ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== TAB 5: CONTACT & INFO ==================== -->
                <div class="cms-tab-pane" id="tab-info">
                    <div class="admin-card" style="padding: 1.75rem; margin-bottom: 1.5rem;">
                        <div style="border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding-bottom: 1rem; margin-bottom: 1.5rem;">
                            <h3 style="color: #fbbf24; font-size: 1.2rem; font-family: 'Outfit', sans-serif; margin-bottom: 4px;">
                                <i class="fa-solid fa-map-location-dot"></i> Resort Business Information & Top Banner
                            </h3>
                            <p style="color: var(--text-sub); font-size: 0.85rem;">Official contact details, payment credentials, and optional announcement bar.</p>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
                            <div class="cms-input-group" style="margin-bottom: 0;">
                                <label class="cms-label"><i class="fa-solid fa-location-dot"></i> Resort Location</label>
                                <input type="text" name="resort_location" class="cms-input" value="<?= htmlspecialchars($c['resort_location']) ?>">
                            </div>
                            <div class="cms-input-group" style="margin-bottom: 0;">
                                <label class="cms-label"><i class="fa-solid fa-phone"></i> Official Mobile / GCash Number</label>
                                <input type="text" name="resort_phone" class="cms-input" value="<?= htmlspecialchars($c['resort_phone']) ?>">
                            </div>
                            <div class="cms-input-group" style="margin-bottom: 0;">
                                <label class="cms-label"><i class="fa-solid fa-qrcode"></i> GCash Payment Settings</label>
                                <div style="font-size: 0.83rem; color: #cbd5e1; padding-top: 0.4rem;">
                                    GCash QR & details are configured in the <a href="javascript:void(0)" onclick="switchCmsTab('tab-gcash', document.querySelectorAll('.cms-tab-btn')[3])" style="color: #fbbf24; text-decoration: underline; font-weight: 700;">GCash Payment & QR tab</a>.
                                </div>
                            </div>
                        </div>

                        <!-- Optional Announcement Bar -->
                        <div style="background: rgba(124, 58, 237, 0.08); border: 1px dashed rgba(168, 85, 247, 0.35); border-radius: 10px; padding: 1.25rem;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                                <div>
                                    <h4 style="color: #ffffff; font-size: 0.98rem; margin: 0 0 2px 0;">Top Announcement Banner</h4>
                                    <span style="font-size: 0.8rem; color: var(--text-sub);">Optionally show an announcement message at the very top of the page.</span>
                                </div>
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" name="announcement_enabled" value="1" <?= !empty($c['announcement_enabled']) ? 'checked' : '' ?> style="width: 18px; height: 18px; accent-color: #8b5cf6;">
                                    <span style="font-weight: 700; font-size: 0.88rem; color: #fbbf24;">Enable Banner</span>
                                </label>
                            </div>

                            <div class="cms-input-group">
                                <label class="cms-label">Announcement Text</label>
                                <input type="text" name="announcement_text" class="cms-input" value="<?= htmlspecialchars($c['announcement_text'] ?? '') ?>" placeholder="e.g. Special Weekend Promo: 15% off all kubo stays!">
                            </div>

                            <div class="cms-input-group" style="margin-bottom: 0;">
                                <label class="cms-label">Announcement Action Link</label>
                                <input type="text" name="announcement_link" class="cms-input" value="<?= htmlspecialchars($c['announcement_link'] ?? 'browse_kubos.php') ?>" placeholder="browse_kubos.php">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==================== TAB 7: RESORT PROFILE & LOGO (ENHANCED) ==================== -->
                <div class="cms-tab-pane" id="tab-branding">
                    <div class="admin-card" style="padding: 1.75rem; margin-bottom: 1.5rem;">
                        <div style="border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding-bottom: 1rem; margin-bottom: 1.5rem;">
                            <h3 style="color: #fbbf24; font-size: 1.2rem; font-family: 'Outfit', sans-serif; margin-bottom: 4px;">
                                <i class="fa-solid fa-id-card-clip"></i> Resort Profile, Logo & Brand Identity
                            </h3>
                            <p style="color: var(--text-sub); font-size: 0.85rem;">Upload the official resort logo image and manage the resort's display title and subtitle shown across both Admin and Guest interfaces.</p>
                        </div>

                        <!-- LOGO USAGE CHECKLIST -->
                        <div style="background: rgba(59, 130, 246, 0.08); border: 1.5px solid rgba(59, 130, 246, 0.35); border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.75rem;">
                            <h4 style="color: #93c5fd; font-size: 0.95rem; margin: 0 0 0.75rem 0; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-circle-info"></i> Where Your Logo Appears (System-Wide)
                            </h4>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.6rem; font-size: 0.84rem; color: #cbd5e1;">
                                <div><i class="fa-solid fa-check" style="color: #10b981; margin-right: 6px;"></i> Admin Sidebar Header</div>
                                <div><i class="fa-solid fa-check" style="color: #10b981; margin-right: 6px;"></i> Admin Top Navigation Bar</div>
                                <div><i class="fa-solid fa-check" style="color: #10b981; margin-right: 6px;"></i> Admin Login Page</div>
                                <div><i class="fa-solid fa-check" style="color: #10b981; margin-right: 6px;"></i> Guest Homepage (index.php)</div>
                                <div><i class="fa-solid fa-check" style="color: #10b981; margin-right: 6px;"></i> Guest Navigation Bar</div>
                                <div><i class="fa-solid fa-check" style="color: #10b981; margin-right: 6px;"></i> Guest Booking Pages</div>
                                <div><i class="fa-solid fa-check" style="color: #10b981; margin-right: 6px;"></i> Receipts & Invoices</div>
                                <div><i class="fa-solid fa-check" style="color: #10b981; margin-right: 6px;"></i> Browser Favicon (if applicable)</div>
                                <div><i class="fa-solid fa-check" style="color: #10b981; margin-right: 6px;"></i> Email Notifications</div>
                                <div><i class="fa-solid fa-check" style="color: #10b981; margin-right: 6px;"></i> Footer Branding</div>
                            </div>
                            <p style="font-size: 0.78rem; color: #64748b; margin: 0.75rem 0 0 0;">
                                <i class="fa-solid fa-lightbulb" style="color: #fbbf24;"></i> Uploading a new logo automatically updates <strong>all</strong> these locations. A backward-compatible copy is also saved to <code style="color: #fbbf24;">assets/logo.jpg</code> and <code style="color: #fbbf24;">uploads/logo</code>.
                            </p>
                        </div>

                        <!-- Logo Upload & Live Preview Grid -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
                            <!-- Current Logo Card -->
                            <div style="background: rgba(255, 255, 255, 0.03); border: 1.5px solid rgba(255, 255, 255, 0.1); border-radius: 12px; padding: 1.5rem; text-align: center;">
                                <label class="cms-label" style="display: block; margin-bottom: 0.75rem; text-align: center;">
                                    <i class="fa-solid fa-image"></i> Active Resort Logo
                                </label>
                                <div style="display: inline-block; padding: 8px; border-radius: 12px; background: rgba(0, 0, 0, 0.4); border: 2px solid #8b5cf6; margin-bottom: 1rem; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);">
                                    <img id="logo_preview_display" src="<?= htmlspecialchars($c['resort_logo'] ?? 'assets/logo.jpg') ?>?v=<?= time() ?>" alt="Resort Logo Preview" style="width: 130px; height: 130px; object-fit: cover; border-radius: 8px; display: block;">
                                </div>
                                <div style="font-size: 0.8rem; color: #94a3b8; line-height: 1.5;">
                                    Current File: <code style="color: #fbbf24; word-break: break-all;"><?= htmlspecialchars($c['resort_logo'] ?? 'assets/logo.jpg') ?></code>
                                </div>
                                <?php if (!empty($c['resort_logo']) && $c['resort_logo'] !== 'assets/logo.jpg'): ?>
                                    <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" style="margin-top: 0.9rem; color: #f43f5e; border-color: #f43f5e;" onclick="confirmRemoveLogo()">
                                        <i class="fa-solid fa-trash-can"></i> Remove & Use Default
                                    </button>
                                <?php endif; ?>
                            </div>

                            <!-- Upload New Logo Form (Drag & Drop) -->
                            <div id="logo-dropzone" class="upload-dropzone"
                                 ondragover="event.preventDefault(); this.classList.add('dragover');"
                                 ondragleave="this.classList.remove('dragover');"
                                 ondrop="handleLogoDrop(event);"
                                 onclick="document.getElementById('resort_logo_file').click();">
                                <div style="text-align: center; margin-bottom: 1rem;">
                                    <i class="fa-solid fa-cloud-arrow-up" style="font-size: 2.5rem; color: #a855f7; margin-bottom: 0.75rem; display: block;"></i>
                                    <label class="cms-label" style="margin-bottom: 0.4rem; cursor: pointer;">
                                        Upload New Logo File
                                    </label>
                                    <p style="font-size: 0.82rem; color: #cbd5e1; margin: 0 0 0.5rem 0; line-height: 1.5;">
                                        <strong style="color: #fbbf24;">Click to browse</strong> or drag & drop an image here.
                                    </p>
                                </div>
                                <input type="hidden" name="resort_logo" value="<?= htmlspecialchars($c['resort_logo'] ?? 'assets/logo.jpg') ?>">
                                <input type="hidden" name="remove_logo" id="remove_logo_flag" value="">
                                <input type="file" name="resort_logo_file" id="resort_logo_file" class="cms-input" accept=".jpg,.jpeg,.png,.webp,.svg,.gif" onchange="handleLogoUploadPreview(this)" style="padding: 0.5rem; background: rgba(0, 0, 0, 0.25);">
                                <div class="cms-hint" style="margin-top: 0.6rem; text-align: center;">
                                    <i class="fa-solid fa-circle-check" style="color: #10b981;"></i> Recommended: Square ratio (500x500px) • PNG, JPG, WEBP, SVG • Max 5MB
                                </div>
                                <div id="logo_upload_status" style="margin-top: 0.75rem; font-size: 0.82rem; text-align: center; display: none;"></div>
                            </div>
                        </div>

                        <!-- Brand Title & Subtitle Inputs -->
                        <div style="border-top: 1px solid rgba(255, 255, 255, 0.08); padding-top: 1.5rem; margin-bottom: 1.5rem;">
                            <h4 style="color: #ffffff; font-size: 1.05rem; font-family: 'Outfit', sans-serif; margin-bottom: 1rem;">
                                <i class="fa-solid fa-font"></i> Brand Header & Resort Name Settings
                            </h4>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem;">
                                <div class="cms-input-group" style="margin-bottom: 0;">
                                    <label class="cms-label"><i class="fa-solid fa-crown"></i> Primary Brand Title (Line 1)</label>
                                    <input type="text" name="resort_brand_title" id="inp_brand_title" class="cms-input" value="<?= htmlspecialchars($c['resort_brand_title'] ?? 'PURPLE BAMBOO') ?>" oninput="updateBrandPreview()" required>
                                    <div class="cms-hint">E.g., "PURPLE BAMBOO" (displayed bold in nav headers).</div>
                                </div>
                                <div class="cms-input-group" style="margin-bottom: 0;">
                                    <label class="cms-label"><i class="fa-solid fa-water"></i> Brand Subtitle (Line 2)</label>
                                    <input type="text" name="resort_brand_subtitle" id="inp_brand_subtitle" class="cms-input" value="<?= htmlspecialchars($c['resort_brand_subtitle'] ?? 'BEACH RESORT') ?>" oninput="updateBrandPreview()">
                                    <div class="cms-hint">E.g., "BEACH RESORT" (displayed beneath brand title).</div>
                                </div>
                                <div class="cms-input-group" style="margin-bottom: 0;">
                                    <label class="cms-label"><i class="fa-solid fa-hotel"></i> Full Resort Display Name</label>
                                    <input type="text" name="resort_name" id="inp_resort_name" class="cms-input" value="<?= htmlspecialchars($c['resort_name'] ?? 'Purple Bamboo Resort') ?>">
                                    <div class="cms-hint">Formal name for receipts, footer, and notifications.</div>
                                </div>
                            </div>
                        </div>

                        <!-- Live Navbar & Sidebar Preview Mockup -->
                        <div style="border-top: 1px solid rgba(255, 255, 255, 0.08); padding-top: 1.5rem;">
                            <label class="cms-label" style="margin-bottom: 0.75rem;"><i class="fa-solid fa-desktop"></i> Live Display Mockup Preview</label>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.25rem;">
                                <!-- Admin Sidebar Brand Preview -->
                                <div style="background: #0f0a21; border: 1px solid rgba(124, 58, 237, 0.4); border-radius: 10px; padding: 1rem 1.25rem;">
                                    <span style="font-size: 0.72rem; color: #a78bfa; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 0.75rem;">Admin Sidebar Header</span>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <img id="mockup_admin_logo" src="<?= htmlspecialchars($c['resort_logo'] ?? 'assets/logo.jpg') ?>?v=<?= time() ?>" style="width: 34px; height: 34px; border-radius: 8px; object-fit: cover; border: 1px solid rgba(251, 191, 36, 0.5);">
                                        <div>
                                            <div id="mockup_admin_title" style="font-weight: 800; font-size: 0.98rem; color: #ffffff; letter-spacing: 0.5px; font-family: 'Outfit', sans-serif;">
                                                <?= htmlspecialchars($c['resort_brand_title'] ?? 'PURPLE BAMBOO') ?>
                                            </div>
                                            <div style="font-size: 0.72rem; color: #fbbf24; font-weight: 700; text-transform: uppercase;">ADMIN CONSOLE</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Guest Navigation Brand Preview -->
                                <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1rem 1.25rem;">
                                    <span style="font-size: 0.72rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 0.75rem;">Guest Sidebar / Navbar Header</span>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <img id="mockup_guest_logo" src="<?= htmlspecialchars($c['resort_logo'] ?? 'assets/logo.jpg') ?>?v=<?= time() ?>" style="width: 36px; height: 36px; border-radius: 8px; object-fit: cover; border: 1.5px solid #d97706;">
                                        <div>
                                            <div id="mockup_guest_title" style="font-weight: 800; font-size: 0.98rem; color: #4c1d95; letter-spacing: 0.5px; line-height: 1.1; font-family: 'Outfit', sans-serif;">
                                                <?= htmlspecialchars($c['resort_brand_title'] ?? 'PURPLE BAMBOO') ?>
                                            </div>
                                            <div id="mockup_guest_subtitle" style="font-size: 0.72rem; color: #d97706; font-weight: 700; letter-spacing: 1px;">
                                                <?= htmlspecialchars($c['resort_brand_subtitle'] ?? 'BEACH RESORT') ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Sticky Bottom Action Bar -->
                <div class="sticky-save-bar" id="cmsStickySaveBar" style="<?= $is_profile_active ? 'display: none;' : '' ?>">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="admin-badge admin-badge-purple" style="font-size: 0.75rem;">
                            <i class="fa-solid fa-clock"></i> Last updated: <?= htmlspecialchars($c['updated_at'] ?? 'Initial') ?>
                        </span>
                        <?php if (!empty($c['updated_by'])): ?>
                            <span style="font-size: 0.78rem; color: var(--text-sub);">by <?= htmlspecialchars($c['updated_by']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div style="display: flex; align-items: center; gap: 10px;">
                        <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" style="color: #f43f5e; border-color: #f43f5e;" onclick="confirmResetDefaults()">
                            <i class="fa-solid fa-rotate-left"></i> Reset Defaults
                        </button>
                        <button type="submit" class="admin-btn admin-btn-gold" style="font-weight: 800; padding: 0.75rem 1.75rem; font-size: 0.95rem;">
                            <i class="fa-solid fa-floppy-disk"></i> Save & Publish Changes
                        </button>
                    </div>
                </div>
            </form>

            <!-- ==================== TAB 8: ADMIN PROFILE & SECURITY ==================== -->
            <div class="cms-tab-pane <?= $is_profile_active ? 'active' : '' ?>" id="tab-admin-profile">
                
                <!-- Executive Profile Banner Card -->
                <div class="admin-card" style="padding: 1.75rem 2rem; margin-bottom: 1.75rem; background: linear-gradient(135deg, rgba(30, 16, 68, 0.75) 0%, rgba(18, 9, 44, 0.95) 100%); border: 1.5px solid rgba(139, 92, 246, 0.35); border-radius: 16px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1.5rem;">
                        <div style="display: flex; align-items: center; gap: 1.5rem;">
                            <div style="width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #7c3aed 0%, #f59e0b 100%); display: flex; align-items: center; justify-content: center; font-size: 2.2rem; font-weight: 800; color: #ffffff; box-shadow: 0 4px 20px rgba(124, 58, 237, 0.5); border: 2px solid rgba(255, 255, 255, 0.2); flex-shrink: 0;">
                                <?= strtoupper(substr($admin_data['full_name'] ?? 'Admin', 0, 1)) ?>
                            </div>
                            <div>
                                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                    <h2 style="font-family: 'Outfit', sans-serif; font-size: 1.6rem; color: #ffffff; margin: 0; font-weight: 800;"><?= htmlspecialchars($admin_data['full_name'] ?? 'Administrator') ?></h2>
                                    <span class="admin-badge admin-badge-purple" style="font-size: 0.75rem; text-transform: uppercase; font-weight: 800;">
                                        <i class="fa-solid fa-shield-halved"></i> <?= htmlspecialchars($admin_data['role'] ?? 'admin') ?>
                                    </span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 14px; margin-top: 8px; flex-wrap: wrap; font-size: 0.82rem; color: #cbd5e1;">
                                    <span><i class="fa-solid fa-envelope" style="color: #a78bfa; margin-right: 5px;"></i> <?= htmlspecialchars($admin_data['email'] ?? 'admin@purplebamboo.com') ?></span>
                                    <span><i class="fa-solid fa-phone" style="color: #a78bfa; margin-right: 5px;"></i> <?= htmlspecialchars($admin_data['phone'] ?? '09612613415') ?></span>
                                    <span style="color: #fbbf24; font-weight: 700; background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.3); padding: 2px 8px; border-radius: 6px;">
                                        <i class="fa-solid fa-id-badge"></i> <?= htmlspecialchars($admin_data['profile_id'] ?? 'PBR-ADM-001') ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 6px;">
                            <span style="color: #10b981; font-weight: 700; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 6px; background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.3); padding: 4px 12px; border-radius: 20px;">
                                <span style="width: 7px; height: 7px; border-radius: 50%; background: #10b981; box-shadow: 0 0 6px #10b981;"></span> Active Admin Session
                            </span>
                            <span style="font-size: 0.75rem; color: var(--text-sub);">Full CMS & System Administration Privilege</span>
                        </div>
                    </div>
                </div>

                <!-- Forms Grid (Profile Information + Security/Password) -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 1.5rem;">
                    
                    <!-- Box 1: Personal Profile Details -->
                    <div class="admin-card" style="padding: 1.75rem;">
                        <div style="border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding-bottom: 1rem; margin-bottom: 1.5rem;">
                            <h3 style="color: #fbbf24; font-size: 1.15rem; font-family: 'Outfit', sans-serif; margin-bottom: 4px;">
                                <i class="fa-solid fa-user-pen"></i> Personal Information
                            </h3>
                            <p style="color: var(--text-sub); font-size: 0.82rem;">Update your administrative display name and personal contact number.</p>
                        </div>

                        <form action="edit_homepage.php?tab=profile" method="POST">
                            <input type="hidden" name="action" value="update_admin_profile">

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <div class="cms-input-group">
                                    <label class="cms-label"><i class="fa-solid fa-user"></i> First Name</label>
                                    <input type="text" name="first_name" class="cms-input" value="<?= htmlspecialchars($admin_data['first_name'] ?? '') ?>" required>
                                </div>
                                <div class="cms-input-group">
                                    <label class="cms-label">Middle Name</label>
                                    <input type="text" name="middle_name" class="cms-input" value="<?= htmlspecialchars($admin_data['middle_name'] ?? '') ?>">
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 12px;">
                                <div class="cms-input-group">
                                    <label class="cms-label"><i class="fa-solid fa-user"></i> Last Name</label>
                                    <input type="text" name="last_name" class="cms-input" value="<?= htmlspecialchars($admin_data['last_name'] ?? '') ?>" required>
                                </div>
                                <div class="cms-input-group">
                                    <label class="cms-label">Suffix</label>
                                    <select name="suffix" class="cms-input">
                                        <option value="">None</option>
                                        <option value="Jr." <?= (($admin_data['suffix'] ?? '') === 'Jr.') ? 'selected' : '' ?>>Jr.</option>
                                        <option value="Sr." <?= (($admin_data['suffix'] ?? '') === 'Sr.') ? 'selected' : '' ?>>Sr.</option>
                                        <option value="II" <?= (($admin_data['suffix'] ?? '') === 'II') ? 'selected' : '' ?>>II</option>
                                        <option value="III" <?= (($admin_data['suffix'] ?? '') === 'III') ? 'selected' : '' ?>>III</option>
                                        <option value="IV" <?= (($admin_data['suffix'] ?? '') === 'IV') ? 'selected' : '' ?>>IV</option>
                                        <option value="V" <?= (($admin_data['suffix'] ?? '') === 'V') ? 'selected' : '' ?>>V</option>
                                    </select>
                                </div>
                            </div>

                            <div class="cms-input-group">
                                <label class="cms-label"><i class="fa-solid fa-envelope"></i> Email Address</label>
                                <input type="email" class="cms-input" value="<?= htmlspecialchars($admin_data['email'] ?? '') ?>" disabled style="opacity: 0.7; cursor: not-allowed; background: rgba(0,0,0,0.3);">
                                <div class="cms-hint" style="color: #64748b;"><i class="fa-solid fa-lock"></i> Email is your primary login identifier and cannot be modified here.</div>
                            </div>

                            <div class="cms-input-group">
                                <label class="cms-label"><i class="fa-solid fa-phone"></i> Phone Number</label>
                                <input type="text" name="phone" class="cms-input" value="<?= htmlspecialchars($admin_data['phone'] ?? '') ?>" placeholder="09xxxxxxxxx">
                                <div class="cms-hint">Used for urgent SMS notifications and administrative contact.</div>
                            </div>

                            <div style="margin-top: 1.5rem; border-top: 1px solid rgba(255, 255, 255, 0.08); padding-top: 1rem; display: flex; justify-content: flex-end;">
                                <button type="submit" class="admin-btn admin-btn-purple" style="font-weight: 700; padding: 0.75rem 1.5rem; display: inline-flex; align-items: center; gap: 8px;">
                                    <i class="fa-solid fa-floppy-disk"></i> Save Profile Details
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Box 2: Password & Account Security -->
                    <div class="admin-card" style="padding: 1.75rem;">
                        <div style="border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding-bottom: 1rem; margin-bottom: 1.5rem;">
                            <h3 style="color: #f43f5e; font-size: 1.15rem; font-family: 'Outfit', sans-serif; margin-bottom: 4px;">
                                <i class="fa-solid fa-shield-keyhole"></i> Account Security & Password
                            </h3>
                            <p style="color: var(--text-sub); font-size: 0.82rem;">Protect your account with a secure password of at least 6 characters.</p>
                        </div>

                        <form action="edit_homepage.php?tab=profile" method="POST">
                            <input type="hidden" name="action" value="update_admin_password">

                            <div class="cms-input-group">
                                <label class="cms-label"><i class="fa-solid fa-lock"></i> Current Password</label>
                                <div style="position: relative;">
                                    <input type="password" name="current_password" id="admin_curr_pwd" class="cms-input" required placeholder="Enter your current password">
                                    <button type="button" onclick="togglePasswordVisibility('admin_curr_pwd', this)" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #94a3b8; cursor: pointer;">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="cms-input-group">
                                <label class="cms-label"><i class="fa-solid fa-key"></i> New Password</label>
                                <div style="position: relative;">
                                    <input type="password" name="new_password" id="admin_new_pwd" class="cms-input" required minlength="6" placeholder="At least 6 characters">
                                    <button type="button" onclick="togglePasswordVisibility('admin_new_pwd', this)" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #94a3b8; cursor: pointer;">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="cms-input-group">
                                <label class="cms-label"><i class="fa-solid fa-circle-check"></i> Confirm New Password</label>
                                <div style="position: relative;">
                                    <input type="password" name="confirm_password" id="admin_conf_pwd" class="cms-input" required minlength="6" placeholder="Re-type new password">
                                    <button type="button" onclick="togglePasswordVisibility('admin_conf_pwd', this)" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #94a3b8; cursor: pointer;">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 8px; padding: 0.75rem 1rem; margin-top: 1rem; display: flex; align-items: center; gap: 10px; font-size: 0.78rem; color: #fde68a;">
                                <i class="fa-solid fa-triangle-exclamation" style="font-size: 1rem; color: #fbbf24; flex-shrink: 0;"></i>
                                <span>After changing your password, your active session remains safe. Make sure to remember your new password for your next login.</span>
                            </div>

                            <div style="margin-top: 1.5rem; border-top: 1px solid rgba(255, 255, 255, 0.08); padding-top: 1rem; display: flex; justify-content: flex-end;">
                                <button type="submit" class="admin-btn admin-btn-gold" style="font-weight: 700; padding: 0.75rem 1.5rem; display: inline-flex; align-items: center; gap: 8px;">
                                    <i class="fa-solid fa-key"></i> Update Password
                                </button>
                            </div>
                        </form>
                    </div>

                </div>
            </div>

            <!-- Hidden Reset Form -->
            <form id="resetForm" action="edit_homepage.php" method="POST" style="display: none;">
                <input type="hidden" name="action" value="reset_defaults">
            </form>

        </div>
    </div>

    <script>
    function switchCmsTab(tabId, btn) {
        document.querySelectorAll('.cms-tab-pane').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.cms-tab-btn').forEach(b => b.classList.remove('active'));
        const pane = document.getElementById(tabId);
        if (pane) pane.classList.add('active');
        if (btn) btn.classList.add('active');

        const stickyBar = document.getElementById('cmsStickySaveBar');
        const previewBox = document.getElementById('cmsLivePreviewBox');
        if (tabId === 'tab-admin-profile') {
            if (stickyBar) stickyBar.style.display = 'none';
            if (previewBox) previewBox.style.display = 'none';
        } else {
            if (stickyBar) stickyBar.style.display = 'flex';
            if (previewBox) previewBox.style.display = 'block';
        }
    }

    function togglePasswordVisibility(inputId, btn) {
        const input = document.getElementById(inputId);
        if (!input) return;
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            if (icon) {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            }
        } else {
            input.type = 'password';
            if (icon) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    }

    // ============ QR UPLOAD HANDLERS ============
    function handleQrUploadPreview(input) {
        if (input.files && input.files[0]) {
            const file = input.files[0];
            const maxSize = 5 * 1024 * 1024; // 5MB
            const statusEl = document.getElementById('qr_upload_status');

            if (file.size > maxSize) {
                statusEl.style.display = 'block';
                statusEl.style.color = '#f43f5e';
                statusEl.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> File too large. Max 5MB.';
                input.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('qr_preview_display');
                if (preview) preview.src = e.target.result;

                statusEl.style.display = 'block';
                statusEl.style.color = '#10b981';
                statusEl.innerHTML = '<i class="fa-solid fa-circle-check"></i> Ready to upload: ' + file.name;
            };
            reader.readAsDataURL(file);
        }
    }

    function handleQrDrop(event) {
        event.preventDefault();
        const dropzone = document.getElementById('qr-dropzone');
        if (dropzone) dropzone.classList.remove('dragover');

        const files = event.dataTransfer.files;
        if (files.length > 0) {
            const input = document.getElementById('gcash_qr_file');
            input.files = files;
            handleQrUploadPreview(input);
        }
    }

    function confirmRemoveQr() {
        if (confirm('Remove the custom QR code and revert to the default assets/gcash_qr.jpg?')) {
            document.getElementById('remove_qr_flag').value = '1';
            document.getElementById('cmsForm').submit();
        }
    }

    // ============ LOGO UPLOAD HANDLERS ============
    function handleLogoUploadPreview(input) {
        if (input.files && input.files[0]) {
            const file = input.files[0];
            const maxSize = 5 * 1024 * 1024; // 5MB
            const statusEl = document.getElementById('logo_upload_status');

            if (file.size > maxSize) {
                statusEl.style.display = 'block';
                statusEl.style.color = '#f43f5e';
                statusEl.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> File too large. Max 5MB.';
                input.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                const dataUrl = e.target.result;
                const p1 = document.getElementById('logo_preview_display');
                if (p1) p1.src = dataUrl;
                const p2 = document.getElementById('mockup_admin_logo');
                if (p2) p2.src = dataUrl;
                const p3 = document.getElementById('mockup_guest_logo');
                if (p3) p3.src = dataUrl;

                statusEl.style.display = 'block';
                statusEl.style.color = '#10b981';
                statusEl.innerHTML = '<i class="fa-solid fa-circle-check"></i> Ready to upload: ' + file.name;
            };
            reader.readAsDataURL(file);
        }
    }

    function handleLogoDrop(event) {
        event.preventDefault();
        const dropzone = document.getElementById('logo-dropzone');
        if (dropzone) dropzone.classList.remove('dragover');

        const files = event.dataTransfer.files;
        if (files.length > 0) {
            const input = document.getElementById('resort_logo_file');
            input.files = files;
            handleLogoUploadPreview(input);
        }
    }

    function confirmRemoveLogo() {
        if (confirm('Remove the custom logo and revert to the default assets/logo.jpg?')) {
            document.getElementById('remove_logo_flag').value = '1';
            document.getElementById('cmsForm').submit();
        }
    }

    // ============ BRAND PREVIEW ============
    function updateBrandPreview() {
        const titleVal = document.getElementById('inp_brand_title')?.value || 'PURPLE BAMBOO';
        const subVal = document.getElementById('inp_brand_subtitle')?.value || 'BEACH RESORT';
        
        const aTitle = document.getElementById('mockup_admin_title');
        if (aTitle) aTitle.textContent = titleVal;
        
        const gTitle = document.getElementById('mockup_guest_title');
        if (gTitle) gTitle.textContent = titleVal;

        const gSub = document.getElementById('mockup_guest_subtitle');
        if (gSub) gSub.textContent = subVal;
    }

    function confirmResetDefaults() {
        if (confirm('Are you sure you want to reset all homepage content back to default resort settings? Any custom text will be replaced.')) {
            document.getElementById('resetForm').submit();
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const tabParam = urlParams.get('tab');
        const hash = window.location.hash.replace('#', '');
        
        if (tabParam === 'profile' || hash === 'tab-admin-profile') {
            const btn = document.getElementById('btn-tab-admin-profile');
            if (btn) switchCmsTab('tab-admin-profile', btn);
        } else if (hash) {
            const targetBtn = document.querySelector(`[onclick*="'${hash}'"]`);
            if (targetBtn) {
                switchCmsTab(hash, targetBtn);
            }
        }
        
    });
    </script>
</body>
</html>