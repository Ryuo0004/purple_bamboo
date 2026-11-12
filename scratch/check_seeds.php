<?php
require_once __DIR__ . '/../database.php';
header('Content-Type: text/plain');

echo "========================================\n";
echo "1. CHECKING TEST / SEED DATA IN 'bookings'\n";
echo "========================================\n";
$stmt = $pdo->query("SELECT id, guest_name, guest_email, item_name, type, check_in, check_out, booking_status, payment_status, total_amount FROM bookings WHERE id LIKE '%TEST%' OR guest_name LIKE '%Test%' OR guest_email LIKE '%test%' OR guest_email LIKE '%example.com%' OR id IN ('PBR-2026-8801', 'PBR-2026-8750', 'PBR-2026-8690', 'ACT-2026-1022')");
$testBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($testBookings) . " test/seed/simulation bookings:\n";
foreach ($testBookings as $b) {
    echo "  - [{$b['id']}] {$b['guest_name']} ({$b['guest_email']}) | Item: {$b['item_name']} | Status: {$b['booking_status']} | Payment: {$b['payment_status']} | Check-in: {$b['check_in']}\n";
}

echo "\n========================================\n";
echo "2. ALL BOOKINGS SUMMARY BY STATUS\n";
echo "========================================\n";
$stmt = $pdo->query("SELECT booking_status, COUNT(*) as c FROM bookings GROUP BY booking_status");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  - {$row['booking_status']}: {$row['c']}\n";
}

echo "\n========================================\n";
echo "3. TODAY'S ACTIVE BOOKINGS (IS ANY KUBO OCCUPIED TODAY?)\n";
echo "========================================\n";
$today = date('Y-m-d');
$stmt = $pdo->query("SELECT id, guest_name, item_id, item_name, type, check_in, check_out, booking_status FROM bookings WHERE (booking_status IN ('approved', 'checked_in') OR (booking_status = 'pending' AND payment_status = 'verified')) AND DATE(check_in) <= '{$today}' AND (check_out IS NULL OR DATE(check_out) >= '{$today}')");
$activeToday = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Active bookings covering today ({$today}): " . count($activeToday) . "\n";
foreach ($activeToday as $ab) {
    echo "  - [{$ab['id']}] {$ab['guest_name']} | Item #{$ab['item_id']} ({$ab['item_name']}) | {$ab['check_in']} -> {$ab['check_out']} | Status: {$ab['booking_status']}\n";
}

echo "\n========================================\n";
echo "4. HOUSE KUBOS INVENTORY STATUS\n";
echo "========================================\n";
$stmt = $pdo->query("SELECT kubo_id, kubo_name_number, status, capacity, price_per_night, renovation_notes FROM house_kubos");
$kubos = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($kubos as $k) {
    echo "  - Kubo #{$k['kubo_id']}: {$k['kubo_name_number']} | Status in DB: '{$k['status']}' | Price: ₱{$k['price_per_night']} | Reno Notes: " . ($k['renovation_notes'] ?: 'None') . "\n";
}

echo "\n========================================\n";
echo "5. TEST / SEED USERS IN 'users'\n";
echo "========================================\n";
$stmt = $pdo->query("SELECT id, first_name, last_name, email, role, status FROM users WHERE email LIKE '%example.com%' OR email LIKE '%test%' OR first_name LIKE '%test%' OR last_name LIKE '%test%' OR role IN ('admin', 'staff')");
$testUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($testUsers as $u) {
    echo "  - User #{$u['id']}: {$u['first_name']} {$u['last_name']} ({$u['email']}) | Role: {$u['role']} | Status: {$u['status']}\n";
}

echo "\n========================================\n";
echo "6. SEED / DEMO DATA IN CODE & STORES\n";
echo "========================================\n";
require_once __DIR__ . '/../dining_store.php';
$diningOrders = DiningStore::getAllOrders();
echo "DiningStore Orders count: " . count($diningOrders) . "\n";
if (!empty($diningOrders)) {
    foreach (array_slice($diningOrders, 0, 3) as $d) {
        echo "  - [{$d['id']}] {$d['guest_name']} ({$d['kubo_location']}) - ₱{$d['total_amount']} [{$d['order_status']}]\n";
    }
}
