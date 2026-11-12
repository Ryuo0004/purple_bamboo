<?php
$logFiles = [
    'C:/xampp/apache/logs/error.log',
    'C:/xampp/php/logs/php_error_log.log',
    ini_get('error_log')
];

foreach ($logFiles as $lf) {
    if ($lf && file_exists($lf)) {
        echo "=== File: $lf ===\n";
        $lines = file($lf);
        $recent = array_slice($lines, -30);
        echo implode('', $recent) . "\n";
    }
}
