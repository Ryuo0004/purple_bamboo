<?php
require_once __DIR__ . '/../booking_store.php';
require_once __DIR__ . '/../inventory_store.php';

$all_bookings = BookingStore::getAllBookings();
echo "Total all_bookings: " . count($all_bookings) . "\n";
foreach ($all_bookings as $b) {
    echo "  Booking {$b['id']}: in={$b['check_in']}, out={$b['check_out']}, status={$b['booking_status']}\n";
}

$_GET['month'] = 10;
$_GET['year'] = 2026;
ob_start();
include __DIR__ . '/../calendar.php';
ob_end_clean();

echo "Calendar map keys: " . implode(', ', array_keys($calendar_map)) . "\n";
if (isset($calendar_map['2026-10-07'])) {
    echo "2026-10-07 count: " . count($calendar_map['2026-10-07']) . "\n";
    print_r($calendar_map['2026-10-07']);
} else {
    echo "2026-10-07 NOT in calendar_map!\n";
}
?>
