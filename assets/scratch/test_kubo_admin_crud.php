<?php
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../inventory_store.php';

echo "=== TEST 1: InventoryStore Backend Direct Validation ===\n";

// 1. Get original Beach Kubo (kubo_id = 1)
$original = InventoryStore::getKuboById(1);
echo "Original Kubo #1: " . $original['name'] . " | Price: ₱" . $original['price'] . " | Status: " . $original['status'] . "\n";

// 2. Test edit details: change price to 3750, badges, description
$updateData = [
    'name' => 'Beach Kubo',
    'category_id' => 1,
    'price' => 3750.00,
    'capacity' => 6,
    'status' => 'available',
    'badges' => 'Air Conditioned, Beachfront, Free WiFi',
    'description' => 'Upgraded beach kubo with premium beachfront view and mini-ref.',
    'existing_image' => 'assets/beach_kubo.jpg'
];
$res = InventoryStore::saveKuboDetails(1, $updateData);
assert($res === true, "saveKuboDetails should return true");

$afterEdit = InventoryStore::getKuboById(1);
echo "After Edit Kubo #1: " . $afterEdit['name'] . " | Price: ₱" . $afterEdit['price'] . " | Badges: " . $afterEdit['badges'] . "\n";
if (floatval($afterEdit['price']) == 3750.00 && strpos($afterEdit['badges'], 'Beachfront') !== false) {
    echo "  [PASS] Edit details succeeded in memory/cache/DB!\n";
} else {
    echo "  [FAIL] Edit details did not match expected price or badges!\n";
    exit(1);
}

// 3. Test Add New Kubo Unit
$newData = [
    'name' => 'Sunset Villa Kubo #99',
    'category_id' => 3,
    'price' => 4500.00,
    'capacity' => 8,
    'status' => 'available',
    'badges' => 'Sunset View, Jacuzzi, Balcony',
    'description' => 'Brand new luxurious sunset villa kubo unit.',
    'image_url' => 'https://images.unsplash.com/photo-1590490360182-c33d57733427?auto=format&fit=crop&w=600&q=80'
];
$newId = InventoryStore::addKubo($newData);
echo "Added new Kubo with ID: {$newId}\n";
$fetchNew = InventoryStore::getKuboById($newId);
if ($fetchNew && $fetchNew['name'] === 'Sunset Villa Kubo #99' && floatval($fetchNew['price']) == 4500.00) {
    echo "  [PASS] Add new kubo unit succeeded!\n";
} else {
    echo "  [FAIL] Could not find newly added kubo unit!\n";
    exit(1);
}

// 4. Test Delete Kubo Unit
$delRes = InventoryStore::deleteKubo($newId);
$fetchDeleted = InventoryStore::getKuboById($newId);
if (!$fetchDeleted || intval($fetchDeleted['kubo_id']) !== $newId) {
    echo "  [PASS] Delete kubo unit succeeded! (ID {$newId} no longer in inventory)\n";
} else {
    echo "  [FAIL] Kubo unit still exists after deletion!\n";
    exit(1);
}

// Restore Kubo #1 original price (3500)
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

echo "\n=== Direct InventoryStore Tests Passed Successfully! ===\n";
