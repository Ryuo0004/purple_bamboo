<?php
$output = shell_exec('php -l "../register.php" 2>&1');
header('Content-Type: application/json');
echo json_encode(['lint_output' => $output]);
