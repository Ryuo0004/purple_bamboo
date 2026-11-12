<?php
session_start();
$_SESSION['role'] = 'admin';
$_SESSION['admin_user'] = 'admin';
$_SESSION['admin_logged_in'] = true;

require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../booking_store.php';

$token = generate_csrf_token();

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['csrf_token'] = $token;
$_POST['quick_action'] = 'reject';
$_POST['quick_ref'] = 'PBR-TEST-9848';
$_POST['reason'] = 'Incorrect amount paid';

ob_start();
include __DIR__ . '/../verify_gcash.php';
$html = ob_get_clean();

$booking = BookingStore::getBookingById('PBR-TEST-9848');
echo "Updated payment_status: " . ($booking['payment_status'] ?? 'N/A') . "\n";
echo "Updated rejection_reason: " . ($booking['rejection_reason'] ?? 'N/A') . "\n";
if (strpos($html, 'was marked as REJECTED') !== false) {
    echo "Success message found in output HTML!\n";
} else {
    echo "Success message NOT found in output HTML!\n";
}
