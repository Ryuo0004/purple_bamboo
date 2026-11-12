<?php
require_once __DIR__ . '/../database.php';
header('Content-Type: application/json');

$admin_staff = $pdo->query("SELECT * FROM users WHERE role IN ('admin', 'staff')")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($admin_staff, JSON_PRETTY_PRINT);
