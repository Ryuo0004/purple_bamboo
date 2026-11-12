<?php
header('Content-Type: text/plain');
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/inventory_store.php';

echo "=== TEST 1: ADD ACTIVITY WITHOUT DURATION ===\n";
$newActId = InventoryStore::addActivity([
    'activity_name' => 'Open Water Floating Lounge',
    'category' => 'pool',
    'price' => 300.00,
    'duration_minutes' => '', // No duration provided!
    'description' => 'Unlimited lounge access during day hours.',
    'is_available' => 1
]);
echo "Added activity ID: $newActId\n";

$act = InventoryStore::getActivityById($newActId);
echo "duration_minutes: " . var_export($act['duration_minutes'], true) . "\n";
echo "duration: " . var_export($act['duration'], true) . "\n";
echo "activity_type: " . var_export($act['activity_type'], true) . "\n";

assert($act['duration_minutes'] === null, "duration_minutes should be null");
assert($act['duration'] === 'No limit', "duration should be 'No limit'");
assert($act['activity_type'] === 'full_day', "activity_type should be 'full_day'");
echo "[PASS] Test 1: Added activity without duration successfully!\n\n";

echo "=== TEST 2: EDIT ACTIVITY TO ADD DURATION ===\n";
InventoryStore::saveActivityDetails($newActId, [
    'activity_name' => 'Open Water Floating Lounge',
    'category' => 'pool',
    'price' => 350.00,
    'duration_minutes' => '75', // 75 mins
    'description' => 'Now limited to 75 mins.',
    'is_available' => 1
]);

$actAfterEdit = InventoryStore::getActivityById($newActId);
echo "duration_minutes: " . var_export($actAfterEdit['duration_minutes'], true) . "\n";
echo "duration: " . var_export($actAfterEdit['duration'], true) . "\n";
echo "activity_type: " . var_export($actAfterEdit['activity_type'], true) . "\n";

assert($actAfterEdit['duration_minutes'] === 75, "duration_minutes should be 75");
assert($actAfterEdit['duration'] === '75 Mins', "duration should be '75 Mins'");
assert($actAfterEdit['activity_type'] === 'time_based', "activity_type should be 'time_based'");
echo "[PASS] Test 2: Updated activity with duration successfully!\n\n";

echo "=== TEST 3: EDIT ACTIVITY TO REMOVE DURATION (BLANK INPUT) ===\n";
InventoryStore::saveActivityDetails($newActId, [
    'activity_name' => 'Open Water Floating Lounge',
    'category' => 'pool',
    'price' => 350.00,
    'duration_minutes' => '', // Cleared duration!
    'description' => 'Unlimited lounge access again.',
    'is_available' => 1
]);

$actCleared = InventoryStore::getActivityById($newActId);
echo "duration_minutes: " . var_export($actCleared['duration_minutes'], true) . "\n";
echo "duration: " . var_export($actCleared['duration'], true) . "\n";
echo "activity_type: " . var_export($actCleared['activity_type'], true) . "\n";

assert($actCleared['duration_minutes'] === null, "duration_minutes should be null");
assert($actCleared['duration'] === 'No limit', "duration should be 'No limit'");
assert($actCleared['activity_type'] === 'full_day', "activity_type should be 'full_day'");
echo "[PASS] Test 3: Removed duration from activity successfully!\n\n";

// Cleanup test record
InventoryStore::deleteActivity($newActId);
echo "=== ALL OPTIONAL DURATION TESTS PASSED! ===\n";
