<?php
require_once __DIR__ . '/../database.php';
$stmt = $pdo->query('SELECT * FROM house_categories');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
