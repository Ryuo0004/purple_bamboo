<?php
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../dining_store.php';
header('Content-Type: text/plain');

$testOrder = DiningStore::createOrder([
    'guest_name' => 'Kent Harold Delios Rollo',
    'guest_email' => 'rollokentharold@gmail.com',
    'guest_phone' => '09171234567',
    'kubo_location' => 'Mahogany Bamboo Kubo #1',
    'items' => [
        ['id' => 1, 'name' => 'Sizzling Garlic Butter Tiger Prawns', 'price' => 680, 'qty' => 2],
        ['id' => 6, 'name' => 'Purple Velvet Ube Cream Shake', 'price' => 220, 'qty' => 2]
    ],
    'total_amount' => 1800.00,
    'guest_allergies' => ['Seafood / Shellfish', 'Dairy'],
    'allergy_notes' => 'Please make sure food is prepared with separate tongs',
    'requested_serve_time' => 'ASAP (15-20 Mins)',
    'kitchen_notes' => 'Guest is in Kubo #1'
]);

echo "Created Order Ref: " . $testOrder['id'] . "\n";

$orders = DiningStore::getAllOrders();
echo "Total orders retrieved by DiningStore::getAllOrders(): " . count($orders) . "\n";
foreach ($orders as $o) {
    echo "- Order #{$o['id']} by {$o['guest_name']} ({$o['kubo_location']}) - Total: ₱{$o['total_amount']} - Status: {$o['order_status']}\n";
}
