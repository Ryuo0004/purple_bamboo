<?php
require __DIR__ . '/../database.php';
foreach ($pdo->query('SELECT activity_id,activity_name,category,capacity,total_units,available_units,equipment,activity_type FROM activities') as $r) {
    echo json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
}
