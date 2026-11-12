<?php
require_once __DIR__ . '/../database.php';
header('Content-Type: application/json');

if (!$pdo) {
    echo json_encode(['error' => 'No PDO connection']);
    exit;
}

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$users_roles = $pdo->query("SELECT role, count(*) as count FROM users GROUP BY role")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'tables' => $tables,
    'users_roles' => $users_roles
], JSON_PRETTY_PRINT);
