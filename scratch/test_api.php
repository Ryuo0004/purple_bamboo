<?php
$_GET = ['activity_ids' => $argv[1], 'date' => '2026-10-21', 'start_time' => '09:00'];
include __DIR__ . '/../api_check_activity_availability.php';
echo "\n";
