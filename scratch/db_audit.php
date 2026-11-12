<?php
require_once __DIR__ . '/../database.php';

echo "=== DATABASE CONNECTION AUDIT ===\n";
echo "Config Host: " . DB_HOST . "\n";
echo "Config DB Name: " . DB_NAME . "\n";
echo "Config User: " . DB_USER . "\n";

if (!$pdo) {
    echo "ERROR: \$pdo is null. Could not connect!\n";
    exit(1);
}

echo "Connection successfully established with PDO.\n\n";

// 1. Check current database and other databases
$currDb = $pdo->query("SELECT DATABASE()")->fetchColumn();
echo "Currently connected database: " . $currDb . "\n\n";

$dbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
echo "All databases on MySQL server:\n";
foreach ($dbs as $db) {
    echo "  - " . $db . "\n";
}
echo "\n";

// 2. List tables in current database
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "Tables in '" . $currDb . "': (" . count($tables) . " tables)\n";
$currentSchema = [];
foreach ($tables as $t) {
    $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
    $currentSchema[$t] = [];
    echo "  Table: $t (" . count($cols) . " columns)\n";
    foreach ($cols as $c) {
        $currentSchema[$t][$c['Field']] = [
            'type' => $c['Type'],
            'null' => $c['Null'],
            'key' => $c['Key'],
            'default' => $c['Default'],
            'extra' => $c['Extra']
        ];
    }
}

// 3. If there is another database like 'purple_bamboo' or similar, check its tables/columns
$otherDb = null;
foreach ($dbs as $d) {
    if ($d !== $currDb && (strpos($d, 'purple_bamboo') !== false || strpos($d, 'purple') !== false)) {
        $otherDb = $d;
        break;
    }
}

if ($otherDb) {
    echo "\n=== Found another database: '$otherDb' ===\n";
    $otherTables = $pdo->query("SHOW TABLES FROM `$otherDb`")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables in '$otherDb': (" . count($otherTables) . " tables)\n";
    foreach ($otherTables as $ot) {
        $otCols = $pdo->query("SHOW COLUMNS FROM `$otherDb`.`$ot`")->fetchAll(PDO::FETCH_ASSOC);
        echo "  Table: $ot (" . count($otCols) . " columns)\n";
    }
}

file_put_contents(__DIR__ . '/live_schema.json', json_encode($currentSchema, JSON_PRETTY_PRINT));
echo "\nSaved live schema to live_schema.json\n";
