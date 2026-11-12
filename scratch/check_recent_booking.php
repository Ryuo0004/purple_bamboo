<?php
require_once __DIR__ . '/../database.php';
$stmt = $pdo->query("SELECT * FROM bookings WHERE id = 'PBR-2026-9939'");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
$stmt2 = $pdo->query("SELECT * FROM addon_reservations WHERE reservation_id = 'PBR-2026-9939'");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
