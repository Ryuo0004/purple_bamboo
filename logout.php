<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If confirmed, proceed with logout
if (isset($_GET['confirm']) && $_GET['confirm'] == '1') {
    session_unset();
    session_destroy();
    header("Location: login.php?logged_out=1");
    exit();
}

// Fallback Confirmation UI if accessed directly without javascript modal
$user_role = $_SESSION['role'] ?? 'guest';
$is_admin = in_array(strtolower($user_role), ['admin', 'staff']);
$return_url = $is_admin ? 'admin-dashboard.php' : (!empty($_SESSION['user_id']) ? 'user-dashboard.php' : 'index.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Out Confirmation | Purple Bamboo Resort</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0b0718 0%, #170d2c 50%, #200b3b 100%);
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin: 0;
            padding: 1rem;
            color: #ffffff;
        }
        .logout-card {
            background: #1e1b4b;
            border: 1.5px solid #4c1d95;
            border-radius: 18px;
            padding: 2.25rem 2rem;
            max-width: 420px;
            width: 100%;
            text-align: center;
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.5), 0 0 32px rgba(124, 58, 237, 0.2);
            animation: fadeIn 0.25s ease-out;
        }
        .logout-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: rgba(244, 63, 94, 0.15);
            color: #f43f5e;
            border: 2px solid rgba(244, 63, 94, 0.35);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            margin-bottom: 1.25rem;
        }
        .logout-title {
            font-family: 'Outfit', sans-serif;
            color: #ffffff;
            font-size: 1.4rem;
            font-weight: 700;
            margin: 0 0 0.5rem 0;
        }
        .logout-text {
            color: #cbd5e1;
            font-size: 1.05rem;
            font-weight: 500;
            margin: 0 0 1.75rem 0;
        }
        .logout-buttons {
            display: flex;
            gap: 0.85rem;
            justify-content: center;
        }
        .btn-confirm {
            flex: 1;
            padding: 0.8rem 1.25rem;
            border-radius: 10px;
            font-size: 0.98rem;
            font-weight: 800;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #ffffff;
            border: 1px solid #ef4444;
            transition: all 0.2s ease;
        }
        .btn-confirm:hover {
            background: #b91c1c;
            box-shadow: 0 6px 16px rgba(239, 68, 68, 0.35);
            transform: translateY(-1px);
        }
        .btn-cancel {
            flex: 1;
            padding: 0.8rem 1.25rem;
            border-radius: 10px;
            font-size: 0.98rem;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #334155;
            color: #ffffff;
            border: 1px solid #475569;
            transition: all 0.2s ease;
        }
        .btn-cancel:hover {
            background: #475569;
            transform: translateY(-1px);
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
    </style>
</head>
<body>
    <div class="logout-card">
        <div class="logout-icon">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </div>
        <h2 class="logout-title">Sign Out Confirmation</h2>
        <p class="logout-text">Do you really want to logout?</p>
        <div class="logout-buttons">
            <a href="<?= htmlspecialchars($return_url) ?>" class="btn-cancel">
                <i class="fa-solid fa-xmark"></i> No
            </a>
            <a href="logout.php?confirm=1" class="btn-confirm">
                <i class="fa-solid fa-check"></i> Yes
            </a>
        </div>
    </div>
</body>
</html>
