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
            InventoryStore::deleteActivity($id);
            $_SESSION['activity_flash'] = [
                'type' => 'success',
                'msg' => 'Activity deleted from resort inventory.'
            ];
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
        <link rel="stylesheet" href="admin-style.css">
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
            box-shadow: 0 16px 36px -6px rgba(124, 58, 237, 0.12);
            border-color: rgba(124, 58, 237, 0.4);
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
    </style>
</head>
<body class="<?= $is_admin_layout ? 'admin-layout-body' : '' ?>">

    <?php if ($is_admin_layout): ?>
        <?php include 'admin-nav.php'; ?>
        <div class="admin-main-wrapper">
            <header class="admin-top-header">
                <div class="admin-top-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" placeholder="Search activities..." value="<?= htmlspecialchars($search_query) ?>" onkeyup="if(event.key==='Enter') window.location='browse_activities.php?search='+encodeURIComponent(this.value)">
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

        <div class="<?= $is_admin_layout ? 'admin-page-header' : 'page-header' ?>">
            <div>
                <h1 class="<?= $is_admin_layout ? 'admin-page-title' : '' ?>">
                    <i class="fa-solid fa-water-ladder" style="color: var(--gold-glow, var(--bamboo-gold));"></i> 
                    Pool & Water Activities
                </h1>
                <p class="<?= $is_admin_layout ? 'admin-page-subtitle' : '' ?>">
                    <?= $is_admin_layout ? 'Manage resort pools, water sports, wellness spa sessions, prices, and maintenance status.' : 'Enhance your resort stay with crystal-clear infinity pool swimming, water sports, and relaxing bamboo wellness treatments.' ?>
                </p>
            </div>
            <?php if ($is_admin_layout): ?>
                <button onclick="openAddActivityModal()" class="admin-btn admin-btn-gold">
                    <i class="fa-solid fa-plus"></i> Add New Activity
                </button>
            <?php endif; ?>
        </div>

        <!-- Admin Stats Overview -->
        <?php if ($is_admin_layout): ?>
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
        <?php endif; ?>

        <!-- Activities Grid -->
        <?php if (empty($all_activities)): ?>
            <div class="<?= $is_admin_layout ? 'admin-card' : 'card' ?>" style="text-align: center; padding: 3rem 1.5rem;">
                <i class="fa-solid fa-water-ladder" style="font-size: 3rem; color: #a855f7; margin-bottom: 1rem;"></i>
                <h3 style="margin-bottom: 0.5rem; color: <?= $is_admin_layout ? '#ffffff' : 'var(--primary-purple)' ?>;">No Activities Found</h3>
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
            <div class="activities-grid">
                <?php foreach ($all_activities as $act): 
                    $is_avail = !empty($act['is_available']);
                    $img = !empty($act['image']) ? $act['image'] : 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80';
                ?>
                    <div class="activity-card <?= !$is_avail ? 'is-maintenance' : '' ?>">
                        
                        <!-- Media Header with Full-Bleed Image and Floating Glass Badges -->
                        <div class="activity-card-media">
                            <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($act['activity_name']) ?>" <?= !$is_avail ? 'style="filter: saturate(60%) brightness(85%);"' : '' ?>>

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
                                    Max <?= htmlspecialchars($act['capacity'] ?? 5) ?> Pax
                                </span>
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
                                        <a href="book_activities.php?activity_id=<?= $act['activity_id'] ?>" class="btn btn-primary" style="width: 100%; justify-content: center; gap: 8px; font-weight: 700; padding: 0.65rem 1.2rem; border-radius: 8px; display: inline-flex; align-items: center; text-decoration: none;">
                                            <i class="fa-solid fa-calendar-check"></i> Reserve Session
                                        </a>
                                    <?php else: ?>
                                        <button disabled class="btn btn-sm" style="width: 100%; background: #ea580c; color: #ffffff; cursor: not-allowed; border: none; padding: 0.65rem 1rem; border-radius: 8px; font-weight: 700; opacity: 0.95; display: inline-flex; justify-content: center; align-items: center; gap: 8px;">
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

    <!-- Modal: Add New Activity -->
    <div id="addActivityModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(6px); z-index: 9999; align-items: center; justify-content: center; padding: 1rem;">
        <div style="background: #1e1035; border: 1.5px solid #4c1d95; border-radius: 16px; width: 100%; max-width: 850px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px rgba(0,0,0,0.7); animation: modalPop 0.25s ease;">
            <div style="padding: 1.25rem 1.5rem; background: linear-gradient(135deg, #10b981, #059669); border-radius: 14px 14px 0 0; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 10;">
                <h3 style="font-family: 'Outfit', sans-serif; color: #ffffff; margin: 0; font-size: 1.15rem; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-plus-circle"></i> Add New Resort Activity
                </h3>
                <button onclick="closeAddActivityModal()" style="background: rgba(255,255,255,0.2); border: none; color: #fff; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 1.1rem; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form id="addActivityForm" method="POST" action="browse_activities.php" enctype="multipart/form-data" style="padding: 1.5rem;">
                <input type="hidden" name="action" value="add_activity">

                <div style="display: grid; grid-template-columns: 1.3fr 1fr; gap: 1rem; margin-bottom: 1.15rem;">
                    <div>
                        <label for="addActName" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-water-ladder" style="color: #34d399;"></i> Activity Name
                        </label>
                        <input type="text" name="activity_name" id="addActName" placeholder="e.g. Inflatable Aqua Obstacle Course" required style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.95rem; font-weight: 600;">
                    </div>
                    <div>
                        <label for="addActCategory" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-tag" style="color: #34d399;"></i> Category
                        </label>
                        <select name="category" id="addActCategory" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.92rem; font-weight: 600; cursor: pointer;">
                            <option value="pool" selected>Pool Activity</option>
                            <option value="outdoor">Water Sport / Lagoon</option>
                            <option value="wellness">Wellness & Spa</option>
                            <option value="indoor">Indoor Leisure</option>
                            <option value="other">Other Activity</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 1rem; margin-bottom: 1.15rem;">
                    <div>
                        <label for="addActPrice" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-peso-sign" style="color: #fbbf24;"></i> Price (₱)
                        </label>
                        <input type="number" name="price" id="addActPrice" step="50" min="0" value="450" required style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #fbbf24; font-weight: 800; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 1rem;">
                    </div>
                    <div>
                        <label for="addActDuration" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-regular fa-clock" style="color: #38bdf8;"></i> Duration (Mins)
                        </label>
                        <input type="number" name="duration_minutes" id="addActDuration" step="15" min="15" value="60" required style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; font-weight: 700; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.95rem;">
                    </div>
                    <div>
                        <label for="addActStatus" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-toggle-on" style="color: #4ade80;"></i> Status
                        </label>
                        <select name="is_available" id="addActStatus" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #e2e8f0; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.92rem; font-weight: 700; cursor: pointer;">
                            <option value="1" selected>✅ Available</option>
                            <option value="0">🔧 Maintenance</option>
                        </select>
                    </div>
                </div>

                <!-- Picture Section -->
                <div style="background: rgba(16, 185, 129, 0.08); border: 1.5px dashed #059669; border-radius: 12px; padding: 1.15rem; margin-bottom: 1.15rem;">
                    <label style="display: block; font-weight: 800; color: #e2e8f0; margin-bottom: 0.65rem; font-size: 0.92rem;">
                        <i class="fa-solid fa-camera" style="color: #34d399;"></i> Activity Picture / Photo
                    </label>
                    <div style="display: flex; gap: 1rem; align-items: flex-start; flex-wrap: wrap;">
                        <div style="position: relative;">
                            <img id="addActImgPreview" src="https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80" alt="Preview" style="width: 120px; height: 85px; object-fit: cover; border-radius: 8px; border: 2px solid #059669; background: #0f172a; box-shadow: 0 4px 10px rgba(0,0,0,0.5);">
                            <span style="position: absolute; bottom: 4px; left: 4px; background: rgba(0,0,0,0.7); color: #fff; font-size: 0.65rem; padding: 1px 5px; border-radius: 4px; font-weight: 700;">PREVIEW</span>
                        </div>
                        <div style="flex: 1; min-width: 220px;">
                            <div>
                                <label style="display: block; font-size: 0.82rem; color: #cbd5e1; margin-bottom: 0.35rem; font-weight: 600;">
                                    <i class="fa-solid fa-upload"></i> Upload picture from computer:
                                </label>
                                <input type="file" name="image_file" id="addActFile" accept="image/*" onchange="previewFile(this, 'addActImgPreview')" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #cbd5e1; padding: 0.55rem 0.75rem; border-radius: 8px; font-size: 0.85rem; cursor: pointer;">
                            </div>
                            <small style="color: #94a3b8; font-size: 0.75rem; display: block; margin-top: 0.45rem;">
                                * Supports JPG, PNG, WEBP. Leave empty to use default photo.
                            </small>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 1.15rem;">
                    <label for="addActDesc" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                        <i class="fa-solid fa-align-left" style="color: #34d399;"></i> Description / Rules
                    </label>
                    <textarea name="description" id="addActDesc" rows="2" placeholder="Describe the water activity, guidelines, lifeguard coverage..." style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.6rem 0.85rem; border-radius: 8px; font-size: 0.88rem; font-family: inherit; resize: vertical;"></textarea>
                </div>

                <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                    <button type="button" onclick="showAddConfirm('Add New Activity', 'Are you sure you want to add this activity to the resort?', function() { document.getElementById('addActivityForm').submit(); })" class="admin-btn admin-btn-gold" style="flex: 1; justify-content: center; padding: 0.75rem; font-weight: 800;">
                        <i class="fa-solid fa-plus-circle"></i> Add Activity to Inventory
                    </button>
                    <button type="button" onclick="closeAddActivityModal()" class="admin-btn admin-btn-outline" style="flex: 1; justify-content: center; padding: 0.75rem;">
                        <i class="fa-solid fa-xmark"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Activity -->
    <div id="editActivityModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(6px); z-index: 9999; align-items: center; justify-content: center; padding: 1rem;">
        <div style="background: #1e1035; border: 1.5px solid #4c1d95; border-radius: 16px; width: 100%; max-width: 850px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px rgba(0,0,0,0.7); animation: modalPop 0.25s ease;">
            <div style="padding: 1.25rem 1.5rem; background: linear-gradient(135deg, #7c3aed, #a855f7); border-radius: 14px 14px 0 0; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 10;">
                <h3 style="font-family: 'Outfit', sans-serif; color: #ffffff; margin: 0; font-size: 1.15rem; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-pen-to-square"></i> Edit Activity Details & Status
                </h3>
                <button onclick="closeEditActivityModal()" style="background: rgba(255,255,255,0.2); border: none; color: #fff; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 1.1rem; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form id="editActivityForm" method="POST" action="browse_activities.php" enctype="multipart/form-data" style="padding: 1.5rem;">
                <input type="hidden" name="action" value="edit_activity">
                <input type="hidden" name="activity_id" id="editActId" value="">
                <input type="hidden" name="existing_image" id="editActExistingImage" value="">

                <div style="display: grid; grid-template-columns: 1.3fr 1fr; gap: 1rem; margin-bottom: 1.15rem;">
                    <div>
                        <label for="editActName" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-water-ladder" style="color: #c084fc;"></i> Activity Name
                        </label>
                        <input type="text" name="activity_name" id="editActName" required style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.95rem; font-weight: 600;">
                    </div>
                    <div>
                        <label for="editActCategory" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-tag" style="color: #c084fc;"></i> Category
                        </label>
                        <select name="category" id="editActCategory" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.92rem; font-weight: 600; cursor: pointer;">
                            <option value="pool">Pool Activity</option>
                            <option value="outdoor">Water Sport / Lagoon</option>
                            <option value="wellness">Wellness & Spa</option>
                            <option value="indoor">Indoor Leisure</option>
                            <option value="other">Other Activity</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 1rem; margin-bottom: 1.15rem;">
                    <div>
                        <label for="editActPrice" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-peso-sign" style="color: #fbbf24;"></i> Price (₱)
                        </label>
                        <input type="number" name="price" id="editActPrice" step="50" min="0" required style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #fbbf24; font-weight: 800; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 1rem;">
                    </div>
                    <div>
                        <label for="editActDuration" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-regular fa-clock" style="color: #38bdf8;"></i> Duration (Mins)
                        </label>
                        <input type="number" name="duration_minutes" id="editActDuration" step="15" min="15" required style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; font-weight: 700; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.95rem;">
                    </div>
                    <div>
                        <label for="editActStatus" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                            <i class="fa-solid fa-toggle-on" style="color: #4ade80;"></i> Status
                        </label>
                        <select name="is_available" id="editActStatus" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #e2e8f0; padding: 0.65rem 0.85rem; border-radius: 8px; font-size: 0.92rem; font-weight: 700; cursor: pointer;">
                            <option value="1">✅ Available</option>
                            <option value="0">🔧 Under Maintenance</option>
                        </select>
                    </div>
                </div>

                <!-- Picture Section -->
                <div style="background: rgba(124, 58, 237, 0.08); border: 1.5px dashed #4c1d95; border-radius: 12px; padding: 1.15rem; margin-bottom: 1.15rem;">
                    <label style="display: block; font-weight: 800; color: #e2e8f0; margin-bottom: 0.65rem; font-size: 0.92rem;">
                        <i class="fa-solid fa-camera" style="color: #c084fc;"></i> Activity Picture / Photo
                    </label>
                    <div style="display: flex; gap: 1rem; align-items: flex-start; flex-wrap: wrap;">
                        <div style="position: relative;">
                            <img id="editActImgPreview" src="" alt="Preview" style="width: 120px; height: 85px; object-fit: cover; border-radius: 8px; border: 2px solid #7c3aed; background: #0f172a; box-shadow: 0 4px 10px rgba(0,0,0,0.5);">
                            <span style="position: absolute; bottom: 4px; left: 4px; background: rgba(0,0,0,0.7); color: #fff; font-size: 0.65rem; padding: 1px 5px; border-radius: 4px; font-weight: 700;">PREVIEW</span>
                        </div>
                        <div style="flex: 1; min-width: 220px;">
                            <div>
                                <label style="display: block; font-size: 0.8rem; color: #cbd5e1; margin-bottom: 0.25rem;">
                                    <i class="fa-solid fa-upload"></i> Upload new photo:
                                </label>
                                <input type="file" name="image_file" id="editActFile" accept="image/*" onchange="previewFile(this, 'editActImgPreview')" style="width: 100%; background: #0f172a; border: 1px solid #4c1d95; color: #cbd5e1; padding: 0.4rem 0.6rem; border-radius: 6px; font-size: 0.82rem;">
                            </div>
                            <small style="color: #94a3b8; font-size: 0.76rem; display: block; margin-top: 0.35rem;">
                                * Leave blank to keep current picture.
                            </small>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 1.15rem;">
                    <label for="editActDesc" style="display: block; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; font-size: 0.88rem;">
                        <i class="fa-solid fa-align-left" style="color: #c084fc;"></i> Description
                    </label>
                    <textarea name="description" id="editActDesc" rows="2" style="width: 100%; background: #0f172a; border: 1.5px solid #4c1d95; color: #ffffff; padding: 0.6rem 0.85rem; border-radius: 8px; font-size: 0.88rem; font-family: inherit; resize: vertical;"></textarea>
                </div>

                <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                    <button type="button" onclick="showUpdateConfirm('Save Activity Updates', 'Are you sure you want to update this activity?', function() { document.getElementById('editActivityForm').submit(); })" class="admin-btn admin-btn-gold" style="flex: 1; justify-content: center; padding: 0.75rem; font-weight: 800;">
                        <i class="fa-solid fa-floppy-disk"></i> Save & Update Activity
                    </button>
                    <button type="button" onclick="closeEditActivityModal()" class="admin-btn admin-btn-outline" style="flex: 1; justify-content: center; padding: 0.75rem;">
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

    function openAddActivityModal() {
        if (document.getElementById('addActName')) document.getElementById('addActName').value = '';
        if (document.getElementById('addActCategory')) document.getElementById('addActCategory').value = 'pool';
        if (document.getElementById('addActPrice')) document.getElementById('addActPrice').value = '450';
        if (document.getElementById('addActDuration')) document.getElementById('addActDuration').value = '60';
        if (document.getElementById('addActCapacity')) document.getElementById('addActCapacity').value = '5';
        if (document.getElementById('addActStatus')) document.getElementById('addActStatus').value = '1';
        if (document.getElementById('addActDesc')) document.getElementById('addActDesc').value = '';
        if (document.getElementById('addActUrl')) document.getElementById('addActUrl').value = '';
        if (document.getElementById('addActFile')) document.getElementById('addActFile').value = '';
        if (document.getElementById('addActImgPreview')) document.getElementById('addActImgPreview').src = 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80';
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
        if (document.getElementById('editActPrice')) document.getElementById('editActPrice').value = act.price || 0;
        if (document.getElementById('editActDuration')) document.getElementById('editActDuration').value = act.duration_minutes || 60;
        if (document.getElementById('editActStatus')) document.getElementById('editActStatus').value = (act.is_available == 1 || act.is_available === true) ? '1' : '0';
        if (document.getElementById('editActDesc')) document.getElementById('editActDesc').value = act.description || '';
        if (document.getElementById('editActUrl')) document.getElementById('editActUrl').value = '';
        if (document.getElementById('editActFile')) document.getElementById('editActFile').value = '';

        const preview = document.getElementById('editActImgPreview');
        if (preview) {
            preview.src = act.image || 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80';
        }
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

</body>
</html>
