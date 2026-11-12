<?php
require_once __DIR__ . '/../database.php';
try {
    $pdo->query("EXPLAIN SELECT ar.reservation_id, b.booking_reference, u.full_name AS guest, a.activity_name AS activity, ar.scheduled_time, ar.status, ar.scheduled_date FROM activity_reservations ar JOIN bookings b ON ar.booking_id = b.booking_id JOIN users u ON b.guest_id = u.user_id JOIN activities a ON ar.activity_id = a.activity_id");
} catch (PDOException $e) {
    echo "EXPLAIN error: " . $e->getMessage() . "\n";
}
