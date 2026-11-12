<?php
require __DIR__ . '/../database.php';
$stmt = $pdo->query("SELECT id, email FROM users LIMIT 3");
foreach ($stmt as $row) {
    echo $row['id'] . ' ' . $row['email'] . PHP_EOL;
}
