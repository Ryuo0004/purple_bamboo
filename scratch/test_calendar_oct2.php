<?php
$_GET['month'] = 10;
$_GET['year'] = 2026;

ob_start();
include __DIR__ . '/../calendar.php';
$html = ob_get_clean();

// Find cell 7
$p7 = strpos($html, 'data-date="2026-10-07"');
if ($p7 !== false) {
    echo "=== OCT 7 CELL ===\n";
    echo strip_tags(substr($html, $p7, 1200)) . "\n";
}

$p8 = strpos($html, 'data-date="2026-10-08"');
if ($p8 !== false) {
    echo "=== OCT 8 CELL ===\n";
    echo strip_tags(substr($html, $p8, 1200)) . "\n";
}

$p9 = strpos($html, 'data-date="2026-10-09"');
if ($p9 !== false) {
    echo "=== OCT 9 CELL ===\n";
    echo strip_tags(substr($html, $p9, 1200)) . "\n";
}
?>
