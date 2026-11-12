<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$admin_current_page = basename($_SERVER['PHP_SELF']);
$admin_raw_name = trim($_SESSION['full_name'] ?? '');
if (empty($admin_raw_name)) {
    $admin_raw_name = trim($_SESSION['username'] ?? '');
}
if (empty($admin_raw_name) && (!empty($_SESSION['first_name']) || !empty($_SESSION['last_name']))) {
    $admin_raw_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
}
if (empty($admin_raw_name) && !empty($_SESSION['email'])) {
    $email_prefix = explode('@', $_SESSION['email'])[0];
    $admin_raw_name = ucwords(str_replace(['.', '_', '-'], ' ', $email_prefix));
}
if (empty($admin_raw_name)) {
    $admin_raw_name = (isset($_SESSION['role']) && $_SESSION['role'] === 'staff') ? 'Front Desk Staff' : 'Super Admin';
}
$admin_full_name = $admin_raw_name;
$_SESSION['full_name'] = $admin_full_name;
$admin_role = $_SESSION['role'] ?? 'admin';

require_once __DIR__ . '/booking_store.php';
$admin_stats = BookingStore::getStats();
require_once __DIR__ . '/review_store.php';
$review_nav_stats = ReviewStore::getStats();
require_once __DIR__ . '/dining_store.php';
$dining_nav_stats = DiningStore::getStats();
require_once __DIR__ . '/content_store.php';
$cms_nav_data = ContentStore::getContent();
$admin_resort_logo = !empty($cms_nav_data['resort_logo']) ? $cms_nav_data['resort_logo'] : 'assets/logo.jpg';
$admin_brand_title = !empty($cms_nav_data['resort_brand_title']) ? $cms_nav_data['resort_brand_title'] : 'PURPLE BAMBOO';
$admin_brand_subtitle = !empty($cms_nav_data['resort_brand_subtitle']) ? $cms_nav_data['resort_brand_subtitle'] : 'BEACH RESORT';
?>

<!-- Floating Mobile Toggle Button (Top Left, Sticky) -->
<button type="button" class="admin-floating-toggle-btn" onclick="toggleAdminSidebar()" aria-label="Open Navigation Menu">
    <i class="fa-solid fa-bars"></i>
</button>

