<?php
require_once __DIR__ . '/../database.php';
$stmt = $pdo->query("DESCRIBE activities");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
