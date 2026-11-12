<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_role = $_SESSION['role'] ?? 'guest';
$is_admin_layout = ($user_role === 'admin' || $user_role === 'staff');

require_once __DIR__ . '/inventory_store.php';

// Handle POST actions for Admin with PRG pattern
if ($is_admin_layout && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add_activity') {
        $image_file = $_FILES['image_file'] ?? null;
        $new_id = InventoryStore::addActivity($_POST, $image_file);
        if ($new_id) {
            $name = htmlspecialchars($_POST['activity_name'] ?? 'Activity');
            $_SESSION['activity_flash'] = [
                'type' => 'success',
                'msg' => "New activity \"{$name}\" added to resort inventory successfully!"
            ];
        } else {
            $_SESSION['activity_flash'] = [
                'type' => 'error',
                'msg' => 'Failed to add new activity.'
            ];
        }
        header("Location: browse_activities.php");
        exit();
    } elseif ($action === 'edit_activity') {
        $id = intval($_POST['activity_id'] ?? 0);
        if ($id > 0) {
            $image_file = $_FILES['image_file'] ?? null;
            $res = InventoryStore::saveActivityDetails($id, $_POST, $image_file);
            if ($res) {
                $name = htmlspecialchars($_POST['activity_name'] ?? 'Activity');
                $_SESSION['activity_flash'] = [
                    'type' => 'success',
                    'msg' => "Activity \"{$name}\" details, price, and status updated successfully!"
                ];
            } else {
                $_SESSION['activity_flash'] = [
                    'type' => 'error',
                    'msg' => 'Failed to update activity details.'
                ];
            }
        }
        header("Location: browse_activities.php");
        exit();
    } elseif ($action === 'delete_activity') {
        $id = intval($_POST['activity_id'] ?? 0);
        if ($id > 0) {
            $result = InventoryStore::deleteActivity($id);
            if (is_array($result) && !$result['success']) {
                $_SESSION['activity_flash'] = [
                    'type' => 'error',
                    'msg' => $result['msg']
                ];
            } else {
                $_SESSION['activity_flash'] = [
                    'type' => 'success',
                    'msg' => 'Activity deleted from resort inventory.'
                ];
            }
        }
        header("Location: browse_activities.php");
        exit();
    } elseif ($action === 'toggle_activity_status') {
        $id = intval($_POST['activity_id'] ?? 0);
        $new_status = intval($_POST['is_available'] ?? 0);
        if ($id > 0) {
            InventoryStore::toggleActivityStatus($id, $new_status);
            $_SESSION['activity_flash'] = [
                'type' => 'success',
                'msg' => 'Activity operational status updated!'
            ];
        }
        header("Location: browse_activities.php");
        exit();
    }
}

// Flash message
$flash = $_SESSION['activity_flash'] ?? null;
unset($_SESSION['activity_flash']);

$search_query = trim($_GET['search'] ?? '');
$all_activities = InventoryStore::getAllActivities(true);

// Filter by search if provided
if (!empty($search_query)) {
    $all_activities = array_filter($all_activities, function($act) use ($search_query) {
        return stripos($act['activity_name'], $search_query) !== false
            || stripos($act['description'], $search_query) !== false
            || stripos($act['category'] ?? '', $search_query) !== false;
    });
}

