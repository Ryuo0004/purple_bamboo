<?php
$_GET['month'] = 10;
$_GET['year'] = 2026;

// Simulate admin session
session_start();
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;

ob_start();
include __DIR__ . '/../calendar.php';
$html = ob_get_clean();

// Find Oct 7, 8, 9 cells
preg_match_all('/<div class="cal-day-cell[^>]*data-date="2026-10-0[789]"[^>]*>.*?<\/div>\s*<\/div>\s*<\/div>/s', $html, $matches);
foreach ($matches[0] as $cell) {
    echo "========================================\n";
    echo $cell . "\n";
}
?>
