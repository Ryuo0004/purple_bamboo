<?php
session_start();
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;
$_SESSION['admin_logged_in'] = true;
$_SESSION['full_name'] = 'Super Admin';

ob_start();
include __DIR__ . '/../admin-dashboard.php';
$html = ob_get_clean();

echo "Dashboard HTML Length: " . strlen($html) . PHP_EOL;
echo "Contains Command Center: " . (strpos($html, 'Executive Command Center') !== false ? 'YES' : 'NO') . PHP_EOL;
echo "Contains Expected Arrivals: " . (strpos($html, "Today's Expected Arrivals") !== false ? 'YES' : 'NO') . PHP_EOL;
echo "Contains Resort Accommodation: " . (strpos($html, 'Resort Accommodation Status') !== false ? 'YES' : 'NO') . PHP_EOL;
echo "Contains GCash Summary: " . (strpos($html, 'GCash Verification Summary') !== false ? 'YES' : 'NO') . PHP_EOL;
echo "Contains Recent Reservations: " . (strpos($html, 'Recent Reservation Records') !== false ? 'YES' : 'NO') . PHP_EOL;
echo "Contains Revenue Trends: " . (strpos($html, 'Monthly Revenue Performance') !== false ? 'YES' : 'NO') . PHP_EOL;