// Calculate Stats
$total_acts = count($all_activities);
$available_count = 0;
$maintenance_count = 0;
foreach ($all_activities as $a) {
    if (!empty($a['is_available'])) $available_count++;
    else $maintenance_count++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Pool & Resort Activities | Purple Bamboo Resort</title>
    <?php if ($is_admin_layout): ?>
        <link rel="stylesheet" href="admin-style.css?v=<?= time() ?>">
    <?php else: ?>
        <link rel="stylesheet" href="style.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Modern Activity Cards & Grid System */
        .activities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 340px), 1fr));
            gap: 1.75rem;
            width: 100%;
            margin-bottom: 2.5rem;
        }
        @media (max-width: 768px) {
            .activities-grid {
                grid-template-columns: 1fr;
                gap: 1.25rem;
            }
        }

        .activity-card {
            background: #ffffff;
            border: 1.5px solid var(--admin-card-border, #e2e8f0);
            border-radius: 16px;
            overflow: hidden;
            position: relative;
            display: flex;
            flex-direction: column;
            height: 100%;
            box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06);
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.25s cubic-bezier(0.4, 0, 0.2, 1), border-color 0.25s ease;
        }
        .activity-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 36px -6px rgba(224, 18, 156, 0.16);
            border-color: rgba(224, 18, 156, 0.4);
        }
        .activity-card.is-maintenance {
            border-color: #fb923c;
            box-shadow: 0 4px 20px -2px rgba(234, 88, 12, 0.08);
        }

        .activity-card-media {
            position: relative;
            height: 200px;
            width: 100%;
            overflow: hidden;
            background: #0f172a;
        }
        .activity-card-media img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        .activity-card:hover .activity-card-media img {
            transform: scale(1.04);
        }

        .activity-card-body {
            padding: 1.35rem 1.45rem;
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .activity-card-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.2rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
            line-height: 1.35;
            min-height: 2.7rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .activity-card-desc {
            color: #64748b;
            font-size: 0.88rem;
            line-height: 1.55;
            margin: 0.65rem 0 1rem 0;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 2.75rem;
        }

        .activity-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #334155;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 0.32rem 0.7rem;
            border-radius: 8px;
            white-space: nowrap;
        }

        .activity-btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-weight: 700;
            font-size: 0.84rem;
            padding: 0.55rem 0.8rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
            min-height: 38px;
            text-decoration: none;
        }
        .activity-btn-action:hover {
            transform: translateY(-1px);
        }

        /* User Guest Hero Banner & Filter Bar */
        .user-activities-hero {
            background: linear-gradient(135deg, #1e1b4b 0%, #31104b 50%, #4c1d95 100%);
            border-radius: 20px;
            padding: 2.25rem 2.5rem;
            margin-bottom: 2rem;
            color: #ffffff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px -5px rgba(30, 27, 75, 0.25);
        }
        .user-activities-hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 380px;
            height: 380px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(168, 85, 247, 0.35) 0%, transparent 70%);
            pointer-events: none;
        }
        .user-activities-hero::after {
            content: '🌊';
            position: absolute;
            right: 25px;
            bottom: -20px;
            font-size: 8rem;
            opacity: 0.12;
            pointer-events: none;
        }
        .user-hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: #f5f3ff;
            font-size: 0.78rem;
            font-weight: 800;
            padding: 0.35rem 0.85rem;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 0.85rem;
        }
        .user-hero-title {
            font-family: 'Outfit', sans-serif;
            font-size: clamp(1.8rem, 4vw, 2.4rem);
            font-weight: 900;
            color: #ffffff;
            margin: 0 0 0.65rem 0;
            line-height: 1.2;
        }
        .user-hero-desc {
            font-size: 1rem;
            color: #e9d5ff;
            max-width: 680px;
            line-height: 1.6;
            margin: 0 0 1.5rem 0;
        }
        .user-hero-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
        }
        .user-hero-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(15, 23, 42, 0.45);
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 0.35rem 0.85rem;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 700;
            color: #ffffff;
        }

        /* Filter Tabs & Search Bar */
        .activities-filter-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        .activities-cat-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .act-tab-btn {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            color: #475569;
            font-weight: 700;
            font-size: 0.88rem;
            padding: 0.6rem 1.15rem;
            border-radius: 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        }
        .act-tab-btn:hover {
            border-color: #c084fc;
            background: #faf5ff;
            color: #6b21a8;
        }
        .act-tab-btn.active {
            background: linear-gradient(135deg, #7c3aed, #9333ea);
            color: #ffffff;
            border-color: #7c3aed;
            box-shadow: 0 4px 14px rgba(124, 58, 237, 0.3);
        }
        .activities-search-box {
            position: relative;
            min-width: 260px;
            flex-grow: 1;
            max-width: 360px;
        }
        .activities-search-box input {
            width: 100%;
            padding: 0.65rem 1rem 0.65rem 2.5rem;
            border-radius: 12px;
            border: 1.5px solid #e2e8f0;
            font-size: 0.9rem;
            outline: none;
            transition: all 0.2s ease;
            background: #ffffff;
            color: #0f172a;
        }
        .activities-search-box input:focus {
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15);
        }
        .activities-search-box .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.9rem;
        }
        .user-btn-reserve {
            background: linear-gradient(135deg, #7c3aed 0%, #9333ea 100%);
            color: #ffffff !important;
            font-weight: 800;
            padding: 0.75rem 1.25rem;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            text-decoration: none;
            transition: all 0.25s ease;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.25);
            border: none;
        }
        .user-btn-reserve:hover {
            background: linear-gradient(135deg, #6d28d9 0%, #7c3aed 100%);
            box-shadow: 0 6px 20px rgba(124, 58, 237, 0.4);
            transform: translateY(-1px);
        }

        /* Modern Activity Modal Design (Exact match to screenshot) */
        @keyframes modalPop {
            0% { opacity: 0; transform: scale(0.96) translateY(12px); }
            100% { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-custom-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(5, 8, 18, 0.78);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .modal-custom-window {
            background: #0f172a;
            border: 1.5px solid rgba(139, 92, 246, 0.35);
            border-radius: 20px;
            width: 100%;
            max-width: 850px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.95), 0 0 40px rgba(124, 58, 237, 0.2);
            animation: modalPop 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
        }
        .modal-custom-window::-webkit-scrollbar {
            width: 8px;
        }
        .modal-custom-window::-webkit-scrollbar-track {
            background: #090d16;
            border-radius: 0 20px 20px 0;
        }
        .modal-custom-window::-webkit-scrollbar-thumb {
            background: rgba(168, 85, 247, 0.45);
            border-radius: 4px;
        }
        .modal-custom-window::-webkit-scrollbar-thumb:hover {
            background: rgba(168, 85, 247, 0.75);
        }
        .modal-custom-header {
            padding: 1.2rem 1.6rem;
            background: linear-gradient(135deg, #7c3aed 0%, #4c1d95 100%);
            border-radius: 18px 18px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        }
        .modal-custom-header h3 {
            font-family: 'Outfit', sans-serif;
            color: #ffffff;
            margin: 0;
            font-size: 1.22rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: -0.2px;
        }
        .modal-custom-close {
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: #ffffff;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.05rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        .modal-custom-close:hover {
            background: rgba(239, 68, 68, 0.85);
            border-color: #ef4444;
            transform: scale(1.08) rotate(90deg);
        }
        .modal-form-body {
            padding: 1.5rem 1.65rem;
        }
        .modal-field-label {
            display: flex;
            align-items: center;
            gap: 7px;
            font-weight: 700;
            color: #f1f5f9;
            margin-bottom: 0.45rem;
            font-size: 0.88rem;
        }
        .modal-field-input {
            width: 100%;
            background: #0b1120;
            border: 1.5px solid rgba(139, 92, 246, 0.32);
            color: #ffffff;
            padding: 0.7rem 0.95rem;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
            box-sizing: border-box;
        }
        .modal-field-input:focus {
            border-color: #c084fc;
            background: #0e1628;
            box-shadow: 0 0 0 3px rgba(192, 132, 252, 0.25);
        }
        .modal-box-container {
            background: rgba(30, 41, 59, 0.45);
            border: 1.5px solid rgba(139, 92, 246, 0.28);
            border-radius: 14px;
            padding: 1.2rem 1.35rem;
            margin-bottom: 1.25rem;
        }
        .modal-photo-container {
            background: rgba(30, 41, 59, 0.35);
            border: 1.5px dashed rgba(139, 92, 246, 0.38);
            border-radius: 14px;
            padding: 1.2rem 1.35rem;
            margin-bottom: 1.25rem;
        }
        .modal-stat-box {
            background: #0b1120;
            border: 1.5px solid rgba(139, 92, 246, 0.32);
            border-radius: 10px;
            padding: 0.75rem 0.95rem;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .modal-stat-box:focus-within {
            border-color: #c084fc;
            box-shadow: 0 0 0 2px rgba(192, 132, 252, 0.2);
        }
        .equipment-row-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(124, 58, 237, 0.12);
            border: 1px solid rgba(139, 92, 246, 0.25);
            border-radius: 8px;
            padding: 0.35rem 0.7rem;
            font-size: 0.82rem;
            color: #e2e8f0;
            font-weight: 600;
        }
        .equipment-row-item span.qty {
            color: #c084fc;
            font-weight: 800;
            font-size: 0.85rem;
        }
        .btn-modal-save-gold {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 50%, #d97706 100%);
            color: #0f172a;
            border: none;
            border-radius: 10px;
            font-weight: 800;
            font-size: 0.96rem;
            padding: 0.82rem 1.6rem;
            cursor: pointer;
            flex: 1.25;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            box-shadow: 0 4px 18px rgba(245, 158, 11, 0.4);
            transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
        }
        .btn-modal-save-gold:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.55);
            filter: brightness(1.05);
        }
        .btn-modal-save-purple {
            background: linear-gradient(135deg, #a855f7 0%, #7e22ce 100%);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-weight: 800;
            font-size: 0.96rem;
            padding: 0.82rem 1.6rem;
            cursor: pointer;
            flex: 1.25;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            box-shadow: 0 4px 18px rgba(168, 85, 247, 0.4);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .btn-modal-save-purple:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(168, 85, 247, 0.55);
        }
        .btn-modal-cancel-white {
            background: rgba(255, 255, 255, 0.08);
            color: #f1f5f9;
            border: 1.5px solid rgba(255, 255, 255, 0.18);
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.95rem;
            padding: 0.82rem 1.4rem;
            cursor: pointer;
            flex: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            transition: all 0.2s ease;
        }
        .btn-modal-cancel-white:hover {
            background: rgba(255, 255, 255, 0.16);
            border-color: rgba(255, 255, 255, 0.35);
            color: #ffffff;
            transform: translateY(-2px);
        }
        .modal-actions-row {
            display: flex;
            gap: 1rem;
            margin-top: 1.6rem;
            align-items: center;
        }
        .modal-stat-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr) 1.25fr;
            gap: 0.85rem;
            align-items: start;
        }
        @media (max-width: 768px) {
            .modal-stat-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 480px) {
            .modal-stat-grid {
                grid-template-columns: 1fr;
            }
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
                            <span class="admin-header-page-heading" style="font-family: 'Outfit', sans-serif;">Pool & Water Activities</span>
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
    <?php else: ?>
        <?php include 'nav.php'; ?>
        <div class="container">
    <?php endif; ?>

        <!-- Flash Alert -->
        <?php if ($flash && $flash['type'] === 'success'): ?>
            <div style="background: rgba(16, 185, 129, 0.15); color: #047857; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #a7f3d0; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-circle-check" style="font-size: 1.2rem;"></i> <?= htmlspecialchars($flash['msg']) ?>
            </div>
        <?php elseif ($flash && $flash['type'] === 'error'): ?>
            <div style="background: rgba(239, 68, 68, 0.15); color: #b91c1c; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-circle-xmark" style="font-size: 1.2rem;"></i> <?= htmlspecialchars($flash['msg']) ?>
            </div>
        <?php endif; ?>

        <?php if ($is_admin_layout): ?>
        <div class="admin-page-header">
            <div>
                <h1 class="admin-page-title">
                    <i class="fa-solid fa-water-ladder" style="color: var(--gold-glow, var(--bamboo-gold));"></i> 
                    Pool &amp; Water Activities
                </h1>
                <p class="admin-page-subtitle">
                    Manage resort pools, water sports, wellness spa sessions, prices, and maintenance status.
                </p>
            </div>
            <button onclick="openAddActivityModal()" class="admin-btn admin-btn-gold">
                <i class="fa-solid fa-plus"></i> Add New Activity
            </button>
        </div>

        <!-- Admin Stats Overview -->
        <div class="admin-grid" style="margin-bottom: 2rem;">
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Total Activities</span>
                <div class="admin-stat-value" style="color: var(--primary-glow);"><?= $total_acts ?></div>
                <span class="admin-badge admin-badge-purple"><i class="fa-solid fa-list-check"></i> Total Listed</span>
            </div>
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Available for Booking</span>
                <div class="admin-stat-value" style="color: var(--emerald);"><?= $available_count ?></div>
                <span class="admin-badge admin-badge-success"><i class="fa-solid fa-circle-check"></i> Operational</span>
            </div>
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Under Maintenance</span>
                <div class="admin-stat-value" style="color: var(--rose);"><?= $maintenance_count ?></div>
                <span class="admin-badge admin-badge-danger"><i class="fa-solid fa-wrench"></i> Temporarily Closed</span>
            </div>
        </div>
        <?php else: ?>
        <!-- User Guest Hero Banner -->
        <div class="user-activities-hero">
            <div class="user-activities-hero-content">
                <span class="user-hero-badge"><i class="fa-solid fa-water"></i> Tropical Resort Adventures</span>
                <h1 class="user-hero-title">Pool &amp; Water Activities</h1>
                <p class="user-hero-desc">
                    Dive into our crystal-clear infinity pool, paddle through scenic waters on transparent kayaks, or rejuvenate with authentic bamboo herbal Hilot massage therapies.
                </p>
                <div class="user-hero-tags">
                    <span class="user-hero-tag"><i class="fa-regular fa-clock" style="color: #c084fc;"></i> 8:00 AM &ndash; 10:00 PM Daily</span>
                    <span class="user-hero-tag"><i class="fa-solid fa-vest" style="color: #38bdf8;"></i> Life Jackets &amp; Gear Included</span>
                    <span class="user-hero-tag"><i class="fa-solid fa-certificate" style="color: #facc15;"></i> Certified Lifeguards on Duty</span>
                </div>
            </div>
        </div>

        <!-- Filter & Search Controls Bar -->
        <div class="activities-filter-bar">
            <div class="activities-cat-tabs">
                <button type="button" class="act-tab-btn active" data-cat="all">
                    <i class="fa-solid fa-shapes"></i> All Activities
                </button>
                <button type="button" class="act-tab-btn" data-cat="pool">
                    <i class="fa-solid fa-water-ladder"></i> Pool &amp; Swimming
                </button>
                <button type="button" class="act-tab-btn" data-cat="outdoor">
                    <i class="fa-solid fa-sailboat"></i> Water Sports
                </button>
                <button type="button" class="act-tab-btn" data-cat="wellness">
                    <i class="fa-solid fa-spa"></i> Wellness &amp; Spa
                </button>
            </div>

            <div class="activities-search-box">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" id="actSearchInput" placeholder="Search pool pass, kayak, massage..." autocomplete="off">
            </div>
        </div>
        <?php endif; ?>

        <!-- Activities Grid -->
        <?php if (empty($all_activities)): ?>
            <div class="<?= $is_admin_layout ? 'admin-card' : 'card' ?>" style="text-align: center; padding: 3rem 1.5rem;">
                <i class="fa-solid fa-water-ladder" style="font-size: 3rem; color: #a855f7; margin-bottom: 1rem;"></i>
                <h3 style="margin-bottom: 0.5rem; color: <?= $is_admin_layout ? '#0f172a' : 'var(--primary-purple)' ?>;">No Activities Found</h3>
                <p style="color: var(--text-muted); margin-bottom: 1.5rem;">No pool or wellness activities match your search query.</p>
                <?php if ($is_admin_layout): ?>
                    <button onclick="openAddActivityModal()" class="admin-btn admin-btn-gold">
                        <i class="fa-solid fa-plus"></i> Add First Activity
                    </button>
                <?php else: ?>
                    <a href="browse_activities.php" class="btn btn-purple">View All Activities</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div id="noMatchNotice" class="card" style="display: none; text-align: center; padding: 3rem 1.5rem; margin-bottom: 2rem;">
                <i class="fa-solid fa-water-ladder" style="font-size: 2.8rem; color: #a855f7; margin-bottom: 0.75rem;"></i>
                <h3 style="margin-bottom: 0.5rem; color: #1e1b4b;">No Activities Found in This Category</h3>
                <p style="color: #64748b; margin-bottom: 1.25rem;">Try choosing another category tab or clearing your search term.</p>
                <button type="button" onclick="resetActFilters()" class="btn btn-purple btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-arrows-rotate"></i> Show All Activities
                </button>
            </div>

            <div class="activities-grid" id="activitiesGrid">
                <?php foreach ($all_activities as $act): 
                    $is_avail = !empty($act['is_available']);
                    $img = !empty($act['image']) ? $act['image'] : 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80';
                    $actCat = !empty($act['category']) ? strtolower(trim($act['category'])) : 'pool';
                ?>
                    <div class="activity-card <?= !$is_avail ? 'is-maintenance' : '' ?>" data-category="<?= htmlspecialchars($actCat) ?>" data-name="<?= htmlspecialchars(strtolower($act['activity_name'])) ?>">
                        
                        <!-- Media Header with Full-Bleed Image and Floating Glass Badges -->
                        <div class="activity-card-media">
                            <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($act['activity_name']) ?>" loading="lazy" <?= !$is_avail ? 'style="filter: saturate(60%) brightness(85%);"' : '' ?>>

                            <!-- Floating Badges Layer -->
                            <div style="position: absolute; top: 12px; left: 12px; right: 12px; display: flex; justify-content: space-between; align-items: center; pointer-events: none; z-index: 2;">
                                <!-- Category Pill Badge -->
                                <span style="background: rgba(15, 23, 42, 0.78); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); color: #f5f3ff; border: 1px solid rgba(192, 132, 252, 0.4); font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.6px; padding: 0.32rem 0.75rem; border-radius: 999px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); display: inline-flex; align-items: center; gap: 5px;">
                                    <i class="fa-solid fa-water" style="color: #c084fc;"></i>
                                    <?= htmlspecialchars($act['category_label'] ?? 'Water Activity') ?>
                                </span>

                                <!-- Status Pill Badge -->
                                <?php if ($is_avail): ?>
                                    <span style="background: rgba(5, 150, 105, 0.9); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); color: #ffffff; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; padding: 0.32rem 0.75rem; border-radius: 999px; box-shadow: 0 4px 12px rgba(0,0,0,0.25); display: inline-flex; align-items: center; gap: 5px;">
                                        <i class="fa-solid fa-circle-check"></i> Available
                                    </span>
                                <?php else: ?>
                                    <span style="background: rgba(225, 29, 72, 0.95); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); color: #ffffff; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; padding: 0.32rem 0.75rem; border-radius: 999px; box-shadow: 0 4px 12px rgba(0,0,0,0.25); display: inline-flex; align-items: center; gap: 5px;">
                                        <i class="fa-solid fa-wrench"></i> Maintenance
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Bottom Maintenance Ribbon Overlay -->
                            <?php if (!$is_avail): ?>
                                <div style="position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(180deg, transparent 0%, rgba(194, 65, 12, 0.95) 100%); color: #ffffff; padding: 0.85rem 1rem 0.45rem; font-size: 0.75rem; font-weight: 800; display: flex; align-items: center; justify-content: space-between; z-index: 2;">
                                    <span style="display: flex; align-items: center; gap: 6px;"><i class="fa-solid fa-triangle-exclamation"></i> UNDER MAINTENANCE</span>
                                    <span style="font-size: 0.7rem; opacity: 0.95;">Temporarily closed</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Card Body Content -->
                        <div class="activity-card-body">
                            
                            <!-- Title & Price Header Row -->
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem; margin-bottom: 0.4rem;">
                                <h3 class="activity-card-title" title="<?= htmlspecialchars($act['activity_name']) ?>">
                                    <?= htmlspecialchars($act['activity_name']) ?>
                                </h3>
                                <div style="text-align: right; flex-shrink: 0; white-space: nowrap;">
                                    <div style="font-family: 'Outfit', sans-serif; font-size: 1.35rem; font-weight: 800; color: #d97706; line-height: 1.1;">
                                        ₱<?= number_format($act['price']) ?>
                                    </div>
                                    <span style="font-size: 0.75rem; font-weight: 600; color: #64748b;">
                                        / session
                                    </span>
                                </div>
                            </div>

                            <!-- Description (Clamped to 2 lines with fallback if empty in DB) -->
                            <p class="activity-card-desc">
                                <?= !empty(trim($act['description'] ?? '')) ? htmlspecialchars(trim($act['description'])) : 'Enjoy our premium resort activities with clean facilities, lounge areas, and dedicated staff.' ?>
                            </p>

                            <!-- Meta Chips (Duration & Capacity) -->
                            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.15rem;">
                                <span class="activity-chip">
                                    <i class="fa-regular fa-clock" style="color: #0284c7;"></i>
                                    <?= (!empty($act['duration_minutes']) && intval($act['duration_minutes']) > 0) ? ('Duration: ' . htmlspecialchars($act['duration'] ?? ($act['duration_minutes'] . ' Mins'))) : 'No time limit' ?>
                                </span>
                                <span class="activity-chip">
                                    <i class="fa-solid fa-users" style="color: #8b5cf6;"></i>
                                    Max <?= htmlspecialchars($act['max_pax'] ?? $act['capacity'] ?? 2) ?> Pax
                                </span>
                                <?php 
                                $cardEq = [];
                                if (!empty($act['equipment'])) {
                                    if (is_array($act['equipment'])) {
                                        $cardEq = $act['equipment'];
                                    } else {
                                        $d = json_decode($act['equipment'], true);
                                        if (is_array($d)) $cardEq = $d;
                                    }
                                }
                                if (empty($cardEq)) {
                                    $nLower = strtolower($act['activity_name'] ?? '');
                                    if (strpos($nLower, 'kayak') !== false || strpos($nLower, 'paddle') !== false) {
                                        $cardEq = [
                                            ['name' => 'Kayak', 'qty' => 6, 'icon' => 'fa-sailboat'],
                                            ['name' => 'Paddle', 'qty' => 6, 'icon' => 'fa-water']
                                        ];
                                    } elseif (strpos($nLower, 'scuba') !== false || strpos($nLower, 'diving') !== false) {
                                        $cardEq = [
                                            ['name' => 'Scuba Gear', 'qty' => intval($act['capacity'] ?? 5), 'icon' => 'fa-mask-snorkel']
                                        ];
                                    }
                                }
                                foreach ($cardEq as $eqItem):
                                    $eqName = htmlspecialchars($eqItem['name'] ?? 'Gear');
                                    $eqQty = intval($eqItem['qty'] ?? 1);
                                    $eqIcon = htmlspecialchars($eqItem['icon'] ?? 'fa-boxes-stacked');
                                ?>
                                    <span class="activity-chip" style="background: rgba(124, 58, 237, 0.08); border-color: rgba(139, 92, 246, 0.3); color: #7c3aed;">
                                        <i class="fa-solid <?= $eqIcon ?>" style="color: #9333ea;"></i>
                                        <?= $eqQty ?> <?= $eqName ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if (!empty($act['total_units']) && empty($cardEq)): ?>
                                    <span class="activity-chip" style="background: rgba(124, 58, 237, 0.08); border-color: rgba(139, 92, 246, 0.3); color: #7c3aed;">
                                        <i class="fa-solid fa-boxes-stacked" style="color: #9333ea;"></i>
                                        <?= intval($act['available_units'] ?? $act['total_units']) ?>/<?= intval($act['total_units']) ?> Units
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Action Buttons Row (Pinned to bottom) -->
                            <?php if ($is_admin_layout): ?>
                                <div style="display: flex; gap: 0.5rem; align-items: center; margin-top: auto; padding-top: 1rem; border-top: 1px solid #f1f5f9;">
                                    <button type="button" onclick='openEditActivityModal(<?= json_encode($act, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' class="activity-btn-action admin-btn-gold" style="flex: 1;">
                                        <i class="fa-solid fa-pen-to-square"></i> Edit
                                    </button>
                                    <button type="button" onclick="toggleActivityStatus(<?= $act['activity_id'] ?>, <?= $is_avail ? 0 : 1 ?>)" class="activity-btn-action" style="flex: 1.25; <?= $is_avail ? 'background: #fff7ed; color: #c2410c; border: 1.5px solid #fed7aa;' : 'background: #ecfdf5; color: #047857; border: 1.5px solid #a7f3d0;' ?>" title="Switch between Available and Maintenance">
                                        <i class="fa-solid <?= $is_avail ? 'fa-wrench' : 'fa-check' ?>"></i> <?= $is_avail ? 'Maintenance' : 'Set Active' ?>
                                    </button>
                                    <button type="button" onclick="confirmDeleteActivity(<?= $act['activity_id'] ?>, '<?= htmlspecialchars(addslashes($act['activity_name'])) ?>')" class="activity-btn-action" style="width: 38px; height: 38px; min-width: 38px; padding: 0; background: #fff1f2; color: #e11d48; border: 1.5px solid #fecdd3; flex-shrink: 0;" title="Delete Activity">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            <?php else: ?>
                                <div style="margin-top: auto; padding-top: 1rem; border-top: 1px solid #f1f5f9;">
                                    <?php if ($is_avail): ?>
                                        <a href="book_activities.php?activity_id=<?= $act['activity_id'] ?>" class="user-btn-reserve">
                                            <i class="fa-solid fa-calendar-check"></i>
                                            <span>Reserve Session</span>
                                            <i class="fa-solid fa-arrow-right" style="margin-left: 2px;"></i>
                                        </a>
                                    <?php else: ?>
                                        <button disabled class="btn btn-sm" style="width: 100%; background: #ea580c; color: #ffffff; cursor: not-allowed; border: none; padding: 0.75rem 1rem; border-radius: 10px; font-weight: 700; opacity: 0.95; display: inline-flex; justify-content: center; align-items: center; gap: 8px;">
                                            <i class="fa-solid fa-wrench"></i> Not Available (Under Maintenance)
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php if ($is_admin_layout): ?>
            </main>
        </div>
    <?php else: ?>
        </div>
        <?php include 'footer.php'; ?>
    <?php endif; ?>

    <?php if ($is_admin_layout): ?>
    <!-- Hidden POST forms -->
    <form id="deleteActivityForm" method="POST" action="browse_activities.php" style="display: none;">
        <input type="hidden" name="action" value="delete_activity">
        <input type="hidden" name="activity_id" id="deleteActivityId" value="">
    </form>

    <form id="toggleActivityForm" method="POST" action="browse_activities.php" style="display: none;">
        <input type="hidden" name="action" value="toggle_activity_status">
        <input type="hidden" name="activity_id" id="toggleActivityId" value="">
        <input type="hidden" name="is_available" id="toggleActivityStatusVal" value="">
    </form>

    <!-- Modal: Add New Activity (Styled exactly like the screenshot) -->
    <div id="addActivityModal" class="modal-custom-backdrop">
        <div class="modal-custom-window">
            <div class="modal-custom-header">
                <h3>
                    <i class="fa-solid fa-pen-to-square"></i> Add Activity Details &amp; Status
                </h3>
                <button type="button" onclick="closeAddActivityModal()" class="modal-custom-close" aria-label="Close modal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form id="addActivityForm" method="POST" action="browse_activities.php" enctype="multipart/form-data" class="modal-form-body">
                <input type="hidden" name="action" value="add_activity">

                <!-- Row 1: Activity Name & Category -->
                <div style="display: grid; grid-template-columns: 1.3fr 1fr; gap: 1rem; margin-bottom: 1.15rem;">
                    <div>
                        <label for="addActName" class="modal-field-label">
                            <i class="fa-solid fa-person-swimming" style="color: #c084fc;"></i> Activity Name
                        </label>
                        <input type="text" name="activity_name" id="addActName" placeholder="Clear-Bottom Kayak & Paddle Board" required class="modal-field-input">
                    </div>
                    <div>
                        <label for="addActCategory" class="modal-field-label">
                            <i class="fa-solid fa-tag" style="color: #c084fc;"></i> Category
                        </label>
                        <select name="category" id="addActCategory" onchange="onCategoryChange('add', this.value)" class="modal-field-input" style="cursor: pointer;">
                            <option value="pool" selected>Pool Activity</option>
                            <option value="outdoor">Water Sport / Lagoon</option>
                            <option value="wellness">Wellness &amp; Spa</option>
                            <option value="indoor">Indoor Leisure</option>
                            <option value="other">Other Activity</option>
                        </select>
                    </div>
                </div>

                <!-- Row 2: Price, Duration, Status -->
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1.15rem;">
                    <div>
                        <label for="addActPrice" class="modal-field-label">
                            <i class="fa-solid fa-peso-sign" style="color: #c084fc;"></i> Price (₱)
                        </label>
                        <input type="number" name="price" id="addActPrice" step="50" min="0" value="500.00" required class="modal-field-input" style="color: #c084fc; font-weight: 800; font-size: 1.05rem;">
                    </div>
                    <div>
                        <label for="addActDuration" class="modal-field-label" style="justify-content: space-between;">
                            <span><i class="fa-regular fa-clock" style="color: #c084fc;"></i> Duration (Mins)</span>
                            <span style="font-weight: 400; font-size: 0.72rem; color: #94a3b8;">(Optional)</span>
                        </label>
                        <input type="number" name="duration_minutes" id="addActDuration" step="5" min="0" value="60" placeholder="60" class="modal-field-input" style="font-weight: 700;">
                        <small style="color: #94a3b8; font-size: 0.72rem; display: block; margin-top: 0.25rem;">* Leave blank for no time limit.</small>
                    </div>
                    <div>
                        <label for="addActStatus" class="modal-field-label">
                            <i class="fa-solid fa-circle-dot" style="color: #c084fc;"></i> Status
                        </label>
                        <select name="is_available" id="addActStatus" class="modal-field-input" style="cursor: pointer; font-weight: 700;">
                            <option value="1" selected>✅ Available</option>
                            <option value="0">🔧 Under Maintenance</option>
                        </select>
                    </div>
                </div>

                <!-- Row 3: Availability & Equipment Group Box -->
                <div class="modal-box-container">
                    <div style="font-weight: 800; color: #f1f5f9; font-size: 0.92rem; margin-bottom: 0.85rem; display: flex; align-items: center; justify-content: space-between;">
                        <span style="display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-box-open" style="color: #c084fc;"></i> Availability &amp; Equipment
                        </span>
                    </div>

                    <div class="modal-stat-grid">
                        <!-- Total Units -->
                        <div class="modal-stat-box">
                            <label for="addActTotalUnits" style="display: flex; align-items: center; gap: 6px; font-weight: 700; color: #e2e8f0; font-size: 0.82rem; margin: 0;">
                                <i class="fa-solid fa-boxes-stacked" style="color: #c084fc;"></i> Total Units
                            </label>
                            <input type="number" name="total_units" id="addActTotalUnits" min="1" step="1" value="6" oninput="onTotalUnitsChange('add', this.value)" style="width: 100%; background: transparent; border: none; outline: none; color: #ffffff; font-size: 1.3rem; font-weight: 800; padding: 0.2rem 0;">
                        </div>

                        <!-- Available Units -->
                        <div class="modal-stat-box">
                            <label for="addActAvailableUnits" style="display: flex; align-items: center; gap: 6px; font-weight: 700; color: #e2e8f0; font-size: 0.82rem; margin: 0;">
                                <i class="fa-solid fa-circle-check" style="color: #34d399;"></i> Available Units
                            </label>
                            <input type="number" name="available_units" id="addActAvailableUnits" min="0" step="1" value="4" style="width: 100%; background: transparent; border: none; outline: none; color: #34d399; font-size: 1.3rem; font-weight: 800; padding: 0.2rem 0;">
                            <small style="color: #94a3b8; font-size: 0.72rem; display: block; margin-top: 0.15rem;">* System-calculated</small>
                        </div>

                        <!-- Max Pax -->
                        <div class="modal-stat-box">
                            <label for="addActMaxPax" style="display: flex; align-items: center; gap: 6px; font-weight: 700; color: #e2e8f0; font-size: 0.82rem; margin: 0;">
                                <i class="fa-solid fa-users" style="color: #c084fc;"></i> Max Pax
                            </label>
                            <input type="number" name="max_pax" id="addActMaxPax" min="1" step="1" value="2" required style="width: 100%; background: transparent; border: none; outline: none; color: #ffffff; font-size: 1.3rem; font-weight: 800; padding: 0.2rem 0;">
                            <small style="color: #94a3b8; font-size: 0.72rem; display: block; margin-top: 0.15rem;">* Guests per unit</small>
                        </div>

                        <!-- Equipment -->
                        <div class="modal-stat-box">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem;">
                                <label style="display: flex; align-items: center; gap: 6px; font-weight: 700; color: #e2e8f0; font-size: 0.82rem; margin: 0;">
                                    <i class="fa-solid fa-wrench" style="color: #c084fc;"></i> Equipment
                                </label>
                                <button type="button" onclick="toggleEquipmentEditor('add')" style="background: rgba(192, 132, 252, 0.15); border: 1px solid rgba(192, 132, 252, 0.35); border-radius: 6px; color: #c084fc; font-size: 0.72rem; font-weight: 700; cursor: pointer; text-decoration: none; padding: 0.2rem 0.5rem; display: flex; align-items: center; gap: 5px; transition: all 0.2s ease;" title="Customize equipment list">
                                    <i id="addEqToggleIcon" class="fa-solid fa-pen-to-square"></i> <span id="addEqToggleText">Edit</span>
                                </button>
                            </div>

                            <div id="addEquipmentDisplay" style="display: flex; flex-direction: column; gap: 0.35rem;">
                                <div class="equipment-row-item">
                                    <span><i class="fa-solid fa-sailboat" style="color: #c084fc; width: 16px;"></i> Kayak</span>
                                    <span class="qty">× 6</span>
                                </div>
                                <div class="equipment-row-item">
                                    <span><i class="fa-solid fa-water" style="color: #c084fc; width: 16px;"></i> Paddle</span>
                                    <span class="qty">× 6</span>
                                </div>
                                <div class="equipment-row-item">
                                    <span><i class="fa-solid fa-vest" style="color: #c084fc; width: 16px;"></i> Life Jacket</span>
                                    <span class="qty">× 10</span>
                                </div>
                            </div>

                            <!-- Inline Edit Panel -->
                            <div id="addEquipmentEditPanel" style="display: none; flex-direction: column; gap: 0.35rem; margin-top: 0.3rem;">
                                <div id="addEquipmentInputsContainer" style="display: flex; flex-direction: column; gap: 0.3rem;"></div>
                                <div style="display: flex; gap: 0.4rem; margin-top: 0.3rem;">
                                    <button type="button" onclick="addEquipmentRow('add')" style="flex: 1; background: rgba(124, 58, 237, 0.25); border: 1px dashed #a855f7; color: #e2e8f0; border-radius: 6px; font-size: 0.72rem; font-weight: 700; padding: 0.3rem 0.4rem; cursor: pointer;">
                                        + Item
                                    </button>
                                    <button type="button" onclick="saveEquipmentEdit('add')" style="background: #a855f7; border: none; color: #ffffff; border-radius: 6px; font-size: 0.72rem; font-weight: 800; padding: 0.3rem 0.7rem; cursor: pointer;">
                                        Done
                                    </button>
                                </div>
                            </div>

                            <input type="hidden" name="equipment" id="addEquipmentInput" value='[{"name":"Kayak","qty":6,"icon":"fa-sailboat"},{"name":"Paddle","qty":6,"icon":"fa-water"},{"name":"Life Jacket","qty":10,"icon":"fa-vest"}]'>
                        </div>
                    </div>
                </div>

                <!-- Row 4: Picture Section -->
                <div class="modal-photo-container">
                    <label style="display: flex; align-items: center; gap: 7px; font-weight: 800; color: #e2e8f0; margin-bottom: 0.65rem; font-size: 0.92rem;">
                        <i class="fa-solid fa-camera" style="color: #c084fc;"></i> Activity Picture / Photo
                    </label>
                    <div style="display: flex; gap: 1rem; align-items: flex-start; flex-wrap: wrap;">
                        <div style="position: relative; flex-shrink: 0;">
                            <img id="addActImgPreview" src="https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=600&q=80" alt="Preview" style="width: 125px; height: 85px; object-fit: cover; border-radius: 10px; border: 2px solid #7c3aed; background: #070b18; box-shadow: 0 4px 12px rgba(0,0,0,0.4);">
                            <span style="position: absolute; bottom: 4px; left: 4px; background: rgba(15, 23, 42, 0.88); color: #fbbf24; font-size: 0.65rem; padding: 2px 7px; border-radius: 4px; font-weight: 800; letter-spacing: 0.5px; border: 1px solid rgba(251, 191, 36, 0.35);">PREVIEW</span>
                        </div>
                        <div style="flex: 1; min-width: 220px;">
                            <div>
                                <label style="display: block; font-size: 0.82rem; color: #cbd5e1; margin-bottom: 0.4rem; font-weight: 600;">
                                    <i class="fa-solid fa-upload"></i> Upload new photo from device:
                                </label>
                                <input type="file" name="image_file" id="addActFile" accept="image/*" onchange="previewFile(this, 'addActImgPreview')" class="modal-field-input" style="padding: 0.6rem 0.85rem; font-size: 0.85rem; cursor: pointer;">
                            </div>
                            <small style="color: #94a3b8; font-size: 0.75rem; display: block; margin-top: 0.45rem;">
                                * Supports JPG, PNG, WEBP. Leave empty to use default photo.
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Row 5: Description -->
                <div style="margin-bottom: 1.15rem;">
                    <label for="addActDesc" class="modal-field-label">
                        <i class="fa-solid fa-align-left" style="color: #c084fc;"></i> Description
                    </label>
                    <textarea name="description" id="addActDesc" rows="2" placeholder="Transparent kayak equipment for scenic pool and lagoon exploration." class="modal-field-input" style="font-family: inherit; resize: vertical; min-height: 70px; line-height: 1.5;">Transparent kayak equipment for scenic pool and lagoon exploration.</textarea>
                </div>

                <!-- Row 6: Buttons -->
                <div class="modal-actions-row">
                    <button type="button" onclick="showAddConfirm('Add New Activity', 'Are you sure you want to add this activity to the resort?', function() { document.getElementById('addActivityForm').submit(); })" class="btn-modal-save-gold">
                        <i class="fa-solid fa-floppy-disk"></i> Save &amp; Add Activity
                    </button>
                    <button type="button" onclick="closeAddActivityModal()" class="btn-modal-cancel-white">
                        <i class="fa-solid fa-xmark"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Activity (Styled exactly like the screenshot) -->
    <div id="editActivityModal" class="modal-custom-backdrop">
        <div class="modal-custom-window">
            <div class="modal-custom-header">
                <h3>
                    <i class="fa-solid fa-pen-to-square"></i> Edit Activity Details &amp; Status
                </h3>
                <button type="button" onclick="closeEditActivityModal()" class="modal-custom-close" aria-label="Close modal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form id="editActivityForm" method="POST" action="browse_activities.php" enctype="multipart/form-data" class="modal-form-body">
                <input type="hidden" name="action" value="edit_activity">
                <input type="hidden" name="activity_id" id="editActId" value="">
                <input type="hidden" name="existing_image" id="editActExistingImage" value="">

                <!-- Row 1: Activity Name & Category -->
                <div style="display: grid; grid-template-columns: 1.3fr 1fr; gap: 1rem; margin-bottom: 1.15rem;">
                    <div>
                        <label for="editActName" class="modal-field-label">
                            <i class="fa-solid fa-person-swimming" style="color: #c084fc;"></i> Activity Name
                        </label>
                        <input type="text" name="activity_name" id="editActName" required class="modal-field-input">
                    </div>
                    <div>
                        <label for="editActCategory" class="modal-field-label">
                            <i class="fa-solid fa-tag" style="color: #c084fc;"></i> Category
                        </label>
                        <select name="category" id="editActCategory" onchange="onCategoryChange('edit', this.value)" class="modal-field-input" style="cursor: pointer;">
                            <option value="pool">Pool Activity</option>
                            <option value="outdoor">Water Sport / Lagoon</option>
                            <option value="wellness">Wellness &amp; Spa</option>
                            <option value="indoor">Indoor Leisure</option>
                            <option value="other">Other Activity</option>
                        </select>
                    </div>
                </div>

                <!-- Row 2: Price, Duration, Status -->
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1.15rem;">
                    <div>
                        <label for="editActPrice" class="modal-field-label">
                            <i class="fa-solid fa-peso-sign" style="color: #c084fc;"></i> Price (₱)
                        </label>
                        <input type="number" name="price" id="editActPrice" step="50" min="0" required class="modal-field-input" style="color: #c084fc; font-weight: 800; font-size: 1.05rem;">
                    </div>
                    <div>
                        <label for="editActDuration" class="modal-field-label" style="justify-content: space-between;">
                            <span><i class="fa-regular fa-clock" style="color: #c084fc;"></i> Duration (Mins)</span>
                            <span style="font-weight: 400; font-size: 0.72rem; color: #94a3b8;">(Optional)</span>
                        </label>
                        <input type="number" name="duration_minutes" id="editActDuration" step="5" min="0" placeholder="Leave empty for no limit" class="modal-field-input" style="font-weight: 700;">
                        <small style="color: #94a3b8; font-size: 0.72rem; display: block; margin-top: 0.25rem;">* Leave blank for no time limit.</small>
                    </div>
                    <div>
                        <label for="editActStatus" class="modal-field-label">
                            <i class="fa-solid fa-circle-dot" style="color: #c084fc;"></i> Status
                        </label>
                        <select name="is_available" id="editActStatus" class="modal-field-input" style="cursor: pointer; font-weight: 700;">
                            <option value="1">✅ Available</option>
                            <option value="0">🔧 Under Maintenance</option>
                        </select>
                    </div>
                </div>

                <!-- Row 3: Availability & Equipment Group Box -->
                <div class="modal-box-container">
                    <div style="font-weight: 800; color: #f1f5f9; font-size: 0.92rem; margin-bottom: 0.85rem; display: flex; align-items: center; justify-content: space-between;">
                        <span style="display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-box-open" style="color: #c084fc;"></i> Availability &amp; Equipment
                        </span>
                    </div>

                    <div class="modal-stat-grid">
                        <!-- Total Units -->
                        <div class="modal-stat-box">
                            <label for="editActTotalUnits" style="display: flex; align-items: center; gap: 6px; font-weight: 700; color: #e2e8f0; font-size: 0.82rem; margin: 0;">
                                <i class="fa-solid fa-boxes-stacked" style="color: #c084fc;"></i> Total Units
                            </label>
                            <input type="number" name="total_units" id="editActTotalUnits" min="1" step="1" oninput="onTotalUnitsChange('edit', this.value)" style="width: 100%; background: transparent; border: none; outline: none; color: #ffffff; font-size: 1.3rem; font-weight: 800; padding: 0.2rem 0;">
                        </div>

                        <!-- Available Units -->
                        <div class="modal-stat-box">
                            <label for="editActAvailableUnits" style="display: flex; align-items: center; gap: 6px; font-weight: 700; color: #e2e8f0; font-size: 0.82rem; margin: 0;">
                                <i class="fa-solid fa-circle-check" style="color: #34d399;"></i> Available Units
                            </label>
                            <input type="number" name="available_units" id="editActAvailableUnits" min="0" step="1" style="width: 100%; background: transparent; border: none; outline: none; color: #34d399; font-size: 1.3rem; font-weight: 800; padding: 0.2rem 0;">
                            <small style="color: #94a3b8; font-size: 0.72rem; display: block; margin-top: 0.15rem;">* System-calculated</small>
                        </div>

                        <!-- Max Pax -->
                        <div class="modal-stat-box">
                            <label for="editActMaxPax" style="display: flex; align-items: center; gap: 6px; font-weight: 700; color: #e2e8f0; font-size: 0.82rem; margin: 0;">
                                <i class="fa-solid fa-users" style="color: #c084fc;"></i> Max Pax
                            </label>
                            <input type="number" name="max_pax" id="editActMaxPax" min="1" step="1" value="2" required style="width: 100%; background: transparent; border: none; outline: none; color: #ffffff; font-size: 1.3rem; font-weight: 800; padding: 0.2rem 0;">
                            <small style="color: #94a3b8; font-size: 0.72rem; display: block; margin-top: 0.15rem;">* Guests per unit</small>
                        </div>

                        <!-- Equipment -->
                        <div class="modal-stat-box">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem;">
                                <label style="display: flex; align-items: center; gap: 6px; font-weight: 700; color: #e2e8f0; font-size: 0.82rem; margin: 0;">
                                    <i class="fa-solid fa-wrench" style="color: #c084fc;"></i> Equipment
                                </label>
                                <button type="button" onclick="toggleEquipmentEditor('edit')" style="background: rgba(192, 132, 252, 0.15); border: 1px solid rgba(192, 132, 252, 0.35); border-radius: 6px; color: #c084fc; font-size: 0.72rem; font-weight: 700; cursor: pointer; text-decoration: none; padding: 0.2rem 0.5rem; display: flex; align-items: center; gap: 5px; transition: all 0.2s ease;" title="Customize equipment list">
                                    <i id="editEqToggleIcon" class="fa-solid fa-pen-to-square"></i> <span id="editEqToggleText">Edit</span>
                                </button>
                            </div>

                            <div id="editEquipmentDisplay" style="display: flex; flex-direction: column; gap: 0.35rem;"></div>

                            <!-- Inline Edit Panel -->
                            <div id="editEquipmentEditPanel" style="display: none; flex-direction: column; gap: 0.35rem; margin-top: 0.3rem;">
                                <div id="editEquipmentInputsContainer" style="display: flex; flex-direction: column; gap: 0.3rem;"></div>
                                <div style="display: flex; gap: 0.4rem; margin-top: 0.3rem;">
                                    <button type="button" onclick="addEquipmentRow('edit')" style="flex: 1; background: rgba(124, 58, 237, 0.25); border: 1px dashed #a855f7; color: #e2e8f0; border-radius: 6px; font-size: 0.72rem; font-weight: 700; padding: 0.3rem 0.4rem; cursor: pointer;">
                                        + Item
                                    </button>
                                    <button type="button" onclick="saveEquipmentEdit('edit')" style="background: #a855f7; border: none; color: #ffffff; border-radius: 6px; font-size: 0.72rem; font-weight: 800; padding: 0.3rem 0.7rem; cursor: pointer;">
                                        Done
                                    </button>
                                </div>
                            </div>

                            <input type="hidden" name="equipment" id="editEquipmentInput" value='[]'>
                        </div>
                    </div>
                </div>

                <!-- Row 4: Picture Section -->
                <div class="modal-photo-container">
                    <label style="display: flex; align-items: center; gap: 7px; font-weight: 800; color: #e2e8f0; margin-bottom: 0.65rem; font-size: 0.92rem;">
                        <i class="fa-solid fa-camera" style="color: #c084fc;"></i> Activity Picture / Photo
                    </label>
                    <div style="display: flex; gap: 1rem; align-items: flex-start; flex-wrap: wrap;">
                        <div style="position: relative; flex-shrink: 0;">
                            <img id="editActImgPreview" src="" alt="Preview" style="width: 125px; height: 85px; object-fit: cover; border-radius: 10px; border: 2px solid #7c3aed; background: #070b18; box-shadow: 0 4px 12px rgba(0,0,0,0.4);">
                            <span style="position: absolute; bottom: 4px; left: 4px; background: rgba(15, 23, 42, 0.88); color: #fbbf24; font-size: 0.65rem; padding: 2px 7px; border-radius: 4px; font-weight: 800; letter-spacing: 0.5px; border: 1px solid rgba(251, 191, 36, 0.35);">PREVIEW</span>
                        </div>
                        <div style="flex: 1; min-width: 220px;">
                            <div>
                                <label style="display: block; font-size: 0.82rem; color: #cbd5e1; margin-bottom: 0.4rem; font-weight: 600;">
                                    <i class="fa-solid fa-upload"></i> Upload new photo from device:
                                </label>
                                <input type="file" name="image_file" id="editActFile" accept="image/*" onchange="previewFile(this, 'editActImgPreview')" class="modal-field-input" style="padding: 0.6rem 0.85rem; font-size: 0.85rem; cursor: pointer;">
                            </div>
                            <small style="color: #94a3b8; font-size: 0.75rem; display: block; margin-top: 0.45rem;">
                                * Supports JPG, PNG, WEBP. Leave empty to keep current photo.
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Row 5: Description -->
                <div style="margin-bottom: 1.15rem;">
                    <label for="editActDesc" class="modal-field-label">
                        <i class="fa-solid fa-align-left" style="color: #c084fc;"></i> Description
                    </label>
                    <textarea name="description" id="editActDesc" rows="2" class="modal-field-input" style="font-family: inherit; resize: vertical; min-height: 70px; line-height: 1.5;"></textarea>
                </div>

                <!-- Row 6: Buttons -->
                <div class="modal-actions-row">
                    <button type="button" onclick="showUpdateConfirm('Save Activity Updates', 'Are you sure you want to update this activity?', function() { document.getElementById('editActivityForm').submit(); })" class="btn-modal-save-gold">
                        <i class="fa-solid fa-floppy-disk"></i> Save &amp; Update Activity
                    </button>
                    <button type="button" onclick="closeEditActivityModal()" class="btn-modal-cancel-white">
                        <i class="fa-solid fa-xmark"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    const categoryEquipmentPresets = {
        'pool': [
            { name: 'Life Jacket', qty: 10, icon: 'fa-vest' },
            { name: 'Pool Floats', qty: 5, icon: 'fa-life-ring' }
        ],
        'outdoor': [
            { name: 'Life Jacket', qty: 10, icon: 'fa-vest' }
        ],
        'wellness': [
            { name: 'Bamboo Massage Kit', qty: 6, icon: 'fa-spa' },
            { name: 'Organic Essential Oils', qty: 12, icon: 'fa-bottle-droplet' },
            { name: 'Herbal Compress Pillows', qty: 8, icon: 'fa-leaf' }
        ],
        'indoor': [
            { name: 'Billiard Cue Sticks', qty: 6, icon: 'fa-wand-magic-sparkles' },
            { name: 'Pool Ball Set', qty: 2, icon: 'fa-circle-dot' },
            { name: 'Table Tennis Paddles', qty: 8, icon: 'fa-table-tennis-paddle-ball' }
        ],
        'other': [
            { name: 'Safety Life Vests', qty: 8, icon: 'fa-vest' },
            { name: 'Activity Kit', qty: 6, icon: 'fa-box-open' }
        ]
    };

    let equipmentWorkingState = {
        add: [],
        edit: []
    };

    function previewFile(input, targetId) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = document.getElementById(targetId);
                if (img) img.src = e.target.result;
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    function previewUrl(url, targetId) {
        if (url && url.trim().length > 3) {
            const img = document.getElementById(targetId);
            if (img) img.src = url.trim();
        }
    }

    function getCategoryDefaultEquipment(cat, activityName = '') {
        let items = (categoryEquipmentPresets[cat] || categoryEquipmentPresets['pool']).map(item => ({ ...item }));
        if (activityName) {
            const nLower = activityName.toLowerCase();
            if (nLower.includes('kayak') || nLower.includes('paddle')) {
                items.unshift(
                    { name: 'Kayak', qty: 6, icon: 'fa-sailboat' },
                    { name: 'Paddle', qty: 6, icon: 'fa-water' }
                );
            } else if (nLower.includes('scuba') || nLower.includes('diving')) {
                items.unshift(
                    { name: 'Scuba Gear', qty: 5, icon: 'fa-mask-snorkel' }
                );
            }
        }
        return items;
    }

    function renderEquipmentDisplay(type, items) {
        equipmentWorkingState[type] = Array.isArray(items) ? items.map(i => ({ ...i })) : [];
        const display = document.getElementById(type + 'EquipmentDisplay');
        const hiddenInput = document.getElementById(type + 'EquipmentInput');
        if (!display) return;

        display.innerHTML = '';
        equipmentWorkingState[type].forEach(item => {
            const row = document.createElement('div');
            row.className = 'equipment-row-item';
            const iconClass = item.icon || 'fa-box-open';
            row.innerHTML = `<span><i class="fa-solid ${iconClass}" style="color: #c084fc; width: 16px;"></i> ${escapeHtml(item.name)}</span><span class="qty">× ${item.qty}</span>`;
            display.appendChild(row);
        });

        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(equipmentWorkingState[type]);
        }
    }

    function toggleEquipmentEditor(type) {
        const display = document.getElementById(type + 'EquipmentDisplay');
        const panel = document.getElementById(type + 'EquipmentEditPanel');
        const toggleText = document.getElementById(type + 'EqToggleText');
        const toggleIcon = document.getElementById(type + 'EqToggleIcon');
        if (!display || !panel) return;

        if (panel.style.display === 'none' || panel.style.display === '') {
            populateEquipmentInputs(type);
            display.style.display = 'none';
            panel.style.display = 'flex';
            if (toggleText) toggleText.textContent = 'Cancel';
            if (toggleIcon) toggleIcon.className = 'fa-solid fa-xmark';
        } else {
            panel.style.display = 'none';
            display.style.display = 'flex';
            if (toggleText) toggleText.textContent = 'Edit';
            if (toggleIcon) toggleIcon.className = 'fa-solid fa-pen-to-square';
        }
    }

    function populateEquipmentInputs(type) {
        const container = document.getElementById(type + 'EquipmentInputsContainer');
        if (!container) return;
        container.innerHTML = '';

        equipmentWorkingState[type].forEach((item, idx) => {
            const row = document.createElement('div');
            row.style.display = 'flex';
            row.style.gap = '0.3rem';
            row.style.alignItems = 'center';
            row.innerHTML = `
                <input type="text" value="${escapeHtml(item.name)}" placeholder="Item Name" id="${type}_eq_name_${idx}" style="flex: 1; background: #0b1226; border: 1px solid #4c1d95; color: #fff; padding: 0.25rem 0.4rem; border-radius: 4px; font-size: 0.76rem;">
                <input type="number" min="1" value="${item.qty}" id="${type}_eq_qty_${idx}" style="width: 50px; background: #0b1226; border: 1px solid #4c1d95; color: #c084fc; font-weight: 800; padding: 0.25rem 0.3rem; border-radius: 4px; font-size: 0.76rem; text-align: center;">
                <button type="button" onclick="removeEquipmentRow('${type}', ${idx})" style="background: rgba(239,68,68,0.2); border: 1px solid rgba(239,68,68,0.4); color: #f87171; border-radius: 4px; width: 24px; height: 24px; padding: 0; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 0.7rem;">
                    <i class="fa-solid fa-trash"></i>
                </button>
            `;
            container.appendChild(row);
        });
    }

    function syncEquipmentInputsToState(type) {
        const container = document.getElementById(type + 'EquipmentInputsContainer');
        if (!container) return;
        equipmentWorkingState[type].forEach((item, idx) => {
            const nameEl = document.getElementById(`${type}_eq_name_${idx}`);
            const qtyEl = document.getElementById(`${type}_eq_qty_${idx}`);
            if (nameEl) item.name = nameEl.value;
            if (qtyEl) item.qty = parseInt(qtyEl.value) || 1;
        });
    }

    function addEquipmentRow(type) {
        syncEquipmentInputsToState(type);
        equipmentWorkingState[type].push({ name: 'New Item', qty: 1, icon: 'fa-box-open' });
        populateEquipmentInputs(type);
    }

    function removeEquipmentRow(type, idx) {
        syncEquipmentInputsToState(type);
        equipmentWorkingState[type].splice(idx, 1);
        populateEquipmentInputs(type);
    }

    function saveEquipmentEdit(type) {
        const items = [];
        const container = document.getElementById(type + 'EquipmentInputsContainer');
        if (container) {
            equipmentWorkingState[type].forEach((item, idx) => {
                const nameEl = document.getElementById(`${type}_eq_name_${idx}`);
                const qtyEl = document.getElementById(`${type}_eq_qty_${idx}`);
                if (nameEl && nameEl.value.trim()) {
                    items.push({
                        name: nameEl.value.trim(),
                        qty: parseInt(qtyEl ? qtyEl.value : 1) || 1,
                        icon: item.icon || 'fa-box-open'
                    });
                }
            });
        }
        const actNameEl = document.getElementById('editActName');
        const actName = actNameEl ? actNameEl.value : '';
        renderEquipmentDisplay(type, items);
        const display = document.getElementById(type + 'EquipmentDisplay');
        const panel = document.getElementById(type + 'EquipmentEditPanel');
        const toggleText = document.getElementById(type + 'EqToggleText');
        const toggleIcon = document.getElementById(type + 'EqToggleIcon');
        if (panel) panel.style.display = 'none';
        if (display) display.style.display = 'flex';
        if (toggleText) toggleText.textContent = 'Edit';
        if (toggleIcon) toggleIcon.className = 'fa-solid fa-pen-to-square';
    }

    function onTotalUnitsChange(type, val) {
        const total = parseInt(val) || 0;
        const availInput = document.getElementById(type + 'ActAvailableUnits');
        if (availInput && (availInput.value === '' || parseInt(availInput.value) > total || parseInt(availInput.value) === Math.max(0, total - 2 + 1))) {
            availInput.value = Math.max(0, total - 2);
        }
    }

    function onCategoryChange(type, cat) {
        if (type === 'add') {
            const presets = getCategoryDefaultEquipment(cat);
            renderEquipmentDisplay('add', presets);
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, function(m) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m];
        });
    }

    function openAddActivityModal() {
        if (document.getElementById('addActName')) document.getElementById('addActName').value = 'Clear-Bottom Kayak & Paddle Board';
        if (document.getElementById('addActCategory')) document.getElementById('addActCategory').value = 'pool';
        if (document.getElementById('addActPrice')) document.getElementById('addActPrice').value = '500.00';
        if (document.getElementById('addActDuration')) document.getElementById('addActDuration').value = '60';
        if (document.getElementById('addActStatus')) document.getElementById('addActStatus').value = '1';
        if (document.getElementById('addActTotalUnits')) document.getElementById('addActTotalUnits').value = '6';
        if (document.getElementById('addActAvailableUnits')) document.getElementById('addActAvailableUnits').value = '4';
        if (document.getElementById('addActMaxPax')) document.getElementById('addActMaxPax').value = '2';
        if (document.getElementById('addActDesc')) document.getElementById('addActDesc').value = 'Transparent kayak equipment for scenic pool and lagoon exploration.';
        if (document.getElementById('addActUrl')) document.getElementById('addActUrl').value = '';
        if (document.getElementById('addActFile')) document.getElementById('addActFile').value = '';
        if (document.getElementById('addActImgPreview')) document.getElementById('addActImgPreview').src = 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=600&q=80';

        const defaultEq = [
            { name: 'Kayak', qty: 6, icon: 'fa-sailboat' },
            { name: 'Paddle', qty: 6, icon: 'fa-water' },
            { name: 'Life Jacket', qty: 10, icon: 'fa-vest' }
        ];
        if (typeof renderEquipmentDisplay === 'function') {
            renderEquipmentDisplay('add', defaultEq);
        }

        const editPanel = document.getElementById('addEquipmentEditPanel');
        const display = document.getElementById('addEquipmentDisplay');
        const toggleText = document.getElementById('addEqToggleText');
        const toggleIcon = document.getElementById('addEqToggleIcon');
        if (editPanel) editPanel.style.display = 'none';
        if (display) display.style.display = 'flex';
        if (toggleText) toggleText.textContent = 'Edit';
        if (toggleIcon) toggleIcon.className = 'fa-solid fa-pen-to-square';

        const modal = document.getElementById('addActivityModal');
        if (modal) modal.style.display = 'flex';
    }

    function closeAddActivityModal() {
        const modal = document.getElementById('addActivityModal');
        if (modal) modal.style.display = 'none';
    }

    function openEditActivityModal(act) {
        if (!act) return;
        if (document.getElementById('editActId')) document.getElementById('editActId').value = act.activity_id || '';
        if (document.getElementById('editActExistingImage')) document.getElementById('editActExistingImage').value = act.image || '';
        if (document.getElementById('editActName')) document.getElementById('editActName').value = act.activity_name || '';
        if (document.getElementById('editActCategory')) document.getElementById('editActCategory').value = act.category || 'pool';
        if (document.getElementById('editActPrice')) document.getElementById('editActPrice').value = (parseFloat(act.price) || 0).toFixed(2);
        if (document.getElementById('editActDuration')) document.getElementById('editActDuration').value = (act.duration_minutes && parseInt(act.duration_minutes) > 0) ? act.duration_minutes : '';
        if (document.getElementById('editActStatus')) document.getElementById('editActStatus').value = (act.is_available == 1 || act.is_available === true) ? '1' : '0';
        if (document.getElementById('editActTotalUnits')) document.getElementById('editActTotalUnits').value = act.total_units || act.capacity || 6;
        if (document.getElementById('editActAvailableUnits')) document.getElementById('editActAvailableUnits').value = (act.available_units !== undefined && act.available_units !== null) ? act.available_units : 4;
        if (document.getElementById('editActMaxPax')) document.getElementById('editActMaxPax').value = act.max_pax || act.capacity || 2;
        if (document.getElementById('editActDesc')) document.getElementById('editActDesc').value = act.description || '';
        if (document.getElementById('editActUrl')) document.getElementById('editActUrl').value = '';
        if (document.getElementById('editActFile')) document.getElementById('editActFile').value = '';

        const preview = document.getElementById('editActImgPreview');
        if (preview) {
            preview.src = act.image || 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=600&q=80';
        }

        let eqItems = [];
        if (act.equipment) {
            if (typeof act.equipment === 'string') {
                try {
                    eqItems = JSON.parse(act.equipment);
                } catch(e) {
                    eqItems = act.equipment.split(',').filter(s => s.trim().length > 0).map(s => ({
                        name: s.trim(),
                        qty: 1,
                        icon: 'fa-box-open'
                    }));
                }
            } else if (Array.isArray(act.equipment)) {
                eqItems = act.equipment;
            }
        }
        if (!eqItems || eqItems.length === 0) {
            if (typeof getCategoryDefaultEquipment === 'function') {
                eqItems = getCategoryDefaultEquipment(act.category || 'pool', act.activity_name || '');
            } else {
                eqItems = [];
            }
        }
        if (typeof renderEquipmentDisplay === 'function') {
            renderEquipmentDisplay('edit', eqItems);
        }

        const editPanel = document.getElementById('editEquipmentEditPanel');
        const display = document.getElementById('editEquipmentDisplay');
        const toggleText = document.getElementById('editEqToggleText');
        const toggleIcon = document.getElementById('editEqToggleIcon');
        if (editPanel) editPanel.style.display = 'none';
        if (display) display.style.display = 'flex';
        if (toggleText) toggleText.textContent = 'Edit';
        if (toggleIcon) toggleIcon.className = 'fa-solid fa-pen-to-square';

        const modal = document.getElementById('editActivityModal');
        if (modal) modal.style.display = 'flex';
    }

    function closeEditActivityModal() {
        const modal = document.getElementById('editActivityModal');
        if (modal) modal.style.display = 'none';
    }

    function toggleActivityStatus(id, newStatus) {
        document.getElementById('toggleActivityId').value = id;
        document.getElementById('toggleActivityStatusVal').value = newStatus;
        document.getElementById('toggleActivityForm').submit();
    }

    function confirmDeleteActivity(id, name) {
        const safeName = name ? ' "<strong>' + name + '</strong>"' : ' this activity';
        const title = 'Delete Activity Confirmation';
        const msg = 'Are you sure you want to delete' + safeName + ' from resort activities?<br><span style="display:block; margin-top:8px; color: #f87171; font-size: 0.85rem; font-weight: 600;"><i class="fa-solid fa-triangle-exclamation"></i> This cannot be undone.</span>';
        const onConfirm = function() {
            document.getElementById('deleteActivityId').value = id;
            document.getElementById('deleteActivityForm').submit();
        };

        if (typeof showDeleteConfirm === 'function') {
            showDeleteConfirm(title, msg, onConfirm, 'Yes, Delete', 'Cancel');
        } else if (window.showDeleteConfirm) {
            window.showDeleteConfirm(title, msg, onConfirm, 'Yes, Delete', 'Cancel');
        } else if (confirm('Are you sure you want to delete "' + name + '" from resort activities?\n\nThis cannot be undone.')) {
            onConfirm();
        }
    }

    // Modal click backdrop & keyboard listeners
    const addActivityModal = document.getElementById('addActivityModal');
    if (addActivityModal) {
        addActivityModal.addEventListener('click', function(e) {
            if (e.target === this) closeAddActivityModal();
        });
    }

    const editActivityModal = document.getElementById('editActivityModal');
    if (editActivityModal) {
        editActivityModal.addEventListener('click', function(e) {
            if (e.target === this) closeEditActivityModal();
        });
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAddActivityModal();
            closeEditActivityModal();
        }
    });
    </script>
    <?php endif; ?>

    <?php if (!$is_admin_layout): ?>
    <script>
    // Guest Category Filter & Live Search
    (function() {
        const tabs = document.querySelectorAll('.act-tab-btn');
        const searchInput = document.getElementById('actSearchInput');
        const cards = document.querySelectorAll('#activitiesGrid .activity-card');
        const noMatch = document.getElementById('noMatchNotice');
        let currentCat = 'all';

        function filterActivities() {
            const query = (searchInput ? searchInput.value : '').toLowerCase().trim();
            let visibleCount = 0;

            cards.forEach(card => {
                const cat = (card.getAttribute('data-category') || '').toLowerCase();
                const name = (card.getAttribute('data-name') || '').toLowerCase();

                const matchesCat = (currentCat === 'all' || cat === currentCat || (currentCat === 'pool' && (cat.includes('pool') || cat === '')) || (currentCat === 'outdoor' && (cat.includes('water') || cat.includes('lagoon') || cat.includes('outdoor'))));
                const matchesQuery = (!query || name.includes(query) || cat.includes(query));

                if (matchesCat && matchesQuery) {
                    card.style.display = '';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            if (noMatch) {
                noMatch.style.display = (visibleCount === 0) ? 'block' : 'none';
            }
        }

        tabs.forEach(btn => {
            btn.addEventListener('click', function() {
                tabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                currentCat = this.getAttribute('data-cat') || 'all';
                filterActivities();
            });
        });

        if (searchInput) {
            searchInput.addEventListener('input', filterActivities);
        }

        window.resetActFilters = function() {
            if (searchInput) searchInput.value = '';
            currentCat = 'all';
            tabs.forEach(t => {
                if (t.getAttribute('data-cat') === 'all') t.classList.add('active');
                else t.classList.remove('active');
            });
            filterActivities();
        };
    })();
    </script>
    <?php endif; ?>

</body>
</html>

