<?php
require_once __DIR__ . '/../booking_store.php';
require_once __DIR__ . '/../dining_store.php';

$all_b = BookingStore::getAllBookings();
echo "Total house/act bookings in DB: " . count($all_b) . "\n";
$active_b = array_filter($all_b, function($b) {
    $st = strtolower($b['booking_status'] ?? '');
    return !in_array($st, ['checked_out', 'checkedout', 'completed', 'cancelled', 'rejected', 'forfeited']);
});
echo "Active house/act bookings: " . count($active_b) . "\n";

$dining = DiningStore::getAllOrders();
echo "Total dining orders: " . count($dining) . "\n";
$active_d = array_filter($dining, function($d) {
    $st = strtolower($d['order_status'] ?? '');
    return !in_array($st, ['served', 'completed', 'cancelled', 'canceled']);
});
echo "Active dining orders: " . count($active_d) . "\n";
