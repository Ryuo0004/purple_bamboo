<?php
require __DIR__ . '/../database.php';
$email = 'fme090909@gmail.com';
$stmt = $pdo->prepare("SELECT id, CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name, NULLIF(suffix, '')) AS full_name, email, reset_otp, reset_expires FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
$stmt->execute([$email]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo $row ? ('found id=' . $row['id'] . ' name=' . $row['full_name']) : 'not-found';
echo PHP_EOL;
