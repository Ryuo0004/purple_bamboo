<?php
// profile.php — BUG-09 fix: was require_once user-dashboard.php (wrong URL in browser, broke form actions)
// Now properly redirects so the URL always shows user-dashboard.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: user-dashboard.php' . $qs, true, 301);
exit();
