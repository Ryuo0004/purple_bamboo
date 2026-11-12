<?php
session_start();
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;
$_SESSION['full_name'] = 'Super Admin';
$_GET['view'] = 'all';
$_GET['status'] = 'all';
$_GET['payment'] = 'all';
$_GET['type'] = 'all';
$_GET['search'] = '';
$_GET['date'] = '';

ob_start();
include __DIR__ . '/../bookings.php';
$html = ob_get_clean();

preg_match_all('/#PBR-[A-Z]+-\d+(?:-\d+)?/', $html, $pbr_matches);
echo "All PBR- IDs visible on Page 1: (" . count(array_unique($pbr_matches[0])) . ")\n";
foreach (array_unique($pbr_matches[0]) as $p) {
    echo " * $p\n";
}
