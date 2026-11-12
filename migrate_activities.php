<?php
/**
 * migrate_activities.php
 * Safe migration to ensure all required activity columns exist in activities table.
 * Auto-called from inventory_store.php on load.
 */

require_once __DIR__ . '/database.php';

function runActivitiesMigration() {
    global $pdo;
    if (!$pdo) return false;

    try {
        // Ensure activities table exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS activities (
                activity_id INT AUTO_INCREMENT PRIMARY KEY,
                activity_name VARCHAR(100) NOT NULL,
                category VARCHAR(50) DEFAULT 'pool',
                price DECIMAL(10,2) NOT NULL,
                duration VARCHAR(50) DEFAULT '1 Hour',
                duration_minutes INT DEFAULT 60,
                capacity INT DEFAULT 10,
                activity_type ENUM('full_day','time_based') DEFAULT 'time_based',
                description TEXT NULL,
                image VARCHAR(255) NULL,
                activity_image VARCHAR(255) NULL,
                status ENUM('available','maintenance','closed') DEFAULT 'available',
                is_available TINYINT(1) DEFAULT 1,
                maintenance_notes VARCHAR(255) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $columnsToAdd = [
            'category' => "ALTER TABLE activities ADD COLUMN category VARCHAR(50) DEFAULT 'pool' AFTER activity_name",
            'duration_minutes' => "ALTER TABLE activities ADD COLUMN duration_minutes INT DEFAULT 60 AFTER duration",
            'capacity' => "ALTER TABLE activities ADD COLUMN capacity INT DEFAULT 10 AFTER duration_minutes",
            'activity_type' => "ALTER TABLE activities ADD COLUMN activity_type ENUM('full_day','time_based') DEFAULT 'time_based' AFTER capacity",
            'image' => "ALTER TABLE activities ADD COLUMN image VARCHAR(255) NULL AFTER description",
            'activity_image' => "ALTER TABLE activities ADD COLUMN activity_image VARCHAR(255) NULL AFTER image",
            'status' => "ALTER TABLE activities ADD COLUMN status ENUM('available','maintenance','closed') DEFAULT 'available' AFTER activity_image",
            'is_available' => "ALTER TABLE activities ADD COLUMN is_available TINYINT(1) DEFAULT 1 AFTER status",
            'maintenance_notes' => "ALTER TABLE activities ADD COLUMN maintenance_notes VARCHAR(255) NULL AFTER is_available"
        ];

        foreach ($columnsToAdd as $col => $sql) {
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM activities LIKE '$col'");
                if ($chk->rowCount() === 0) {
                    $pdo->exec($sql);
                }
            } catch (PDOException $e) {}
        }

        // Sync image & activity_image, and ensure duration_minutes, capacity, units are nullable
        try {
            $pdo->exec("ALTER TABLE activities MODIFY COLUMN duration_minutes INT NULL DEFAULT NULL");
            $pdo->exec("ALTER TABLE activities MODIFY COLUMN capacity INT NULL DEFAULT NULL");
            $pdo->exec("ALTER TABLE activities MODIFY COLUMN total_units INT NULL DEFAULT NULL");
            $pdo->exec("ALTER TABLE activities MODIFY COLUMN available_units INT NULL DEFAULT NULL");
        } catch (PDOException $e) {}

        try {
            $pdo->exec("UPDATE activities SET activity_image = image WHERE (activity_image IS NULL OR activity_image = '') AND image IS NOT NULL");
            $pdo->exec("UPDATE activities SET image = activity_image WHERE (image IS NULL OR image = '') AND activity_image IS NOT NULL");
            $pdo->exec("UPDATE activities SET is_available = CASE WHEN status = 'maintenance' THEN 0 ELSE 1 END WHERE is_available IS NULL");
            $pdo->exec("UPDATE activities SET status = CASE WHEN is_available = 0 THEN 'maintenance' ELSE 'available' END WHERE status IS NULL");
            // Only assign fallback capacity for non-pool activities
            $pdo->exec("UPDATE activities SET capacity = 5 WHERE (capacity IS NULL OR capacity = 0) AND category != 'pool' AND activity_name NOT LIKE '%pool%'");
            // Pool activities: units/equipment are informational only (booking is never capped, see BookingStore::getActivityAvailability)
        } catch (PDOException $e) {}

        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Auto-run migration
runActivitiesMigration();
