<?php
require_once __DIR__ . '/../database.php';

try {
    $pdo->exec("ALTER TABLE activities ADD COLUMN IF NOT EXISTS capacity INT DEFAULT 100 AFTER duration");
    $pdo->exec("ALTER TABLE activities ADD COLUMN IF NOT EXISTS activity_type ENUM('full_day', 'time_based') DEFAULT 'full_day' AFTER capacity");
    echo "Columns added successfully!\n";
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
