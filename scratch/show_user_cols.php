<?php
require_once __DIR__ . '/../database.php';
$stmt = $pdo->query("SHOW COLUMNS FROM users");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "{$c['Field']} ({$c['Type']}) Key: {$c['Key']}\n";
}
