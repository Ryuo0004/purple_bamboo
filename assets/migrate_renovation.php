<?php
/**
 * migrate_renovation.php
 * Safe migration to add renovation columns and widen status column in house_kubos table.
 * Auto-called from inventory_store.php on first load.
 */

require_once __DIR__ . '/database.php';

function runRenovationMigration() {
    global $pdo;
    if (!$pdo) return false;

    try {
        // 1. Convert status column to VARCHAR(50) to support 'renovation' without enum truncation issues
        try {
            $pdo->exec("ALTER TABLE house_kubos MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'available'");
        } catch (PDOException $e) {
            // Alternatively try ENUM with renovation
            try {
                $pdo->exec("ALTER TABLE house_kubos MODIFY COLUMN status ENUM('available', 'occupied', 'maintenance', 'renovation') DEFAULT 'available'");
            } catch (PDOException $ex) {}
        }

        // 2. Add badges column if it doesn't exist
        try {
            $badgeCheck = $pdo->query("SHOW COLUMNS FROM house_kubos LIKE 'badges'");
            if ($badgeCheck->rowCount() === 0) {
                $pdo->exec("ALTER TABLE house_kubos ADD COLUMN badges VARCHAR(255) NULL");
            }
        } catch (PDOException $e) {}

        // 3. Add renovation columns
        try {
            $check = $pdo->query("SHOW COLUMNS FROM house_kubos LIKE 'renovation_notes'");
            if ($check->rowCount() === 0) {
                $pdo->exec("ALTER TABLE house_kubos ADD COLUMN renovation_notes TEXT NULL");
            }
        } catch (PDOException $e) {}

        try {
            $check = $pdo->query("SHOW COLUMNS FROM house_kubos LIKE 'renovation_start'");
            if ($check->rowCount() === 0) {
                $pdo->exec("ALTER TABLE house_kubos ADD COLUMN renovation_start DATE NULL");
            }
        } catch (PDOException $e) {}

        try {
            $check = $pdo->query("SHOW COLUMNS FROM house_kubos LIKE 'renovation_end'");
            if ($check->rowCount() === 0) {
                $pdo->exec("ALTER TABLE house_kubos ADD COLUMN renovation_end DATE NULL");
            }
        } catch (PDOException $e) {}

        // 4. Add available_units column
        try {
            $check = $pdo->query("SHOW COLUMNS FROM house_kubos LIKE 'available_units'");
            if ($check->rowCount() === 0) {
                $pdo->exec("ALTER TABLE house_kubos ADD COLUMN available_units INT NOT NULL DEFAULT 5");
            }
        } catch (PDOException $e) {}

        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Auto-run migration
runRenovationMigration();
?>
