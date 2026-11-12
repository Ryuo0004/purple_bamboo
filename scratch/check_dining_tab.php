<?php
$f = file_get_contents('c:/xampp/htdocs/purple_bamboo/bookings.php');
$lines = explode("\n", $f);
foreach ($lines as $i => $line) {
    if (stripos($line, 'dining_active_count') !== false || stripos($line, 'dining_status') !== false || stripos($line, 'all_dining_orders') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
