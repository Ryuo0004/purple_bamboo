<?php
require __DIR__ . '/../booking_store.php';

$all_bookings = BookingStore::getAllBookings();
$all_gcash_bookings = array_filter($all_bookings, function($b) {
    $pstatus = strtolower($b['payment_status'] ?? '');
    $proof   = $b['payment_proof'] ?? '';
    $ref     = $b['gcash_ref'] ?? '';
    $is_sample = (strpos($proof, 'sample_receipt.jpg') !== false);

    // Show if explicitly in a verification workflow state
    if (in_array($pstatus, ['proof_submitted', 'verified', 'rejected'])) {
        return true;
    }
    // Or if guest supplied both a reference number AND a real receipt upload
    if (!empty($ref) && !empty($proof) && !$is_sample) {
        return true;
    }
    return false;
});

echo "Total matching bookings in queue: " . count($all_gcash_bookings) . "\n";
$found = false;
foreach ($all_gcash_bookings as $b) {
    if ($b['id'] === 'PBR-2026-4027') {
        echo "FOUND PBR-2026-4027!\n";
        print_r([
            'id' => $b['id'],
            'guest_name' => $b['guest_name'],
            'payment_status' => $b['payment_status'],
            'gcash_ref' => $b['gcash_ref'],
            'payment_proof' => $b['payment_proof']
        ]);
        $found = true;
        break;
    }
}
if (!$found) {
    echo "NOT FOUND!\n";
}
