<?php
require_once __DIR__ . '/../database.php';
foreach (['users', 'admins', 'staff', 'dining_orders'] as $tbl) {
    echo "=== $tbl ===\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM `$tbl`");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        echo "{$c['Field']} ({$c['Type']})\n";
    }
}
