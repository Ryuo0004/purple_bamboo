<?php
require_once __DIR__ . '/../dining_store.php';
$orders = DiningStore::getAllOrders();
echo "Total orders: " . count($orders) . "\n";
foreach ($orders as $o) {
    echo $o['id'] . " | " . $o['order_status'] . " | " . $o['guest_name'] . " | " . $o['guest_email'] . " | " . $o['created_at'] . "\n";
}
