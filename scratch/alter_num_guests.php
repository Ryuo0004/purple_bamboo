<?php
require __DIR__ . '/../database.php';
$pdo->exec("ALTER TABLE bookings MODIFY COLUMN num_guests int(11) NOT NULL DEFAULT 1");
echo "Default 1 set on num_guests.\n";
