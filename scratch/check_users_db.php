<?php
require_once __DIR__ . '/../database.php';
$stmt = $pdo->query("SELECT id, first_name, last_name, email, role FROM users ORDER BY id DESC LIMIT 10");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
    echo "ID: {$u['id']} | {$u['first_name']} {$u['last_name']} | {$u['email']} | {$u['role']}\n";
}
