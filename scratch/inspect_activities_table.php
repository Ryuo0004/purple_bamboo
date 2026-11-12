<?php
require_once __DIR__ . '/../database.php';
global $pdo;

echo "=== ACTIVITIES ROWS ===\n";
$rows = $pdo->query("SELECT * FROM activities")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "#{$r['activity_id']} - {$r['activity_name']} | ₱{$r['price']} | {$r['duration_minutes']} mins | Cat: {$r['category']} | Available: {$r['is_available']}\n";
}

echo "\n=== DINING MENU ROWS ===\n";
$rows = $pdo->query("SELECT * FROM dining_menu")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "#{$r['id']} - {$r['item_name']} | ₱{$r['price']} | Cat: {$r['category']} | Available: {$r['is_available']}\n";
}
