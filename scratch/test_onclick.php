<?php
require __DIR__ . '/../booking_store.php';

$all_bookings = BookingStore::getAllBookings();
$all_gcash_bookings = array_filter($all_bookings, function($b) {
    $pstatus = strtolower($b['payment_status'] ?? '');
    $proof   = $b['payment_proof'] ?? '';
    $gcash   = trim($b['gcash_ref'] ?? '');
    if (in_array($pstatus, ['proof_submitted', 'verified', 'rejected'])) return true;
    if (!empty($gcash) && !empty($proof) && strpos($proof, 'sample_receipt.jpg') === false) return true;
    return false;
});

echo "Total: " . count($all_gcash_bookings) . "\n";
foreach ($all_gcash_bookings as $agb) {
    $id = htmlspecialchars(addslashes($agb['id']));
    $name = htmlspecialchars(addslashes($agb['guest_name']));
    $amt = number_format(floatval($agb['total_amount']));
    $onclick = "event.stopPropagation(); openRejectModal('{$id}', '{$name}', '{$amt}')";
    // Check if quotes break onclick attribute
    if (strpos($onclick, '"') !== false) {
        echo "MALFORMED ONCLICK: $onclick\n";
    }
}
echo "Check done.\n";
