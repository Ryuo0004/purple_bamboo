<?php
require_once __DIR__ . '/../database.php';
header('Content-Type: application/json');

$users = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
$staff = $pdo->query("SELECT * FROM staff")->fetchAll(PDO::FETCH_ASSOC);
$admins = $pdo->query("SELECT * FROM admins")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'users_table_count' => count($users),
    'users' => $users,
    'staff_table_count' => count($staff),
    'staff' => $staff,
    'admins_table_count' => count($admins),
    'admins' => $admins,
]);
