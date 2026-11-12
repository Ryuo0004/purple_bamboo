<?php
require __DIR__ . '/../database.php';
require __DIR__ . '/../booking_store.php';

echo "=== Last 5 in DB ===\n";
$s = $pdo->query("SELECT id, guest_name, created_at FROM bookings ORDER BY created_at DESC LIMIT 5");
while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
    echo "{$r['id']} | {$r['guest_name']} | {$r['created_at']}\n";
}

echo "\n=== Last 5 in JSON ===\n";
$file = __DIR__ . '/../data/bookings.json';
if (file_exists($file)) {
    $j = json_decode(file_get_contents($file), true) ?? [];
    for ($i = 0; $i < min(5, count($j)); $i++) {
        echo "{$j[$i]['id']} | {$j[$i]['guest_name']} | {$j[$i]['created_at']}\n";
    }
} else {
    echo "No JSON file at $file\n";
}

echo "\n=== Check PHP error log ===\n";
$log = ini_get('error_log');
echo "error_log path: $log\n";
if ($log && file_exists($log)) {
    $lines = file($log);
    $last_lines = array_slice($lines, -25);
    echo implode("", $last_lines);
}
