<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/auth.php';

// Handle POST authentication for backwards compatibility with any existing forms/tests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    $result = Auth::login($email, $password, 'auto');
    if ($result['success']) {
        $actual_role = strtolower($result['role'] ?? ($result['user']['role'] ?? 'guest'));
        if ($actual_role === 'admin') {
            header("Location: admin-dashboard.php");
            exit();
        } elseif ($actual_role === 'staff') {
            header("Location: bookings.php");
            exit();
        } else {
            header("Location: index.php?login=1");
            exit();
        }
    } else {
        header("Location: login.php?error=invalid_credentials");
        exit();
    }
}

// Direct all GET traffic (such as ?logged_out=1 or manual visits) directly to unified login.php
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header("Location: login.php" . $qs);
exit();

