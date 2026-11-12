<?php
header('Content-Type: text/plain; charset=utf-8');

echo "=== PURPLE BAMBOO DATABASE & STAFF INSERT DIAGNOSTIC ===\n\n";

$ports = [3306, 3307, 3308];
$hosts = ['localhost', '127.0.0.1'];
$connectedPdo = null;

foreach ($hosts as $h) {
    foreach ($ports as $p) {
        try {
            $testPdo = new PDO("mysql:host={$h};port={$p};charset=utf8mb4", 'root', '', [PDO::ATTR_TIMEOUT => 2]);
            echo "[SUCCESS] Connected to MySQL on {$h}:{$p}\n";
            $connectedPdo = $testPdo;
            $connectedHost = $h;
            $connectedPort = $p;
            break 2;
        } catch (PDOException $e) {
            echo "[FAIL] {$h}:{$p} => " . $e->getMessage() . "\n";
        }
    }
}

if ($connectedPdo) {
    echo "\nFound MySQL on {$connectedHost}:{$connectedPort}!\n";
    $dbs = $connectedPdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Databases: " . implode(', ', $dbs) . "\n";
    
    if (in_array('purple_bamboo_db', $dbs)) {
        $connectedPdo->exec("USE purple_bamboo_db");
        $tables = $connectedPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "Tables in purple_bamboo_db: " . implode(', ', $tables) . "\n";
        
        echo "\nChecking staff table structure:\n";
        $cols = $connectedPdo->query("DESCRIBE staff")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            echo "  - {$col['Field']} ({$col['Type']}) Null:{$col['Null']} Default:{$col['Default']}\n";
        }
        
        echo "\nChecking activity_logs structure:\n";
        $aCols = $connectedPdo->query("DESCRIBE activity_logs")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($aCols as $col) {
            echo "  - {$col['Field']} ({$col['Type']})\n";
        }
    }
} else {
    echo "\nCould not connect to any local MySQL port!\n";
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/staff_store.php';

if ($pdo) {
    $deletedStaff = $pdo->exec("DELETE FROM staff WHERE email LIKE 'teststaff_%'");
    $deletedUsers = $pdo->exec("DELETE FROM users WHERE email LIKE 'teststaff_%'");
    echo "\n[CLEANUP] Deleted {$deletedStaff} dummy staff rows and {$deletedUsers} dummy users rows.\n";
}

