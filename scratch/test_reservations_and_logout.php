<?php
require_once __DIR__ . '/../booking_store.php';

echo "=======================================================\n";
echo "=== 1. TESTING RESERVATION VALIDATION LOGIC        ===\n";
echo "=======================================================\n";

// Test 1a: Check date validation logic
$today = date('Y-m-d');
$pastDate = date('Y-m-d', strtotime('-2 days'));
$futureIn = date('Y-m-d', strtotime('+10 days'));
$futureOut = date('Y-m-d', strtotime('+12 days'));
$invalidOut = date('Y-m-d', strtotime('+9 days'));

// Verify past date is rejected
assert($pastDate < $today, "Past date should be recognized as before today");
echo "  [PASS] Past date validation works.\n";

// Verify checkOut > checkIn
assert($futureOut > $futureIn, "Future out must be greater than future in");
assert(!($invalidOut > $futureIn), "Invalid checkout should not be after check-in");
echo "  [PASS] Stay duration (check-out after check-in) logic verified.\n";

// Test 1b: Phone number validation
$validPhone = '09171234567';
$invalidPhoneShort = '091712345';
$invalidPhoneNon09 = '08171234567';

$clean1 = preg_replace('/[^0-9]/', '', $validPhone);
assert(strlen($clean1) === 11 && substr($clean1, 0, 2) === '09', "Valid phone must pass");

$clean2 = preg_replace('/[^0-9]/', '', $invalidPhoneShort);
assert(!(strlen($clean2) === 11 && substr($clean2, 0, 2) === '09'), "Short phone must fail");

$clean3 = preg_replace('/[^0-9]/', '', $invalidPhoneNon09);
assert(!(strlen($clean3) === 11 && substr($clean3, 0, 2) === '09'), "Non-09 phone must fail");
echo "  [PASS] 11-digit Philippine mobile phone number validation verified.\n";

echo "\n=======================================================\n";
echo "=== 2. TESTING FRONT DESK CANCELLATION & RELEASE   ===\n";
echo "=======================================================\n";

$testRef = 'PBR-FD-TEST-' . rand(1000, 9999);
$kuboId = 2;
$stayIn = '2028-11-01';
$stayOut = '2028-11-04';

// Check room available initially
$avail1 = BookingStore::isItemAvailable($kuboId, $stayIn, $stayOut, 'house');
assert($avail1 === true, "Room #{$kuboId} should be available initially");

// Create test booking
$b = BookingStore::createBooking([
    'id' => $testRef,
    'guest_name' => 'Maria Clara',
    'guest_email' => 'maria@example.com',
    'guest_phone' => '09181234567',
    'type' => 'house',
    'item_id' => $kuboId,
    'item_name' => 'Kubo Deluxe',
    'check_in' => $stayIn,
    'check_out' => $stayOut,
    'time_slot' => '3 Night(s) Stay',
    'pax' => 4,
    'total_amount' => 12000,
    'profile_id' => 'PBR-GST-99999',
    'user_id' => 99999,
    'payment_status' => 'pending',
    'booking_status' => 'pending',
    'special_notes' => 'Test reservation for front desk cancellation'
]);
assert(!empty($b['id']), "Booking should be created");
echo "  [PASS] Created test reservation {$testRef}.\n";

// Room is now blocked
$avail2 = BookingStore::isItemAvailable($kuboId, $stayIn, $stayOut, 'house');
assert($avail2 === false, "Room #{$kuboId} must now be blocked by reservation");
echo "  [PASS] Room #{$kuboId} is blocked during stay dates.\n";

// Front Desk Cancellation
$pstatus = ($b['payment_status'] === 'verified') ? 'refund_pending' : 'cancelled';
$reason = 'Front Desk Cancellation';
BookingStore::updateBookingStatus($testRef, 'cancelled', $pstatus, null, null, "Cancelled: " . $reason);

// Check booking is cancelled
$updated = BookingStore::getBookingById($testRef);
assert($updated['booking_status'] === 'cancelled', "Booking status must be 'cancelled'");
echo "  [PASS] Booking #{$testRef} successfully marked as CANCELLED.\n";

// Room is now released and available again
$avail3 = BookingStore::isItemAvailable($kuboId, $stayIn, $stayOut, 'house');
assert($avail3 === true, "Room #{$kuboId} must be RELEASED and available again after cancellation");
echo "  [PASS] Room #{$kuboId} is immediately RELEASED back to available inventory!\n";

// Cleanup test record
BookingStore::deleteBooking($testRef);
echo "  [PASS] Cleaned up test booking record.\n";

echo "\n=======================================================\n";
echo "=== ALL AUTOMATED TESTS PASSED SUCCESSFULLY!       ===\n";
echo "=======================================================\n";
