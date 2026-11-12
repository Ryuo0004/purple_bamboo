<?php
require_once __DIR__ . '/../inventory_store.php';
$acts = InventoryStore::getAllActivities(true);
echo "Total activities: " . count($acts) . "\n";
foreach ($acts as $a) {
    echo "ID: " . ($a['activity_id'] ?? $a['id'] ?? '') . " | Name: " . ($a['name'] ?? $a['title'] ?? '') . "\n";
}
