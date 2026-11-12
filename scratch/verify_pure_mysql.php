<?php
header('Content-Type: text/plain; charset=utf-8');
$root = dirname(__DIR__);
require_once $root . '/database.php';
require_once $root . '/booking_store.php';
require_once $root . '/inventory_store.php';
require_once $root . '/staff_store.php';
require_once $root . '/review_store.php';
require_once $root . '/content_store.php';

function tryCall($label, $cb) {
    try { echo $label . ': ' . $cb() . "\n"; }
    catch (Throwable $e) { echo $label . ': ERROR ' . $e->getMessage() . "\n"; }
}

echo "PDO: " . ($pdo ? 'ACTIVE' : 'NULL') . "\n";
tryCall('BookingStore::getAllBookings', fn() => count(BookingStore::getAllBookings()));
tryCall('InventoryStore::getAllKubos', fn() => count(InventoryStore::getAllKubos()));
tryCall('StaffStore::getAllUsers', fn() => count(StaffStore::getAllUsers()));
tryCall('ReviewStore::getAll', fn() => count(ReviewStore::getAll()));
tryCall('ContentStore::getContent', fn() => ContentStore::getContent()['resort_brand_title'] ?? '?');
tryCall('get_recent_guest_logins', fn() => count(get_recent_guest_logins()));
foreach (['booking_archive', 'sent_emails', 'activity_logs', 'site_content'] as $t) {
    tryCall("table $t", fn() => $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn());
}
foreach (['forgot-password.php', 'edit_homepage.php', 'staff_store.php', 'security.php', 'content_store.php', 'includes/EmailHelper.php'] as $f) {
    $c = file_get_contents("$root/$f");
    echo "$f json refs: " . preg_match_all('/data\/[a-z_]+\.json/', $c) . "\n";
}
