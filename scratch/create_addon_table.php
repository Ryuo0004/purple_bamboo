<?php
require_once __DIR__ . '/../database.php';
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS addon_reservations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reservation_id VARCHAR(50) NOT NULL,
            activity_id INT NOT NULL,
            activity_date DATE NOT NULL,
            start_time TIME NULL,
            end_time TIME NULL,
            quantity INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (reservation_id) REFERENCES bookings(id) ON DELETE CASCADE
        )
    ");
    echo "addon_reservations created!\n";
} catch(Exception $e) {
    echo $e->getMessage();
}
