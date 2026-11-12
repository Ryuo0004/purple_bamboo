<?php
require_once __DIR__ . '/../booking_store.php';
require_once __DIR__ . '/../inventory_store.php';

$bookings = BookingStore::getAllBookings();
echo "Total bookings: " . count($bookings) . "\n";

$types = [];
$sample = [];
foreach ($bookings as $b) {
    $t = $b['type'] ?? 'empty';
    $types[$t] = ($types[$t] ?? 0) + 1;
    if (count($sample) < 10) {
        $sample[] = [
            'id' => $b['id'] ?? '',
            'type' => $b['type'] ?? '',
            'item_name' => $b['item_name'] ?? '',
            'item_id' => $b['item_id'] ?? '',
            'check_in' => $b['check_in'] ?? '',
            'check_out' => $b['check_out'] ?? '',
            'status' => $b['booking_status'] ?? ''
        ];
    }
}

echo "Types breakdown:\n";
print_r($types);

echo "First 10 bookings:\n";
print_r($sample);