<style>
.admin-floating-toggle-btn {
    display: none;
}
@media (max-width: 991px) {
    .admin-floating-toggle-btn {
        display: flex;
        position: fixed;
        top: 15px;
        left: 15px;
        width: 48px;
        height: 48px;
        border-radius: 12px;
        background: var(--primary-glow, #8b5cf6);
        color: #fff;
        border: none;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        box-shadow: 0 4px 15px rgba(139, 92, 246, 0.4);
        z-index: 1099;
        cursor: pointer;
        transition: transform 0.2s ease, background 0.2s ease;
    }
    .admin-floating-toggle-btn:active {
        transform: scale(0.92);
        background: var(--purple-accent, #e0129c);
    }
    body.admin-sidebar-open .admin-floating-toggle-btn {
        display: none; /* Hide when sidebar is open */
    }
}
/* Guest-Style Header User Badge & Logout in Admin Top Header */
.hs-user-badge,
.admin-header-profile-pill {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    text-decoration: none !important;
    color: #ffffff !important;
    font-weight: 800 !important;
    font-size: 0.88rem !important;
    background: rgba(255, 255, 255, 0.12) !important;
    padding: 0.3rem 0.85rem 0.3rem 0.35rem !important;
    border-radius: 9999px !important;
    border: 1px solid rgba(255, 255, 255, 0.2) !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15) !important;
    transition: all 0.2s ease !important;
    cursor: pointer !important;
}
.hs-user-badge:hover,
.admin-header-profile-pill:hover {
    background: rgba(224, 18, 156, 0.22) !important;
    border-color: rgba(0, 194, 255, 0.45) !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(224, 18, 156, 0.25) !important;
}
.hs-user-avatar,
.admin-header-profile-pill .profile-avatar-circle {
    width: 32px !important;
    height: 32px !important;
    border-radius: 50% !important;
    background: linear-gradient(135deg, #e0129c, #00c2ff) !important;
    color: #ffffff !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-weight: 800 !important;
    font-size: 0.85rem !important;
    box-shadow: 0 2px 8px rgba(224, 18, 156, 0.35) !important;
    flex-shrink: 0 !important;
}
.hs-user-name,
.admin-header-profile-pill .profile-name,
.admin-top-header .hs-user-name,
.admin-header-actions .hs-user-name,
.hs-user-badge .hs-user-name {
    display: inline-block !important;
    visibility: visible !important;
    opacity: 1 !important;
    color: #ffffff !important;
    font-size: 0.84rem !important;
    font-weight: 700 !important;
    white-space: nowrap !important;
}
.admin-header-profile-pill .profile-status {
    display: none !important;
}
.hs-btn-logout {
    color: #f43f5e !important;
    text-decoration: none !important;
    font-size: 1.15rem !important;
    padding: 0.4rem 0.5rem !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border-radius: 8px !important;
    transition: transform 0.2s ease, color 0.2s ease, background 0.2s ease !important;
    cursor: pointer !important;
}
.hs-btn-logout:hover {
    color: #fb7185 !important;
    background: rgba(244, 63, 94, 0.15) !important;
    transform: scale(1.15) !important;
}

/* Redesigned Executive Sidebar & Backdrop System */
.admin-sidebar {
    width: 260px;
    background: linear-gradient(180deg, #0e0722 0%, #0a0518 45%, #060210 100%) !important;
    border-right: 1px solid rgba(255, 255, 255, 0.08) !important;
    display: flex !important;
    flex-direction: column !important;
    position: fixed !important;
    top: 0 !important;
    bottom: 0 !important;
    left: 0 !important;
    height: 100vh !important;
    overflow: hidden !important;
    z-index: 1350 !important; /* CRITICAL: Higher than backdrop (1250) */
    box-shadow: 4px 0 25px rgba(0, 0, 0, 0.5) !important;
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    pointer-events: auto !important;
}

/* Mobile Sidebar Backdrop Overlay */
.admin-sidebar-backdrop {
    display: none;
    position: fixed !important;
    inset: 0 !important;
    background: rgba(5, 2, 14, 0.72) !important;
    -webkit-backdrop-filter: blur(5px) !important;
    backdrop-filter: blur(5px) !important;
    z-index: 1250 !important; /* CRITICAL: Below sidebar (1350), above page content */
    opacity: 0 !important;
    visibility: hidden !important;
    pointer-events: none !important;
    transition: opacity 0.28s ease, visibility 0.28s ease !important;
}

@media (max-width: 991px) {
    .admin-sidebar {
        width: min(300px, 84vw) !important;
        transform: translateX(-100%) !important;
        box-shadow: none !important;
    }
    .admin-sidebar.open {
        transform: translateX(0) !important;
        box-shadow: 10px 0 45px rgba(0, 0, 0, 0.8) !important;
    }
    .admin-sidebar-backdrop {
        display: block !important;
    }
    .admin-sidebar-backdrop.open {
        opacity: 1 !important;
        visibility: visible !important;
        pointer-events: auto !important;
    }
}

@media (min-width: 992px) {
    .admin-sidebar {
        width: 260px !important;
        transform: translateX(0) !important;
    }
    .admin-sidebar-backdrop {
        display: none !important;
        pointer-events: none !important;
    }
}

.admin-sidebar-header-row {
    height: 72px !important;
    padding: 0 1.15rem !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    border-bottom: 1px solid rgba(255, 255, 255, 0.07) !important;
    flex-shrink: 0 !important;
    background: rgba(255, 255, 255, 0.02) !important;
    gap: 8px !important;
}
.admin-sidebar-brand {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    text-decoration: none !important;
    flex: 1 1 auto !important;
    min-width: 0 !important;
    overflow: hidden !important;
}
.admin-sidebar-brand-img {
    width: 36px !important;
    height: 36px !important;
    border-radius: 10px !important;
    object-fit: cover !important;
    border: 1.5px solid rgba(245, 158, 11, 0.5) !important;
    box-shadow: 0 2px 10px rgba(245, 158, 11, 0.25) !important;
    flex-shrink: 0 !important;
}
.admin-sidebar-brand-title {
    font-weight: 800 !important;
    font-size: 0.94rem !important;
    color: #ffffff !important;
    letter-spacing: 0.4px !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    font-family: 'Outfit', sans-serif !important;
    line-height: 1.2 !important;
    text-transform: uppercase !important;
}
.admin-sidebar-brand-sub {
    font-size: 0.65rem !important;
    color: #fbbf24 !important;
    font-weight: 800 !important;
    letter-spacing: 1.2px !important;
    text-transform: uppercase !important;
    display: flex !important;
    align-items: center !important;
    gap: 5px !important;
    line-height: 1.2 !important;
    margin-top: 3px !important;
    white-space: nowrap !important;
}
.admin-sidebar-brand-sub::before {
    content: '' !important;
    display: inline-block !important;
    width: 6px !important;
    height: 6px !important;
    border-radius: 50% !important;
    background: #10b981 !important;
    box-shadow: 0 0 6px #10b981 !important;
    flex-shrink: 0 !important;
}
.admin-sidebar-close-btn {
    display: none !important;
    background: rgba(255, 255, 255, 0.08) !important;
    border: 1px solid rgba(255, 255, 255, 0.15) !important;
    color: #cbd5e1 !important;
    font-size: 0.95rem !important;
    cursor: pointer !important;
    width: 34px !important;
    height: 34px !important;
    border-radius: 8px !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    margin-left: 8px !important;
    transition: all 0.2s ease !important;
}
.admin-sidebar-close-btn:hover,
.admin-sidebar-close-btn:active {
    background: rgba(244, 63, 94, 0.25) !important;
    color: #fca5a5 !important;
    border-color: rgba(244, 63, 94, 0.4) !important;
    transform: scale(1.05) !important;
}
@media (max-width: 991px) {
    .admin-sidebar-close-btn {
        display: inline-flex !important;
    }
}
@media (min-width: 992px) {
    .admin-sidebar-close-btn {
        display: none !important;
    }
}
.admin-nav-section {
    padding: 0.75rem 0.5rem !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    scrollbar-gutter: stable;
    scrollbar-width: thin !important;
    scrollbar-color: rgba(255, 255, 255, 0.15) transparent !important;
    box-sizing: border-box !important;
}
.admin-nav-section::-webkit-scrollbar {
    width: 4px !important;
}
.admin-nav-section::-webkit-scrollbar-track {
    background: transparent !important;
}
.admin-nav-section::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.15) !important;
    border-radius: 4px !important;
}
.admin-nav-section::-webkit-scrollbar-thumb:hover {
    background: rgba(255, 255, 255, 0.3) !important;
}
.admin-nav-menu {
    list-style: none !important;
    margin: 0 !important;
    padding: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 4px !important;
    width: 100% !important;
    box-sizing: border-box !important;
}
.admin-nav-menu li {
    width: 100% !important;
    box-sizing: border-box !important;
    margin: 0 !important;
    padding: 0 !important;
}
.admin-nav-link {
    display: flex !important;
    align-items: center !important;
    width: 100% !important;
    box-sizing: border-box !important;
    gap: 8px !important;
    padding: 0.62rem 0.75rem !important;
    color: #cbd5e1 !important;
    text-decoration: none !important;
    font-weight: 600 !important;
    font-size: 0.83rem !important;
    font-family: 'Plus Jakarta Sans', sans-serif !important;
    border-radius: 10px !important;
    transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease !important;
    border: 1px solid transparent !important;
    white-space: nowrap !important;
    position: relative !important;
}
.admin-nav-link > span:not(.admin-nav-badge) {
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    line-height: 1.25 !important;
    flex: 1 1 auto !important;
    min-width: 0 !important;
}
.admin-nav-link:hover {
    color: #ffffff !important;
    background: rgba(124, 58, 237, 0.2) !important;
    border-color: rgba(124, 58, 237, 0.35) !important;
}
.admin-nav-link.active {
    color: #ffffff !important;
    background: linear-gradient(90deg, rgba(124, 58, 237, 0.45) 0%, rgba(245, 158, 11, 0.18) 100%) !important;
    border: 1px solid rgba(245, 158, 11, 0.4) !important;
    font-weight: 700 !important;
    box-shadow: 0 2px 12px rgba(124, 58, 237, 0.25) !important;
}
.admin-nav-link i {
    font-size: 0.92rem !important;
    width: 20px !important;
    text-align: center !important;
    color: #a78bfa !important;
    transition: color 0.2s ease, transform 0.2s ease !important;
    flex-shrink: 0 !important;
}
.admin-nav-link:hover i {
    color: #fbbf24 !important;
    transform: scale(1.1) !important;
}
.admin-nav-link.active i {
    color: #fbbf24 !important;
}
.admin-nav-badge {
    margin-left: auto !important;
    font-size: 0.68rem !important;
    font-weight: 800 !important;
    padding: 2px 7px !important;
    border-radius: 999px !important;
    box-shadow: 0 2px 6px rgba(0,0,0,0.25) !important;
    line-height: 1.2 !important;
    flex-shrink: 0 !important;
    white-space: nowrap !important;
}
.admin-nav-divider {
    height: 1px !important;
    width: 100% !important;
    box-sizing: border-box !important;
    background: rgba(255, 255, 255, 0.06) !important;
    margin: 6px 0 !important;
    list-style: none !important;
}
.admin-sidebar-footer {
    padding: 0.85rem 0.85rem !important;
    border-top: 1px solid rgba(255, 255, 255, 0.08) !important;
    background: rgba(7, 3, 18, 0.98) !important;
    flex-shrink: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    box-shadow: 0 -4px 16px rgba(0, 0, 0, 0.3) !important;
}
.admin-sidebar-signout-btn {
    width: 100% !important;
    box-sizing: border-box !important;
    padding: 0.72rem 1rem !important;
    border-radius: 10px !important;
    background: rgba(244, 63, 94, 0.12) !important;
    border: 1.5px solid rgba(244, 63, 94, 0.35) !important;
    color: #fb7185 !important;
    font-size: 0.88rem !important;
    font-weight: 700 !important;
    font-family: 'Plus Jakarta Sans', sans-serif !important;
    text-decoration: none !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 9px !important;
    transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1) !important;
    cursor: pointer !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2) !important;
}
.admin-sidebar-signout-btn:hover {
    background: #e11d48 !important;
    border-color: #e11d48 !important;
    color: #ffffff !important;
    box-shadow: 0 4px 16px rgba(225, 29, 72, 0.45) !important;
    transform: translateY(-1px) !important;
}
.admin-sidebar-signout-btn i {
    font-size: 0.95rem !important;
    transition: transform 0.2s ease !important;
}
.admin-sidebar-signout-btn:hover i {
    transform: translateX(3px) !important;
}
</style>

