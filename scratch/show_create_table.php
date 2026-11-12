<?php
require __DIR__ . '/../database.php';
$stmt = $pdo->query("SHOW CREATE TABLE bookings");
$res = $stmt->fetch(PDO::FETCH_ASSOC);
echo $res['Create Table'];
