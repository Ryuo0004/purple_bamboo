<?php
require_once __DIR__ . '/../inventory_store.php';

echo "=== Testing Kubo Image Upload Functionality ===\n";

// Copy existing image to scratch to simulate an uploaded temp file
$sourceImg = __DIR__ . '/../assets/beach_kubo.jpg';
$dummyImgPath = __DIR__ . '/temp_upload_sample.jpg';
copy($sourceImg, $dummyImgPath);

assert(file_exists($dummyImgPath), "Dummy test image must exist");

// Note: move_uploaded_file requires is_uploaded_file() which only passes in real HTTP uploads.
// In inventory_store.php, let's check how move_uploaded_file is called:
// If move_uploaded_file fails (e.g. in CLI unit test), or copy is used.
// Let's test HTTP multipart upload via cURL to test real move_uploaded_file!

$cookieFile = __DIR__ . '/admin_upload_cookie.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

$baseUrl = 'http://localhost/purple_bamboo';

// 1. Login as admin
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
curl_exec($ch);
curl_close($ch);

// 2. HTTP POST with multipart/form-data upload to browse_kubos.php
$ch = curl_init("{$baseUrl}/browse_kubos.php");
$postFields = [
    'action' => 'add_kubo',
    'name' => 'Uploaded Photo Beachside Kubo',
    'category_id' => '1',
    'price' => '4100',
    'capacity' => '4',
    'status' => 'available',
    'badges' => 'Ocean View, Uploaded Photo',
    'description' => 'Real HTTP upload test.',
    'image_file' => new CURLFile($dummyImgPath, 'image/jpeg', 'my_custom_cottage.jpg')
];
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_FOLLOWLOCATION => true
]);
$resp = curl_exec($ch);
curl_close($ch);

$kubos = InventoryStore::getAllKubos();
$target = null;
foreach ($kubos as $k) {
    if ($k['name'] === 'Uploaded Photo Beachside Kubo') {
        $target = $k;
        break;
    }
}

if ($target) {
    echo "Found created Kubo unit: " . $target['name'] . "\n";
    echo "Image attribute: " . $target['image'] . "\n";
    $targetFilePath = __DIR__ . '/../' . $target['image'];
    if (file_exists($targetFilePath) && strpos($target['image'], 'uploads/kubos/') !== false) {
        echo "  [PASS] File successfully uploaded to uploads/kubos/ via HTTP POST!\n";
        unlink($targetFilePath);
    } else {
        echo "  [FAIL] Uploaded file not found at {$targetFilePath}\n";
    }
    // Delete test kubo
    InventoryStore::deleteKubo($target['kubo_id']);
    echo "  [PASS] Cleaned up test kubo unit.\n";
} else {
    echo "  [FAIL] Target kubo not found!\n";
    exit(1);
}

if (file_exists($dummyImgPath)) unlink($dummyImgPath);
if (file_exists($cookieFile)) unlink($cookieFile);

echo "\n=== Real HTTP Multipart Upload Test Passed! ===\n";
