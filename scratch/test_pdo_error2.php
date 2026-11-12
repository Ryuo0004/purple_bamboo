<?php
require __DIR__ . '/../database.php';
$j = json_decode(file_get_contents(__DIR__ . '/../data/bookings.json'), true);
$second = $j[1]; // PBR-2026-7747
echo "Testing insert without num_guests for {$second['id']}...\n";

try {
    $stmt = $pdo->prepare("
        INSERT INTO bookings (id, guest_name, guest_email, guest_phone, profile_id, user_id, type, item_id, item_name, check_in, check_out, time_slot, start_time, end_time, pax, total_amount, gcash_ref, payment_proof, payment_status, booking_status, special_notes, guest_list, created_at)
        VALUES (:id, :guest_name, :guest_email, :guest_phone, :profile_id, :user_id, :type, :item_id, :item_name, :check_in, :check_out, :time_slot, :start_time, :end_time, :pax, :total_amount, :gcash_ref, :payment_proof, :payment_status, :booking_status, :special_notes, :guest_list, :created_at)
    ");
    $stmt->execute([
        ':id' => $second['id'],
        ':guest_name' => $second['guest_name'],
        ':guest_email' => $second['guest_email'],
        ':guest_phone' => $second['guest_phone'],
        ':profile_id' => $second['profile_id'],
        ':user_id' => $second['user_id'],
        ':type' => $second['type'],
        ':item_id' => $second['item_id'],
        ':item_name' => $second['item_name'],
        ':check_in' => $second['check_in'],
        ':check_out' => !empty($second['check_out']) ? $second['check_out'] : null,
        ':time_slot' => $second['time_slot'],
        ':start_time' => null,
        ':end_time' => null,
        ':pax' => $second['pax'],
        ':total_amount' => $second['total_amount'],
        ':gcash_ref' => $second['gcash_ref'],
        ':payment_proof' => $second['payment_proof'],
        ':payment_status' => $second['payment_status'],
        ':booking_status' => $second['booking_status'],
        ':special_notes' => $second['special_notes'],
        ':guest_list' => $second['guest_list'],
        ':created_at' => $second['created_at']
    ]);
    echo "SUCCESS!\n";
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}
