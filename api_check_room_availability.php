<?php
require_once __DIR__ . '/booking_store.php';
require_once __DIR__ . '/inventory_store.php';

header('Content-Type: application/json; charset=utf-8');

$today_str = date('Y-m-d');
$check_in = trim($_GET['check_in'] ?? $today_str);
if (!strtotime($check_in) || $check_in < $today_str) {
    $check_in = $today_str;
}

$default_checkout = date('Y-m-d', strtotime($check_in . ' +1 day'));
$check_out = trim($_GET['check_out'] ?? $default_checkout);
if (!strtotime($check_out) || $check_out <= $check_in) {
    $check_out = $default_checkout;
}

$all_kubos = InventoryStore::getAllKubos();
$rooms = [];

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current_user_id = $_SESSION['user_id'] ?? null;
$current_user_email = $_SESSION['email'] ?? null;
$is_admin_session = (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'staff']));

foreach ($all_kubos as $k) {
    $kid = intval($k['kubo_id']);
    $status = strtolower(trim($k['status'] ?? 'available'));
    $is_reno = ($status === 'renovation');
    $is_maint = in_array($status, ['maintenance', 'unavailable']);
    
    $units_total = BookingStore::getKuboUnits($kid);
    
    if ($is_reno || $is_maint || $units_total <= 0) {
        $units_left = 0;
        $is_avail = false;
    } else {
        $units_left = BookingStore::getRemainingUnits($kid, $check_in, $check_out);
        $is_avail = ($units_left > 0 && BookingStore::isItemAvailable($kid, $check_in, $check_out, 'house'));
        if (!$is_avail) {
            $units_left = 0;
        }
    }

    $user_has_active = false;
    $user_active_status = '';
    $user_active_ref = '';
    if (!empty($current_user_id) && !$is_admin_session) {
        $u_act = BookingStore::getActiveBookingForUser($current_user_id, 'house', $kid, $current_user_email);
        if ($u_act) {
            $user_has_active = true;
            $user_active_status = strtolower($u_act['booking_status'] ?? 'pending');
            $user_active_ref = $u_act['id'] ?? '';
        }
    }

    $rooms[] = [
        'kubo_id' => $kid,
        'name' => $k['name'] ?? $k['kubo_name_number'] ?? "Kubo #{$kid}",
        'category' => $k['category'] ?? 'Resort Suite',
        'price' => floatval($k['price'] ?? 3500),
        'available_units' => $units_total,
        'units_left' => max(0, $units_left),
        'is_available' => (bool)$is_avail,
        'is_renovation' => (bool)$is_reno,
        'status' => $status,
        'user_has_active_booking' => (bool)$user_has_active,
        'user_active_status' => $user_active_status,
        'user_active_ref' => $user_active_ref
    ];
}

echo json_encode([
    'success' => true,
    'today' => $today_str,
    'check_in' => $check_in,
    'check_out' => $check_out,
    'rooms' => $rooms
], JSON_UNESCAPED_SLASHES);
