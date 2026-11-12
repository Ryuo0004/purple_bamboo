<?php
session_start();
$_SESSION['user_id'] = 217;
$_SESSION['email'] = 'fme090909@gmail.com';
$_SESSION['full_name'] = 'Garp D Monkey';
$_SESSION['role'] = 'guest';

ob_start();
include __DIR__ . '/../dining.php';
$html = ob_get_clean();

echo "Length of dining.php HTML: " . strlen($html) . "\n";
preg_match_all('/#PBR-FOOD-2026-\d+/', $html, $matches);
echo "Orders rendered in dining.php for fme090909@gmail.com: " . implode(', ', array_unique($matches[0])) . "\n";
