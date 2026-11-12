<?php
$cookieFile = __DIR__ . '/admin_http_test_cookie.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

$baseUrl = 'http://localhost/purple_bamboo';

// Step 1: Login as admin via admin-login.php
$ch = curl_init("{$baseUrl}/admin-login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'email' => 'admin@purplebamboo.com',
        'password' => 'Admin123'
    ]),
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_FOLLOWLOCATION => true
]);
$loginResp = curl_exec($ch);
curl_close($ch);

echo "Step 1: Admin logged in via admin-login.php.\n";

// Step 2: GET browse_kubos.php and inspect modal presence
$ch = curl_init("{$baseUrl}/browse_kubos.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieFile
]);
$pageHtml = curl_exec($ch);
curl_close($ch);

assert(strpos($pageHtml, 'id="editKuboModal"') !== false, "editKuboModal should be in HTML");
assert(strpos($pageHtml, 'id="addKuboModal"') !== false, "addKuboModal should be in HTML");
assert(strpos($pageHtml, 'openEditKuboModal') !== false, "openEditKuboModal should be called");
assert(strpos($pageHtml, 'openAddKuboModal') !== false, "openAddKuboModal should be called");
assert(strpos($pageHtml, 'id="deleteKuboForm"') !== false, "deleteKuboForm should be in HTML");
echo "Step 2: [PASS] Modals & JS triggers verified in HTML for admin session.\n";

// Step 3: Test POST edit_kubo to change price and badges of Beach Kubo (#1)
$ch = curl_init("{$baseUrl}/browse_kubos.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'action' => 'edit_kubo',
        'kubo_id' => '1',
        'name' => 'Beach Kubo',
        'category_id' => '1',
        'price' => '3800',
        'capacity' => '6',
        'status' => 'available',
        'badges' => 'Air Conditioned, Beachfront Veranda, WiFi',
        'description' => 'Upgraded beach kubo with ocean view and comfortable aircon amenities.',
        'existing_image' => 'assets/beach_kubo.jpg'
    ],
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_FOLLOWLOCATION => true
]);
$editResp = curl_exec($ch);
curl_close($ch);

if (strpos($editResp, '3,800') !== false && strpos($editResp, 'Beachfront Veranda') !== false) {
    echo "Step 3: [PASS] HTTP POST edit_kubo updated price to ₱3,800 and badges!\n";
} else {
    echo "Step 3: [FAIL] Did not see updated price ₱3,800 in page HTML.\n";
    exit(1);
}

// Step 4: Test POST add_kubo
$ch = curl_init("{$baseUrl}/browse_kubos.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'action' => 'add_kubo',
        'name' => 'Garden Deluxe Kubo',
        'category_id' => '2',
        'price' => '4200',
        'capacity' => '5',
        'status' => 'available',
        'badges' => 'Garden View, King Bed, Smart TV',
        'description' => 'Serene garden cottage surrounded by tropical bamboo trees.',
        'image_url' => 'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=600&q=80'
    ],
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_FOLLOWLOCATION => true
]);
$addResp = curl_exec($ch);
curl_close($ch);

if (strpos($addResp, 'Garden Deluxe Kubo') !== false && strpos($addResp, '4,200') !== false) {
    echo "Step 4: [PASS] HTTP POST add_kubo added unit 'Garden Deluxe Kubo' with ₱4,200!\n";
} else {
    echo "Step 4: [FAIL] Newly added kubo not visible in page.\n";
    exit(1);
}

// Find newly added kubo ID from database/store to test delete
require_once 'c:/xamppp/htdocs/purple_bamboo/inventory_store.php';
$all = InventoryStore::getAllKubos();
$targetId = null;
foreach ($all as $item) {
    if ($item['name'] === 'Garden Deluxe Kubo') {
        $targetId = $item['kubo_id'];
        break;
    }
}
echo "Found added kubo with ID: {$targetId}\n";
assert($targetId !== null, "Target kubo should exist");

// Step 5: Test POST delete_kubo
$ch = curl_init("{$baseUrl}/browse_kubos.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'action' => 'delete_kubo',
        'kubo_id' => strval($targetId)
    ],
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_FOLLOWLOCATION => true
]);
$delResp = curl_exec($ch);
curl_close($ch);

if (strpos($delResp, 'Kubo unit has been permanently deleted') !== false) {
    echo "Step 5: [PASS] HTTP POST delete_kubo successfully removed unit!\n";
} else {
    echo "Step 5: [FAIL] Delete flash message not found.\n";
    exit(1);
}

// Step 6: Restore Beach Kubo to ₱3,500
InventoryStore::saveKuboDetails(1, [
    'name' => 'Beach Kubo',
    'category_id' => 1,
    'price' => 3500.00,
    'capacity' => 6,
    'status' => 'available',
    'badges' => 'Free WiFi, Aircon',
    'description' => 'Spacious air-conditioned Kubo with private veranda, refrigerator, and 6-person capacity.',
    'existing_image' => 'assets/beach_kubo.jpg'
]);
echo "Step 6: Beach Kubo price restored to original ₱3,500.\n";

echo "\n=== ALL HTTP ADMIN KUBO MANAGEMENT TESTS PASSED SUCCESSFULLY! ===\n";
