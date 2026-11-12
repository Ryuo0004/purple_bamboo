<?php
require_once __DIR__ . '/../dining_store.php';

$test_order_data = [
    'user_id' => 217,
    'guest_name' => 'Test User',
    'guest_email' => 'testuser@example.com',
    'guest_phone' => '09123456789',
    'kubo_location' => 'Beach Kubo',
    'items' => [
        ['id' => 1, 'name' => 'Sizzling Garlic Butter Tiger Prawns', 'price' => 680, 'qty' => 1]
    ],
    'total_amount' => 680.00,
    'guest_allergies' => [],
    'allergy_notes' => '',
    'requested_serve_time' => 'ASAP (15-20 Mins)',
    'kitchen_notes' => 'Test note'
];

$res = DiningStore::createOrder($test_order_data);
echo "Order created:\n";
print_r($res);

$all = DiningStore::getAllOrders();
echo "Total orders in DiningStore: " . count($all) . "\n";
$found = false;
foreach ($all as $o) {
    if ($o['id'] === $res['id']) {
        $found = true;
        echo "Found new order in getAllOrders! ID: " . $o['id'] . "\n";
    }
}
if (!$found) {
    echo "ERROR: Order was NOT found in getAllOrders!\n";
}
