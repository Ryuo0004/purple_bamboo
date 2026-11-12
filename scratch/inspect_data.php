<?php
require_once __DIR__ . '/../database.php';
header('Content-Type: text/plain');

echo "=== USERS ===\n";
$users = $pdo->query("SELECT id, email, first_name, last_name, role, status FROM users")->fetchAll();
foreach ($users as $u) {
    echo "User #{$u['id']}: {$u['first_name']} {$u['last_name']} ({$u['email']}) - Role: {$u['role']} - Status: {$u['status']}\n";
}

echo "\n=== BOOKINGS ===\n";
$bookings = $pdo->query("SELECT id, user_id, guest_name, guest_email, item_name, check_in, payment_status, booking_status FROM bookings")->fetchAll();
foreach ($bookings as $b) {
    echo "Booking #{$b['id']}: Guest: {$b['guest_name']} ({$b['guest_email']}), User ID: " . ($b['user_id'] ?? 'NULL') . ", Item: {$b['item_name']}, In: {$b['check_in']}, Pay: {$b['payment_status']}, Status: {$b['booking_status']}\n";
}

echo "\n=== DINING ORDERS ===\n";
$dining = $pdo->query("SELECT id, user_id, guest_name, guest_email, total_amount, order_status, created_at FROM dining_orders")->fetchAll();
echo "Total dining orders in DB: " . count($dining) . "\n";
foreach ($dining as $d) {
    echo "Order #{$d['id']}: {$d['guest_name']} ({$d['guest_email']}) - ₱{$d['total_amount']} - Status: {$d['order_status']}\n";
}
