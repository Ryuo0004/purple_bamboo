<?php
session_start();
$_SESSION['role'] = 'admin';
$_SESSION['admin_user'] = 'admin';
$_SESSION['admin_logged_in'] = true;

ob_start();
include __DIR__ . '/../verify_gcash.php';
$html = ob_get_clean();

$ids = [
    'rejectModalRefNo',
    'rejectModalRefDisplay',
    'rejectModalGuest',
    'rejectModalAmount',
    'rejectReasonSelect',
    'rejectOtherReasonGroup',
    'rejectOtherReasonInput',
    'rejectModal'
];

foreach ($ids as $id) {
    if (strpos($html, 'id="' . $id . '"') !== false) {
        echo "Found ID: $id\n";
    } else {
        echo "MISSING ID: $id\n";
    }
}
