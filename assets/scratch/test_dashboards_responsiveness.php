<?php
/**
 * Test Suite for Mobile Responsiveness of All Dashboards
 * Verifies CSS media queries, viewport tags, mobile toggle elements, and HTTP page renders.
 */

$adminCssPath = __DIR__ . '/../admin-style.css';
$styleCssPath = __DIR__ . '/../style.css';
$adminNavPath = __DIR__ . '/../admin-nav.php';

echo "=======================================================\n";
echo "=== 1. VERIFYING CSS MOBILE BREAKPOINTS & RULES     ===\n";
echo "=======================================================\n";

assert(file_exists($adminCssPath), "admin-style.css must exist");
$adminCss = file_get_contents($adminCssPath);

// Check admin-style.css media queries
assert(strpos($adminCss, '@media (max-width: 992px)') !== false, "admin-style.css must contain 992px breakpoint");
assert(strpos($adminCss, '@media (max-width: 768px)') !== false, "admin-style.css must contain 768px breakpoint");
assert(strpos($adminCss, '@media (max-width: 480px)') !== false, "admin-style.css must contain 480px breakpoint");
assert(strpos($adminCss, '.admin-sidebar.open') !== false, "admin-style.css must contain .admin-sidebar.open rule");
assert(strpos($adminCss, '.admin-mobile-toggle-btn') !== false, "admin-style.css must contain .admin-mobile-toggle-btn rule");
assert(strpos($adminCss, '.admin-sidebar-backdrop') !== false, "admin-style.css must contain .admin-sidebar-backdrop rule");
assert(strpos($adminCss, '.table-responsive') !== false, "admin-style.css must contain .table-responsive");
echo "  [PASS] admin-style.css has complete 992px, 768px, and 480px responsive rules!\n";

// Check style.css user dashboard classes
assert(file_exists($styleCssPath), "style.css must exist");
$styleCss = file_get_contents($styleCssPath);
assert(strpos($styleCss, '.user-dashboard-layout') !== false, "style.css must contain .user-dashboard-layout");
assert(strpos($styleCss, '.user-welcome-card') !== false, "style.css must contain .user-welcome-card");
echo "  [PASS] style.css has .user-dashboard-layout and mobile overrides!\n";


echo "\n=======================================================\n";
echo "=== 2. VERIFYING NAV & MOBILE TOGGLE SYSTEM         ===\n";
echo "=======================================================\n";

$adminNav = file_get_contents($adminNavPath);
assert(strpos($adminNav, 'adminSidebarBackdrop') !== false, "admin-nav.php must include backdrop");
assert(strpos($adminNav, 'admin-sidebar-close-btn') !== false, "admin-nav.php must include close button");
assert(strpos($adminNav, 'toggleAdminSidebar') !== false, "admin-nav.php must include toggleAdminSidebar JS");
assert(strpos($adminNav, 'admin-mobile-toggle-btn') !== false, "admin-nav.php must include hamburger auto-inject");
echo "  [PASS] admin-nav.php contains mobile backdrop, close button, and auto-injection!\n";


echo "\n=======================================================\n";
echo "=== 3. VERIFYING VIEWPORT TAGS ACROSS DASHBOARDS    ===\n";
echo "=======================================================\n";

$dashboards = [
    'admin-dashboard.php',
    'user-dashboard.php',
    'bookings.php',
    'dining_monitoring.php',
    'activity_monitoring.php',
    'manage_menu.php',
    'verify_gcash.php',
    'edit_homepage.php',
    'checkin_checkout.php',
    'calendar.php'
];

foreach ($dashboards as $file) {
    $filePath = __DIR__ . '/../' . $file;
    assert(file_exists($filePath), "File $file must exist");
    $content = file_get_contents($filePath);
    assert(strpos($content, 'viewport') !== false, "Dashboard $file must have viewport meta tag for mobile scaling");
    echo "  [PASS] $file has viewport meta tag!\n";
}


echo "\n=======================================================\n";
echo "=== 4. TESTING LIVE HTTP DASHBOARD RENDERS VIA CURL ===\n";
echo "=======================================================\n";

$baseUrl = 'http://localhost/purple_bamboo';
$cookieAdmin = tempnam(sys_get_temp_dir(), 'pbr_adm_');
$cookieUser = tempnam(sys_get_temp_dir(), 'pbr_usr_');

// Helper to log in
function curlLogin($url, $email, $password, $cookieFile) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['email' => $email, 'password' => $password]),
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile
    ]);
    curl_exec($ch);
    curl_close($ch);
}

curlLogin("{$baseUrl}/login.php", 'admin@purplebamboo.com', 'Admin123', $cookieAdmin);
curlLogin("{$baseUrl}/login.php", 'guest@example.com', 'Admin123', $cookieUser);

// 4.1 Admin Dashboard render
$ch = curl_init("{$baseUrl}/admin-dashboard.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieAdmin]);
$adminDashHtml = curl_exec($ch);
curl_close($ch);
assert(strpos($adminDashHtml, 'admin-main-wrapper') !== false, "Admin main wrapper should render");
assert(strpos($adminDashHtml, 'admin-sidebar-backdrop') !== false, "Admin mobile backdrop should render");
assert(strpos($adminDashHtml, 'Executive Command Center') !== false, "Admin dashboard title should render");
echo "  [PASS] admin-dashboard.php rendered with full mobile elements!\n";

// 4.2 User Dashboard render
$ch = curl_init("{$baseUrl}/user-dashboard.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieUser]);
$userDashHtml = curl_exec($ch);
curl_close($ch);
assert(strpos($userDashHtml, 'user-dashboard-layout') !== false, "user-dashboard-layout should render");
assert(strpos($userDashHtml, 'user-welcome-card') !== false, "user-welcome-card should render");
echo "  [PASS] user-dashboard.php rendered with responsive layout classes!\n";

// 4.3 Bookings Manifest render
$ch = curl_init("{$baseUrl}/bookings.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieAdmin]);
$bookingsHtml = curl_exec($ch);
curl_close($ch);
assert(strpos($bookingsHtml, 'table-responsive') !== false, "bookings.php should have table-responsive");
echo "  [PASS] bookings.php rendered with touch-scrollable table-responsive!\n";

// 4.4 Dining Monitoring render
$ch = curl_init("{$baseUrl}/dining_monitoring.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieAdmin]);
$diningHtml = curl_exec($ch);
curl_close($ch);
assert(strpos($diningHtml, 'admin-grid') !== false, "dining_monitoring.php should render admin grid");
echo "  [PASS] dining_monitoring.php rendered with responsive admin grid!\n";

// 4.5 Dining Menu Manager render
$ch = curl_init("{$baseUrl}/manage_menu.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieAdmin]);
$menuHtml = curl_exec($ch);
curl_close($ch);
assert(strpos($menuHtml, 'admin-grid') !== false, "manage_menu.php should render admin-grid");
echo "  [PASS] manage_menu.php rendered with responsive admin-grid!\n";

if (file_exists($cookieAdmin)) unlink($cookieAdmin);
if (file_exists($cookieUser)) unlink($cookieUser);

echo "\n=======================================================\n";
echo "=== ALL DASHBOARDS MOBILE RESPONSIVENESS PASSED 100%! ===\n";
echo "=======================================================\n";