<!-- Responsive CSS: mobile-first for all admin pages (360/768/1200px) -->
<link rel="stylesheet" href="responsive.css">

<!-- Mobile Admin Sidebar Backdrop Overlay -->
<div class="admin-sidebar-backdrop" id="adminSidebarBackdrop" onclick="toggleAdminSidebar()"></div>

<!-- Executive Admin Left Sidebar (Off-Canvas Drawer on Mobile) -->
<aside class="admin-sidebar" id="adminSidebar" aria-label="Admin Navigation">
    <div class="admin-sidebar-header-row">
        <a href="admin-dashboard.php" class="admin-sidebar-brand">
            <img src="<?= htmlspecialchars($admin_resort_logo) ?>" alt="Resort Logo" class="admin-sidebar-brand-img" onerror="this.onerror=null; this.src='logo.jpg';">
            <div style="display: flex; flex-direction: column; min-width: 0;">
                <span class="admin-sidebar-brand-title"><?= htmlspecialchars($admin_brand_title) ?></span>
                <span class="admin-sidebar-brand-sub">ADMIN CONSOLE</span>
            </div>
        </a>
        <button type="button" class="admin-sidebar-close-btn" onclick="toggleAdminSidebar()" aria-label="Close Navigation Sidebar">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <div class="admin-nav-section">
        <ul class="admin-nav-menu">
            <li>
                <a href="admin-dashboard.php" class="admin-nav-link <?= $admin_current_page === 'admin-dashboard.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-chart-line"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="bookings.php" class="admin-nav-link <?= in_array($admin_current_page, ['bookings.php', 'checkin_checkout.php']) ? 'active' : '' ?>">
                    <i class="fa-solid fa-book-bookmark"></i>
                    <span>Booking Management</span>
                    <span class="admin-nav-badge" style="background: var(--purple-accent);"><?= $admin_stats['active_bookings'] ?? $admin_stats['total_bookings'] ?></span>
                </a>
            </li>
            <li>
                <a href="booking_history.php" class="admin-nav-link <?= $admin_current_page === 'booking_history.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <span>Booking History</span>
                    <?php if (!empty($admin_stats['history_count'])): ?>
                        <span class="admin-nav-badge" style="background: rgba(167, 139, 250, 0.25); color: #c4b5fd;"><?= $admin_stats['history_count'] ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <li class="admin-nav-divider"></li>

            <li>
                <a href="browse_kubos.php" class="admin-nav-link <?= $admin_current_page === 'browse_kubos.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-house-chimney-window"></i>
                    <span>House Kubos</span>
                </a>
            </li>
            <li>
                <a href="browse_activities.php" class="admin-nav-link <?= $admin_current_page === 'browse_activities.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-water-ladder"></i>
                    <span>Pool & Activities</span>
                </a>
            </li>
            <li>
                <a href="activity_monitoring.php" class="admin-nav-link <?= $admin_current_page === 'activity_monitoring.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-stopwatch"></i>
                    <span>Activity Timers</span>
                </a>
            </li>
            <li>
                <a href="dining_monitoring.php" class="admin-nav-link <?= in_array($admin_current_page, ['dining_monitoring.php', 'manage_menu.php']) ? 'active' : '' ?>">
                    <i class="fa-solid fa-utensils"></i>
                    <span>Kitchen & Dining</span>
                    <?php 
                    $dining_active_total = ($dining_nav_stats['pending_count'] ?? 0) + ($dining_nav_stats['preparing_count'] ?? 0);
                    if ($dining_active_total > 0): 
                    ?>
                        <span class="admin-nav-badge" style="background: var(--gold-glow, #f59e0b); color: #ffffff;" title="<?= $dining_active_total ?> active kitchen order(s)"><?= $dining_active_total ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="calendar.php" class="admin-nav-link <?= $admin_current_page === 'calendar.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-calendar-days"></i>
                    <span>Schedule Calendar</span>
                </a>
            </li>

            <li class="admin-nav-divider"></li>

            <li>
                <a href="user_management.php" class="admin-nav-link <?= $admin_current_page === 'user_management.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-users-gear"></i>
                    <span>Manage Users</span>
                </a>
            </li>
            <li>
                <a href="reviews.php" class="admin-nav-link <?= $admin_current_page === 'reviews.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-star"></i>
                    <span>Review Management</span>
                    <?php if ($review_nav_stats['pending_replies'] > 0): ?>
                        <span class="admin-nav-badge" style="background: var(--accent-gold); color: #ffffff;" title="<?= $review_nav_stats['pending_replies'] ?> review(s) awaiting response"><?= $review_nav_stats['pending_replies'] ?></span>
                    <?php else: ?>
                        <span class="admin-nav-badge" style="background: var(--purple-accent);"><?= $review_nav_stats['total_reviews'] ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <li class="admin-nav-divider"></li>

            <li>
                <a href="settings.php" class="admin-nav-link <?= in_array($admin_current_page, ['settings.php', 'edit_homepage.php', 'admin_profile.php']) ? 'active' : '' ?>">
                    <i class="fa-solid fa-gear" style="color: var(--gold-glow);"></i>
                    <span>Settings</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Pinned Footer at Bottom of Sidebar with Prominent Sign Out Button -->
    <div class="admin-sidebar-footer">
        <a href="logout.php" onclick="openLogoutModal(event)" class="admin-sidebar-signout-btn" title="Sign out of Admin Console">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            <span>Sign Out</span>
        </a>
    </div>
