<?php
require_once __DIR__ . '/../booking_store.php';

echo "Testing Cancellation with 'Others' reason and custom specified reason...\n";

// 1. Create temporary test booking
$testId = 'PBR-TEST-OTH-' . rand(1000, 9999);
$booking = BookingStore::createBooking([
    'id' => $testId,
    'guest_name' => 'Test Guest Other',
    'guest_email' => 'testother@example.com',
    'guest_phone' => '09171234567',
    'type' => 'house',
    'item_id' => 1,
    'item_name' => 'Beach Kubo',
    'check_in' => '2028-11-01',
    'check_out' => '2028-11-03',
    'time_slot' => '2 Night(s) Stay',
    'pax' => 2,
    'total_amount' => 7000.00,
    'payment_status' => 'pending',
    'booking_status' => 'pending'
]);

assert(!empty($booking['id']), "Booking should be created");
echo "[PASS] Created test booking #{$testId}\n";

// 2. Simulate cancellation when reason is 'Others' with custom reason
$submittedReason = 'Others';
$customReason = 'Guest had emergency flight reschedule to next month';

$finalReason = ($submittedReason === 'Others' && !empty($customReason)) ? $customReason : $submittedReason;
BookingStore::updateBookingStatus($testId, 'cancelled', 'unpaid', null, null, "Cancelled: " . $finalReason);

$updated = BookingStore::getBookingById($testId);
assert($updated['booking_status'] === 'cancelled', "Status should be cancelled");
assert(strpos($updated['special_notes'], 'Cancelled: Guest had emergency flight reschedule') !== false, "Special notes should contain custom reason");
echo "[PASS] Cancellation note contains custom specified reason: '{$updated['special_notes']}'\n";

// 3. Clean up
BookingStore::deleteBooking($testId);
echo "[PASS] Cleaned up test booking\n";
echo "ALL TESTS PASSED!\n";
