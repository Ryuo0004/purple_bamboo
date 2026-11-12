<?php
require_once __DIR__ . '/../database.php';

try {
    $pdo->exec("ALTER TABLE bookings ADD COLUMN IF NOT EXISTS start_time TIME NULL AFTER time_slot");
    $pdo->exec("ALTER TABLE bookings ADD COLUMN IF NOT EXISTS end_time TIME NULL AFTER start_time");
    echo "Columns added to bookings!\n";
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
