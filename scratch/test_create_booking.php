<?php
require __DIR__ . '/../booking_store.php';

$testRef = 'PBR-TEST-' . rand(1000, 9999);
$res = BookingStore::createBooking([
    'id' => $testRef,
    'guest_name' => 'Automated Test Guest',
    'guest_email' => 'autotest@example.com',
    'guest_phone' => '09123456789',
    'type' => 'house',
    'item_id' => 1,
    'item_name' => 'Royal Purple Pavilion #3',
    'check_in' => date('Y-m-d 14:00:00'),
    'check_out' => date('Y-m-d 12:00:00', strtotime('+1 day')),
    'time_slot' => '1 Night(s) Stay',
    'pax' => 2,
    'total_amount' => 4500,
    'payment_status' => 'proof_submitted',
    'booking_status' => 'pending',
    'gcash_ref' => '22222222222222222222',
    'payment_proof' => 'uploads/receipts/receipt_test.jpg'
]);

require_once __DIR__ . '/../database.php';
global $pdo;
$stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
$stmt->execute([$testRef]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    echo "SUCCESS: Booking created and found in MySQL DB: {$row['id']} | {$row['guest_name']}\n";
    // Clean up test booking
    $pdo->prepare("DELETE FROM bookings WHERE id = ?")->execute([$testRef]);
    echo "Cleaned up test record.\n";
} else {
    echo "FAILED: Booking NOT found in MySQL DB!\n";
}
