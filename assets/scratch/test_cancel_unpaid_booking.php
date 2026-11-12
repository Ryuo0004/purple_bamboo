<?php
require_once __DIR__ . '/../booking_store.php';

echo "=======================================================\n";
echo "=== 1. TESTING UNPAID BOOKING CREATION & CONFLICT   ===\n";
echo "=======================================================\n";

$testRef = 'PBR-TEST-' . rand(1000, 9999);
$kuboId = 1;
$checkIn = '2028-05-10';
$checkOut = '2028-05-13';

// Ensure room is available before test
$availBefore = BookingStore::isItemAvailable($kuboId, $checkIn, $checkOut, 'house');
assert($availBefore === true, "Room should be available before test booking");
echo "  [PASS] Accommodation #{$kuboId} is available for {$checkIn} to {$checkOut}.\n";

// Create unpaid house booking
$booking = BookingStore::createBooking([
    'id' => $testRef,
    'guest_name' => 'Carlos Mendoza',
    'guest_email' => 'carlos@test.com',
    'guest_phone' => '0917-555-1234',
    'type' => 'house',
    'item_id' => $kuboId,
    'item_name' => 'Beach Kubo',
    'check_in' => $checkIn,
    'check_out' => $checkOut,
    'time_slot' => 'Overnight Stay',
    'pax' => 3,
    'total_amount' => 10500.00,
    'gcash_ref' => null,
    'payment_proof' => null,
    'payment_status' => 'pending',
    'booking_status' => 'pending',
    'special_notes' => 'Awaiting payment confirmation.'
]);

assert(!empty($booking['id']), "Booking should be created");
echo "  [PASS] Created unpaid test reservation #{$testRef}.\n";

// Verify room is now blocked
$availDuring = BookingStore::isItemAvailable($kuboId, $checkIn, $checkOut, 'house');
assert($availDuring === false, "Room should now be BLOCKED / unavailable during reservation dates");
echo "  [PASS] Room is correctly BLOCKED by the pending reservation.\n";

echo "\n=======================================================\n";
echo "=== 2. TESTING CANCELLATION OF UNPAID BOOKING       ===\n";
echo "=======================================================\n";

// Cancel the unpaid booking
$cancelReason = "Cancelled: No payment received within booking window";
BookingStore::updateBookingStatus($testRef, 'cancelled', 'unpaid', null, null, $cancelReason);

$cancelled = BookingStore::getBookingById($testRef);
assert($cancelled['booking_status'] === 'cancelled', "Booking status must be 'cancelled'");
assert($cancelled['payment_status'] === 'unpaid', "Payment status must be 'unpaid'");
assert(strpos($cancelled['special_notes'], 'Cancelled') !== false, "Special notes must contain cancellation reason");
echo "  [PASS] Booking #{$testRef} successfully marked as cancelled with unpaid status.\n";

// Verify room is now RELEASED and available again!
$availAfter = BookingStore::isItemAvailable($kuboId, $checkIn, $checkOut, 'house');
assert($availAfter === true, "Room must be RELEASED and available for booking after cancellation!");
echo "  [PASS] Room #{$kuboId} has been successfully RELEASED back to resort inventory!\n";

echo "\n=======================================================\n";
echo "=== 3. TESTING PAYMENT STATUS FILTERING             ===\n";
echo "=======================================================\n";

$unpaidList = BookingStore::getAllBookings(['payment_status' => 'unpaid']);
$foundInUnpaid = false;
foreach ($unpaidList as $ub) {
    if ($ub['id'] === $testRef) {
        $foundInUnpaid = true;
        break;
    }
}
assert($foundInUnpaid === true, "Cancelled unpaid booking must appear in 'unpaid' filter list");
echo "  [PASS] Payment status filter 'unpaid' accurately captures unpaid reservations!\n";

// Clean up test booking
BookingStore::deleteBooking($testRef);
echo "  [PASS] Cleaned up test record #{$testRef}.\n";

echo "\n=======================================================\n";
echo "=== ALL CANCELLATION & ROOM RELEASE TESTS PASSED!   ===\n";
echo "=======================================================\n";
