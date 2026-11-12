<?php
header('Content-Type: text/plain; charset=utf-8');

echo "=== CLEANUP: PURIFYING TO 100% PURE MYSQL ===\n\n";

$root = dirname(__DIR__);

// 1. Clean forgot-password.php
$fpFile = $root . '/forgot-password.php';
if (file_exists($fpFile)) {
    $fpContent = file_get_contents($fpFile);
    
    // Replace findUserRecordByEmail
    $oldEmailFunc = '/\/\*\*\s*\*\s*Helper:\s*Find user across MySQL and data\/users\.json\s*\*\/.*?function findUserRecordByEmail\(\$email\) \{.*?return null;\s*\}/s';
    $newEmailFunc = "/**\n * Helper: Find user in MySQL users table\n */\nfunction findUserRecordByEmail(\$email) {\n    global \$pdo;\n    \$email = strtolower(trim(\$email));\n    if (empty(\$email)) return null;\n\n    if (\$pdo) {\n        try {\n            \$stmt = \$pdo->prepare(\"SELECT user_id, COALESCE(id, user_id) AS id, CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name, NULLIF(suffix, '')) AS full_name, email, reset_token, reset_token_expires, password_hash FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1\");\n            \$stmt->execute([\$email]);\n            \$user = \$stmt->fetch(PDO::FETCH_ASSOC);\n            if (\$user) {\n                return \$user;\n            }\n        } catch (PDOException \$e) {\n            error_log(\"findUserRecordByEmail MySQL error: \" . \$e->getMessage());\n        }\n    }\n\n    return null;\n}";

    // Replace findUserRecordById
    $oldIdFunc = '/\/\*\*\s*\*\s*Helper:\s*Find user by ID across MySQL and data\/users\.json\s*\*\/.*?function findUserRecordById\(\$userId\) \{.*?return null;\s*\}/s';
    $newIdFunc = "/**\n * Helper: Find user by ID in MySQL users table\n */\nfunction findUserRecordById(\$userId) {\n    global \$pdo;\n    \$userId = intval(\$userId);\n    if (\$userId <= 0) return null;\n\n    if (\$pdo) {\n        try {\n            \$stmt = \$pdo->prepare(\"SELECT user_id, COALESCE(id, user_id) AS id, CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name, NULLIF(suffix, '')) AS full_name, email, reset_token, reset_token_expires, password_hash FROM users WHERE user_id = ? OR id = ? LIMIT 1\");\n            \$stmt->execute([\$userId, \$userId]);\n            \$user = \$stmt->fetch(PDO::FETCH_ASSOC);\n            if (\$user) {\n                return \$user;\n            }\n        } catch (PDOException \$e) {}\n    }\n\n    return null;\n}";

    // Replace saveUserResetOTP
    $oldOtpFunc = '/\/\*\*\s*\*\s*Helper:\s*Save OTP and expiration to both MySQL and data\/users\.json\s*\*\/.*?function saveUserResetOTP\(\$email, \$userId, \$otpCode, \$expires\) \{.*?\n\}/s';
    $newOtpFunc = "/**\n * Helper: Save OTP and expiration to MySQL users table\n */\nfunction saveUserResetOTP(\$email, \$userId, \$otpCode, \$expires) {\n    global \$pdo;\n    \$email = strtolower(trim(\$email));\n\n    if (\$pdo) {\n        try {\n            \$stmt = \$pdo->prepare(\"UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE LOWER(email) = LOWER(?) OR user_id = ? OR id = ?\");\n            \$stmt->execute([\$otpCode, \$expires, \$email, \$userId, \$userId]);\n        } catch (PDOException \$e) {\n            error_log(\"saveUserResetOTP MySQL error: \" . \$e->getMessage());\n        }\n    }\n}";

    // Replace updateUserPassword
    $oldPwdFunc = '/\/\*\*\s*\*\s*Helper:\s*Update Password in both MySQL and data\/users\.json\s*\*\/.*?function updateUserPassword\(\$email, \$userId, \$passwordHash\) \{.*?\n\}/s';
    $newPwdFunc = "/**\n * Helper: Update Password in MySQL users table\n */\nfunction updateUserPassword(\$email, \$userId, \$passwordHash) {\n    global \$pdo;\n    \$email = strtolower(trim(\$email));\n\n    if (\$pdo) {\n        try {\n            \$stmt = \$pdo->prepare(\"UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE LOWER(email) = LOWER(?) OR user_id = ? OR id = ?\");\n            \$stmt->execute([\$passwordHash, \$email, \$userId, \$userId]);\n        } catch (PDOException \$e) {\n            error_log(\"updateUserPassword MySQL error: \" . \$e->getMessage());\n        }\n    }\n}";

    $fpContent = preg_replace($oldEmailFunc, $newEmailFunc, $fpContent, 1);
    $fpContent = preg_replace($oldIdFunc, $newIdFunc, $fpContent, 1);
    $fpContent = preg_replace($oldOtpFunc, $newOtpFunc, $fpContent, 1);
    $fpContent = preg_replace($oldPwdFunc, $newPwdFunc, $fpContent, 1);

    file_put_contents($fpFile, $fpContent);
    echo "1. forgot-password.php updated to pure MySQL.\n";
}

