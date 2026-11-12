<?php
require_once __DIR__ . '/../database.php';

echo "=== CHECKING ALL TABLES AND COUNTS IN LIVE DB ===\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $t) {
    $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);
    echo sprintf("%-20s : %4d rows, %2d cols\n", $t, $count, count($cols));
}
