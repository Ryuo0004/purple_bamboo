<?php
require_once __DIR__ . '/../database.php';
header('Content-Type: text/plain');

global $pdo;

echo "=== MIGRATING ACTIVITIES FOR AVAILABILITY & EQUIPMENT ===\n";

$cols = $pdo->query("SHOW COLUMNS FROM activities")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('total_units', $cols)) {
    $pdo->exec("ALTER TABLE activities ADD COLUMN total_units INT DEFAULT 6 AFTER capacity");
    echo "Added total_units column.\n";
} else {
    echo "total_units already exists.\n";
}

if (!in_array('available_units', $cols)) {
    $pdo->exec("ALTER TABLE activities ADD COLUMN available_units INT DEFAULT 4 AFTER total_units");
    echo "Added available_units column.\n";
} else {
    echo "available_units already exists.\n";
}

if (!in_array('equipment', $cols)) {
    $pdo->exec("ALTER TABLE activities ADD COLUMN equipment TEXT NULL AFTER available_units");
    echo "Added equipment column.\n";
} else {
    echo "equipment already exists.\n";
}

// Update Clear-Bottom Kayak with exact equipment from screenshot
$kayakEquipment = json_encode([
    ['name' => 'Kayak', 'qty' => 6, 'icon' => 'fa-solid fa-ferry'],
    ['name' => 'Paddle', 'qty' => 6, 'icon' => 'fa-solid fa-person-snowboarding'],
    ['name' => 'Life Jacket', 'qty' => 10, 'icon' => 'fa-solid fa-vest']
]);

$pdo->prepare("UPDATE activities SET total_units = 6, available_units = 4, equipment = ? WHERE activity_id = 2")->execute([$kayakEquipment]);
echo "Updated Kayak activity (#2) with sample equipment.\n";

echo "DONE!\n";
