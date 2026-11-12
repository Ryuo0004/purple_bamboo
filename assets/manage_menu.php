<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_role = $_SESSION['role'] ?? 'guest';
$is_admin_layout = ($user_role === 'admin' || $user_role === 'staff');

// Strict RBAC: Only Admin and Staff allowed
if (!$is_admin_layout) {
    header("Location: admin-login.php?error=unauthorized");
    exit();
}

require_once __DIR__ . '/dining_store.php';

// Handle POST: Menu Item CRUD with PRG pattern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add_menu_item') {
        $image_file = $_FILES['image_file'] ?? null;
        $new_id = DiningStore::addMenuItem($_POST, $image_file);
        if ($new_id) {
            $name = htmlspecialchars($_POST['item_name'] ?? 'Menu Item');
            $_SESSION['menu_flash'] = [
                'type' => 'success',
                'msg' => "New menu item \"{$name}\" added successfully!"
            ];
        } else {
            $_SESSION['menu_flash'] = [
                'type' => 'error',
                'msg' => 'Failed to add new menu item.'
            ];
        }
        header("Location: manage_menu.php");
        exit();
    } elseif ($action === 'edit_menu_item') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $image_file = $_FILES['image_file'] ?? null;
            $res = DiningStore::saveMenuItem($id, $_POST, $image_file);
            if ($res) {
                $name = htmlspecialchars($_POST['item_name'] ?? 'Menu Item');
                $_SESSION['menu_flash'] = [
                    'type' => 'success',
                    'msg' => "Menu item \"{$name}\" updated successfully!"
                ];
            } else {
                $_SESSION['menu_flash'] = [
                    'type' => 'error',
                    'msg' => 'Failed to update menu item.'
                ];
            }
        }
        header("Location: manage_menu.php");
        exit();
    } elseif ($action === 'delete_menu_item') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            DiningStore::deleteMenuItem($id);
            $_SESSION['menu_flash'] = [
                'type' => 'success',
                'msg' => 'Menu item deleted successfully from dining menu.'
            ];
        }
        header("Location: manage_menu.php");
        exit();
    } elseif ($action === 'toggle_availability') {
        $id = intval($_POST['id'] ?? 0);
        $new_avail = intval($_POST['is_available'] ?? 0);
        if ($id > 0) {
            DiningStore::toggleMenuItemAvailability($id, $new_avail);
            $_SESSION['menu_flash'] = [
                'type' => 'success',
                'msg' => 'Menu item availability status updated!'
            ];
        }
        header("Location: manage_menu.php");
        exit();
    }
}

// Flash message
$flash = $_SESSION['menu_flash'] ?? null;
unset($_SESSION['menu_flash']);

$selected_cat = $_GET['cat'] ?? 'All';
$search_query = trim($_GET['search'] ?? '');

$all_items = DiningStore::getAllMenuItemsAdmin($selected_cat);

// Filter by search if provided
if (!empty($search_query)) {
    $all_items = array_filter($all_items, function($item) use ($search_query) {
        return stripos($item['item_name'], $search_query) !== false 
            || stripos($item['description'], $search_query) !== false
            || stripos($item['allergens'] ?? '', $search_query) !== false;
    });
}

// Compute Stats
$all_raw = DiningStore::getAllMenuItemsAdmin();
$total_items = count($all_raw);
$available_count = 0;
$soldout_count = 0;
foreach ($all_raw as $it) {
    if (!empty($it['is_available'])) $available_count++;
    else $soldout_count++;
}