</aside>

<script>
/**
 * ============================================================
 * ADMIN SIDEBAR TOGGLE (Off-Canvas Drawer)
 * Matches public-view nav.php behavior
 * ============================================================
 */
function toggleAdminSidebar() {
    const sidebar = document.getElementById('adminSidebar');
    const backdrop = document.getElementById('adminSidebarBackdrop');
    if (!sidebar) return;

    const isOpening = !sidebar.classList.contains('open');

    if (isOpening) {
        sidebar.classList.add('open');
        if (backdrop) backdrop.classList.add('open');
        document.body.classList.add('admin-sidebar-open');
        // Lock background scroll on mobile
        if (window.innerWidth <= 991) {
            document.body.style.overflow = 'hidden';
        }
    } else {
        sidebar.classList.remove('open');
        if (backdrop) backdrop.classList.remove('open');
        document.body.classList.remove('admin-sidebar-open');
        document.body.style.overflow = '';
    }
}

/**
 * Close sidebar via ESC key
 */
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        const sidebar = document.getElementById('adminSidebar');
        if (sidebar && sidebar.classList.contains('open')) {
            toggleAdminSidebar();
        }
    }
});

/**
 * Auto-close drawer when window resizes back to desktop
 */
let adminSidebarResizeTimer;
window.addEventListener('resize', function () {
    clearTimeout(adminSidebarResizeTimer);
    adminSidebarResizeTimer = setTimeout(function () {
        if (window.innerWidth > 991) {
            const sidebar = document.getElementById('adminSidebar');
            const backdrop = document.getElementById('adminSidebarBackdrop');
            if (sidebar && sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
                if (backdrop) backdrop.classList.remove('open');
                document.body.classList.remove('admin-sidebar-open');
                document.body.style.overflow = '';
            }
        }
    }, 100);
});

