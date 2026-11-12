<?php
header('Content-Type: text/plain; charset=utf-8');
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/dining_store.php';

echo "========================================================================\n";
echo "           TEST TRUNCATE DINING ORDERS BEHAVIOR VERIFICATION             \n";
echo "========================================================================\n";

if (!isset($pdo) || !$pdo instanceof PDO) {
    echo "ERROR: Database not connected.\n";
    exit;
}

// 1. Truncate table in MySQL
$pdo->exec("TRUNCATE TABLE dining_orders");
echo "[1] Executed TRUNCATE TABLE dining_orders in MySQL.\n";

// 2. Fetch all orders through DiningStore
$ordersAfterTruncate = DiningStore::getAllOrders();
$countAfterTruncate = count($ordersAfterTruncate);
echo "[2] Orders returned by DiningStore::getAllOrders(): $countAfterTruncate\n";

if ($countAfterTruncate === 0) {
    echo ">>> SUCCESS: Table stayed 100% truncated! No phantom records restored.\n";
} else {
    echo ">>> FAILURE: Phantom records still appeared! Count = $countAfterTruncate\n";
}

// 3. Create a clean new order
$newOrder = DiningStore::createOrder([
    'user_id' => 1,
    'guest_name' => "Fresh Test Diner",
    'guest_email' => "fresh_diner@test.com",
    'guest_phone' => '09123456789',
    'kubo_location' => 'Poolside Table #1',
    'items' => [['id' => 1, 'name' => 'Sizzling Garlic Butter Tiger Prawns', 'price' => 680, 'qty' => 1]],
    'total_amount' => 680.00
]);

echo "[3] Created fresh order ID: " . ($newOrder['id'] ?? 'none') . "\n";

// 4. Verify orders list now has exactly 1 order
$ordersAfterInsert = DiningStore::getAllOrders();
echo "[4] Orders count after 1 new order: " . count($ordersAfterInsert) . "\n";

if (count($ordersAfterInsert) === 1) {
    echo ">>> SUCCESS: Exactly 1 fresh order present in MySQL!\n";
} else {
    echo ">>> FAILURE: Unexpected order count!\n";
}

// 5. Clean up test order
if (!empty($newOrder['id'])) {
    $pdo->prepare("DELETE FROM dining_orders WHERE id = ?")->execute([$newOrder['id']]);
    echo "[5] Cleaned up test order.\n";
}

$finalCount = count(DiningStore::getAllOrders());
echo "[6] Final count: $finalCount orders.\n";
echo "========================================================================\n";
