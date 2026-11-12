<?php
require __DIR__ . '/../database.php';
$n = $pdo->exec("UPDATE activities SET capacity = 1000 WHERE category = 'pool'");
echo "updated $n\n";
