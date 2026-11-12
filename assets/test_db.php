<?php
require_once __DIR__ . '/database.php';

echo "=== Database Connection Test ===" . PHP_EOL;
if ($pdo) {
    echo "OK: PDO connected" . PHP_EOL;
    
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'house_kubos'");
        echo "house_kubos table: " . ($check->rowCount() > 0 ? 'EXISTS' : 'MISSING') . PHP_EOL;
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . PHP_EOL;
    }

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM house_kubos");
        echo "Columns:" . PHP_EOL;
        while ($col = $cols->fetch()) {
            echo "  - " . $col['Field'] . " (" . $col['Type'] . ")" . PHP_EOL;
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . PHP_EOL;
    }

    try {
        $reno = $pdo->query("SHOW COLUMNS FROM house_kubos LIKE 'renovation_notes'");
        echo "renovation_notes: " . ($reno->rowCount() > 0 ? 'EXISTS' : 'MISSING') . PHP_EOL;
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . PHP_EOL;
    }

    echo PHP_EOL . "=== Running Migration ===" . PHP_EOL;
    require_once __DIR__ . '/migrate_renovation.php';
    echo "Migration done." . PHP_EOL;

    try {
        $reno2 = $pdo->query("SHOW COLUMNS FROM house_kubos LIKE 'renovation_notes'");
        echo "renovation_notes after migration: " . ($reno2->rowCount() > 0 ? 'EXISTS' : 'STILL MISSING') . PHP_EOL;
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . PHP_EOL;
    }

    try {
        $stmt = $pdo->prepare("UPDATE house_kubos SET status = 'available' WHERE kubo_id = 1");
        $stmt->execute();
        echo "Test update OK (rows: " . $stmt->rowCount() . ")" . PHP_EOL;
    } catch (Exception $e) {
        echo "Test update FAILED: " . $e->getMessage() . PHP_EOL;
    }

} else {
    echo "FAILED: PDO is NULL - MySQL NOT connected!" . PHP_EOL;
}
?>
