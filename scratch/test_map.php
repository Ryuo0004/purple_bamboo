<?php
$_GET['month'] = 10;
$_GET['year'] = 2026;

include __DIR__ . '/../calendar.php';
// calendar.php was included, but ob_start was not used so headers might send; let's do clean include
?>
