<?php
$_GET['month'] = 10;
$_GET['year'] = 2026;

ob_start();
include __DIR__ . '/../calendar.php';
$html = ob_get_clean();

// Check if Luffy Monkey appears
echo "Contains Luffy Monkey: " . (strpos($html, 'Luffy Monkey') !== false ? "YES" : "NO") . "\n";
echo "Contains #PBR-2026-4421: " . (strpos($html, 'PBR-2026-4421') !== false ? "YES" : "NO") . "\n";
echo "Contains cal-span-start: " . (strpos($html, 'cal-span-start') !== false ? "YES" : "NO") . "\n";
echo "Contains cal-span-middle: " . (strpos($html, 'cal-span-middle') !== false ? "YES" : "NO") . "\n";
echo "Contains cal-span-end: " . (strpos($html, 'cal-span-end') !== false ? "YES" : "NO") . "\n";

// Extract cell 7, 8, 9
preg_match('/data-date="2026-10-07".*?<\/div>\s*<\/div>\s*<\/div>/s', $html, $m7);
preg_match('/data-date="2026-10-08".*?<\/div>\s*<\/div>\s*<\/div>/s', $html, $m8);
preg_match('/data-date="2026-10-09".*?<\/div>\s*<\/div>\s*<\/div>/s', $html, $m9);

echo "\n--- OCT 7 CELL SNIPPET ---\n";
echo substr($m7[0] ?? 'NOT FOUND', 0, 500) . "\n";

echo "\n--- OCT 8 CELL SNIPPET ---\n";
echo substr($m8[0] ?? 'NOT FOUND', 0, 500) . "\n";

echo "\n--- OCT 9 CELL SNIPPET ---\n";
echo substr($m9[0] ?? 'NOT FOUND', 0, 500) . "\n";
?>
