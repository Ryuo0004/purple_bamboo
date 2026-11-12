<?php
/**
 * staff_store.php — Personnel (Staff & Admin) Directory Management
 *
 * Manages personnel accounts exclusively in MySQL `users` table
 * so accounts can authenticate seamlessly with Purple Bamboo's Auth system.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';

class StaffStore
{
    // Pure MySQL data store

    /**
     * Read all users from MySQL
     */
    public static function readUsersFromFile()
    {
        global $pdo;
        if ($pdo) {
            try {
                $stmt = $pdo->query("SELECT * FROM users ORDER BY id ASC");
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) {}
        }
        return [];
    }

    /**
     * Save all users back to MySQL (handled per entity)
     */
    public static function writeUsersToFile(array $users)
    {
        return true;
    }

    /**
     * Build standard profile ID
     */
    public static function generateProfileId($role, $id)
    {
        $prefix = ($role === 'admin') ? 'PBR-ADM' : (($role === 'staff') ? 'PBR-STF' : 'PBR-GST');
        return sprintf('%s-%05d', $prefix, (int)$id);
    }

    /**
     * Assemble full name from parts
     */
    public static function buildFullName($first, $middle, $last, $suffix)
    {
        $parts = [];
        if (!empty($last)) {
            $ln = $last;
            if (!empty($suffix)) $ln .= ' ' . $suffix;
            $fn = trim($first . ' ' . $middle);
            return !empty($fn) ? ($ln . ', ' . $fn) : $ln;
        }
        if (!empty($first)) $parts[] = $first;
        if (!empty($middle)) $parts[] = $middle;
        if (!empty($last)) $parts[] = $last;
        if (!empty($suffix)) $parts[] = $suffix;
        return implode(' ', $parts);
    }

    /**
     * Normalize user array for consistent usage
     */
    public static function normalizeUser(array $u, $withHash = false)
    {
        $first  = trim($u['first_name'] ?? '');
        $middle = trim($u['middle_name'] ?? '');
        $last   = trim($u['last_name'] ?? '');
        $suffix = trim($u['suffix'] ?? '');
        $email  = strtolower(trim($u['email'] ?? ''));
        $role   = strtolower(trim($u['role'] ?? 'guest'));
        $id     = (int)($u['id'] ?? $u['user_id'] ?? 0);

        $fullName = trim($u['full_name'] ?? '');
        if (empty($fullName)) {
            $fullName = self::buildFullName($first, $middle, $last, $suffix);
        }

        $profileId = trim($u['profile_id'] ?? '');
        if (empty($profileId) && $id > 0) {
            $profileId = self::generateProfileId($role, $id);
        }

        $norm = [
            'id'             => $id,
            'user_id'        => $id,
            'profile_id'     => $profileId,
            'first_name'     => $first,
            'middle_name'    => $middle,
            'last_name'      => $last,
            'suffix'         => $suffix,
            'full_name'      => $fullName,
            'email'          => $email,
            'phone'          => trim($u['phone'] ?? ''),
            'role'           => $role,
            'created_at'     => $u['created_at'] ?? date('Y-m-d H:i:s'),
            'profile_picture'=> $u['profile_picture'] ?? '',
        ];

        if ($withHash) {
            $norm['password_hash'] = $u['password_hash'] ?? '';
        }

        return $norm;
    }

    /**
     * Retrieve all users merging MySQL and JSON file
     */
    public static function getAllUsers($withHash = false)
    {
        global $pdo;
        $usersMap = [];

        if ($pdo) {
            try {
                // Admin/staff accounts stored in the main users table (lowest priority)
                $stmtU = $pdo->query("SELECT * FROM users WHERE role IN ('admin','staff') ORDER BY user_id ASC");
                foreach (($stmtU->fetchAll(PDO::FETCH_ASSOC) ?: []) as $du) {
                    $em = strtolower(trim($du['email'] ?? ''));
                    if (!empty($em)) {
                        $usersMap[$em] = self::normalizeUser($du, $withHash);
                    }
                }

                // Fetch from separate admins table
                $stmtA = $pdo->query("SELECT *, 'admin' AS role FROM admins ORDER BY id ASC");
                $dbAdmins = $stmtA->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($dbAdmins as $du) {
                    $em = strtolower(trim($du['email'] ?? ''));
                    if (!empty($em)) {
                        $usersMap[$em] = self::normalizeUser($du, $withHash);
                    }
                }

                // Fetch from separate staff table
                $stmtS = $pdo->query("SELECT *, 'staff' AS role FROM staff ORDER BY id ASC");
                $dbStaff = $stmtS->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($dbStaff as $du) {
                    $em = strtolower(trim($du['email'] ?? ''));
                    if (!empty($em)) {
                        $usersMap[$em] = self::normalizeUser($du, $withHash);
                    }
                }
            } catch (Exception $e) {
                error_log("StaffStore::getAllUsers DB error: " . $e->getMessage());
            }
        }

        return array_values($usersMap);
    }

    /**
     * Get users by specific role ('staff' or 'admin') directly from their separated tables
     */
    public static function getUsersByRole($role, $withHash = false)
    {
        global $pdo;
        $role = strtolower(trim($role));
        $table = ($role === 'admin') ? 'admins' : (($role === 'staff') ? 'staff' : null);

        if ($pdo && $table) {
            try {
                $stmt = $pdo->query("SELECT *, '{$role}' AS role FROM `{$table}` ORDER BY id ASC");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $res = [];
                foreach ($rows as $r) {
                    $res[] = self::normalizeUser($r, $withHash);
                }
                return $res;
            } catch (Exception $e) {}
        }

        $all = self::getAllUsers($withHash);
        $filtered = [];
        foreach ($all as $u) {
            if ($u['role'] === $role) {
                $filtered[] = $u;
            }
        }
        return $filtered;
    }

    /**
     * Find a user by email
     */
    public static function findByEmail($email, $withHash = false)
    {
        $email = strtolower(trim($email));
        $all = self::getAllUsers($withHash);
        foreach ($all as $u) {
            if ($u['email'] === $email) {
                return $u;
            }
        }
        return null;
    }

    /**
     * Validate name parts
     */
    private static function validateName($val, $label)
    {
        $val = trim($val);
        if ($val === '') return '';
        if (preg_match('/[0-9]/', $val)) {
            return "{$label} cannot contain numbers.";
        }
        return '';
    }

    /**
     * Generate a high-entropy, secure password for newly provisioned personnel
     * Guarantees length >= 12, uppercase, lowercase, numbers, and symbols.
     */
    public static function generateSecureRandomPassword($role = 'staff')
    {
        $prefix = (strtolower($role) === 'admin') ? 'PB-Admin' : 'PB-Staff';
        $charsUpper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $charsLower = 'abcdefghijkmnpqrstuvwxyz';
        $charsNum   = '23456789';
        $symbols    = '!@#$%&*';

        $part = $charsUpper[random_int(0, strlen($charsUpper) - 1)] .
                $charsLower[random_int(0, strlen($charsLower) - 1)] .
                $charsNum[random_int(0, strlen($charsNum) - 1)] .
                $symbols[random_int(0, strlen($symbols) - 1)] .
                $charsLower[random_int(0, strlen($charsLower) - 1)] .
                $charsNum[random_int(0, strlen($charsNum) - 1)];

        return $prefix . '#' . $part . random_int(10, 99);
    }

    /**
     * Create a new personnel account (admin or staff)
     */
    public static function createPersonnel(array $data)
    {
        global $pdo;

        $role = strtolower(trim($data['role'] ?? 'staff'));
        if (!in_array($role, ['admin', 'staff'])) {
            $role = 'staff';
        }

        $firstName  = trim($data['first_name'] ?? '');
        $middleName = trim($data['middle_name'] ?? '');
        $lastName   = trim($data['last_name'] ?? '');
        $suffix     = trim($data['suffix'] ?? '');
        $email      = strtolower(trim($data['email'] ?? ''));
        $phone      = trim($data['phone'] ?? '');
        $password   = $data['password'] ?? '';
        $confirm    = $data['confirm_password'] ?? '';

        // Validation
        if (empty($firstName)) {
            return ['success' => false, 'error' => 'First name is required.'];
        }
        if (empty($lastName)) {
            return ['success' => false, 'error' => 'Last name is required.'];
        }
        if ($err = self::validateName($firstName, 'First name')) return ['success' => false, 'error' => $err];
        if ($err = self::validateName($middleName, 'Middle name')) return ['success' => false, 'error' => $err];
        if ($err = self::validateName($lastName, 'Last name')) return ['success' => false, 'error' => $err];

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Please provide a valid email address.'];
        }

        if (!preg_match('/^[a-zA-Z0-9._%+-]+@gmail\.com$/i', $email)) {
            return ['success' => false, 'error' => 'Only valid Google accounts (@gmail.com) are accepted. Other email domains are not allowed.'];
        }

        // Phone validation: normalize Philippine numbers (+639..., 639..., 09...)
        if (empty($phone)) {
            return ['success' => false, 'error' => 'Contact phone number is required.'];
        }
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 12 && substr($digits, 0, 3) === '639') {
            $digits = '0' . substr($digits, 2);
        } elseif (strlen($digits) === 10 && substr($digits, 0, 1) === '9') {
            $digits = '0' . $digits;
        }
        if (strlen($digits) !== 11 || substr($digits, 0, 2) !== '09') {
            return ['success' => false, 'error' => 'Contact phone number must be 11 digits starting with 09 (e.g. 09171234567).'];
        }
        $phone = $digits;

        // Check if email already in use
        $existing = self::findByEmail($email);
        if ($existing) {
            return ['success' => false, 'error' => 'An account with this email address already exists.'];
        }

        // Password handling: if not provided or empty, auto-generate strong credentials
        $generatedPassword = '';
        if (empty($password)) {
            $generatedPassword = self::generateSecureRandomPassword($role);
            $password = $generatedPassword;
            $confirm = $generatedPassword;
        } else {
            if ($password !== $confirm) {
                return ['success' => false, 'error' => 'Password and Confirm Password do not match.'];
            }
            $strength = validate_password_strength($password);
            if (!$strength['valid']) {
                return ['success' => false, 'error' => $strength['error']];
            }
        }

        $pwHash = hash_password($password);
        $fullName = self::buildFullName($firstName, $middleName, $lastName, $suffix);
        $createdAt = date('Y-m-d H:i:s');

        // Determine new ID fallback
        $newId = 0;
        $fileUsers = self::readUsersFromFile();
        $maxId = 200;
        foreach ($fileUsers as $fu) {
            $fid = (int)($fu['id'] ?? $fu['user_id'] ?? 0);
            if ($fid > $maxId) $maxId = $fid;
        }

        // 1. Insert into MySQL (admins or staff table)
        $table = ($role === 'admin') ? 'admins' : 'staff';
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO `{$table}` (first_name, middle_name, last_name, suffix, email, phone, password_hash, role, created_at)
                    VALUES (:fn, :mn, :ln, :suf, :em, :ph, :pw, :role, :created)
                ");
                $stmt->execute([
                    ':fn'      => $firstName,
                    ':mn'      => $middleName,
                    ':ln'      => $lastName,
                    ':suf'     => $suffix,
                    ':em'      => $email,
                    ':ph'      => $phone,
                    ':pw'      => $pwHash,
                    ':role'    => $role,
                    ':created' => $createdAt
                ]);
                $newId = (int)$pdo->lastInsertId();
            } catch (Exception $e) {
                error_log("StaffStore::createPersonnel DB insert error: " . $e->getMessage());
                return ['success' => false, 'error' => 'Database error saving staff: ' . $e->getMessage()];
            }
        }

        if ($newId <= 0) {
            $newId = $maxId + 1;
        }

        $profileId = self::generateProfileId($role, $newId);

        // Update profile_id in MySQL separated table
        if ($pdo && $newId > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE `{$table}` SET profile_id = :pid WHERE id = :id");
                $stmt->execute([':pid' => $profileId, ':id' => $newId]);
            } catch (Exception $e) {
                error_log("StaffStore::createPersonnel profile_id update error: " . $e->getMessage());
            }

            // Also persist into MySQL users table so record exists in both tables
            try {
                $stmtU = $pdo->prepare("
                    INSERT INTO users (profile_id, full_name, first_name, middle_name, last_name, suffix, email, phone, password_hash, role, status, created_at)
                    VALUES (:pid, :fullname, :fn, :mn, :ln, :suf, :em, :ph, :pw, :role, 'active', :created)
                    ON DUPLICATE KEY UPDATE
                        profile_id = VALUES(profile_id),
                        full_name = VALUES(full_name),
                        first_name = VALUES(first_name),
                        middle_name = VALUES(middle_name),
                        last_name = VALUES(last_name),
                        suffix = VALUES(suffix),
                        phone = VALUES(phone),
                        password_hash = VALUES(password_hash),
                        role = VALUES(role),
                        status = 'active'
                ");
                $stmtU->execute([
                    ':pid'      => $profileId,
                    ':fullname' => $fullName,
                    ':fn'       => $firstName,
                    ':mn'       => $middleName,
                    ':ln'       => $lastName,
                    ':suf'      => $suffix,
                    ':em'       => $email,
                    ':ph'       => $phone,
                    ':pw'       => $pwHash,
                    ':role'     => $role,
                    ':created'  => $createdAt
                ]);
            } catch (Exception $e) {
                error_log("StaffStore::createPersonnel users table insert error: " . $e->getMessage());
            }
        }

        if (function_exists('log_activity')) {
            log_activity("PERSONNEL_CREATED", "Created new {$role} account: {$fullName} ({$email}, {$profileId})");
        }

        return [
            'success'            => true,
            'user_id'            => $newId,
            'profile_id'         => $profileId,
            'full_name'          => $fullName,
            'generated_password' => $generatedPassword,
            'plain_password'     => $password
        ];
    }

    /**
     * Update personnel details (name, phone, and optional password)
     */
    public static function updatePersonnel($targetEmail, array $data)
    {
        global $pdo;
        $targetEmail = strtolower(trim($targetEmail));
        $existing = self::findByEmail($targetEmail, true);

        if (!$existing) {
            return ['success' => false, 'error' => 'Personnel account not found.'];
        }

        $firstName  = trim($data['first_name'] ?? '');
        $middleName = trim($data['middle_name'] ?? '');
        $lastName   = trim($data['last_name'] ?? '');
        $suffix     = trim($data['suffix'] ?? '');
        $phone      = trim($data['phone'] ?? '');
        $newPass    = $data['password'] ?? '';
        $confirm    = $data['confirm_password'] ?? '';

        if (empty($firstName)) return ['success' => false, 'error' => 'First name is required.'];
        if (empty($lastName)) return ['success' => false, 'error' => 'Last name is required.'];
        if ($err = self::validateName($firstName, 'First name')) return ['success' => false, 'error' => $err];
        if ($err = self::validateName($middleName, 'Middle name')) return ['success' => false, 'error' => $err];
        if ($err = self::validateName($lastName, 'Last name')) return ['success' => false, 'error' => $err];

        if (!empty($phone)) {
            $digits = preg_replace('/\D/', '', $phone);
            if (strlen($digits) !== 11 || substr($digits, 0, 2) !== '09') {
                return ['success' => false, 'error' => 'Contact phone number must be exactly 11 digits starting with 09 (e.g. 09171234567).'];
            }
            $phone = $digits;
        }

        $pwHash = $existing['password_hash'] ?? '';
        if (!empty($newPass)) {
            if ($newPass !== $confirm) {
                return ['success' => false, 'error' => 'New password and confirmation do not match.'];
            }
            $strength = validate_password_strength($newPass);
            if (!$strength['valid']) {
                return ['success' => false, 'error' => $strength['error']];
            }
            $pwHash = hash_password($newPass);
        }

        $fullName = self::buildFullName($firstName, $middleName, $lastName, $suffix);

        // 1. Update in MySQL (admins or staff table)
        $role = strtolower(trim($existing['role'] ?? 'staff'));
        $table = ($role === 'admin') ? 'admins' : 'staff';
        if ($pdo) {
            try {
                $sql = "UPDATE `{$table}` SET first_name = :fn, middle_name = :mn, last_name = :ln, suffix = :suf, phone = :ph";
                $params = [
                    ':fn'  => $firstName,
                    ':mn'  => $middleName,
                    ':ln'  => $lastName,
                    ':suf' => $suffix,
                    ':ph'  => $phone,
                    ':em'  => $targetEmail
                ];
                if (!empty($newPass)) {
                    $sql .= ", password_hash = :pw";
                    $params[':pw'] = $pwHash;
                }
                $sql .= " WHERE LOWER(email) = :em";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } catch (Exception $e) {
                // ignore
            }
            // Also update the legacy users table (where some admin accounts live)
            try {
                $sqlU = "UPDATE users SET first_name = :fn, middle_name = :mn, last_name = :ln, suffix = :suf, phone = :ph, full_name = :full";
                $paramsU = [
                    ':fn' => $firstName, ':mn' => $middleName, ':ln' => $lastName,
                    ':suf' => $suffix, ':ph' => $phone, ':full' => $fullName, ':em' => $targetEmail
                ];
                if (!empty($newPass)) {
                    $sqlU .= ", password_hash = :pw";
                    $paramsU[':pw'] = $pwHash;
                }
                $sqlU .= " WHERE LOWER(email) = :em AND role IN ('admin','staff')";
                $pdo->prepare($sqlU)->execute($paramsU);
            } catch (Exception $e) {
                error_log("StaffStore::updatePersonnel users table error: " . $e->getMessage());
            }
        }

        // Update active session if target is current user
        if (isset($_SESSION['email']) && strtolower(trim($_SESSION['email'])) === $targetEmail) {
            $_SESSION['first_name']  = $firstName;
            $_SESSION['middle_name'] = $middleName;
            $_SESSION['last_name']   = $lastName;
            $_SESSION['suffix']      = $suffix;
            $_SESSION['full_name']   = $fullName;
            if (!empty($phone)) $_SESSION['phone'] = $phone;
        }

        if (function_exists('log_activity')) {
            log_activity("PERSONNEL_UPDATED", "Updated personnel details for {$fullName} ({$targetEmail})");
        }

        return [
            'success'          => true,
            'message'          => 'Personnel details updated successfully!',
            'profile_id'       => $existing['profile_id'] ?? '',
            'full_name'        => $fullName,
            'password_updated' => !empty($newPass)
        ];
    }

    /**
     * Delete personnel account
     */
    public static function deletePersonnel($targetEmail, $currentUserEmail)
    {
        global $pdo;
        $targetEmail = strtolower(trim($targetEmail));
        $currentUserEmail = strtolower(trim($currentUserEmail));

        if ($targetEmail === $currentUserEmail) {
            return ['success' => false, 'error' => 'You cannot delete your own account while currently logged in.'];
        }

        $existing = self::findByEmail($targetEmail);
        if (!$existing) {
            return ['success' => false, 'error' => 'Personnel account not found.'];
        }

        // If target is an admin, ensure at least one other admin remains
        if ($existing['role'] === 'admin') {
            $admins = self::getUsersByRole('admin');
            if (count($admins) <= 1) {
                return ['success' => false, 'error' => 'Cannot delete the only administrator account on the system.'];
            }
        }

        // 1. Delete from MySQL (admins or staff table)
        $role = strtolower(trim($existing['role'] ?? 'staff'));
        $table = ($role === 'admin') ? 'admins' : 'staff';
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE LOWER(email) = :em");
                $stmt->execute([':em' => $targetEmail]);
            } catch (Exception $e) {
                // ignore
            }
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE LOWER(email) = :em AND role IN ('admin', 'staff')");
                $stmt->execute([':em' => $targetEmail]);
            } catch (Exception $e) {
                // ignore
            }
        }

        if (function_exists('log_activity')) {
            log_activity("PERSONNEL_DELETED", "Deleted {$existing['role']} account: {$existing['full_name']} ({$targetEmail})");
        }

        return ['success' => true, 'message' => 'Personnel account removed successfully.'];
    }

    /**
     * Change password with current password verification
     */
    public static function changePassword($email, $currentPassword, $newPassword, $confirmPassword)
    {
        global $pdo;
        $email = strtolower(trim($email));
        $user = self::findByEmail($email, true);

        if (!$user) {
            return ['success' => false, 'error' => 'Account not found.'];
        }

        $storedHash = $user['password_hash'] ?? '';
        if (empty($storedHash)) {
            // Check demo defaults
            if ($user['role'] === 'admin') {
                $storedHash = hash_password('Admin123');
            } else {
                $storedHash = hash_password('staff123');
            }
        }

        if (!verify_password($currentPassword, $storedHash)) {
            return ['success' => false, 'error' => 'Current password entered is incorrect.'];
        }

        if ($newPassword !== $confirmPassword) {
            return ['success' => false, 'error' => 'New password and confirmation do not match.'];
        }

        $strength = validate_password_strength($newPassword);
        if (!$strength['valid']) {
            return ['success' => false, 'error' => $strength['error']];
        }

        $newHash = hash_password($newPassword);

        // 1. Update in MySQL (admins or staff table)
        $role = strtolower(trim($user['role'] ?? 'staff'));
        $table = ($role === 'admin') ? 'admins' : 'staff';
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("UPDATE `{$table}` SET password_hash = :pw WHERE LOWER(email) = :em");
                $stmt->execute([':pw' => $newHash, ':em' => $email]);
            } catch (Exception $e) {
                // ignore
            }
            try {
                $stmt = $pdo->prepare("UPDATE users SET password_hash = :pw WHERE LOWER(email) = :em AND role IN ('admin','staff')");
                $stmt->execute([':pw' => $newHash, ':em' => $email]);
            } catch (Exception $e) {
                error_log("StaffStore::changePassword users table error: " . $e->getMessage());
            }
        }

        // 2. Update in users.json
        $fileUsers = self::readUsersFromFile();
        $found = false;
        foreach ($fileUsers as $idx => $fu) {
            if (strtolower(trim($fu['email'] ?? '')) === $email) {
                $fileUsers[$idx]['password_hash'] = $newHash;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $user['password_hash'] = $newHash;
            $fileUsers[] = $user;
        }

        self::writeUsersToFile($fileUsers);

        if (function_exists('log_activity')) {
            log_activity("PASSWORD_CHANGED", "Admin/Staff password changed for {$email}");
        }

        return [
            'success'    => true,
            'message'    => 'Password updated successfully!',
            'profile_id' => $user['profile_id'] ?? '',
            'full_name'  => $user['full_name'] ?? '',
            'role'       => $role
        ];
    }
}
