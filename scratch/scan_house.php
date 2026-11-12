<?php
$lines = file(__DIR__ . '/../book_house.php');
foreach ([606, 925, 1192, 1602, 1745, 973, 991] as $n) {
    for ($k = $n - 1; $k >= max(0, $n - 8); $k--) {
        if (strpos($lines[$k], '{') !== false) { echo $n . ' => ' . trim($lines[$k]) . "\n"; break; }
    }
}
