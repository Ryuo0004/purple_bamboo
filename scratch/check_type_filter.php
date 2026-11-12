<?php
$f = file_get_contents('c:/xampp/htdocs/purple_bamboo/bookings.php');
$lines = explode("\n", $f);
foreach ($lines as $i => $line) {
    if (strpos($line, '$type_filter') !== false) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
