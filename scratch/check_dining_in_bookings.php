<?php
$f = file_get_contents('c:/xampp/htdocs/purple_bamboo/bookings.php');
$lines = explode("\n", $f);
foreach ($lines as $i => $line) {
    if (stripos($line, 'all_dining_orders') !== false || stripos($line, 'dining-orders') !== false || stripos($line, 'dining_monitoring') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
