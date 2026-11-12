<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';

/**
 * XSS Escaping Helper
 */
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * CSRF Protection
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
}

function verify_csrf_token() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (empty($token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            die('<div style="font-family:sans-serif; padding:2rem; text-align:center; color:#b91c1c;">
                <h2>Security Alert: Invalid CSRF Token</h2>
                <p>Your request could not be authenticated. Please go back, refresh the page, and try submitting again.</p>
                <a href="index.php" style="color:#7c3aed; text-decoration:none; font-weight:bold;">Return to Home</a>
            </div>');
        }
    }
}

/**
 * Password Security & Validation
 */
function validate_password_strength($password) {
    if (strlen($password) < 9) {
        return [
            'valid' => false,
            'error' => 'Password must be at least 9 characters long.'
        ];
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return [
            'valid' => false,
            'error' => 'Password must contain at least 1 uppercase (Big) letter (A-Z).'
        ];
    }
    if (!preg_match('/[a-z]/', $password)) {
        return [
            'valid' => false,
            'error' => 'Password must contain lowercase letters (a-z) to ensure a strong password.'
        ];
    }
    if (!preg_match('/[0-9]/', $password)) {
        return [
            'valid' => false,
            'error' => 'Password must contain at least 1 number (0-9).'
        ];
    }
    return ['valid' => true, 'error' => ''];
}

function hash_password($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}

function verify_password($password, $hash) {
    if (empty($hash)) return false;
    // Allow plain password fallback for default seed accounts if hash is simple
    if (in_array($hash, ['admin123', 'password123', 'Admin123', 'Password123'])) {
        return ($password === $hash || strtolower($password) === strtolower($hash));
    }
    return password_verify($password, $hash);
}

/**
 * Role-Based Access Control (RBAC)
 */
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header("Location: login.php?required=1");
        exit();
    }
}

function require_role($allowed_roles) {
    require_login();
    if (!is_array($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }
    $current_role = $_SESSION['role'] ?? 'guest';
    if (!in_array($current_role, $allowed_roles)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif; padding:2rem; text-align:center; color:#b91c1c;">
            <h2>Access Denied: Insufficient Role Permissions</h2>
            <p>You do not have authorization to view this admin module.</p>
            <a href="user-dashboard.php" style="color:#7c3aed; text-decoration:none; font-weight:bold;">Return to Guest Portal</a>
        </div>');
    }
}

if (!function_exists('log_activity')) {
    function log_activity($arg1, $arg2 = null, $arg3 = null, $arg4 = null) {
        global $pdo;
        
        // Handle legacy 4-parameter signature: log_activity($pdo, $user_id, $action_type, $description)
        if ($arg1 instanceof PDO || ($arg3 !== null && is_string($arg3))) {
            $user_id = $arg2;
            $action = $arg3;
            $details = $arg4;
        } else {
            // Standard 2-parameter signature: log_activity($action, $details = null)
            $action = $arg1;
            $details = $arg2;
            $user_id = $_SESSION['user_id'] ?? null;
        }

        $user_email = $_SESSION['email'] ?? 'anonymous';
        $user_name = $_SESSION['full_name'] ?? 'Guest User';
        $profile_id = $_SESSION['profile_id'] ?? 'PBR-GST-GUEST';
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $timestamp = date('Y-m-d H:i:s');

        if ($pdo) {
            try {
                $validUid = null;
                if (!empty($user_id)) {
                    $chk = $pdo->prepare("SELECT user_id FROM users WHERE user_id = :uid LIMIT 1");
                    $chk->execute([':uid' => $user_id]);
                    if ($chk->fetchColumn()) {
                        $validUid = $user_id;
                    }
                }
                $desc = is_array($details) ? json_encode($details) : ($details ?: $action);
                if (!empty($user_email) && $user_email !== 'anonymous') {
                    $desc .= " [{$user_email}]";
                }
                $stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, action_type, description, ip_address)
                    VALUES (:uid, :action, :desc, :ip)
                ");
                $stmt->execute([
                    ':uid'    => $validUid,
                    ':action' => $action,
                    ':desc'   => $desc,
                    ':ip'     => $ip_address
                ]);
            } catch (PDOException $e) {
                error_log("log_activity MySQL error: " . $e->getMessage());
            }
        }
    }
}

function get_recent_guest_logins() {
    global $pdo;
    $logins = [];

    if ($pdo) {
        try {
            $stmt = $pdo->query("
                SELECT a.log_id AS id, a.user_id, a.user_email AS email, a.action, a.ip_address, a.details, a.created_at AS timestamp
                FROM activity_logs a
                ORDER BY a.log_id DESC
                LIMIT 25
            ");
            $db_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($db_logs as $log) {
                $fullName = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? ''));
                if (empty($fullName)) {
                    $fullName = str_replace(['Logged in as ', 'Demo login as '], '', $log['details'] ?: 'Guest Account');
                }
                $logins[] = [
                    'id' => 'LOG-' . $log['id'],
                    'user_id' => $log['user_id'],
                    'profile_id' => sprintf("PBR-GST-%05d", $log['user_id'] ?: 101),
                    'full_name' => $fullName,
                    'email' => $log['email'],
                    'role' => $log['role'] ?? 'guest',
                    'action' => $log['action'],
                    'details' => $log['details'],
                    'ip_address' => $log['ip_address'],
                    'timestamp' => $log['timestamp']
                ];
            }
        } catch (PDOException $e) {
            error_log("get_recent_guest_logins MySQL error: " . $e->getMessage());
        }
    }

    return $logins;
}

/**
 * Phone Number Validation & Formatting Helper
 * Limits phone numbers to standard 11 digits (or 13 chars formatted with hyphens).
 */
function sanitize_phone_number($phone) {
    $phone = trim($phone ?? '');
    // Retain digits, plus sign, hyphens, spaces up to 13 characters
    $cleaned = preg_replace('/[^\d\+\-\s]/', '', $phone);
    return substr($cleaned, 0, 13);
}

function validate_phone_number($phone) {
    $digits = preg_replace('/\D/', '', $phone);
    return (strlen($digits) >= 10 && strlen($digits) <= 12);
}
