<?php
require __DIR__ . '/../database.php';
require __DIR__ . '/../booking_store.php';

$json_file = __DIR__ . '/../data/bookings.json';
$json_bookings = json_decode(file_get_contents($json_file), true) ?? [];

echo "Total bookings in JSON: " . count($json_bookings) . "\n";

$synced = 0;
foreach ($json_bookings as $b) {
    $id = $b['id'] ?? '';
    if (empty($id)) continue;
    
    $check = $pdo->prepare("SELECT id, payment_status, gcash_ref, payment_proof FROM bookings WHERE id = ?");
    $check->execute([$id]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    
    if (!$existing) {
        $stmt = $pdo->prepare("
            INSERT INTO bookings (id, guest_name, guest_email, guest_phone, profile_id, user_id, type, item_id, item_name, check_in, check_out, time_slot, start_time, end_time, pax, num_guests, total_amount, gcash_ref, payment_proof, payment_status, booking_status, special_notes, guest_list, created_at)
            VALUES (:id, :guest_name, :guest_email, :guest_phone, :profile_id, :user_id, :type, :item_id, :item_name, :check_in, :check_out, :time_slot, :start_time, :end_time, :pax, :num_guests, :total_amount, :gcash_ref, :payment_proof, :payment_status, :booking_status, :special_notes, :guest_list, :created_at)
        ");
        $stmt->execute([
            ':id' => $b['id'],
            ':guest_name' => $b['guest_name'] ?? 'Guest',
            ':guest_email' => $b['guest_email'] ?? '',
            ':guest_phone' => $b['guest_phone'] ?? '',
            ':profile_id' => $b['profile_id'] ?? null,
            ':user_id' => $b['user_id'] ?? null,
            ':type' => $b['type'] ?? 'house',
            ':item_id' => $b['item_id'] ?? 1,
            ':item_name' => $b['item_name'] ?? '',
            ':check_in' => $b['check_in'] ?? date('Y-m-d H:i:s'),
            ':check_out' => !empty($b['check_out']) ? $b['check_out'] : null,
            ':time_slot' => $b['time_slot'] ?? '',
            ':start_time' => $b['start_time'] ?? null,
            ':end_time' => $b['end_time'] ?? null,
            ':pax' => $b['pax'] ?? 1,
            ':num_guests' => $b['pax'] ?? 1,
            ':total_amount' => $b['total_amount'] ?? 0,
            ':gcash_ref' => $b['gcash_ref'] ?? null,
            ':payment_proof' => $b['payment_proof'] ?? null,
            ':payment_status' => $b['payment_status'] ?? 'pending',
            ':booking_status' => $b['booking_status'] ?? 'pending',
            ':special_notes' => $b['special_notes'] ?? '',
            ':guest_list' => $b['guest_list'] ?? '',
            ':created_at' => $b['created_at'] ?? date('Y-m-d H:i:s')
        ]);
        echo "Inserted missing booking: $id ({$b['guest_name']})\n";
        $synced++;
    } else {
        // Update payment_status, gcash_ref, payment_proof if JSON is newer / has proof
        if (!empty($b['payment_proof']) && $b['payment_proof'] !== $existing['payment_proof']) {
            $upd = $pdo->prepare("UPDATE bookings SET payment_status = ?, gcash_ref = ?, payment_proof = ? WHERE id = ?");
            $upd->execute([$b['payment_status'], $b['gcash_ref'], $b['payment_proof'], $id]);
            echo "Updated proof for: $id\n";
        }
    }
}

echo "Sync complete. Newly inserted: $synced\n";

echo "\n=== Verifying PBR-2026-4027 in DB ===\n";
$s = $pdo->prepare("SELECT id, guest_name, payment_status, gcash_ref, payment_proof FROM bookings WHERE id = 'PBR-2026-4027'");
$s->execute();
print_r($s->fetch(PDO::FETCH_ASSOC));
