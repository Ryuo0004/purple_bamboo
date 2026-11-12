<?php
require_once __DIR__ . '/../database.php';
global $pdo;
$stmt = $pdo->query('SELECT activity_id, activity_name, is_available, capacity, total_units, available_units, equipment FROM activities');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
