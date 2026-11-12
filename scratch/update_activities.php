<?php
require_once __DIR__ . '/../database.php';
$pdo->exec("UPDATE activities SET capacity = 20, activity_type = 'full_day' WHERE activity_id = 1"); // Infinity Pool
$pdo->exec("UPDATE activities SET capacity = 5, activity_type = 'time_based' WHERE activity_id = 2"); // Kayak
$pdo->exec("UPDATE activities SET capacity = 10, activity_type = 'time_based' WHERE activity_id = 3"); // Massage
echo "Updated activities!\n";
