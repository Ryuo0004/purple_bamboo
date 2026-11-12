<?php
require_once __DIR__ . '/../database.php';
header('Content-Type: text/plain');

$tables = ['users', 'bookings', 'booking_archive', 'house_kubos', 'reviews', 'site_content', 'dining_menu', 'dining_orders', 'activity_logs', 'sent_emails'];

foreach ($tables as $t) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "Table `$t`: $count rows\n";
    } catch (Exception $e) {
        echo "Table `$t`: ERROR - " . $e->getMessage() . "\n";
    }
}