function updateAdminHeaderClock() {
    const el = document.getElementById('adminHeaderLiveClock');
    if (!el) return;
    const now = new Date();
    let hours = now.getHours();
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    const strHours = String(hours).padStart(2, '0');
    el.textContent = `${strHours}:${minutes} ${ampm}`;
}

document.addEventListener('DOMContentLoaded', function () {
    updateAdminHeaderClock();
    setInterval(updateAdminHeaderClock, 15000);

    // Auto-inject hamburger button into .admin-top-header if not present
    const header = document.querySelector('.admin-top-header');
    if (header) {
        const floatingBtn = document.querySelector('.admin-floating-toggle-btn');
        if (floatingBtn) floatingBtn.style.display = 'none';

        if (!header.querySelector('.admin-mobile-toggle-btn')) {
            const toggleBtn = document.createElement('button');
            toggleBtn.type = 'button';
            toggleBtn.className = 'admin-mobile-toggle-btn';
            toggleBtn.setAttribute('aria-label', 'Open Navigation Menu');
            toggleBtn.setAttribute('aria-expanded', 'false');
            toggleBtn.innerHTML = '<i class="fa-solid fa-bars"></i>';
            toggleBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                toggleAdminSidebar();
                const isOpen = document.getElementById('adminSidebar')?.classList.contains('open');
                toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
            header.prepend(toggleBtn);
        }
    }

    // Stop event bubbling from within sidebar so internal clicks don't hit backdrop
    const sidebar = document.getElementById('adminSidebar');
    if (sidebar) {
        sidebar.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }
});

