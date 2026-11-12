<?php
session_start();
$_SESSION['role'] = 'admin';
$_SESSION['admin_user'] = 'admin';
$_SESSION['admin_logged_in'] = true;

ob_start();
include __DIR__ . '/../verify_gcash.php';
$html = ob_get_clean();

echo "Rendered HTML length: " . strlen($html) . "\n";

// Find all onclick attributes calling openRejectModal
preg_match_all('/onclick="[^"]*openRejectModal[^"]*"/', $html, $matches);
echo "Reject button onclicks found: " . count($matches[0]) . "\n";
foreach ($matches[0] as $i => $m) {
    echo "  [$i] " . $m . "\n";
}
