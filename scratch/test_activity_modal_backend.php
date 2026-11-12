<?php
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../inventory_store.php';

echo "=======================================================\n";
echo "=== TESTING ACTIVITIES TOTAL_UNITS, AVAILABLE_UNITS, ===\n";
echo "=== AND EQUIPMENT BACKEND CRUD                     ===\n";
echo "=======================================================\n";

// 1. Add activity with total_units, available_units, equipment
$testData = [
    'activity_name' => 'Test Kayak & Paddle ' . time(),
    'category' => 'pool',
    'price' => '500.00',
    'duration_minutes' => '60',
    'total_units' => 6,
    'available_units' => 4,
    'equipment' => json_encode([
        ['name' => 'Kayak', 'qty' => 6, 'icon' => 'fa-sailboat'],
        ['name' => 'Paddle', 'qty' => 6, 'icon' => 'fa-water'],
        ['name' => 'Life Jacket', 'qty' => 10, 'icon' => 'fa-vest']
    ]),
    'is_available' => 1,
    'description' => 'Transparent kayak equipment for scenic pool and lagoon exploration.'
];

$id = InventoryStore::addActivity($testData);
echo "1. Created Activity ID: {$id}\n";
assert($id > 0, "Activity should have a valid inserted ID");

$fetched = InventoryStore::getActivityById($id);
echo "2. Fetched Activity: " . $fetched['activity_name'] . "\n";
echo "   Total Units: " . $fetched['total_units'] . " (Expected: 6)\n";
echo "   Available Units: " . $fetched['available_units'] . " (Expected: 4)\n";
echo "   Equipment: " . $fetched['equipment'] . "\n";
assert(intval($fetched['total_units']) === 6, "Total units must be 6");
assert(intval($fetched['available_units']) === 4, "Available units must be 4");
assert(strpos($fetched['equipment'], 'Kayak') !== false, "Equipment must contain Kayak");

// 3. Update activity
$updateData = [
    'activity_name' => 'Updated Kayak Adventure',
    'category' => 'outdoor',
    'price' => '550.00',
    'duration_minutes' => '90',
    'total_units' => 8,
    'available_units' => 5,
    'equipment' => json_encode([
        ['name' => 'Pro Kayak', 'qty' => 8, 'icon' => 'fa-sailboat'],
        ['name' => 'Carbon Paddle', 'qty' => 8, 'icon' => 'fa-water'],
        ['name' => 'Life Jacket', 'qty' => 12, 'icon' => 'fa-vest']
    ]),
    'is_available' => 1,
    'description' => 'Updated kayak description.'
];

$upRes = InventoryStore::saveActivityDetails($id, $updateData);
assert($upRes === true, "saveActivityDetails should return true");

$fetchedUpdated = InventoryStore::getActivityById($id);
echo "4. After Update Activity:\n";
echo "   Name: " . $fetchedUpdated['activity_name'] . "\n";
echo "   Price: " . $fetchedUpdated['price'] . "\n";
echo "   Total Units: " . $fetchedUpdated['total_units'] . " (Expected: 8)\n";
echo "   Available Units: " . $fetchedUpdated['available_units'] . " (Expected: 5)\n";
assert(intval($fetchedUpdated['total_units']) === 8, "Total units must be 8");
assert(intval($fetchedUpdated['available_units']) === 5, "Available units must be 5");
assert(strpos($fetchedUpdated['equipment'], 'Pro Kayak') !== false, "Equipment must contain Pro Kayak");

// 5. Clean up
InventoryStore::deleteActivity($id);
echo "5. Successfully deleted test activity.\n";

echo "ALL TESTS PASSED SUCCESSFULLY!\n";
?>
