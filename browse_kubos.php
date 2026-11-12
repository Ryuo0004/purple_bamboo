<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_role = $_SESSION['role'] ?? 'guest';
$is_admin_layout = ($user_role === 'admin' || $user_role === 'staff');

// Get kubos data from inventory store
require_once __DIR__ . '/inventory_store.php';
require_once __DIR__ . '/booking_store.php';

$filter_check_in = $_GET['check_in'] ?? null;
$filter_check_out = $_GET['check_out'] ?? null;

// Handle POST: Admin kubo management (edit, add, delete, status) with PRG pattern
if ($is_admin_layout && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'edit_kubo' || $action === 'update_kubo_status') {
        $kubo_id = intval($_POST['kubo_id'] ?? 0);
        if ($kubo_id > 0) {
            $image_file = $_FILES['image_file'] ?? null;
            $result = InventoryStore::saveKuboDetails($kubo_id, $_POST, $image_file);
            if ($result) {
                $_SESSION['status_flash'] = [
                    'type' => 'success',
                    'msg' => 'Kubo unit details, price, picture, and status updated successfully!'
                ];
            } else {
                $_SESSION['status_flash'] = [
                    'type' => 'error',
                    'msg' => 'Failed to update kubo details.'
                ];
            }
        }
        header("Location: browse_kubos.php");
        exit();
    } elseif ($action === 'add_kubo') {
        $image_file = $_FILES['image_file'] ?? null;
        $new_id = InventoryStore::addKubo($_POST, $image_file);
        if ($new_id) {
            $added_name = htmlspecialchars($_POST['name'] ?? 'New Kubo');
            $_SESSION['status_flash'] = [
                'type' => 'success',
                'msg' => "New Kubo unit \"{$added_name}\" added to resort inventory successfully!"
            ];
        } else {
            $_SESSION['status_flash'] = [
                'type' => 'error',
                'msg' => 'Failed to add new kubo unit.'
            ];
        }
        header("Location: browse_kubos.php");
        exit();
    } elseif ($action === 'delete_kubo') {
        $kubo_id = intval($_POST['kubo_id'] ?? 0);
        if ($kubo_id > 0) {
            InventoryStore::deleteKubo($kubo_id);
            $_SESSION['status_flash'] = [
                'type' => 'success',
                'msg' => 'Kubo unit has been permanently deleted from resort inventory.'
            ];
        }
        header("Location: browse_kubos.php");
        exit();
    }
}

// Flash message retrieval
$flash = $_SESSION['status_flash'] ?? null;
unset($_SESSION['status_flash']);

$kubos = InventoryStore::getAllKubos();
$all_categories = InventoryStore::getAllCategories();

// Calculate counts
$total_rooms = count($kubos);
$available_count = 0;
$occupied_count = 0;
$maintenance_count = 0;
$renovation_count = 0;
foreach ($kubos as $k) {
    $st = $k['status'] ?? 'available';
    if ($st === 'available') $available_count++;
    elseif ($st === 'occupied') $occupied_count++;
    elseif ($st === 'maintenance') $maintenance_count++;
    elseif ($st === 'renovation') $renovation_count++;
}

$search_query = trim($_GET['search'] ?? '');
$active_category_id = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? $_GET['category_id'] : null;

// Build Category Summary cards with stats, capacity, pricing & representative images
$categories_summary = [];
foreach ($all_categories as $cat) {
    $cid = (int)$cat['category_id'];
    $cat_kubos = array_values(array_filter($kubos, function($k) use ($cid) {
        return (int)($k['category_id'] ?? 0) === $cid;
    }));

    $total_units_in_cat = 0;
    $available_units_in_cat = 0;
    $min_price = null;
    $max_capacity = 0;
    $rep_image = null;

    foreach ($cat_kubos as $k) {
        $pr = (float)($k['price'] ?? 0);
        if ($min_price === null || $pr < $min_price) $min_price = $pr;
        $cap = (int)($k['capacity'] ?? 0);
        if ($cap > $max_capacity) $max_capacity = $cap;
        if (!$rep_image && !empty($k['image'])) $rep_image = $k['image'];

        $k_status = strtolower(trim($k['status'] ?? 'available'));
        $k_is_reno = ($k_status === 'renovation');
        $k_units = (int)($k['available_units'] ?? 5);
        $total_units_in_cat += $k_units;

        if ($k_status === 'available' && !$k_is_reno) {
            $available_units_in_cat += $k_units;
        }
    }

    if (!$rep_image) {
        $cat_fallbacks = [
            1 => 'assets/beach_kubo.jpg',
            2 => 'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=600&q=80',
            3 => 'https://images.unsplash.com/photo-1618773928121-c32242e63f39?auto=format&fit=crop&w=600&q=80'
        ];
        $rep_image = $cat_fallbacks[$cid] ?? 'assets/beach_kubo.jpg';
    }

    $cat_icons = [
        1 => 'fa-people-roof',
        2 => 'fa-heart',
        3 => 'fa-users',
        4 => 'fa-crown'
    ];
    $icon = $cat_icons[$cid] ?? 'fa-house-chimney';

    $categories_summary[$cid] = [
        'id' => $cid,
        'name' => $cat['category_name'],
        'description' => !empty($cat['description']) ? $cat['description'] : 'Authentic bamboo accommodations designed for supreme comfort.',
        'icon' => $icon,
        'kubos_count' => count($cat_kubos),
        'total_units' => $total_units_in_cat,
        'available_units' => $available_units_in_cat,
        'min_price' => $min_price ?? 0,
        'max_capacity' => $max_capacity ?: 4,
        'image' => $rep_image
    ];
}

