<?php
require_once __DIR__ . '/../database.php';
global $pdo;

$stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = 'PBR-2026-4421'");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode($row, JSON_PRETTY_PRINT);
?>