$categories = ['All', 'Main Dishes', 'Appetizers', 'Drinks & Cocktails', 'Desserts & Snacks'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Dining Menu | Purple Bamboo Resort Admin</title>
    <link rel="stylesheet" href="admin-style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .menu-items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
            gap: 1.5rem;
        }
        .menu-item-card {
            padding: 1.25rem !important;
            overflow: hidden;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border-radius: 14px;
            box-shadow: 0 4px 16px -2px rgba(15, 23, 42, 0.06);
            border: 1px solid var(--admin-card-border);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .menu-item-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px -4px rgba(15, 23, 42, 0.12);
        }
        .menu-card-meta-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        .menu-card-meta-row .admin-badge {
            flex-shrink: 0;
            white-space: nowrap;
            letter-spacing: 0.4px;
            font-size: 0.72rem;
            padding: 0.28rem 0.65rem;
        }
        .menu-card-actions {
            display: grid;
            grid-template-columns: 1fr 1fr 38px;
            gap: 0.5rem;
            align-items: center;
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }
        @media (max-width: 640px) {
            .menu-items-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="admin-layout-body">

    <?php include 'admin-nav.php'; ?>

    <div class="admin-main-wrapper">
        <header class="admin-top-header">
            <div class="admin-top-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" placeholder="Search menu dishes, drinks, allergens..." value="<?= htmlspecialchars($search_query) ?>" onkeyup="if(event.key==='Enter') window.location='manage_menu.php?search='+encodeURIComponent(this.value)+'&cat=<?= urlencode($selected_cat) ?>'">
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
            <!-- Flash Alert -->
            <?php if ($flash && $flash['type'] === 'success'): ?>
                <div style="background: rgba(16, 185, 129, 0.15); color: #10b981; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #059669; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-circle-check" style="font-size: 1.2rem;"></i> <?= htmlspecialchars($flash['msg']) ?>
                </div>
            <?php elseif ($flash && $flash['type'] === 'error'): ?>
                <div style="background: rgba(239, 68, 68, 0.15); color: #f43f5e; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #e11d48; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-circle-xmark" style="font-size: 1.2rem;"></i> <?= htmlspecialchars($flash['msg']) ?>
                </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="admin-page-header">
                <div>
                    <h1 class="admin-page-title">
                        <i class="fa-solid fa-bowl-food" style="color: var(--gold-glow, #fbbf24);"></i> 
                        Dining Menu Management
                    </h1>
                    <p class="admin-page-subtitle">
                        Manage food and drink menu items, prices, pictures, descriptions, allergens, and kitchen availability.
                    </p>
                </div>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <button onclick="openAddMenuModal()" class="admin-btn admin-btn-gold">
                        <i class="fa-solid fa-plus"></i> Add New Menu Item
                    </button>
                </div>
            </div>

            <!-- Stats Summary -->
            <div class="admin-grid" style="margin-bottom: 2rem;">
                <div class="admin-card admin-stat-card">
                    <span class="admin-stat-label">Total Menu Dishes & Drinks</span>
                    <div class="admin-stat-value" style="color: var(--primary-glow);"><?= $total_items ?></div>
                    <span class="admin-badge admin-badge-purple"><i class="fa-solid fa-book-open"></i> Full Menu</span>
                </div>
                <div class="admin-card admin-stat-card">
                    <span class="admin-stat-label">Available for Ordering</span>
                    <div class="admin-stat-value" style="color: var(--emerald);"><?= $available_count ?></div>
                    <span class="admin-badge admin-badge-success"><i class="fa-solid fa-circle-check"></i> Ready to Serve</span>
                </div>
                <div class="admin-card admin-stat-card">
                    <span class="admin-stat-label">Sold Out / Unavailable</span>
                    <div class="admin-stat-value" style="color: var(--rose);"><?= $soldout_count ?></div>
                    <span class="admin-badge admin-badge-danger"><i class="fa-solid fa-ban"></i>Out of Stock</span>
                </div>
            </div>

            <!-- Category Filter Tabs -->
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.5rem;">
                <?php foreach ($categories as $cat): ?>
                    <a href="manage_menu.php?cat=<?= urlencode($cat) ?><?= !empty($search_query) ? '&search=' . urlencode($search_query) : '' ?>" 
                       class="admin-btn <?= $selected_cat === $cat ? 'admin-btn-gold' : 'admin-btn-outline' ?>" 
                       style="padding: 0.5rem 1rem; font-size: 0.88rem; font-weight: 700;">
                        <?= htmlspecialchars($cat) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Menu Items Grid -->
            <?php if (empty($all_items)): ?>
                <div class="admin-card" style="text-align: center; padding: 3rem 1.5rem;">
                    <i class="fa-solid fa-plate-wheat" style="font-size: 3rem; color: #4c1d95; margin-bottom: 1rem;"></i>
                    <h3 style="color: #ffffff; margin-bottom: 0.5rem;">No Menu Items Found</h3>
                    <p style="color: var(--text-muted); margin-bottom: 1.5rem;">No dishes or drinks match your current category or search criteria.</p>
                    <button onclick="openAddMenuModal()" class="admin-btn admin-btn-gold">
                        <i class="fa-solid fa-plus"></i> Add First Menu Item
                    </button>
                </div>
            <?php else: ?>
                <div class="menu-items-grid">
                    <?php foreach ($all_items as $item): 
                        $is_avail = !empty($item['is_available']);
                        $img = !empty($item['image']) ? $item['image'] : 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80';
                    ?>
                        <div class="admin-card menu-item-card" style="<?= !$is_avail ? 'border: 1.5px solid #e11d48; opacity: 0.9;' : '' ?>">
                            <div>
                                <!-- Sold Out Banner -->
                                <?php if (!$is_avail): ?>
                                    <div style="background: linear-gradient(135deg, #be123c, #e11d48); color: #ffffff; padding: 0.45rem 0.85rem; font-weight: 800; font-size: 0.78rem; display: flex; align-items: center; justify-content: space-between; margin: -1.25rem -1.25rem 1rem -1.25rem;">
                                        <span><i class="fa-solid fa-ban"></i> SOLD OUT / UNAVAILABLE</span>
                                        <span>GUEST CANNOT ORDER</span>
                                    </div>
                                <?php endif; ?>

                                <!-- Food Thumbnail -->
                                <div style="position: relative;">
                                    <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($item['item_name']) ?>" style="width: 100%; height: 180px; object-fit: cover; border-radius: var(--radius-sm); margin-bottom: 0.85rem; <?= !$is_avail ? 'filter: grayscale(40%);' : '' ?>">
                                    <span style="position: absolute; top: 10px; right: 10px; background: rgba(0,0,0,0.75); color: #fbbf24; padding: 4px 10px; border-radius: 6px; font-weight: 800; font-size: 0.95rem; border: 1px solid rgba(251, 191, 36, 0.4);">
                                        ₱<?= number_format($item['price'], 2) ?>
                                    </span>
                                </div>

                                <div class="menu-card-meta-row">
                                    <span class="admin-badge admin-badge-purple">
                                        <?= htmlspecialchars($item['category']) ?>
                                    </span>
                                    <?php if ($is_avail): ?>
                                        <span class="admin-badge admin-badge-success" style="margin-left: auto;">
                                            <i class="fa-solid fa-check"></i> Available
                                        </span>
                                    <?php else: ?>
                                        <span class="admin-badge admin-badge-danger" style="margin-left: auto;">
                                            <i class="fa-solid fa-ban"></i> Unavailable
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <h3 style="font-family: 'Outfit', sans-serif; color: #ffffff; margin-bottom: 0.4rem; font-size: 1.1rem;">
                                    <?= htmlspecialchars($item['item_name']) ?>
                                </h3>

                                <p style="color: var(--text-muted); font-size: 0.88rem; margin-bottom: 0.85rem; line-height: 1.45; min-height: 2.8rem;">
                                    <?= htmlspecialchars($item['description'] ?? 'Delicious resort kitchen specialty.') ?>
                                </p>

                                <!-- Allergens Warning Box -->
                                <?php if (!empty($item['allergens'])): ?>
                                    <div style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 6px; padding: 0.4rem 0.65rem; margin-bottom: 1rem; font-size: 0.78rem; color: #fde68a;">
                                        <i class="fa-solid fa-triangle-exclamation" style="color: #fbbf24; margin-right: 4px;"></i>
                                        <strong>Allergens:</strong> <?= htmlspecialchars($item['allergens']) ?>
                                    </div>
                                <?php else: ?>
                                    <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 6px; padding: 0.4rem 0.65rem; margin-bottom: 1rem; font-size: 0.78rem; color: #a7f3d0;">
                                        <i class="fa-solid fa-leaf" style="color: #10b981; margin-right: 4px;"></i> No major allergen warnings specified
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Action Buttons -->
                            <div class="menu-card-actions">
                                <button type="button" onclick='openEditMenuModal(<?= json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' class="admin-btn admin-btn-gold admin-btn-sm" style="justify-content: center; padding: 0.45rem 0.4rem; font-weight: 700; font-size: 0.82rem; white-space: nowrap;">
                                    <i class="fa-solid fa-pen-to-square"></i> Edit
                                </button>
                                <button type="button" onclick="toggleAvailability(<?= $item['id'] ?>, <?= $is_avail ? 0 : 1 ?>)" class="admin-btn admin-btn-outline admin-btn-sm" style="justify-content: center; padding: 0.45rem 0.4rem; font-weight: 700; font-size: 0.82rem; white-space: nowrap; <?= $is_avail ? 'color: #fbbf24;' : 'color: #34d399;' ?>">
                                    <i class="fa-solid <?= $is_avail ? 'fa-ban' : 'fa-check' ?>"></i> <?= $is_avail ? 'Mark Sold Out' : 'Mark Available' ?>
                                </button>
                                <button type="button" onclick="confirmDeleteMenu(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['item_name'])) ?>')" class="admin-btn admin-btn-outline admin-btn-sm" style="justify-content: center; padding: 0; width: 38px; height: 32px; color: #f43f5e; border-color: rgba(244,63,94,0.4);" title="Delete Menu Item">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Hidden Forms for Fast Actions -->
    <form id="deleteMenuForm" method="POST" action="manage_menu.php" style="display: none;">
        <input type="hidden" name="action" value="delete_menu_item">
        <input type="hidden" name="id" id="deleteMenuId" value="">
    </form>

    <form id="toggleMenuForm" method="POST" action="manage_menu.php" style="display: none;">
        <input type="hidden" name="action" value="toggle_availability">
        <input type="hidden" name="id" id="toggleMenuId" value="">
        <input type="hidden" name="is_available" id="toggleMenuStatus" value="">
    </form>

    <!-- Modal: Add New Menu Item -->
    <div id="addMenuModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(6px); z-index: 9999; align-items: center; justify-content: center; padding: 1rem;">
        <div style="background: #1e1035; border: 1.5px solid #4c1d95; border-radius: 16px; width: 100%; max-width: 850px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px rgba(0,0,0,0.7); animation: modalPop 0.25s ease;">
            <div style="padding: 1.25rem 1.5rem; background: linear-gradient(135deg, #10b981, #059669); border-radius: 14px 14px 0 0; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 10;">
                <h3 style="font-family: 'Outfit', sans-serif; color: #ffffff; margin: 0; font-size: 1.15rem; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-plus-circle"></i> Add New Dining Menu Item
                </h3>
                <button onclick="closeAddMenuModal()" style="background: rgba(255,255,255,0.2); border: none; color: #fff; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 1.1rem; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form method="POST" action="manage_menu.php" enctype="multipart/form-data" style="padding: 1.5rem;">
                <input type="hidden" name="action" value="add_menu_item">

                <div style="display: grid; grid-template-columns: 1.3fr 1fr; gap: 1rem; margin-bottom: 1.15rem;">
                    <div>
                        <label for="addMenuName" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-utensils" style="color: #34d399;"></i> Dish / Drink Name
                        </label>
                        <input type="text" name="item_name" id="addMenuName" placeholder="e.g. Sizzling Crispy Sisig" required style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.95rem; font-weight: 600;">
                    </div>
                    <div>
                        <label for="addMenuCategory" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-tag" style="color: #34d399;"></i> Category
                        </label>
                        <select name="category" id="addMenuCategory" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.92rem; font-weight: 600; cursor: pointer;">
                            <option value="Main Dishes" selected>Main Dishes</option>
                            <option value="Appetizers">Appetizers</option>
                            <option value="Drinks & Cocktails">Drinks & Cocktails</option>
                            <option value="Desserts & Snacks">Desserts & Snacks</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.15rem;">
                    <div>
                        <label for="addMenuPrice" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-peso-sign" style="color: #fbbf24;"></i> Price (₱)
                        </label>
                        <input type="number" name="price" id="addMenuPrice" step="10" min="0" value="350" required style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #fbbf24; font-weight: 800; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 1rem;">
                    </div>
                    <div>
                        <label for="addMenuStatus" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-toggle-on" style="color: #4ade80;"></i> Initial Availability
                        </label>
                        <select name="is_available" id="addMenuStatus" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #e2e8f0; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.92rem; font-weight: 700; cursor: pointer;">
                            <option value="1" selected>✅ Available (Guests can order)</option>
                            <option value="0">❌ Sold Out / Unavailable</option>
                        </select>
                    </div>
                </div>

                <!-- Picture Section -->
                <div style="background: rgba(16, 185, 129, 0.08); border: 1.5px dashed #059669; border-radius: 12px; padding: 1.15rem; margin-bottom: 1.15rem;">
                    <label style="display: block; font-weight: 800; color: #e2e8f0; margin-bottom: 0.65rem; font-size: 0.92rem;">
                        <i class="fa-solid fa-camera" style="color: #34d399;"></i> Dish / Drink Photo
                    </label>
                    <div style="display: flex; gap: 1rem; align-items: flex-start; flex-wrap: wrap;">
                        <div style="position: relative;">
                            <img id="addMenuImgPreview" src="https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80" alt="Preview" style="width: 120px; height: 85px; object-fit: cover; border-radius: 8px; border: 2px solid #059669; background: #0f172a; box-shadow: 0 4px 10px rgba(0,0,0,0.5);">
                            <span style="position: absolute; bottom: 4px; left: 4px; background: rgba(0,0,0,0.7); color: #fff; font-size: 0.65rem; padding: 1px 5px; border-radius: 4px; font-weight: 700;">PREVIEW</span>
                        </div>
                        <div style="flex: 1; min-width: 220px;">
                            <div>
                                <label style="display: block; font-size: 0.82rem; color: #cbd5e1; margin-bottom: 0.35rem; font-weight: 600;">
                                    <i class="fa-solid fa-upload"></i> Upload food picture from device:
                                </label>
                                <input type="file" name="image_file" id="addMenuFile" accept="image/*" onchange="previewFile(this, 'addMenuImgPreview')" style="width: 100%; background: #0f172a; border: 1.5px solid #059669; color: #cbd5e1; padding: 0.55rem 0.75rem; border-radius: 8px; font-size: 0.85rem; cursor: pointer;">
                            </div>
                            <small style="color: #94a3b8; font-size: 0.75rem; display: block; margin-top: 0.45rem;">
                                * Supports JPG, PNG, WEBP files.
                            </small>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 1.15rem;">
                    <label for="addMenuAllergens" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                        <i class="fa-solid fa-triangle-exclamation" style="color: #fbbf24;"></i> Allergens (comma-separated, e.g. Dairy, Seafood, Peanuts)
                    </label>
                    <input type="text" name="allergens" id="addMenuAllergens" placeholder="e.g. Seafood / Shellfish, Dairy, Soy" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.6rem 0.85rem; border-radius: 8px; font-size: 0.9rem;">
                </div>

                <div style="margin-bottom: 1.15rem;">
                    <label for="addMenuDesc" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                        <i class="fa-solid fa-align-left" style="color: #34d399;"></i> Description / Ingredients
                    </label>
                    <textarea name="description" id="addMenuDesc" rows="2" placeholder="Describe the ingredients, preparation, and taste profile..." style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.6rem 0.85rem; border-radius: 8px; font-size: 0.88rem; font-family: inherit; resize: vertical;"></textarea>
                </div>

                <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                    <button type="submit" class="admin-btn admin-btn-gold" style="flex: 1; justify-content: center; padding: 0.75rem; font-weight: 800;">
                        <i class="fa-solid fa-plus-circle"></i> Add Item to Menu
                    </button>
                    <button type="button" onclick="closeAddMenuModal()" class="admin-btn admin-btn-outline" style="flex: 1; justify-content: center; padding: 0.75rem;">
                        <i class="fa-solid fa-xmark"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Menu Item -->
    <div id="editMenuModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(6px); z-index: 9999; align-items: center; justify-content: center; padding: 1rem;">
        <div style="background: #1e1035; border: 1.5px solid #4c1d95; border-radius: 16px; width: 100%; max-width: 850px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px rgba(0,0,0,0.7); animation: modalPop 0.25s ease;">
            <div style="padding: 1.25rem 1.5rem; background: linear-gradient(135deg, #7c3aed, #a855f7); border-radius: 14px 14px 0 0; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 10;">
                <h3 style="font-family: 'Outfit', sans-serif; color: #ffffff; margin: 0; font-size: 1.15rem; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-pen-to-square"></i> Edit Dining Menu Item
                </h3>
                <button onclick="closeEditMenuModal()" style="background: rgba(255,255,255,0.2); border: none; color: #fff; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 1.1rem; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form method="POST" action="manage_menu.php" enctype="multipart/form-data" style="padding: 1.5rem;">
                <input type="hidden" name="action" value="edit_menu_item">
                <input type="hidden" name="id" id="editMenuId" value="">
                <input type="hidden" name="existing_image" id="editExistingImage" value="">

                <div style="display: grid; grid-template-columns: 1.3fr 1fr; gap: 1rem; margin-bottom: 1.15rem;">
                    <div>
                        <label for="editMenuName" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-utensils" style="color: #c084fc;"></i> Dish / Drink Name
                        </label>
                        <input type="text" name="item_name" id="editMenuName" required style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.95rem; font-weight: 600;">
                    </div>
                    <div>
                        <label for="editMenuCategory" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-tag" style="color: #c084fc;"></i> Category
                        </label>
                        <select name="category" id="editMenuCategory" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.92rem; font-weight: 600; cursor: pointer;">
                            <option value="Main Dishes">Main Dishes</option>
                            <option value="Appetizers">Appetizers</option>
                            <option value="Drinks & Cocktails">Drinks & Cocktails</option>
                            <option value="Desserts & Snacks">Desserts & Snacks</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.15rem;">
                    <div>
                        <label for="editMenuPrice" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-peso-sign" style="color: #fbbf24;"></i> Price (₱)
                        </label>
                        <input type="number" name="price" id="editMenuPrice" step="10" min="0" required style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #fbbf24; font-weight: 800; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 1rem;">
                    </div>
                    <div>
                        <label for="editMenuStatus" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-toggle-on" style="color: #4ade80;"></i> Availability Status
                        </label>
                        <select name="is_available" id="editMenuStatus" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #e2e8f0; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.92rem; font-weight: 700; cursor: pointer;">
                            <option value="1">✅ Available (Guests can order)</option>
                            <option value="0">❌ Sold Out / Unavailable</option>
                        </select>
                    </div>
                </div>

                <!-- Picture Section -->
                <div style="background: rgba(124, 58, 237, 0.08); border: 1.5px dashed #4c1d95; border-radius: 12px; padding: 1.15rem; margin-bottom: 1.15rem;">
                    <label style="display: block; font-weight: 800; color: #e2e8f0; margin-bottom: 0.65rem; font-size: 0.92rem;">
                        <i class="fa-solid fa-camera" style="color: #c084fc;"></i> Food Picture / Photo
                    </label>
                    <div style="display: flex; gap: 1rem; align-items: flex-start; flex-wrap: wrap;">
                        <div style="position: relative;">
                            <img id="editMenuImgPreview" src="" alt="Preview" style="width: 120px; height: 85px; object-fit: cover; border-radius: 8px; border: 2px solid #7c3aed; background: #0f172a; box-shadow: 0 4px 10px rgba(0,0,0,0.5);">
                            <span style="position: absolute; bottom: 4px; left: 4px; background: rgba(0,0,0,0.7); color: #fff; font-size: 0.65rem; padding: 1px 5px; border-radius: 4px; font-weight: 700;">PREVIEW</span>
                        </div>
                        <div style="flex: 1; min-width: 220px;">
                            <div>
                                <label style="display: block; font-size: 0.82rem; color: #cbd5e1; margin-bottom: 0.35rem; font-weight: 600;">
                                    <i class="fa-solid fa-upload"></i> Upload new picture:
                                </label>
                                <input type="file" name="image_file" id="editMenuFile" accept="image/*" onchange="previewFile(this, 'editMenuImgPreview')" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #cbd5e1; padding: 0.55rem 0.75rem; border-radius: 8px; font-size: 0.85rem; cursor: pointer;">
                            </div>
                            <small style="color: #94a3b8; font-size: 0.76rem; display: block; margin-top: 0.35rem;">
                                * Leave blank to retain current photo. Supports JPG, PNG, WEBP.
                            </small>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 1.15rem;">
                    <label for="editMenuAllergens" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                        <i class="fa-solid fa-triangle-exclamation" style="color: #fbbf24;"></i> Allergens (comma-separated)
                    </label>
                    <input type="text" name="allergens" id="editMenuAllergens" placeholder="e.g. Seafood, Dairy, Soy" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.6rem 0.85rem; border-radius: 8px; font-size: 0.9rem;">
                </div>

                <div style="margin-bottom: 1.15rem;">
                    <label for="editMenuDesc" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                        <i class="fa-solid fa-align-left" style="color: #c084fc;"></i> Description
                    </label>
                    <textarea name="description" id="editMenuDesc" rows="2" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.6rem 0.85rem; border-radius: 8px; font-size: 0.88rem; font-family: inherit; resize: vertical;"></textarea>
                </div>

                <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                    <button type="submit" class="admin-btn admin-btn-gold" style="flex: 1; justify-content: center; padding: 0.75rem; font-weight: 800;">
                        <i class="fa-solid fa-floppy-disk"></i> Save & Update Item
                    </button>
                    <button type="button" onclick="closeEditMenuModal()" class="admin-btn admin-btn-outline" style="flex: 1; justify-content: center; padding: 0.75rem;">
                        <i class="fa-solid fa-xmark"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
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

    function openAddMenuModal() {
        if (document.getElementById('addMenuName')) document.getElementById('addMenuName').value = '';
        if (document.getElementById('addMenuCategory')) document.getElementById('addMenuCategory').value = 'Main Dishes';
        if (document.getElementById('addMenuPrice')) document.getElementById('addMenuPrice').value = '350';
        if (document.getElementById('addMenuStatus')) document.getElementById('addMenuStatus').value = '1';
        if (document.getElementById('addMenuAllergens')) document.getElementById('addMenuAllergens').value = '';
        if (document.getElementById('addMenuDesc')) document.getElementById('addMenuDesc').value = '';
        if (document.getElementById('addMenuUrl')) document.getElementById('addMenuUrl').value = '';
        if (document.getElementById('addMenuFile')) document.getElementById('addMenuFile').value = '';
        if (document.getElementById('addMenuImgPreview')) document.getElementById('addMenuImgPreview').src = 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80';
        const modal = document.getElementById('addMenuModal');
        if (modal) modal.style.display = 'flex';
    }

    function closeAddMenuModal() {
        const modal = document.getElementById('addMenuModal');
        if (modal) modal.style.display = 'none';
    }

    function openEditMenuModal(item) {
        if (!item) return;
        if (document.getElementById('editMenuId')) document.getElementById('editMenuId').value = item.id || '';
        if (document.getElementById('editExistingImage')) document.getElementById('editExistingImage').value = item.image || '';
        if (document.getElementById('editMenuName')) document.getElementById('editMenuName').value = item.item_name || '';
        if (document.getElementById('editMenuCategory')) document.getElementById('editMenuCategory').value = item.category || 'Main Dishes';
        if (document.getElementById('editMenuPrice')) document.getElementById('editMenuPrice').value = item.price || 0;
        if (document.getElementById('editMenuStatus')) document.getElementById('editMenuStatus').value = (item.is_available == 1 || item.is_available === true) ? '1' : '0';
        if (document.getElementById('editMenuAllergens')) document.getElementById('editMenuAllergens').value = item.allergens || '';
        if (document.getElementById('editMenuDesc')) document.getElementById('editMenuDesc').value = item.description || '';
        if (document.getElementById('editMenuUrl')) document.getElementById('editMenuUrl').value = '';
        if (document.getElementById('editMenuFile')) document.getElementById('editMenuFile').value = '';
        
        const preview = document.getElementById('editMenuImgPreview');
        if (preview) {
            preview.src = item.image || 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80';
        }
        const modal = document.getElementById('editMenuModal');
        if (modal) modal.style.display = 'flex';
    }

    function closeEditMenuModal() {
        const modal = document.getElementById('editMenuModal');
        if (modal) modal.style.display = 'none';
    }

    function toggleAvailability(id, newStatus) {
        document.getElementById('toggleMenuId').value = id;
        document.getElementById('toggleMenuStatus').value = newStatus;
        document.getElementById('toggleMenuForm').submit();
    }

    function confirmDeleteMenu(id, name) {
        const safeName = name ? ' "<strong>' + name + '</strong>"' : ' this menu item';
        const title = 'Delete Menu Item Confirmation';
        const msg = 'Are you sure you want to delete' + safeName + ' from the dining menu?<br><span style="display:block; margin-top:8px; color: #f87171; font-size: 0.85rem; font-weight: 600;"><i class="fa-solid fa-triangle-exclamation"></i> This cannot be undone.</span>';
        const onConfirm = function() {
            document.getElementById('deleteMenuId').value = id;
            document.getElementById('deleteMenuForm').submit();
        };

        if (typeof showDeleteConfirm === 'function') {
            showDeleteConfirm(title, msg, onConfirm, 'Yes, Delete', 'Cancel');
        } else if (window.showDeleteConfirm) {
            window.showDeleteConfirm(title, msg, onConfirm, 'Yes, Delete', 'Cancel');
        } else if (confirm('Are you sure you want to delete "' + name + '" from the dining menu?\n\nThis cannot be undone.')) {
            onConfirm();
        }
    }

    // Modal click backdrop & keyboard listeners
    document.getElementById('addMenuModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeAddMenuModal();
    });
    document.getElementById('editMenuModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeEditMenuModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAddMenuModal();
            closeEditMenuModal();
        }
    });
    </script>
</body>
</html>
