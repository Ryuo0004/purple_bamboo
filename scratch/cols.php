<?php
header('Content-Type: text/plain');
require_once dirname(__DIR__) . '/database.php';
try {
    $r = $pdo->query("SELECT log_id AS id, user_id, user_email AS email, action, ip_address, details, created_at AS timestamp FROM activity_logs ORDER BY log_id DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
    echo "direct rows: " . count($r) . "\n";
} catch (Throwable $e) { echo "ERR " . $e->getMessage() . "\n"; }
$rf = new ReflectionFunction('get_recent_guest_logins');
echo $rf->getFileName() . ':' . $rf->getStartLine() . "\n";
if (function_exists('opcache_reset')) opcache_reset();
