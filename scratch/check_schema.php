<?php
require __DIR__ . '/../database.php';

$file = 'C:/xampp/php/logs/php_error_log';
if (file_exists($file)) {
    $lines = file($file);
    echo "=== Last 40 lines of PHP error log ===\n";
    echo implode("", array_slice($lines, -40));
} else {
    echo "Log file not found at $file\n";
}

echo "\n=== Columns of bookings table ===\n";
$q = $pdo->query("DESCRIBE bookings");
while ($col = $q->fetch(PDO::FETCH_ASSOC)) {
    echo "{$col['Field']} - {$col['Type']} - Null: {$col['Null']}\n";
}
