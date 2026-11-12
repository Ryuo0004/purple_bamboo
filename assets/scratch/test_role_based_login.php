<?php
/**
 * Comprehensive Test Suite for Role-Based Login for Both Guest & Admin
 * Tests Auth::login, login.php HTTP POST, and admin-login.php HTTP POST.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../security.php';

echo "=======================================================\n";
echo "=== 1. TESTING DIRECT Auth::login ROLE-BASED SYSTEM ===\n";
echo "=======================================================\n";

// Test 1.1: Admin login via Auth::login
$_SESSION = [];
$adminRes = Auth::login('admin@purplebamboo.com', 'Admin123');
assert($adminRes['success'] === true, "Admin login should succeed");
assert($adminRes['role'] === 'admin', "Role must be 'admin'");
assert($adminRes['redirect_url'] === 'admin-dashboard.php', "Admin should redirect to admin-dashboard.php");
assert($_SESSION['role'] === 'admin', "Session role must be 'admin'");
assert(!empty($_SESSION['admin_logged_in']), "admin_logged_in must be set");
assert(!empty($_SESSION['user_id']), "user_id must be set");
echo "  [PASS] Auth::login as Admin succeeded and set all admin credentials!\n";

// Test 1.2: Staff login via Auth::login
$_SESSION = [];
$staffRes = Auth::login('staff@purplebamboo.com', 'Admin123');
assert($staffRes['success'] === true, "Staff login should succeed");
assert($staffRes['role'] === 'staff', "Role must be 'staff'");
assert($staffRes['redirect_url'] === 'bookings.php', "Staff should redirect to bookings.php");
assert($_SESSION['role'] === 'staff', "Session role must be 'staff'");
assert(!empty($_SESSION['admin_logged_in']), "admin_logged_in must be set for staff");
echo "  [PASS] Auth::login as Staff succeeded and set all staff credentials!\n";

// Test 1.3: Guest login via Auth::login
$_SESSION = [];
$guestRes = Auth::login('guest@example.com', 'Admin123');
assert($guestRes['success'] === true, "Guest login should succeed");
assert($guestRes['role'] === 'guest', "Role must be 'guest'");
assert(strpos($guestRes['redirect_url'], 'index.php') !== false, "Guest should redirect to index.php");
assert($_SESSION['role'] === 'guest', "Session role must be 'guest'");
assert(empty($_SESSION['admin_logged_in']), "admin_logged_in should NOT be set for guest");
echo "  [PASS] Auth::login as Guest succeeded and established guest session!\n";

// Test 1.4: Invalid password
$wrongPwRes = Auth::login('admin@purplebamboo.com', 'Wrong123');
assert($wrongPwRes['success'] === false, "Wrong password must fail");
echo "  [PASS] Auth::login correctly rejects invalid password!\n";


echo "\n=======================================================\n";
echo "=== 2. TESTING HTTP login.php ROLE-BASED ROUTING    ===\n";
echo "=======================================================\n";

$baseUrl = 'http://localhost/purple_bamboo';

function performHttpLogin($url, $email, $password) {
    $cookie = tempnam(sys_get_temp_dir(), 'pbr_ck_');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'email' => $email,
            'password' => $password
        ]),
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_FOLLOWLOCATION => false
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    $location = '';
    if (preg_match('/^Location:\s*(.*)$/mi', $headers, $m)) {
        $location = trim($m[1]);
    }

    return [
        'code' => $httpCode,
        'location' => $location,
        'headers' => $headers,
        'body' => $body,
        'cookie' => $cookie
    ];
}

// Test 2.1: Admin logs in on login.php
$loginAdminHttp = performHttpLogin("{$baseUrl}/login.php", 'admin@purplebamboo.com', 'Admin123');
assert($loginAdminHttp['code'] === 302, "login.php should return 302 redirect for admin");
assert($loginAdminHttp['location'] === 'admin-dashboard.php', "Admin should be auto-routed to admin-dashboard.php, got: " . $loginAdminHttp['location']);

// Verify authenticated session allows accessing admin-dashboard.php
$ch = curl_init("{$baseUrl}/admin-dashboard.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $loginAdminHttp['cookie']
]);
$dashHtml = curl_exec($ch);
curl_close($ch);
assert(strpos($dashHtml, 'Executive Command Center') !== false || strpos($dashHtml, 'Dashboard') !== false, "Admin dashboard should load successfully");
echo "  [PASS] Admin logged in on login.php and was routed directly to admin-dashboard.php!\n";
if (file_exists($loginAdminHttp['cookie'])) unlink($loginAdminHttp['cookie']);

// Test 2.2: Staff logs in on login.php
$loginStaffHttp = performHttpLogin("{$baseUrl}/login.php", 'staff@purplebamboo.com', 'Admin123');
assert($loginStaffHttp['code'] === 302, "login.php should return 302 redirect for staff");
assert($loginStaffHttp['location'] === 'bookings.php', "Staff should be auto-routed to bookings.php, got: " . $loginStaffHttp['location']);
echo "  [PASS] Staff logged in on login.php and was routed directly to bookings.php!\n";
if (file_exists($loginStaffHttp['cookie'])) unlink($loginStaffHttp['cookie']);

// Test 2.3: Guest logs in on login.php
$loginGuestHttp = performHttpLogin("{$baseUrl}/login.php", 'guest@example.com', 'Admin123');
assert($loginGuestHttp['code'] === 302, "login.php should return 302 redirect for guest");
assert(strpos($loginGuestHttp['location'], 'index.php') !== false, "Guest should be auto-routed to index.php, got: " . $loginGuestHttp['location']);

// Verify guest session
$ch = curl_init("{$baseUrl}/user-dashboard.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $loginGuestHttp['cookie']
]);
$userDashHtml = curl_exec($ch);
curl_close($ch);
assert(strpos($userDashHtml, 'Guest Profile') !== false || strpos($userDashHtml, 'Dela Cruz') !== false || strpos($userDashHtml, 'PBR-GST-') !== false, "User dashboard should load for guest");
echo "  [PASS] Guest logged in on login.php and was routed directly to index.php / user area!\n";
if (file_exists($loginGuestHttp['cookie'])) unlink($loginGuestHttp['cookie']);


echo "\n=======================================================\n";
echo "=== 3. TESTING HTTP admin-login.php ROLE-BASED ROUTING=\n";
echo "=======================================================\n";

// Test 3.1: Admin logs in on admin-login.php
$adminLoginAdminHttp = performHttpLogin("{$baseUrl}/admin-login.php", 'admin@purplebamboo.com', 'Admin123');
assert($adminLoginAdminHttp['code'] === 302, "admin-login.php should return 302 redirect for admin");
assert($adminLoginAdminHttp['location'] === 'admin-dashboard.php', "Admin should be routed to admin-dashboard.php");
echo "  [PASS] Admin logged in on admin-login.php and was routed to admin-dashboard.php!\n";
if (file_exists($adminLoginAdminHttp['cookie'])) unlink($adminLoginAdminHttp['cookie']);

// Test 3.2: Guest logs in on admin-login.php (Role-based handling)
$guestLoginAdminHttp = performHttpLogin("{$baseUrl}/admin-login.php", 'guest@example.com', 'Admin123');
assert($guestLoginAdminHttp['code'] === 302, "admin-login.php should return 302 redirect for guest");
assert(strpos($guestLoginAdminHttp['location'], 'index.php') !== false, "Guest should be smoothly routed to index.php, got: " . $guestLoginAdminHttp['location']);
echo "  [PASS] Guest logged in on admin-login.php and was safely routed to guest portal!\n";
if (file_exists($guestLoginAdminHttp['cookie'])) unlink($guestLoginAdminHttp['cookie']);


echo "\n=======================================================\n";
echo "=== 4. TESTING PASSWORD CONSTRAINTS ENFORCEMENT     ===\n";
echo "=======================================================\n";

// Test 4.1: Short password
$shortPwHttp = performHttpLogin("{$baseUrl}/login.php", 'guest@example.com', 'Short1');
assert(strpos($shortPwHttp['body'], 'exactly 8 characters long') !== false, "Should enforce exactly 8 chars");
echo "  [PASS] Short password rejected with 8-character constraint message!\n";
if (file_exists($shortPwHttp['cookie'])) unlink($shortPwHttp['cookie']);

// Test 4.2: Missing uppercase
$noCapHttp = performHttpLogin("{$baseUrl}/login.php", 'guest@example.com', 'admin123');
assert(strpos($noCapHttp['body'], 'uppercase') !== false, "Should enforce uppercase letter");
echo "  [PASS] Missing uppercase password rejected!\n";
if (file_exists($noCapHttp['cookie'])) unlink($noCapHttp['cookie']);

// Test 4.3: Missing number
$noNumHttp = performHttpLogin("{$baseUrl}/login.php", 'guest@example.com', 'Adminabc');
assert(strpos($noNumHttp['body'], 'number') !== false, "Should enforce number");
echo "  [PASS] Missing number password rejected!\n";
if (file_exists($noNumHttp['cookie'])) unlink($noNumHttp['cookie']);

echo "\n=======================================================\n";
echo "=== ALL ROLE-BASED LOGIN TESTS PASSED 100%!         ===\n";
echo "=======================================================\n";
