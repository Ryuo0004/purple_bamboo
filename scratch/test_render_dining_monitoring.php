<?php
session_start();
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;
$_SESSION['email'] = 'admin@purplebamboo.com';
$_SESSION['full_name'] = 'Super Admin';

ob_start();
include __DIR__ . '/../dining_monitoring.php';
$html = ob_get_clean();

echo "Length of dining_monitoring HTML: " . strlen($html) . "\n";
if (strpos($html, 'PBR-FOOD-2026-4398') !== false) {
    echo "SUCCESS: Found PBR-FOOD-2026-4398 in dining_monitoring.php HTML!\n";
} else {
    echo "ERROR: PBR-FOOD-2026-4398 was NOT found in dining_monitoring.php HTML!\n";
}

// Check other orders
preg_match_all('/#PBR-FOOD-2026-\d+/', $html, $matches);
echo "Orders rendered in dining_monitoring.php: " . implode(', ', $matches[0] ?? []) . "\n";