// Intercept clicks on any logout link in admin console
document.addEventListener('click', function(e) {
    const target = e.target.closest('a[href="logout.php"], .admin-header-signout-btn, .admin-sidebar-signout-btn');
    if (target) {
        e.preventDefault();
        openLogoutModal();
    }
});

// Direct clicks on header admin profile pill to Settings (Admin Profile tab)
document.addEventListener('click', function(e) {
    const pill = e.target.closest('.admin-header-profile-pill');
    if (pill) {
        if (!pill.closest('a') && pill.tagName.toLowerCase() !== 'a') {
            e.preventDefault();
            window.location.href = 'settings.php?tab=profile';
        }
    }
});

function openLogoutModal(e) {
    if (e) e.preventDefault();
    const modal = document.getElementById('adminLogoutConfirmModal');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}
function closeLogoutModal() {
    const modal = document.getElementById('adminLogoutConfirmModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLogoutModal();
});
</script>

<script src="assets/action-modal.js"></script>

<!-- Admin Logout Confirmation Modal Dialog -->
<div id="adminLogoutConfirmModal" class="logout-modal-backdrop" style="display: none;" onclick="if(event.target === this) closeLogoutModal();">
    <div class="logout-modal-dialog">
        <div class="logout-modal-icon">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </div>
        <h3 class="logout-modal-title">Sign Out Confirmation</h3>
        <p class="logout-modal-text">Do you really want to logout?</p>
        <div class="logout-modal-actions">
            <button type="button" class="logout-modal-btn logout-modal-btn-cancel" onclick="closeLogoutModal()">
                <i class="fa-solid fa-xmark"></i> No
            </button>
            <a href="logout.php?confirm=1" class="logout-modal-btn logout-modal-btn-confirm">
                <i class="fa-solid fa-check"></i> Yes
            </a>
        </div>
    </div>
