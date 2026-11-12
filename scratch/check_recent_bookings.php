<?php
require_once __DIR__ . '/../database.php';
$stmt = $pdo->query("SELECT * FROM bookings ORDER BY created_at DESC LIMIT 5");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "ID: " . $r['id'] . "\n";
    echo "Type: " . $r['type'] . "\n";
    echo "Guest: " . $r['guest_name'] . " (" . $r['guest_email'] . ")\n";
    echo "Item: " . $r['item_name'] . "\n";
    echo "Booking Status: " . $r['booking_status'] . "\n";
    echo "Payment Status: " . $r['payment_status'] . "\n";
    echo "Created At: " . $r['created_at'] . "\n";
    echo "---------------------------------\n";
}
