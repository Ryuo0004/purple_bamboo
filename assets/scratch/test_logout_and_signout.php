<?php
$baseUrl = 'http://localhost/purple_bamboo';

// Test 1: GET admin-login.php?logged_out=1
$ch = curl_init("{$baseUrl}/admin-login.php?logged_out=1");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HEADER => true
]);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Test 1 - admin-login.php?logged_out=1:\n";
echo "HTTP Code: $httpCode\n";
preg_match('/Location:\s*([^\r\n]+)/i', $res, $loc);
echo "Location: " . ($loc[1] ?? 'None') . "\n";
assert($httpCode === 302, "Should return 302 redirect");
assert(strpos($loc[1], 'login.php?logged_out=1') !== false, "Should redirect to login.php?logged_out=1");
echo "[PASS] Successfully redirects to login.php?logged_out=1\n\n";

// Test 2: GET logout.php
$ch = curl_init("{$baseUrl}/logout.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HEADER => true
]);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Test 2 - logout.php:\n";
echo "HTTP Code: $httpCode\n";
preg_match('/Location:\s*([^\r\n]+)/i', $res, $loc);
echo "Location: " . ($loc[1] ?? 'None') . "\n";
assert($httpCode === 302, "Should return 302 redirect");
assert(strpos($loc[1], 'login.php?logged_out=1') !== false, "Should redirect to login.php?logged_out=1");
echo "[PASS] Successfully redirects to login.php?logged_out=1\n\n";

// Test 3: GET login.php?logged_out=1 contains safe sign-out message
$ch = curl_init("{$baseUrl}/login.php?logged_out=1");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true
]);
$body = curl_exec($ch);
curl_close($ch);

echo "Test 3 - login.php?logged_out=1 content:\n";
assert(strpos($body, 'You have been safely signed out') !== false, "Should contain safe sign-out alert");
echo "[PASS] login.php shows safe signout banner!\n\n";

// Test 4: Check admin-nav.php has Sign Out buttons
$navContent = file_get_contents(__DIR__ . '/../admin-nav.php');
assert(strpos($navContent, 'logout.php') !== false, "admin-nav.php must link to logout.php");
assert(strpos($navContent, 'Sign Out') !== false, "admin-nav.php must contain Sign Out label");
echo "[PASS] admin-nav.php contains Sign Out button and links to logout.php!\n";

echo "\nALL TESTS PASSED!\n";
