<?php
$_GET['month'] = 10;
$_GET['year'] = 2026;

ob_start();
include __DIR__ . '/../calendar.php';
$html = ob_get_clean();

$pos = strpos($html, 'cal-event-banner');
while ($pos !== false) {
    echo "=== BANNER FOUND AT $pos ===\n";
    echo substr($html, $pos - 12, 450) . "\n\n";
    $pos = strpos($html, 'cal-event-banner', $pos + 50);
}
?>
