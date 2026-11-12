<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['full_name'] = 'Super Admin';
$_SESSION['email'] = 'admin@purplebamboo.com';

ob_start();
include __DIR__ . '/../user_management.php';
$html = ob_get_clean();

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'html_len' => strlen($html),
    'contains_table' => strpos($html, 'admin-table') !== false,
    'contains_super_admin' => strpos($html, 'Super Admin') !== false,
    'contains_front_desk' => strpos($html, 'Front Desk Staff') !== false,
    'contains_juan' => strpos($html, 'Juan') !== false
]);
