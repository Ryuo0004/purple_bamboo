<?php
require __DIR__ . '/../database.php';

echo "=== Looking for PBR-2026-4027 ===\n";
$s = $pdo->prepare("SELECT * FROM bookings WHERE id = 'PBR-2026-4027'");
$s->execute();
$r = $s->fetch(PDO::FETCH_ASSOC);
if ($r) {
    foreach ($r as $k => $v) echo "  $k: $v\n";
} else {
    echo "NOT FOUND in database.\n";
}

echo "\n=== Most recent bookings (last 5) ===\n";
$s2 = $pdo->query("SELECT id, guest_name, guest_email, payment_status, booking_status, gcash_ref, payment_proof, created_at FROM bookings ORDER BY created_at DESC LIMIT 5");
foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $r2) {
    echo "ID:{$r2['id']} | {$r2['guest_name']} | pay:{$r2['payment_status']} | gcash:{$r2['gcash_ref']} | proof:{$r2['payment_proof']} | {$r2['created_at']}\n";
}