// Check for any kubos with category_id not in $all_categories
$uncategorized_kubos = array_values(array_filter($kubos, function($k) use ($categories_summary) {
    return !isset($categories_summary[(int)($k['category_id'] ?? 0)]);
}));
if (!empty($uncategorized_kubos)) {
    $categories_summary[0] = [
        'id' => 0,
        'name' => 'Other Accommodations',
        'description' => 'Additional bamboo houses and resort villas.',
        'icon' => 'fa-house-chimney',
        'kubos_count' => count($uncategorized_kubos),
        'total_units' => count($uncategorized_kubos) * 5,
        'available_units' => count($uncategorized_kubos) * 5,
        'min_price' => $uncategorized_kubos[0]['price'] ?? 0,
        'max_capacity' => $uncategorized_kubos[0]['capacity'] ?? 4,
        'image' => $uncategorized_kubos[0]['image'] ?? 'assets/beach_kubo.jpg'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse House Kubos | Purple Bamboo Resort</title>
    <?php if ($is_admin_layout): ?>
        <link rel="stylesheet" href="admin-style.css">
    <?php else: ?>
        <link rel="stylesheet" href="style.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Modern Kubo Cards & Grid System */
        .kubos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 340px), 1fr));
            gap: 1.75rem;
            width: 100%;
            margin-bottom: 2.5rem;
        }
        @media (max-width: 768px) {
            .kubos-grid {
                grid-template-columns: 1fr;
                gap: 1.25rem;
            }
        }

        .kubo-card {
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
        .kubo-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 36px -6px rgba(124, 58, 237, 0.12);
            border-color: rgba(124, 58, 237, 0.4);
        }
        .kubo-card.is-renovation {
            border: 2px solid #ea580c;
            box-shadow: 0 4px 20px -2px rgba(234, 88, 12, 0.12);
        }
        .kubo-card.is-unavailable {
            border: 2px solid #ef4444;
            box-shadow: 0 4px 20px -2px rgba(239, 68, 68, 0.12);
        }

        /* Card Top Status Banner */
        .kubo-status-banner {
            padding: 0.65rem 1rem;
            font-weight: 800;
            font-size: 0.82rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            letter-spacing: 0.3px;
        }
        .kubo-status-banner.banner-renovation {
            background: linear-gradient(135deg, #c2410c, #ea580c);
            color: #ffffff;
        }
        .kubo-status-banner.banner-unavailable {
            background: linear-gradient(135deg, #991b1b, #dc2626);
            color: #ffffff;
        }
        .kubo-status-banner .banner-title {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .kubo-status-banner .banner-tag {
            background: rgba(0, 0, 0, 0.28);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.72rem;
            text-transform: uppercase;
            font-weight: 800;
            white-space: nowrap;
            flex-shrink: 0;
        }

        /* Media Container */
        .kubo-card-media {
            position: relative;
            height: 200px;
            width: 100%;
            overflow: hidden;
            background: #0f172a;
        }
        .kubo-card-media img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        .kubo-card:hover .kubo-card-media img {
            transform: scale(1.04);
        }

        /* Badges floating on image */
        .kubo-floating-badges {
            position: absolute;
            top: 12px;
            left: 12px;
            right: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            pointer-events: none;
            z-index: 2;
        }
        .kubo-category-pill {
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            color: #f5f3ff;
            border: 1px solid rgba(192, 132, 252, 0.4);
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            padding: 0.32rem 0.75rem;
            border-radius: 999px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            display: inline-flex;
            align-items: center;
            gap: 5px;
            max-width: 62%;
            min-width: 0;
            overflow: hidden;
            white-space: nowrap;
        }
        .kubo-category-pill span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .kubo-status-pill {
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.32rem 0.75rem;
            border-radius: 999px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }
        .kubo-status-pill.pill-available {
            background: rgba(5, 150, 105, 0.9);
            color: #ffffff;
        }
        .kubo-status-pill.pill-occupied {
            background: rgba(217, 119, 6, 0.9);
            color: #ffffff;
        }
        .kubo-status-pill.pill-maintenance {
            background: rgba(225, 29, 72, 0.9);
            color: #ffffff;
        }
        .kubo-status-pill.pill-renovation {
            background: rgba(234, 88, 12, 0.9);
            color: #ffffff;
        }

        /* Card Body */
        .kubo-card-body {
            padding: 1.35rem 1.45rem;
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .kubo-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }
        .kubo-card-title {
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
            flex: 1;
        }
        .kubo-price-block {
            text-align: right;
            flex-shrink: 0;
            white-space: nowrap;
        }
        .kubo-price-amount {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1.3rem;
            color: var(--gold-glow, #d97706);
            line-height: 1.1;
        }
        .kubo-price-unit {
            font-size: 0.72rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .kubo-card-desc {
            color: #64748b;
            font-size: 0.88rem;
            line-height: 1.55;
            margin: 0.25rem 0 1rem 0;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 2.75rem;
        }

        /* Amenity Chips */
        .kubo-card-chips {
            display: flex;
            gap: 0.45rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .kubo-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #334155;
            font-size: 0.76rem;
            font-weight: 700;
            padding: 0.3rem 0.65rem;
            border-radius: 8px;
            white-space: nowrap;
        }
        .kubo-chip.chip-success {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #047857;
        }
        .kubo-chip.chip-warning {
            background: #fffbeb;
            border-color: #fde68a;
            color: #b45309;
        }
        .kubo-chip.chip-danger {
            background: #fef2f2;
            border-color: #fecaca;
            color: #b91c1c;
        }
        .kubo-chip.chip-orange {
            background: #fff7ed;
            border-color: #fed7aa;
            color: #c2410c;
        }

        /* Compact Renovation Note */
        .kubo-renovation-box {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-left: 4px solid #ea580c;
            border-radius: 8px;
            padding: 0.75rem 0.9rem;
            margin-bottom: 1rem;
        }
        .kubo-renovation-header {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 0.35rem;
        }
        .kubo-reno-badge {
            background: #ea580c;
            color: #ffffff;
            font-size: 0.68rem;
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .kubo-reno-title {
            font-size: 0.82rem;
            font-weight: 700;
            color: #9a3412;
        }
        .kubo-renovation-note {
            font-size: 0.85rem;
            font-weight: 600;
            color: #1e293b;
            line-height: 1.4;
            margin-bottom: 0.4rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .kubo-renovation-dates {
            font-size: 0.76rem;
            color: #78350f;
            font-weight: 600;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        /* Actions Bar */
        .kubo-card-actions {
            margin-top: auto;
            padding-top: 1rem;
            border-top: 1px solid #f1f5f9;
            display: flex;
            gap: 0.6rem;
            align-items: center;
        }

        /* ==================== CATEGORIES VIEW SYSTEM ==================== */
        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 340px), 1fr));
            gap: 1.75rem;
            width: 100%;
            margin-bottom: 2.5rem;
        }

        .category-card {
            background: #ffffff;
            border: 1.5px solid var(--admin-card-border, #e2e8f0);
            border-radius: 18px;
            overflow: hidden;
            position: relative;
            display: flex;
            flex-direction: column;
            height: 100%;
            box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06);
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.25s cubic-bezier(0.4, 0, 0.2, 1), border-color 0.25s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }
        .category-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px -10px rgba(124, 58, 237, 0.18);
            border-color: rgba(124, 58, 237, 0.5);
        }

        .category-card-media {
            position: relative;
            height: 220px;
            width: 100%;
            overflow: hidden;
            background: #0f172a;
        }
        .category-card-media img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .category-card:hover .category-card-media img {
            transform: scale(1.06);
        }
        .category-card-media::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 60%;
            background: linear-gradient(to top, rgba(15, 23, 42, 0.85) 0%, rgba(15, 23, 42, 0) 100%);
            pointer-events: none;
        }

        .category-floating-badge {
            position: absolute;
            top: 14px;
            left: 14px;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            color: #f5f3ff;
            border: 1px solid rgba(192, 132, 252, 0.4);
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.35rem 0.8rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            z-index: 2;
        }

        .category-units-count-badge {
            position: absolute;
            bottom: 14px;
            left: 14px;
            background: linear-gradient(135deg, #7c3aed, #a855f7);
            color: #ffffff;
            font-size: 0.75rem;
            font-weight: 800;
            padding: 0.32rem 0.8rem;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.35);
            z-index: 2;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .category-card-body {
            padding: 1.4rem 1.5rem;
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .category-card-title {
            font-size: 1.25rem;
            font-weight: 800;
            color: #1e1b4b;
            margin: 0 0 0.45rem 0;
            font-family: 'Outfit', sans-serif;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .category-card-desc {
            color: #64748b;
            font-size: 0.88rem;
            line-height: 1.5;
            margin: 0 0 1.15rem 0;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 2.65rem;
        }

        .category-card-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.85rem 1rem;
            background: #f8fafc;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            margin-bottom: 1.15rem;
        }

        .category-meta-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.82rem;
            font-weight: 700;
            color: #475569;
        }

        .category-price-from {
            font-size: 0.8rem;
            color: #64748b;
            font-weight: 600;
        }
        .category-price-from strong {
            font-size: 1.1rem;
            color: #d97706;
            font-weight: 900;
            font-family: 'Outfit', sans-serif;
            margin-left: 2px;
        }

        .btn-category-action {
            margin-top: auto;
            width: 100%;
            height: 44px;
            background: linear-gradient(135deg, var(--primary-purple, #7c3aed), #9333ea);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.92rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.25s ease;
            cursor: pointer;
        }
        .category-card:hover .btn-category-action {
            background: linear-gradient(135deg, #6d28d9, #7c3aed);
            box-shadow: 0 4px 16px rgba(124, 58, 237, 0.35);
            transform: translateY(-1px);
        }

        /* Houses Navigation & Category Banner */
        .category-nav-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .btn-back-categories {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            color: #334155;
            padding: 0.6rem 1.2rem;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.88rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
            text-decoration: none;
        }
        .btn-back-categories:hover {
            background: #7c3aed;
            color: #ffffff;
            border-color: #7c3aed;
            transform: translateX(-3px);
            box-shadow: 0 4px 14px rgba(124, 58, 237, 0.25);
        }

        .category-tab-pills {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .cat-pill-tab {
            background: #f1f5f9;
            border: 1.5px solid #e2e8f0;
            color: #475569;
            padding: 0.45rem 0.95rem;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .cat-pill-tab:hover {
            background: #e2e8f0;
            color: #1e293b;
        }
        .cat-pill-tab.active {
            background: linear-gradient(135deg, #7c3aed, #9333ea);
            border-color: #7c3aed;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.25);
        }

        .category-header-banner {
            background: linear-gradient(135deg, rgba(124, 58, 237, 0.08), rgba(217, 119, 6, 0.08));
            border: 1.5px solid rgba(124, 58, 237, 0.2);
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
    </style>
</head>
<body class="<?= $is_admin_layout ? 'admin-layout-body' : '' ?>">

    <?php if ($is_admin_layout): ?>
        <?php include 'admin-nav.php'; ?>
        <div class="admin-main-wrapper">
            <header class="admin-top-header">
                <button type="button" class="admin-mobile-toggle-btn" onclick="toggleAdminSidebar()" aria-label="Open Navigation Menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="admin-top-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" placeholder="Search kubos..." onkeyup="if(event.key==='Enter') window.location='browse_kubos.php?search='+encodeURIComponent(this.value)">
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
        <div class="container">
    <?php endif; ?>

        <!-- Flash Message -->
        <?php if ($flash && $flash['type'] === 'success'): ?>
            <div style="background: rgba(16, 185, 129, 0.15); color: #047857; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #a7f3d0; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-circle-check" style="font-size: 1.2rem;"></i> <?= htmlspecialchars($flash['msg']) ?>
            </div>
        <?php elseif ($flash && $flash['type'] === 'error'): ?>
            <div style="background: rgba(239, 68, 68, 0.15); color: #b91c1c; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #fca5a5; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-circle-xmark" style="font-size: 1.2rem;"></i> <?= htmlspecialchars($flash['msg']) ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="<?= $is_admin_layout ? 'admin-page-header' : 'page-header' ?>">
            <div>
                <h1 class="<?= $is_admin_layout ? 'admin-page-title' : '' ?>">
                    <i class="fa-solid fa-house-chimney-window" style="color: var(--gold-glow, var(--bamboo-gold));"></i> 
                    Our House Kubos & Cottages
                </h1>
                <p class="<?= $is_admin_layout ? 'admin-page-subtitle' : '' ?>">
                    <?= $is_admin_layout ? 'Inventory management for all bamboo house accommodations and maintenance tracking.' : 'Select your ideal traditional bamboo accommodations with modern air-conditioned amenities.' ?>
                </p>
            </div>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <?php if ($is_admin_layout): ?>
                    <button onclick="openAddKuboModal()" class="admin-btn admin-btn-gold">
                        <i class="fa-solid fa-plus"></i> Add New Kubo Unit
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats Summary for Admin -->
        <?php if ($is_admin_layout): ?>
        <div class="admin-grid" style="margin-bottom: 2rem;">
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Total Kubos</span>
                <div class="admin-stat-value" style="color: var(--primary-glow);"><?= $total_rooms ?></div>
                <span class="admin-badge admin-badge-purple"><i class="fa-solid fa-building"></i> Total Inventory</span>
            </div>
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Available for Booking</span>
                <div class="admin-stat-value" style="color: var(--emerald);"><?= $available_count ?></div>
                <span class="admin-badge admin-badge-success"><i class="fa-solid fa-check-circle"></i> Ready for Guests</span>
            </div>
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Occupied</span>
                <div class="admin-stat-value" style="color: var(--gold-glow);"><?= $occupied_count ?></div>
                <span class="admin-badge admin-badge-gold"><i class="fa-solid fa-key"></i> Currently Occupied</span>
            </div>
            <div class="admin-card admin-stat-card">
                <span class="admin-stat-label">Under Maintenance</span>
                <div class="admin-stat-value" style="color: var(--rose);"><?= $maintenance_count ?></div>
                <span class="admin-badge admin-badge-danger"><i class="fa-solid fa-wrench"></i> In Maintenance</span>
            </div>
            <div class="admin-card admin-stat-card" style="border-color: rgba(234, 88, 12, 0.4); background: rgba(234, 88, 12, 0.04);">
                <span class="admin-stat-label">Under Renovation</span>
                <div class="admin-stat-value" style="color: #ea580c;"><?= $renovation_count ?></div>
                <span class="admin-badge admin-badge-orange">
                    <i class="fa-solid fa-hammer"></i> Under Renovation
                </span>
            </div>
        </div>
        <?php endif; ?>

        <!-- ==================== 1. CATEGORIES VIEW ==================== -->
        <div id="categories-container" style="<?= ($active_category_id !== null || !empty($search_query)) ? 'display: none;' : '' ?>">
            <div style="margin-bottom: 1.75rem; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h2 style="font-size: 1.35rem; color: #1e1b4b; font-family: 'Outfit', sans-serif; font-weight: 800; margin: 0 0 0.35rem 0; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-shapes" style="color: #a855f7;"></i> Select Accommodation Category
                    </h2>
                    <p style="color: #64748b; font-size: 0.92rem; margin: 0;">Click on a category below to explore all available house kubos and cottage units.</p>
                </div>
                <?php if ($is_admin_layout): ?>
                    <button type="button" onclick="selectCategory('all')" class="admin-btn admin-btn-outline admin-btn-sm" style="font-weight: 700;">
                        <i class="fa-solid fa-list-check"></i> View All Units (<?= $total_rooms ?>)
                    </button>
                <?php endif; ?>
            </div>

            <div class="categories-grid">
                <?php foreach ($categories_summary as $c): ?>
                    <div class="category-card" onclick="selectCategory(<?= $c['id'] ?>)" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' ') selectCategory(<?= $c['id'] ?>)">
                        <div class="category-card-media">
                            <img src="<?= htmlspecialchars($c['image']) ?>" alt="<?= htmlspecialchars($c['name']) ?>" loading="lazy">
                            <div class="category-floating-badge">
                                <i class="fa-solid <?= $c['icon'] ?>" style="color: #c084fc;"></i>
                                <span><?= htmlspecialchars($c['name']) ?></span>
                            </div>
                            <div class="category-units-count-badge">
                                <i class="fa-solid fa-house-chimney"></i>
                                <span><?= $c['kubos_count'] ?> <?= $c['kubos_count'] === 1 ? 'House Unit' : 'House Units' ?></span>
                            </div>
                        </div>

                        <div class="category-card-body">
                            <div class="category-card-title">
                                <span><?= htmlspecialchars($c['name']) ?></span>
                                <i class="fa-solid fa-arrow-right" style="color: #a855f7; font-size: 0.95rem; transition: transform 0.2s ease;"></i>
                            </div>
                            <p class="category-card-desc"><?= htmlspecialchars($c['description']) ?></p>

                            <div class="category-card-meta">
                                <div class="category-meta-item">
                                    <i class="fa-solid fa-users" style="color: #6366f1;"></i>
                                    <span>Max <?= $c['max_capacity'] ?> Pax</span>
                                </div>
                                <div class="category-price-from">
                                    From <strong>₱<?= number_format($c['min_price']) ?></strong><small>/night</small>
                                </div>
                            </div>

                            <button type="button" class="btn-category-action" onclick="event.stopPropagation(); selectCategory(<?= $c['id'] ?>);">
                                <span>View Accommodations</span>
                                <i class="fa-solid fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ==================== 2. HOUSES VIEW ==================== -->
        <div id="houses-container" style="<?= ($active_category_id !== null || !empty($search_query)) ? '' : 'display: none;' ?>">
            
            <!-- Navigation Bar -->
            <div class="category-nav-bar">
                <button type="button" class="btn-back-categories" onclick="showCategoriesView()">
                    <i class="fa-solid fa-arrow-left"></i>
                    <span>All Categories</span>
                </button>

                <!-- Category Pill Selector Tabs -->
                <div class="category-tab-pills">
                    <button type="button" class="cat-pill-tab" data-cat-id="all_cats" onclick="showCategoriesView()" title="Back to Category Cards">
                        <i class="fa-solid fa-shapes"></i> Categories
                    </button>
                    <?php foreach ($categories_summary as $c): ?>
                        <button type="button" class="cat-pill-tab <?= ((string)$active_category_id === (string)$c['id']) ? 'active' : '' ?>" data-cat-id="<?= $c['id'] ?>" onclick="selectCategory(<?= $c['id'] ?>)">
                            <i class="fa-solid <?= $c['icon'] ?>"></i>
                            <span><?= htmlspecialchars($c['name']) ?> (<?= $c['kubos_count'] ?>)</span>
                        </button>
                    <?php endforeach; ?>
                    <?php if ($is_admin_layout): ?>
                        <button type="button" class="cat-pill-tab <?= ((string)$active_category_id === 'all') ? 'active' : '' ?>" data-cat-id="all" onclick="selectCategory('all')">
                            <i class="fa-solid fa-list-check"></i> All (<?= $total_rooms ?>)
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Active Category Header Banner -->
            <div class="category-header-banner" id="activeCategoryBanner">
                <div style="flex: 1; min-width: 250px;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 0.35rem;">
                        <span class="admin-badge admin-badge-purple" style="font-size: 0.72rem; text-transform: uppercase;">
                            <i class="fa-solid fa-tag"></i> Active Category
                        </span>
                        <span id="activeCategoryCountBadge" class="admin-badge admin-badge-gold" style="font-size: 0.72rem;">
                            <?= isset($categories_summary[$active_category_id]) ? ($categories_summary[$active_category_id]['kubos_count'] . ' Units') : ($total_rooms . ' Units') ?>
                        </span>
                    </div>
                    <h2 id="activeCategoryTitle" style="font-size: 1.35rem; color: #1e1b4b; font-family: 'Outfit', sans-serif; font-weight: 800; margin: 0 0 0.25rem 0;">
                        <?php if (isset($categories_summary[$active_category_id])): ?>
                            <i class="fa-solid <?= $categories_summary[$active_category_id]['icon'] ?>" style="color: #a855f7; margin-right: 8px;"></i><?= htmlspecialchars($categories_summary[$active_category_id]['name']) ?>
                        <?php elseif ($active_category_id === 'all'): ?>
                            <i class="fa-solid fa-list-check" style="color: #a855f7; margin-right: 8px;"></i>All House Accommodations
                        <?php elseif (!empty($search_query)): ?>
                            <i class="fa-solid fa-magnifying-glass" style="color: #a855f7; margin-right: 8px;"></i>Search Results for "<?= htmlspecialchars($search_query) ?>"
                        <?php else: ?>
                            <i class="fa-solid fa-house-chimney" style="color: #a855f7; margin-right: 8px;"></i>House Accommodations
                        <?php endif; ?>
                    </h2>
                    <p id="activeCategoryDesc" style="color: #64748b; font-size: 0.88rem; margin: 0; line-height: 1.45;">
                        <?= isset($categories_summary[$active_category_id]) ? htmlspecialchars($categories_summary[$active_category_id]['description']) : 'Showing bamboo houses and cottage accommodations.' ?>
                    </p>
                </div>
                <div>
                    <button type="button" onclick="showCategoriesView()" class="btn-back-categories" style="font-size: 0.82rem; padding: 0.45rem 0.9rem;">
                        <i class="fa-solid fa-arrows-rotate"></i> Change Category
                    </button>
                </div>
            </div>

            <!-- Empty state if no kubos match -->
            <div id="no-kubos-in-cat" style="display: none; text-align: center; padding: 3rem 1.5rem; background: #ffffff; border: 1.5px dashed #cbd5e1; border-radius: 16px; margin-bottom: 2.5rem;">
                <i class="fa-solid fa-house-circle-xmark" style="font-size: 2.5rem; color: #a855f7; margin-bottom: 0.75rem; display: block;"></i>
                <h3 style="color: #1e293b; font-family: 'Outfit', sans-serif; font-weight: 700; margin-bottom: 0.4rem;">No Accommodations Found</h3>
                <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 1.25rem;">There are currently no house units listed under this category.</p>
                <button type="button" class="btn-back-categories" onclick="showCategoriesView()">
                    <i class="fa-solid fa-arrow-left"></i> View Other Categories
                </button>
            </div>

            <!-- Kubos Grid -->
            <div id="kubos-grid" class="kubos-grid">
                <?php foreach ($kubos as $kubo): 
                    $kubo_status = strtolower(trim($kubo['status'] ?? 'available'));
                    $is_renovation = ($kubo_status === 'renovation');
                    $is_date_booked = false;
                    if (!empty($filter_check_in) && !empty($filter_check_out)) {
                        $is_date_booked = !BookingStore::isItemAvailable($kubo['kubo_id'], $filter_check_in, $filter_check_out, 'house');
                    } else {
                        // Check if booked for today / active reservation
                        $is_date_booked = !BookingStore::isItemAvailable($kubo['kubo_id'], date('Y-m-d'), date('Y-m-d', strtotime('+1 day')), 'house');
                    }
                    // If marked occupied in DB but no active booking today, release to available
                    if ($kubo_status === 'occupied' && !$is_date_booked) {
                        $kubo_status = 'available';
                        if (class_exists('InventoryStore')) {
                            InventoryStore::updateKuboStatus($kubo['kubo_id'], 'available');
                        }
                    }
                    // Check if logged-in user already has an active (confirm or pending) booking for this specific kubo
                    $user_has_active_booking = false;
                    $user_active_status = '';
                    $user_active_ref = '';
                    if (!empty($_SESSION['user_id']) && empty($is_admin_layout)) {
                        $u_act = BookingStore::getActiveBookingForUser($_SESSION['user_id'], 'house', $kubo['kubo_id'], $_SESSION['email'] ?? null);
                        if ($u_act) {
                            $user_has_active_booking = true;
                            $user_active_status = strtolower($u_act['booking_status'] ?? 'pending');
                            $user_active_ref = $u_act['id'] ?? '';
                        }
                    }

                    $is_unavailable = in_array($kubo_status, ['renovation', 'maintenance', 'unavailable']) || $is_date_booked;
                    $card_state_class = $is_renovation ? 'is-renovation' : ($user_has_active_booking ? 'is-user-booked' : ($is_unavailable ? 'is-unavailable' : ''));
                    $kubo_img = !empty($kubo['image']) ? $kubo['image'] : 'https://images.unsplash.com/photo-1590490360182-c33d57733427?auto=format&fit=crop&w=600&q=80';
                    $kubo_desc = !empty(trim($kubo['description'] ?? '')) ? htmlspecialchars(trim($kubo['description'])) : 'Authentic bamboo villa designed for supreme comfort, scenic relaxation, and tropical stays.';
                    $kubo_cat_id = (int)($kubo['category_id'] ?? 0);
                    $is_matching_cat = ($active_category_id === 'all' || (string)$active_category_id === (string)$kubo_cat_id);
                    if (!empty($search_query)) {
                        $is_matching_cat = stripos($kubo['name'], $search_query) !== false
                            || stripos($kubo['description'] ?? '', $search_query) !== false
                            || stripos($kubo['category'] ?? '', $search_query) !== false
                            || stripos($kubo['badges'] ?? '', $search_query) !== false;
                    }
                ?>
                    <div class="kubo-card <?= $card_state_class ?>" 
                         data-category-id="<?= $kubo_cat_id ?>"
                         style="<?= (!$is_matching_cat && empty($search_query) && $active_category_id !== null) ? 'display: none;' : '' ?><?= ($is_unavailable || $is_renovation || $user_has_active_booking) && !$is_admin_layout ? ' opacity: 0.96;' : '' ?>">
                    
                    <!-- Active Booking / Renovation / Unavailable Top Header Banner -->
                    <?php if ($user_has_active_booking): ?>
                        <div class="kubo-status-banner" style="background: linear-gradient(135deg, #d97706, #b45309); color: #ffffff;">
                            <span class="banner-title"><i class="fa-solid fa-clock-rotate-left"></i> ALREADY BOOKED BY YOU</span>
                            <span class="banner-tag"><?= strtoupper($user_active_status) ?></span>
                        </div>
                    <?php elseif ($is_renovation): ?>
                        <div class="kubo-status-banner banner-renovation">
                            <span class="banner-title"><i class="fa-solid fa-triangle-exclamation"></i> UNDER RENOVATION</span>
                            <span class="banner-tag">NOT AVAILABLE</span>
                        </div>
                    <?php elseif ($is_unavailable): ?>
                        <div class="kubo-status-banner banner-unavailable">
                            <span class="banner-title"><i class="fa-solid fa-ban"></i> <?= ($kubo_status === 'maintenance') ? 'UNDER MAINTENANCE' : (($kubo_status === 'occupied' || $is_date_booked) ? 'CURRENTLY OCCUPIED / BOOKED' : 'CURRENTLY NOT AVAILABLE') ?></span>
                            <span class="banner-tag">NOT AVAILABLE</span>
                        </div>
                    <?php endif; ?>

                    <!-- Media Container with Hover Zoom & Floating Badges -->
                    <div class="kubo-card-media">
                        <img src="<?= htmlspecialchars($kubo_img) ?>" 
                             alt="<?= htmlspecialchars($kubo['name']) ?>"
                             <?= ($is_renovation || $is_unavailable) ? 'style="filter: saturate(65%) brightness(90%);"' : '' ?>>

                        <!-- Floating Badges Layer (Category on left, Status on right) -->
                        <div class="kubo-floating-badges">
                            <?php 
                            $raw_cat = $kubo['category'] ?? 'Resort Kubo';
                            $display_cat = trim(explode(',', $raw_cat)[0]);
                            if (empty($display_cat)) $display_cat = $raw_cat;
                            ?>
                            <span class="kubo-category-pill" title="<?= htmlspecialchars($raw_cat) ?>">
                                <i class="fa-solid fa-house-chimney-window" style="color: #c084fc; flex-shrink: 0;"></i>
                                <span><?= htmlspecialchars($display_cat) ?></span>
                            </span>

                            <?php if ($is_renovation): ?>
                                <span class="kubo-status-pill pill-renovation">
                                    <i class="fa-solid fa-hammer"></i> Renovating
                                </span>
                            <?php elseif ($kubo_status === 'occupied' || $is_date_booked): ?>
                                <span class="kubo-status-pill pill-occupied">
                                    <i class="fa-solid fa-key"></i> Booked
                                </span>
                            <?php elseif ($kubo_status === 'maintenance'): ?>
                                <span class="kubo-status-pill pill-maintenance">
                                    <i class="fa-solid fa-wrench"></i> Maintenance
                                </span>
                            <?php else: ?>
                                <span class="kubo-status-pill pill-available">
                                    <i class="fa-solid fa-circle-check"></i> Available
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Card Body -->
                    <div class="kubo-card-body">
                        <!-- Title & Price Header Row -->
                        <div class="kubo-card-header">
                            <h3 class="kubo-card-title"><?= htmlspecialchars($kubo['name']) ?></h3>
                            <div class="kubo-price-block">
                                <div class="kubo-price-amount">₱<?= number_format($kubo['price']) ?></div>
                                <div class="kubo-price-unit">per night</div>
                            </div>
                        </div>

                        <!-- 2-Line Description with guaranteed height & no blank gaps -->
                        <p class="kubo-card-desc"><?= $kubo_desc ?></p>

                        <!-- Feature Chips -->
                        <div class="kubo-card-chips">
                            <span class="kubo-chip">
                                <i class="fa-solid fa-users" style="color: #6366f1;"></i> Max <?= htmlspecialchars($kubo['capacity'] ?? 4) ?> Pax
                            </span>
                            <span class="kubo-chip">
                                <i class="fa-solid fa-door-open" style="color: #8b5cf6;"></i> <?= htmlspecialchars($kubo['available_units'] ?? 5) ?> Units
                            </span>
                            <?php 
                            $badges = [];
                            if (!empty($kubo['badges'])) {
                                $badges = is_array($kubo['badges']) ? $kubo['badges'] : explode(',', $kubo['badges']);
                            }
                            foreach ($badges as $badge): 
                                $b_trim = trim($badge);
                                if ($b_trim === '') continue;
                            ?>
                                <span class="kubo-chip">
                                    <i class="fa-solid fa-check" style="color: #10b981;"></i> <?= htmlspecialchars($b_trim) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>

                        <!-- Renovation / Damage Details (if applicable) -->
                        <?php if ($is_renovation): ?>
                            <div class="kubo-renovation-box">
                                <div class="kubo-renovation-header">
                                    <span class="kubo-reno-badge"><i class="fa-solid fa-wrench"></i> REPAIRS</span>
                                    <span class="kubo-reno-title">Damage / Maintenance Notes:</span>
                                </div>
                                <div class="kubo-renovation-note">
                                    <?= !empty($kubo['renovation_notes']) ? nl2br(htmlspecialchars($kubo['renovation_notes'])) : 'This room is currently undergoing repairs and maintenance.' ?>
                                </div>
                                <div class="kubo-renovation-dates">
                                    <?php if (!empty($kubo['renovation_start'])): ?>
                                        <span><i class="fa-regular fa-calendar"></i> Started: <strong><?= date('M d, Y', strtotime($kubo['renovation_start'])) ?></strong></span>
                                    <?php endif; ?>
                                    <?php if (!empty($kubo['renovation_end'])): ?>
                                        <span><i class="fa-regular fa-calendar-check"></i> Target: <strong><?= date('M d, Y', strtotime($kubo['renovation_end'])) ?></strong></span>
                                    <?php else: ?>
                                        <span><i class="fa-regular fa-clock"></i> In Progress</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Action Buttons Pinned at Bottom -->
                        <div class="kubo-card-actions">
                            <?php if ($is_admin_layout): ?>
                                <button onclick='openEditKuboModal(<?= json_encode($kubo, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' class="admin-btn admin-btn-gold admin-btn-sm" style="flex: 1; justify-content: center; height: 38px; font-weight: 700; white-space: nowrap; font-size: 0.85rem; padding: 0 0.85rem;">
                                    <i class="fa-solid fa-pen-to-square"></i> Edit Details & Status
                                </button>
                                <button onclick="confirmDeleteKubo(<?= $kubo['kubo_id'] ?>, '<?= htmlspecialchars(addslashes($kubo['name'])) ?>')" class="admin-btn admin-btn-outline admin-btn-sm" style="height: 38px; justify-content: center; padding: 0 0.85rem; color: #f43f5e; border-color: rgba(244,63,94,0.3); font-weight: 700; white-space: nowrap; font-size: 0.85rem;">
                                    <i class="fa-solid fa-trash"></i> Delete
                                </button>
                            <?php else: ?>
                                <?php if ($is_renovation): ?>
                                    <button disabled class="btn btn-sm" style="flex: 1; height: 40px; background: #ea580c; color: #ffffff; cursor: not-allowed; border: none; border-radius: 8px; font-weight: 700; font-size: 0.85rem; opacity: 0.95; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                                        <i class="fa-solid fa-hammer"></i> Not Available (Under Renovation)
                                    </button>
                                <?php elseif ($user_has_active_booking): ?>
                                    <a href="history.php" class="btn btn-sm" style="flex: 1; height: 40px; background: #fef3c7; color: #92400e; border: 1.5px solid #fcd34d; border-radius: 8px; font-weight: 800; font-size: 0.85rem; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none;" title="You already have an active <?= $user_active_status ?> booking (#<?= htmlspecialchars($user_active_ref) ?>) for this house">
                                        <i class="fa-solid fa-clock-rotate-left" style="color: #d97706;"></i> Booked by You (<?= ucfirst($user_active_status) ?>)
                                    </a>
                                <?php elseif ($is_unavailable): ?>
                                    <button disabled class="btn btn-sm" style="flex: 1; height: 40px; background: #fee2e2; color: #991b1b; cursor: not-allowed; border: 1.5px solid #fca5a5; border-radius: 8px; font-weight: 800; font-size: 0.85rem; display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                                        <i class="fa-solid fa-ban"></i> Not Available (<?= ($kubo_status === 'maintenance') ? 'Maintenance' : 'Booked' ?>)
                                    </button>
                                <?php else: ?>
                                    <a href="book_house.php?id=<?= $kubo['kubo_id'] ?><?= (!empty($filter_check_in) && !empty($filter_check_out)) ? '&check_in=' . urlencode($filter_check_in) . '&check_out=' . urlencode($filter_check_out) : '' ?>" class="btn-book-kubo" style="flex: 1; height: 40px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; border-radius: 8px; font-weight: 700; font-size: 0.9rem; background: linear-gradient(135deg, var(--bamboo-gold, #c49746), #d97706); color: #ffffff;">
                                        <i class="fa-solid fa-calendar-check"></i>
                                        <span>Book Kubo</span>
                                        <i class="fa-solid fa-arrow-right"></i>
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div><!-- /#kubos-grid -->
        </div><!-- /#houses-container -->

    <?php if ($is_admin_layout): ?>
            </main>
        </div>
    <?php else: ?>
        </div>
    <?php endif; ?>

    <?php if (!$is_admin_layout): ?>
        <?php include 'footer.php'; ?>
    <?php endif; ?>

    <?php if ($is_admin_layout): ?>
    <!-- Hidden Delete Form -->
    <form id="deleteKuboForm" method="POST" action="browse_kubos.php" style="display: none;">
        <input type="hidden" name="action" value="delete_kubo">
        <input type="hidden" name="kubo_id" id="deleteKuboId" value="">
    </form>

    <!-- Modal 1: Edit Kubo Details, Price, Picture, Badges & Status -->
    <div id="editKuboModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(6px); z-index: 9999; align-items: center; justify-content: center; padding: 1rem;">
        <div style="background: #1e1035; border: 1.5px solid #4c1d95; border-radius: 16px; width: 100%; max-width: 850px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px rgba(0,0,0,0.7); animation: modalPop 0.25s ease;">
            <!-- Modal Header -->
            <div style="padding: 1.25rem 1.5rem; background: linear-gradient(135deg, #7c3aed, #a855f7); border-radius: 14px 14px 0 0; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 10;">
                <h3 style="font-family: 'Outfit', sans-serif; color: #ffffff; margin: 0; font-size: 1.15rem; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-pen-to-square"></i> Edit Kubo Details, Price & Photo
                </h3>
                <button onclick="closeEditKuboModal()" style="background: rgba(255,255,255,0.2); border: none; color: #fff; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 1.1rem; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Modal Body -->
            <form id="editKuboForm" method="POST" action="browse_kubos.php" enctype="multipart/form-data" style="padding: 1.5rem;">
                <input type="hidden" name="action" value="edit_kubo">
                <input type="hidden" name="kubo_id" id="editKuboId" value="">
                <input type="hidden" name="existing_image" id="editExistingImage" value="">

                <!-- Row 1: Name & Category -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.15rem;">
                    <div>
                        <label for="editKuboName" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-house-chimney" style="color: #c084fc;"></i> Room / Kubo Name
                        </label>
                        <input type="text" name="name" id="editKuboName" required style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.95rem; font-weight: 600;">
                    </div>
                    <div>
                        <label for="editKuboCategory" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-tag" style="color: #c084fc;"></i> Category
                        </label>
                        <select name="category_id" id="editKuboCategory" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.92rem; font-weight: 600; cursor: pointer;">
                            <?php foreach ($all_categories as $cat): ?>
                                <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Row 2: Price, Capacity & Status -->
                <input type="hidden" name="available_units" id="editKuboAvailableUnits" value="1">
                <div style="display: grid; grid-template-columns: 1fr 1fr 1.2fr; gap: 1rem; margin-bottom: 1.15rem;">
                    <div>
                        <label for="editKuboPrice" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-peso-sign" style="color: #fbbf24;"></i> Price / Night
                        </label>
                        <input type="number" name="price" id="editKuboPrice" step="50" min="0" required style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #fbbf24; font-weight: 800; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 1rem;">
                    </div>
                    <div>
                        <label for="editKuboCapacity" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-users" style="color: #38bdf8;"></i> Max Pax
                        </label>
                        <input type="number" name="capacity" id="editKuboCapacity" min="1" max="100" required style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; font-weight: 700; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.95rem;">
                    </div>
                    <div>
                        <label for="editKuboStatus" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-toggle-on" style="color: #4ade80;"></i> Status
                        </label>
                        <select name="status" id="editKuboStatus" onchange="toggleEditRenovationFields()" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #e2e8f0; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.92rem; font-weight: 700; cursor: pointer;">
                            <option value="available">✅ Available</option>
                            <option value="renovation">🚧 Under Renovation</option>
                            <option value="occupied">🔑 Occupied</option>
                            <option value="maintenance">🔧 Maintenance</option>
                        </select>
                    </div>
                </div>

                <!-- Picture / Photo Section -->
                <div style="background: rgba(124, 58, 237, 0.08); border: 1.5px dashed #4c1d95; border-radius: 12px; padding: 1.15rem; margin-bottom: 1.15rem;">
                    <label style="display: block; font-weight: 800; color: #e2e8f0; margin-bottom: 0.65rem; font-size: 0.92rem;">
                        <i class="fa-solid fa-camera" style="color: #c084fc;"></i> Room Picture / Photo
                    </label>
                    <div style="display: flex; gap: 1rem; align-items: flex-start; flex-wrap: wrap;">
                        <div style="position: relative;">
                            <img id="editKuboImgPreview" src="assets/beach_kubo.jpg" alt="Preview" style="width: 120px; height: 85px; object-fit: cover; border-radius: 8px; border: 2px solid #7c3aed; background: #0f172a; box-shadow: 0 4px 10px rgba(0,0,0,0.5);">
                            <span style="position: absolute; bottom: 4px; left: 4px; background: rgba(0,0,0,0.7); color: #fff; font-size: 0.65rem; padding: 1px 5px; border-radius: 4px; font-weight: 700;">PREVIEW</span>
                        </div>
                        <div style="flex: 1; min-width: 220px;">
                            <div>
                                <label style="display: block; font-size: 0.82rem; color: #cbd5e1; margin-bottom: 0.35rem; font-weight: 600;">
                                    <i class="fa-solid fa-upload"></i> Upload new photo from computer:
                                </label>
                                <input type="file" name="image_file" id="editKuboFile" accept="image/*" onchange="previewKuboFile(this, 'editKuboImgPreview')" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #cbd5e1; padding: 0.55rem 0.75rem; border-radius: 8px; font-size: 0.85rem; cursor: pointer;">
                            </div>
                            <small style="color: #94a3b8; font-size: 0.75rem; display: block; margin-top: 0.45rem;">
                                * Supports JPG, PNG, WEBP. Leave empty to keep current picture.
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Badges & Description -->
                <div style="margin-bottom: 1.15rem;">
                    <label for="editKuboBadges" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                        <i class="fa-solid fa-sparkles" style="color: #fbbf24;"></i> Badges / Highlights (comma separated)
                    </label>
                    <input type="text" name="badges" id="editKuboBadges" placeholder="Free WiFi, Aircon, Sea View, Smart TV" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.6rem 0.85rem; border-radius: 8px; font-size: 0.9rem;">
                </div>

                <div style="margin-bottom: 1.15rem;">
                    <label for="editKuboDesc" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                        <i class="fa-solid fa-align-left" style="color: #c084fc;"></i> Room Description
                    </label>
                    <textarea name="description" id="editKuboDesc" rows="2" placeholder="Describe the bamboo cottage features, amenities, view..." style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.6rem 0.85rem; border-radius: 8px; font-size: 0.88rem; font-family: inherit; resize: vertical;"></textarea>
                </div>

                <!-- Renovation Fields (toggled if renovation or maintenance) -->
                <div id="editRenovationFields" style="display: none; border: 1.5px solid #ea580c; background: rgba(234, 88, 12, 0.1); border-radius: 10px; padding: 1.25rem; margin-bottom: 1.25rem;">
                    <h4 style="font-family: 'Outfit', sans-serif; color: #fb923c; margin-bottom: 0.75rem; font-size: 0.98rem; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-hammer"></i> Room Damage & Renovation Details
                        <span style="background: #ea580c; color: #fff; font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; font-weight: 800;">Visible to Guests</span>
                    </h4>

                    <div style="margin-bottom: 1rem;">
                        <label for="editRenoNotes" style="display: block; font-weight: 700; color: #fed7aa; margin-bottom: 0.35rem; font-size: 0.85rem;">
                            Specify damages or reason why this room is unavailable:
                        </label>
                        <textarea name="renovation_notes" id="editRenoNotes" rows="2" placeholder="e.g., Bed frame repair, aircon maintenance, bathroom fixing..." style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.6rem 0.85rem; border-radius: 8px; font-size: 0.9rem; resize: vertical; font-family: inherit;"></textarea>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <label for="editRenoStart" style="display: block; font-weight: 700; color: #fed7aa; margin-bottom: 0.35rem; font-size: 0.82rem;">Start Date</label>
                            <input type="date" name="renovation_start" id="editRenoStart" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.55rem 0.75rem; border-radius: 8px; font-size: 0.88rem;">
                        </div>
                        <div>
                            <label for="editRenoEnd" style="display: block; font-weight: 700; color: #fed7aa; margin-bottom: 0.35rem; font-size: 0.82rem;">Estimated Completion Date</label>
                            <input type="date" name="renovation_end" id="editRenoEnd" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.55rem 0.75rem; border-radius: 8px; font-size: 0.88rem;">
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                    <button type="button" onclick="showUpdateConfirm('Save Kubo Updates', 'Are you sure you want to update this kubo unit?', function() { document.getElementById('editKuboForm').submit(); })" class="admin-btn admin-btn-gold" style="flex: 1; justify-content: center; padding: 0.75rem; font-weight: 800; font-size: 0.95rem;">
                        <i class="fa-solid fa-floppy-disk"></i> Save & Update Kubo
                    </button>
                    <button type="button" onclick="closeEditKuboModal()" class="admin-btn admin-btn-outline" style="flex: 1; justify-content: center; padding: 0.75rem;">
                        <i class="fa-solid fa-xmark"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal 2: Add New Kubo Unit -->
    <div id="addKuboModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(6px); z-index: 9999; align-items: center; justify-content: center; padding: 1rem;">
        <div style="background: #1e1035; border: 1.5px solid #4c1d95; border-radius: 16px; width: 100%; max-width: 850px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px rgba(0,0,0,0.7); animation: modalPop 0.25s ease;">
            <!-- Modal Header -->
            <div style="padding: 1.25rem 1.5rem; background: linear-gradient(135deg, #10b981, #059669); border-radius: 14px 14px 0 0; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 10;">
                <h3 style="font-family: 'Outfit', sans-serif; color: #ffffff; margin: 0; font-size: 1.15rem; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-circle-plus"></i> Add New Kubo Unit
                </h3>
                <button onclick="closeAddKuboModal()" style="background: rgba(255,255,255,0.2); border: none; color: #fff; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 1.1rem; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Modal Body -->
            <form id="addKuboForm" method="POST" action="browse_kubos.php" enctype="multipart/form-data" style="padding: 1.5rem;">
                <input type="hidden" name="action" value="add_kubo">

                <!-- Row 1: Name & Category -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.15rem;">
                    <div>
                        <label for="addKuboName" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-house-chimney" style="color: #34d399;"></i> Kubo Name
                        </label>
                        <input type="text" name="name" id="addKuboName" placeholder="e.g. Bamboo Kubo #4" required style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.95rem; font-weight: 600;">
                    </div>
                    <div>
                        <label for="addKuboCategory" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-tag" style="color: #34d399;"></i> Category
                        </label>
                        <select name="category_id" id="addKuboCategory" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.92rem; font-weight: 600; cursor: pointer;">
                            <?php foreach ($all_categories as $cat): ?>
                                <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Row 2: Price, Capacity & Status -->
                <input type="hidden" name="available_units" id="addKuboAvailableUnits" value="1">
                <div style="display: grid; grid-template-columns: 1fr 1fr 1.2fr; gap: 1rem; margin-bottom: 1.15rem;">
                    <div>
                        <label for="addKuboPrice" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-peso-sign" style="color: #fbbf24;"></i> Price / Night
                        </label>
                        <input type="number" name="price" id="addKuboPrice" value="3000" step="50" min="0" required style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #fbbf24; font-weight: 800; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 1rem;">
                    </div>
                    <div>
                        <label for="addKuboCapacity" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-users" style="color: #38bdf8;"></i> Max Pax
                        </label>
                        <input type="number" name="capacity" id="addKuboCapacity" value="4" min="1" max="100" required style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; font-weight: 700; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.95rem;">
                    </div>
                    <div>
                        <label for="addKuboStatus" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-toggle-on" style="color: #4ade80;"></i> Initial Status
                        </label>
                        <select name="status" id="addKuboStatus" onchange="toggleAddRenovationFields()" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #e2e8f0; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.92rem; font-weight: 700; cursor: pointer;">
                            <option value="available" selected>✅ Available</option>
                            <option value="renovation">🚧 Under Renovation</option>
                            <option value="occupied">🔑 Occupied</option>
                            <option value="maintenance">🔧 Maintenance</option>
                        </select>
                    </div>
                </div>

                <!-- Picture / Photo Section -->
                <div style="background: rgba(16, 185, 129, 0.08); border: 1.5px dashed #059669; border-radius: 12px; padding: 1.15rem; margin-bottom: 1.15rem;">
                    <label style="display: block; font-weight: 800; color: #e2e8f0; margin-bottom: 0.65rem; font-size: 0.92rem;">
                        <i class="fa-solid fa-camera" style="color: #34d399;"></i> Room Picture / Photo
                    </label>
                    <div style="display: flex; gap: 1rem; align-items: flex-start; flex-wrap: wrap;">
                        <div style="position: relative;">
                            <img id="addKuboImgPreview" src="assets/beach_kubo.jpg" alt="Preview" style="width: 120px; height: 85px; object-fit: cover; border-radius: 8px; border: 2px solid #059669; background: #0f172a; box-shadow: 0 4px 10px rgba(0,0,0,0.5);">
                            <span style="position: absolute; bottom: 4px; left: 4px; background: rgba(0,0,0,0.7); color: #fff; font-size: 0.65rem; padding: 1px 5px; border-radius: 4px; font-weight: 700;">PREVIEW</span>
                        </div>
                        <div style="flex: 1; min-width: 220px;">
                            <div>
                                <label style="display: block; font-size: 0.82rem; color: #cbd5e1; margin-bottom: 0.35rem; font-weight: 600;">
                                    <i class="fa-solid fa-upload"></i> Upload picture from computer:
                                </label>
                                <input type="file" name="image_file" id="addKuboFile" accept="image/*" onchange="previewKuboFile(this, 'addKuboImgPreview')" style="width: 100%; background: #0f172a; border: 1.5px solid #059669; color: #cbd5e1; padding: 0.55rem 0.75rem; border-radius: 8px; font-size: 0.85rem; cursor: pointer;">
                            </div>
                            <small style="color: #94a3b8; font-size: 0.75rem; display: block; margin-top: 0.45rem;">
                                * Supports JPG, PNG, WEBP files.
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Badges & Description -->
                <div style="margin-bottom: 1.15rem;">
                    <label for="addKuboBadges" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                        <i class="fa-solid fa-sparkles" style="color: #fbbf24;"></i> Badges / Highlights (comma separated)
                    </label>
                    <input type="text" name="badges" id="addKuboBadges" value="Free WiFi, Aircon" placeholder="Free WiFi, Aircon, Sea View" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.6rem 0.85rem; border-radius: 8px; font-size: 0.9rem;">
                </div>

                <div style="margin-bottom: 1.15rem;">
                    <label for="addKuboDesc" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                        <i class="fa-solid fa-align-left" style="color: #34d399;"></i> Room Description
                    </label>
                    <textarea name="description" id="addKuboDesc" rows="2" placeholder="Describe the bamboo cottage features, amenities, view..." style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.6rem 0.85rem; border-radius: 8px; font-size: 0.88rem; font-family: inherit; resize: vertical;"></textarea>
                </div>

                <!-- Renovation Fields (toggled if renovation or maintenance) -->
                <div id="addRenovationFields" style="display: none; border: 1.5px solid #ea580c; background: rgba(234, 88, 12, 0.1); border-radius: 10px; padding: 1.25rem; margin-bottom: 1.25rem;">
                    <h4 style="font-family: 'Outfit', sans-serif; color: #fb923c; margin-bottom: 0.75rem; font-size: 0.98rem; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-hammer"></i> Room Damage & Renovation Details
                    </h4>

                    <div style="margin-bottom: 1rem;">
                        <label for="addRenoNotes" style="display: block; font-weight: 700; color: #fed7aa; margin-bottom: 0.35rem; font-size: 0.85rem;">
                            Specify damages or reason why this room is unavailable:
                        </label>
                        <textarea name="renovation_notes" id="addRenoNotes" rows="2" placeholder="e.g., Under construction, painting, roof maintenance..." style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.6rem 0.85rem; border-radius: 8px; font-size: 0.9rem; resize: vertical; font-family: inherit;"></textarea>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <label for="addRenoStart" style="display: block; font-weight: 700; color: #fed7aa; margin-bottom: 0.35rem; font-size: 0.82rem;">Start Date</label>
                            <input type="date" name="renovation_start" id="addRenoStart" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.55rem 0.75rem; border-radius: 8px; font-size: 0.88rem;">
                        </div>
                        <div>
                            <label for="addRenoEnd" style="display: block; font-weight: 700; color: #fed7aa; margin-bottom: 0.35rem; font-size: 0.82rem;">Estimated Completion Date</label>
                            <input type="date" name="renovation_end" id="addRenoEnd" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.55rem 0.75rem; border-radius: 8px; font-size: 0.88rem;">
                        </div>
                    </div>
                </div>

                <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                    <button type="button" onclick="showAddConfirm('Add New Kubo Unit', 'Are you sure you want to add this kubo unit to inventory?', function() { document.getElementById('addKuboForm').submit(); })" class="admin-btn admin-btn-gold" style="flex: 1; justify-content: center; padding: 0.75rem; font-weight: 800; font-size: 0.95rem;">
                        <i class="fa-solid fa-plus-circle"></i> Add Unit to Inventory
                    </button>
                    <button type="button" onclick="closeAddKuboModal()" class="admin-btn admin-btn-outline" style="flex: 1; justify-content: center; padding: 0.75rem;">
                        <i class="fa-solid fa-xmark"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Live Image Preview for Local File Upload
    function previewKuboFile(input, targetImgId) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = document.getElementById(targetImgId);
                if (img) img.src = e.target.result;
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    // Live Image Preview for URL input
    function previewKuboUrl(url, targetImgId) {
        if (url && url.trim().length > 3) {
            const img = document.getElementById(targetImgId);
            if (img) img.src = url.trim();
        }
    }

    // Edit Modal Logic
    function openEditKuboModal(kubo) {
        if (!kubo) return;
        document.getElementById('editKuboId').value = kubo.kubo_id || '';
        document.getElementById('editExistingImage').value = kubo.image || '';
        document.getElementById('editKuboName').value = kubo.name || '';
        
        // Category
        if (kubo.category_id) {
            document.getElementById('editKuboCategory').value = kubo.category_id;
        }
        
        document.getElementById('editKuboPrice').value = kubo.price || 0;
        document.getElementById('editKuboCapacity').value = kubo.capacity || 2;
        if (document.getElementById('editKuboAvailableUnits')) {
            document.getElementById('editKuboAvailableUnits').value = kubo.available_units !== undefined ? kubo.available_units : 1;
        }
        
        // Status
        const st = (kubo.status || 'available').toLowerCase().trim();
        document.getElementById('editKuboStatus').value = st;
        
        // Image preview & fields
        const previewImg = document.getElementById('editKuboImgPreview');
        const defaultImg = kubo.image || 'assets/beach_kubo.jpg';
        if (previewImg) previewImg.src = defaultImg;
        if (document.getElementById('editKuboUrl')) document.getElementById('editKuboUrl').value = '';
        document.getElementById('editKuboFile').value = '';
        
        // Badges
        let badgeStr = '';
        if (Array.isArray(kubo.badges)) {
            badgeStr = kubo.badges.join(', ');
        } else if (typeof kubo.badges === 'string') {
            badgeStr = kubo.badges;
        }
        document.getElementById('editKuboBadges').value = badgeStr;
        
        // Description
        document.getElementById('editKuboDesc').value = kubo.description || '';
        
        // Renovation fields
        document.getElementById('editRenoNotes').value = kubo.renovation_notes || '';
        document.getElementById('editRenoStart').value = kubo.renovation_start || '';
        document.getElementById('editRenoEnd').value = kubo.renovation_end || '';
        
        toggleEditRenovationFields();
        document.getElementById('editKuboModal').style.display = 'flex';
    }

    function closeEditKuboModal() {
        document.getElementById('editKuboModal').style.display = 'none';
    }

    function toggleEditRenovationFields() {
        const status = (document.getElementById('editKuboStatus').value || '').toLowerCase().trim();
        const fields = document.getElementById('editRenovationFields');
        if (fields) {
            fields.style.display = (status === 'renovation' || status === 'maintenance') ? 'block' : 'none';
        }
    }

    // Add Modal Logic
    function openAddKuboModal() {
        if (document.getElementById('addKuboName')) document.getElementById('addKuboName').value = '';
        if (document.getElementById('addKuboPrice')) document.getElementById('addKuboPrice').value = '3000';
        if (document.getElementById('addKuboCapacity')) document.getElementById('addKuboCapacity').value = '4';
        if (document.getElementById('addKuboAvailableUnits')) document.getElementById('addKuboAvailableUnits').value = '1';
        if (document.getElementById('addKuboStatus')) document.getElementById('addKuboStatus').value = 'available';
        if (document.getElementById('addKuboBadges')) document.getElementById('addKuboBadges').value = 'Free WiFi, Aircon';
        if (document.getElementById('addKuboDesc')) document.getElementById('addKuboDesc').value = '';
        if (document.getElementById('addKuboUrl')) document.getElementById('addKuboUrl').value = '';
        if (document.getElementById('addKuboFile')) document.getElementById('addKuboFile').value = '';
        if (document.getElementById('addKuboImgPreview')) document.getElementById('addKuboImgPreview').src = 'assets/beach_kubo.jpg';
        if (document.getElementById('addRenoNotes')) document.getElementById('addRenoNotes').value = '';
        if (document.getElementById('addRenoStart')) document.getElementById('addRenoStart').value = '';
        if (document.getElementById('addRenoEnd')) document.getElementById('addRenoEnd').value = '';
        toggleAddRenovationFields();
        const modal = document.getElementById('addKuboModal');
        if (modal) modal.style.display = 'flex';
    }

    function closeAddKuboModal() {
        const modal = document.getElementById('addKuboModal');
        if (modal) modal.style.display = 'none';
    }

    function toggleAddRenovationFields() {
        const status = (document.getElementById('addKuboStatus').value || '').toLowerCase().trim();
        const fields = document.getElementById('addRenovationFields');
        if (fields) {
            fields.style.display = (status === 'renovation' || status === 'maintenance') ? 'block' : 'none';
        }
    }

    // Delete Confirmation (Matches Logout Confirmation Modal Design)
    function confirmDeleteKubo(kuboId, kuboName) {
        const title = 'Delete Kubo Confirmation';
        const msg = 'Are you sure you want to permanently delete "<strong>' + (kuboName || 'this unit') + '</strong>" from resort inventory?<br><span style="display:block; margin-top:8px; color: #f87171; font-size: 0.85rem; font-weight: 600;"><i class="fa-solid fa-triangle-exclamation"></i> This action cannot be undone.</span>';
        const onConfirm = function() {
            document.getElementById('deleteKuboId').value = kuboId;
            document.getElementById('deleteKuboForm').submit();
        };

        if (typeof showDeleteConfirm === 'function') {
            showDeleteConfirm(title, msg, onConfirm, 'Yes, Delete', 'Cancel');
        } else if (window.showDeleteConfirm) {
            window.showDeleteConfirm(title, msg, onConfirm, 'Yes, Delete', 'Cancel');
        } else if (confirm('Are you sure you want to permanently delete "' + kuboName + '" from resort inventory?\n\nThis action cannot be undone.')) {
            onConfirm();
        }
    }

    // Modal click backdrop & keyboard listeners
    const editKuboModal = document.getElementById('editKuboModal');
    if (editKuboModal) {
        editKuboModal.addEventListener('click', function(e) {
            if (e.target === this) closeEditKuboModal();
        });
    }

    const addKuboModalEl = document.getElementById('addKuboModal');
    if (addKuboModalEl) {
        addKuboModalEl.addEventListener('click', function(e) {
            if (e.target === this) closeAddKuboModal();
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeEditKuboModal();
            closeAddKuboModal();
        }
    });
    </script>
    <?php endif; ?>

    <!-- Category Filtering & Dynamic View Script (Available for both Guests & Admin) -->
    <script>
    const categoriesData = <?= json_encode($categories_summary, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    function selectCategory(catId, updateHistory = true) {
        const catContainer = document.getElementById('categories-container');
        const housesContainer = document.getElementById('houses-container');
        const cards = document.querySelectorAll('.kubo-card');
        const noKubosNotice = document.getElementById('no-kubos-in-cat');
        
        if (catId === null || catId === undefined || catId === '') {
            // Show categories view
            if (catContainer) catContainer.style.display = 'block';
            if (housesContainer) housesContainer.style.display = 'none';
            
            if (updateHistory) {
                const url = new URL(window.location.href);
                url.searchParams.delete('category_id');
                window.history.pushState({ category_id: null }, '', url.toString());
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }
        
        // Switch to houses view
        if (catContainer) catContainer.style.display = 'none';
        if (housesContainer) housesContainer.style.display = 'block';
        
        // Update active tab pills
        document.querySelectorAll('.cat-pill-tab').forEach(tab => {
            const tabCatId = tab.getAttribute('data-cat-id');
            if (String(tabCatId) === String(catId)) {
                tab.classList.add('active');
            } else {
                tab.classList.remove('active');
            }
        });
        
        // Update header info
        const catInfo = categoriesData[catId];
        const titleEl = document.getElementById('activeCategoryTitle');
        const descEl = document.getElementById('activeCategoryDesc');
        const countEl = document.getElementById('activeCategoryCountBadge');
        
        if (catInfo) {
            if (titleEl) titleEl.innerHTML = '<i class="fa-solid ' + (catInfo.icon || 'fa-house-chimney') + '" style="color: #a855f7; margin-right: 8px;"></i>' + catInfo.name;
            if (descEl) descEl.textContent = catInfo.description || 'Authentic bamboo accommodations in this category.';
            if (countEl) countEl.textContent = catInfo.kubos_count + (catInfo.kubos_count === 1 ? ' Unit' : ' Units');
        } else if (catId === 'all') {
            if (titleEl) titleEl.innerHTML = '<i class="fa-solid fa-list-check" style="color: #a855f7; margin-right: 8px;"></i> All House Accommodations';
            if (descEl) descEl.textContent = 'Browsing all resort kubo cottages and villas across all categories.';
            if (countEl) countEl.textContent = cards.length + ' Total Units';
        }
        
        // Filter cards
        let visibleCount = 0;
        cards.forEach(card => {
            const cardCat = card.getAttribute('data-category-id');
            if (catId === 'all' || String(cardCat) === String(catId)) {
                card.style.display = 'flex';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });
        
        if (noKubosNotice) {
            noKubosNotice.style.display = visibleCount === 0 ? 'block' : 'none';
        }
        
        // Update URL
        if (updateHistory) {
            const url = new URL(window.location.href);
            url.searchParams.set('category_id', catId);
            window.history.pushState({ category_id: catId }, '', url.toString());
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function showCategoriesView() {
        selectCategory(null, true);
    }

    // Support browser Back/Forward navigation
    window.addEventListener('popstate', function(e) {
        const urlParams = new URLSearchParams(window.location.search);
        const catId = urlParams.get('category_id');
        selectCategory(catId, false);
    });

    // Handle initial state if opened with category_id parameter
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const initialCatId = urlParams.get('category_id');
        if (initialCatId) {
            selectCategory(initialCatId, false);
        }
    });
    </script>

</body>
</html>