</div>

<!-- Admin Action Confirmation & Notice Modal Dialog (Adding, Deleting, Updating) -->
<div id="adminActionModal" class="logout-modal-backdrop action-modal-backdrop" style="display: none;" onclick="if(event.target === this) closeActionModal();">
    <div class="logout-modal-dialog action-modal-dialog">
        <div id="adminActionModalIcon" class="logout-modal-icon action-modal-icon">
            <i class="fa-solid fa-circle-question"></i>
        </div>
        <h3 id="adminActionModalTitle" class="logout-modal-title action-modal-title">Action Confirmation</h3>
        <p id="adminActionModalText" class="logout-modal-text action-modal-text">Are you sure you want to proceed?</p>
        <div id="adminActionModalActions" class="logout-modal-actions action-modal-actions">
            <button type="button" id="adminActionModalCancelBtn" class="logout-modal-btn logout-modal-btn-cancel action-modal-btn-cancel" onclick="closeActionModal()">
                <i class="fa-solid fa-xmark"></i> Cancel
            </button>
            <button type="button" id="adminActionModalConfirmBtn" class="logout-modal-btn logout-modal-btn-confirm">
                <i class="fa-solid fa-check"></i> Yes
            </button>
        </div>
    </div>
</div>

<style>
.action-modal-backdrop,
.logout-modal-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(15, 23, 42, 0.8);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 999999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    box-sizing: border-box;
}
.action-modal-dialog,
.logout-modal-dialog {
    background: #1e293b;
    border: 1.5px solid #334155;
    border-radius: 16px;
    padding: 2rem 1.75rem;
    max-width: 400px;
    width: 100%;
    text-align: center;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5), 0 0 24px rgba(0, 0, 0, 0.3);
    animation: scaleInAdminLogoutModal 0.2s ease-out;
}
.action-modal-icon,
.logout-modal-icon {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: rgba(245, 158, 11, 0.15);
    color: #f59e0b;
    border: 2px solid rgba(245, 158, 11, 0.35);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin: 0 auto 1rem auto;
}

