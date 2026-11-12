<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;
$_SESSION['full_name'] = 'Resort Admin';
$_SESSION['username'] = 'admin';
header("Location: ../browse_kubos.php");
exit;
