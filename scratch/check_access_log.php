<?php
$accessLog = 'C:/xampp/apache/logs/access.log';
if (file_exists($accessLog)) {
    $lines = file($accessLog);
    $recent = array_slice($lines, -35);
    foreach ($recent as $l) {
        if (strpos($l, 'purple_bamboo') !== false) {
            echo $l;
        }
    }
} else {
    echo "Access log not found.\n";
}
