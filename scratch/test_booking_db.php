<?php
$pdo = new PDO('mysql:host=localhost;dbname=purple_bamboo', 'root', '');
$stmt = $pdo->query("SELECT id, booking_reference, booking_status, payment_status, rejection_reason FROM bookings WHERE id = 'PBR-2026-4027' OR booking_reference = 'PBR-2026-4027'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
