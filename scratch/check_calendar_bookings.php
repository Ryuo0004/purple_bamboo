<?php
require_once __DIR__ . '/../database.php';
global $pdo;

echo "=== ALL BOOKINGS IN DATABASE ===\n";
if ($pdo) {
    $stmt = $pdo->query("SELECT id, guest_name, type, item_id, item_name, check_in, check_out, total_amount, booking_status, special_notes FROM bookings ORDER BY created_at DESC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo "ID: {$r['id']} | Guest: {$r['guest_name']} | Type: {$r['type']} | Item: {$r['item_name']} | In: {$r['check_in']} | Out: {$r['check_out']} | Status: {$r['booking_status']}\n";
        echo "  Notes: " . substr(str_replace("\n", " ", $r['special_notes'] ?? ''), 0, 100) . "\n";
    }

    echo "\n=== ALL ADDON RESERVATIONS ===\n";
    try {
        $stmt2 = $pdo->query("SELECT * FROM addon_reservations");
        $addons = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        foreach ($addons as $a) {
            echo "Reservation: {$a['reservation_id']} | Activity: {$a['activity_id']} | Date: {$a['activity_date']} | Qty: {$a['quantity']}\n";
        }
    } catch (Exception $e) {
        echo "addon_reservations error: " . $e->getMessage() . "\n";
    }
}
?>
