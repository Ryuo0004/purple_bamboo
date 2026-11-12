<?php
require_once __DIR__ . '/../database.php';
header('Content-Type: text/plain');

$rows = $pdo->query("SELECT * FROM dining_orders")->fetchAll();
echo "Found " . count($rows) . " rows in dining_orders DB table:\n";
foreach ($rows as $r) {
    print_r($r);
}