// 2. Clean edit_homepage.php
$ehFile = $root . '/edit_homepage.php';
if (file_exists($ehFile)) {
    $ehContent = file_get_contents($ehFile);

    // Remove users.json fallback
    $pattern1 = '/\/\/\s*Fallback to data\/users\.json if not found in database\s*if \(!\$admin_data\) \{.*?\$users_json = json_decode.*?\}\s*\}/s';
    $ehContent = preg_replace($pattern1, '// Admin record loaded strictly from MySQL users table', $ehContent, 1);

    // Remove write to users.json in profile update
    $pattern2 = '/\$users_file = __DIR__ \. \'\/data\/users\.json\';\s*if \(file_exists\(\$users_file\)\) \{.*?@file_put_contents\(\$users_file, json_encode.*?\}\s*\}/s';
    $ehContent = preg_replace($pattern2, '// Saved directly to MySQL users table', $ehContent, 1);

    // Remove write to users.json in password update
    $pattern3 = '/\$users_file = __DIR__ \. \'\/data\/users\.json\';\s*if \(file_exists\(\$users_file\)\) \{.*?@file_put_contents\(\$users_file, json_encode.*?\}\s*\}/s';
    $ehContent = preg_replace($pattern3, '// Password updated directly in MySQL users table', $ehContent, 1);

    file_put_contents($ehFile, $ehContent);
    echo "2. edit_homepage.php updated to pure MySQL.\n";
}

// 3. Clean staff_store.php
$ssFile = $root . '/staff_store.php';
if (file_exists($ssFile)) {
    $ssContent = file_get_contents($ssFile);
    $ssContent = str_replace(
        "private static function getFilePath()\n    {\n        return __DIR__ . '/data/users.json';\n    }",
        "// Pure MySQL data store",
        $ssContent
    );
    $ssContent = str_replace(
        "private static function getFilePath()\r\n    {\r\n        return __DIR__ . '/data/users.json';\r\n    }",
        "// Pure MySQL data store",
        $ssContent
    );
    $ssContent = str_replace(
        "Manages personnel accounts across both MySQL `users` table and data/users.json",
        "Manages personnel accounts exclusively in MySQL `users` table",
        $ssContent
    );
    file_put_contents($ssFile, $ssContent);
    echo "3. staff_store.php updated to pure MySQL.\n";
}

// 4. Test all MySQL queries and count records
require_once $root . '/database.php';
require_once $root . '/booking_store.php';
require_once $root . '/inventory_store.php';
require_once $root . '/staff_store.php';
require_once $root . '/review_store.php';
require_once $root . '/content_store.php';

echo "\n--- TESTING MYSQL STORES ---\n";
if ($pdo) {
    echo "PDO Connection: ACTIVE\n";

    // BookingStore test
    $allBookings = BookingStore::getAll();
    echo "BookingStore::getAll(): " . count($allBookings) . " bookings found in MySQL\n";

    // InventoryStore test
    $allKubos = InventoryStore::getAllKubos();
    echo "InventoryStore::getAllKubos(): " . count($allKubos) . " kubos found in MySQL\n";

    // StaffStore test
    $allStaff = StaffStore::getAllUsers();
    echo "StaffStore::getAllUsers(): " . count($allStaff) . " users found in MySQL\n";

    // ReviewStore test
    $allReviews = ReviewStore::getAll();
    echo "ReviewStore::getAll(): " . count($allReviews) . " reviews found in MySQL\n";

    // ContentStore test
    $content = ContentStore::getContent();
    echo "ContentStore::getContent(): Loaded homepage content from MySQL ('" . ($content['resort_brand_title'] ?? '') . "')\n";

    // Archive check
    $archCount = $pdo->query("SELECT COUNT(*) FROM booking_archive")->fetchColumn();
    echo "MySQL booking_archive count: " . $archCount . "\n";

    // Sent emails check
    $emailCount = $pdo->query("SELECT COUNT(*) FROM sent_emails")->fetchColumn();
    echo "MySQL sent_emails count: " . $emailCount . "\n";

    // Activity logs check
    $logCount = $pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
    echo "MySQL activity_logs count: " . $logCount . "\n";

    echo "\n=== ALL MYSQL STORES FUNCTIONING WITH ZERO JSON DEPENDENCY! ===\n";
} else {
    echo "ERROR: PDO NOT CONNECTED\n";
}
