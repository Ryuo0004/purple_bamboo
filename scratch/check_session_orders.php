<?php
header('Content-Type: text/plain');
$p = session_save_path();
echo "session_save_path: " . ($p ? $p : 'empty (system default)') . "\n";
if ($p) {
    $files = glob($p . '/sess_*');
    echo "Found " . count($files) . " in $p\n";
    foreach ($files as $f) {
        $c = file_get_contents($f);
        if (strpos($c, 'PBR-FOOD-') !== false || strpos($c, 'dining_orders') !== false) {
            echo "MATCH: $f\n$c\n";
        }
    }
}
