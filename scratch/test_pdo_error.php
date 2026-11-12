<?php
require __DIR__ . '/../database.php';
$j = json_decode(file_get_contents(__DIR__ . '/../data/bookings.json'), true);
$first = $j[0]; // PBR-2026-4027
echo "Testing corrected insert for {$first['id']}...\n";

try {
    $stmt = $pdo->prepare("
        INSERT INTO bookings (id, guest_name, guest_email, guest_phone, profile_id, user_id, type, item_id, item_name, check_in, check_out, time_slot, pax, num_guests, total_amount, gcash_ref, payment_proof, payment_status, booking_status, special_notes, guest_list, created_at)
        VALUES (:id, :guest_name, :guest_email, :guest_phone, :profile_id, :user_id, :type, :item_id, :item_name, :check_in, :check_out, :time_slot, :pax, :num_guests, :total_amount, :gcash_ref, :payment_proof, :payment_status, :booking_status, :special_notes, :guest_list, :created_at)
    ");
    $stmt->execute([
        ':id' => $first['id'],
        ':guest_name' => $first['guest_name'],
        ':guest_email' => $first['guest_email'],
        ':guest_phone' => $first['guest_phone'],
        ':profile_id' => $first['profile_id'],
        ':user_id' => $first['user_id'],
        ':type' => $first['type'],
        ':item_id' => $first['item_id'],
        ':item_name' => $first['item_name'],
        ':check_in' => $first['check_in'],
        ':check_out' => !empty($first['check_out']) ? $first['check_out'] : null,
        ':time_slot' => $first['time_slot'],
        ':pax' => $first['pax'],
        ':num_guests' => $first['pax'] ?? 1,
        ':total_amount' => $first['total_amount'],
        ':gcash_ref' => $first['gcash_ref'],
        ':payment_proof' => $first['payment_proof'],
        ':payment_status' => $first['payment_status'],
        ':booking_status' => $first['booking_status'],
        ':special_notes' => $first['special_notes'],
        ':guest_list' => $first['guest_list'],
        ':created_at' => $first['created_at']
    ]);
    echo "SUCCESSFULLY INSERTED {$first['id']}!\n";
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}
