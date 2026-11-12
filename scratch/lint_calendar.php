<?php
$output = shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../calendar.php'));
echo $output;
?>
