<?php
$dir = new RecursiveDirectoryIterator(__DIR__ . '/..');
$ite = new RecursiveIteratorIterator($dir);
$files = new RegexIterator($ite, '/\.php$/');

$found_issues = [];

foreach ($files as $file) {
    $path = $file->getRealPath();
    if (strpos($path, 'scratch') !== false) continue;
    
    $content = file_get_contents($path);
    
    // Check if file has admin-top-header
    if (preg_match('/<header class="admin-top-header">([\s\S]*?)<\/header>/', $content, $m)) {
        $header_inner = $m[1];
        // Check for buttons or links in header
        if (preg_match_all('/<(a|button)\b[^>]*>(.*?)<\/\1>/is', $header_inner, $btn_matches, PREG_SET_ORDER)) {
            foreach ($btn_matches as $btn) {
                // If it's a mobile menu toggle button, check if that's standard or also should be reviewed:
                // Note: user said "remove all the buttons in the header the header should only contain the admin profile"
                // Let's list whatever button is found.
                $found_issues[] = [
                    'file' => $path,
                    'tag' => $btn[0]
                ];
            }
        }
    }
}

echo "Found " . count($found_issues) . " buttons/links in header:\n";
foreach ($found_issues as $issue) {
    echo "- File: " . $issue['file'] . "\n  Tag: " . trim(preg_replace('/\s+/', ' ', $issue['tag'])) . "\n";
}