/* Icon Variations */
.action-modal-icon.icon-delete,
.action-modal-icon.icon-danger {
    background: rgba(244, 63, 94, 0.15) !important;
    color: #f43f5e !important;
    border-color: rgba(244, 63, 94, 0.35) !important;
}
.action-modal-icon.icon-update,
.action-modal-icon.icon-edit,
.action-modal-icon.icon-info {
    background: rgba(124, 58, 237, 0.15) !important;
    color: #c084fc !important;
    border-color: rgba(124, 58, 237, 0.35) !important;
}
.action-modal-icon.icon-add,
.action-modal-icon.icon-success {
    background: rgba(16, 185, 129, 0.15) !important;
    color: #10b981 !important;
    border-color: rgba(16, 185, 129, 0.35) !important;
}
.action-modal-icon.icon-warning {
    background: rgba(245, 158, 11, 0.15) !important;
    color: #f59e0b !important;
    border-color: rgba(245, 158, 11, 0.35) !important;
}

.action-modal-title,
.logout-modal-title {
    font-family: 'Outfit', sans-serif;
    color: #ffffff;
    font-size: 1.25rem;
    font-weight: 700;
    margin: 0 0 0.5rem 0;
}
.action-modal-text,
.logout-modal-text {
    font-family: 'Plus Jakarta Sans', sans-serif;
    color: #cbd5e1;
    font-size: 0.95rem;
    font-weight: 500;
    line-height: 1.55;
    margin: 0 0 1.5rem 0;
}
.action-modal-actions,
.logout-modal-actions {
    display: flex;
    gap: 0.75rem;
    justify-content: center;
}
.action-modal-btn,
.logout-modal-btn {
    flex: 1;
    padding: 0.7rem 1rem;
    border-radius: 10px;
    font-size: 0.95rem;
    font-weight: 700;
    font-family: 'Plus Jakarta Sans', sans-serif;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
}
.action-modal-btn-cancel,
.logout-modal-btn-cancel {
    background: #334155 !important;
    color: #ffffff !important;
    border: 1px solid #475569 !important;
}
.action-modal-btn-cancel:hover,
.logout-modal-btn-cancel:hover {
    background: #475569 !important;
}
.logout-modal-btn-confirm {
    background: linear-gradient(135deg, #e0129c, #9d174d);
    color: #ffffff;
    border: 1px solid #e0129c;
}
.logout-modal-btn-confirm:hover {
    background: #831843;
    box-shadow: 0 4px 12px rgba(224, 18, 156, 0.35);
}
.action-modal-btn-delete {
    background: linear-gradient(135deg, #e11d48, #be123c) !important;
    color: #ffffff !important;
    border: 1px solid #e11d48 !important;
}
.action-modal-btn-delete:hover {
    background: #9f1239 !important;
    box-shadow: 0 4px 14px rgba(225, 29, 72, 0.4) !important;
}
.action-modal-btn-update {
    background: linear-gradient(135deg, #e0129c, #0284c7) !important;
    color: #ffffff !important;
    border: 1px solid #e0129c !important;
}
.action-modal-btn-update:hover {
    background: #c026d3 !important;
    box-shadow: 0 4px 14px rgba(224, 18, 156, 0.4) !important;
}
.action-modal-btn-add {
    background: linear-gradient(135deg, #059669, #047857) !important;
    color: #ffffff !important;
    border: 1px solid #059669 !important;
}
.action-modal-btn-add:hover {
    background: #065f46 !important;
    box-shadow: 0 4px 14px rgba(5, 150, 105, 0.4) !important;
}
@keyframes scaleInAdminLogoutModal {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}
</style>
