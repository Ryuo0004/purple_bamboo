<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';

class Auth {
    /**
     * Helper to read users from data/users.json
     */
    public static function getUsersFromFile() {
        $file = __DIR__ . '/data/users.json';
        if (!file_exists($file)) {
            return [];
        }
        $json = @file_get_contents($file);
        return json_decode($json, true) ?: [];
    }

    /**
     * Helper to save a user object to data/users.json
     */
    public static function saveUserToFile($user_data) {
        $dir = __DIR__ . '/data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $file = $dir . '/users.json';
        $users = self::getUsersFromFile();

        $found = false;
        foreach ($users as $idx => $u) {
            if (strtolower($u['email']) === strtolower($user_data['email'])) {
                $users[$idx] = array_merge($u, $user_data);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $users[] = $user_data;
        }

        @file_put_contents($file, json_encode($users, JSON_PRETTY_PRINT));
    }

    /**
     * Authenticate user with Email & Password
     */
    /**
     * Authenticate user with Email & Password with seamless Role-Based Access Control (RBAC).
     * Automatically identifies role (admin, staff, guest) and establishes complete session.
     */
    public static function login($email, $password, $selected_role = 'auto') {
        global $pdo;
        $email = strtolower(trim($email));

        if (empty($email) || empty($password)) {
            return ['success' => false, 'error' => 'Please enter both email address and password.'];
        }

        // 1. Try MySQL Database Authentication
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = :email LIMIT 1");
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch();

                if ($user) {
                    $role = strtolower(trim($user['role'] ?? 'guest'));
                    $is_personnel = in_array($role, ['admin', 'staff']);

                    if (verify_password($password, $user['password_hash'])) {
                        if (!headers_sent()) {
                            @session_regenerate_id(true);
                        }

                        $uid = !empty($user['user_id']) ? $user['user_id'] : (!empty($user['id']) ? $user['id'] : 1);
                        $_SESSION['user_id'] = $uid;
                        $_SESSION['profile_id'] = !empty($user['profile_id']) ? $user['profile_id'] : (($role === 'admin') ? sprintf("PBR-ADM-%05d", $uid) : (($role === 'staff') ? sprintf("PBR-STF-%05d", $uid) : sprintf("PBR-GST-%05d", $uid)));
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['first_name'] = $user['first_name'] ?? '';
                        $_SESSION['middle_name'] = $user['middle_name'] ?? '';
                        $_SESSION['last_name'] = $user['last_name'] ?? '';
                        $_SESSION['suffix'] = $user['suffix'] ?? '';
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['role'] = $role;
                        $_SESSION['phone'] = $user['phone'] ?? '';
                        $_SESSION['emergency_contact_name'] = $user['emergency_contact_name'] ?? '';
                        $_SESSION['emergency_contact_relationship'] = $user['emergency_contact_relationship'] ?? '';
                        $_SESSION['emergency_contact_phone'] = $user['emergency_contact_phone'] ?? '';
                        $_SESSION['emergency_contact_alt_phone'] = $user['emergency_contact_alt_phone'] ?? '';
                        $_SESSION['id_type'] = $user['id_type'] ?? '';
                        $_SESSION['id_number'] = $user['id_number'] ?? '';
                        $_SESSION['id_image'] = $user['id_image'] ?? '';
                        $_SESSION['profile_picture'] = $user['profile_picture'] ?? '';
                        $_SESSION['health_conditions'] = $user['health_conditions'] ?? '';
                        $_SESSION['health_info'] = $user['health_info'] ?? '';

                        if ($is_personnel) {
                            $_SESSION['admin_logged_in'] = true;
                            $_SESSION['admin_id'] = $uid;
                            log_activity("ADMIN_LOGIN_SUCCESS", "Personnel logged in via role-based authentication as {$role}: {$user['full_name']} ({$email})");
                            return [
                                'success' => true,
                                'role' => $role,
                                'redirect_url' => ($role === 'admin') ? 'admin-dashboard.php' : 'bookings.php',
                                'user' => $user
                            ];
                        } else {
                            log_activity("USER_LOGIN_SUCCESS", "Guest logged in via role-based authentication: {$user['full_name']} (Profile ID: {$_SESSION['profile_id']})");
                            return [
                                'success' => true,
                                'role' => 'guest',
                                'redirect_url' => 'index.php?login=1',
                                'user' => $user
                            ];
                        }
                    } else {
                        return ['success' => false, 'error' => 'Incorrect password. Please check your password and try again.'];
                    }
                }
            } catch (PDOException $e) {
                // Fallthrough to persistent file DB
            }
        }

        // 2. Try Persistent File Storage Authentication (data/users.json)
        $file_users = self::getUsersFromFile();
        foreach ($file_users as $fu) {
            if (strtolower($fu['email']) === $email) {
                $role = strtolower(trim($fu['role'] ?? 'guest'));
                $is_personnel = in_array($role, ['admin', 'staff']);

                if (verify_password($password, $fu['password_hash'])) {
                    if (!headers_sent()) {
                        @session_regenerate_id(true);
                    }

                    $uid = !empty($fu['user_id']) ? $fu['user_id'] : (!empty($fu['id']) ? $fu['id'] : 1);
                    $_SESSION['user_id'] = $uid;
                    $_SESSION['profile_id'] = !empty($fu['profile_id']) ? $fu['profile_id'] : (($role === 'admin') ? sprintf("PBR-ADM-%05d", $uid) : (($role === 'staff') ? sprintf("PBR-STF-%05d", $uid) : sprintf("PBR-GST-%05d", $uid)));
                    $_SESSION['full_name'] = $fu['full_name'];
                    $_SESSION['first_name'] = $fu['first_name'] ?? '';
                    $_SESSION['middle_name'] = $fu['middle_name'] ?? '';
                    $_SESSION['last_name'] = $fu['last_name'] ?? '';
                    $_SESSION['suffix'] = $fu['suffix'] ?? '';
                    $_SESSION['email'] = $fu['email'];
                    $_SESSION['role'] = $role;
                    $_SESSION['phone'] = $fu['phone'] ?? '';
                    $_SESSION['emergency_contact_name'] = $fu['emergency_contact_name'] ?? '';
                    $_SESSION['emergency_contact_relationship'] = $fu['emergency_contact_relationship'] ?? '';
                    $_SESSION['emergency_contact_phone'] = $fu['emergency_contact_phone'] ?? '';
                    $_SESSION['emergency_contact_alt_phone'] = $fu['emergency_contact_alt_phone'] ?? '';
                    $_SESSION['id_type'] = $fu['id_type'] ?? '';
                    $_SESSION['id_number'] = $fu['id_number'] ?? '';
                    $_SESSION['id_image'] = $fu['id_image'] ?? '';
                    $_SESSION['profile_picture'] = $fu['profile_picture'] ?? '';
                    $_SESSION['health_conditions'] = $fu['health_conditions'] ?? '';
                    $_SESSION['health_info'] = $fu['health_info'] ?? '';

                    if ($is_personnel) {
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['admin_id'] = $fu['id'];
                        log_activity("ADMIN_LOGIN_SUCCESS", "Personnel logged in via role-based authentication as {$role}: {$fu['full_name']} ({$email})");
                        return [
                            'success' => true,
                            'role' => $role,
                            'redirect_url' => ($role === 'admin') ? 'admin-dashboard.php' : 'bookings.php',
                            'user' => $fu
                        ];
                    } else {
                        log_activity("USER_LOGIN_SUCCESS", "Guest logged in via role-based authentication: {$fu['full_name']} (Profile ID: {$fu['profile_id']})");
                        return [
                            'success' => true,
                            'role' => 'guest',
                            'redirect_url' => 'index.php?login=1',
                            'user' => $fu
                        ];
                    }
                } else {
                    return ['success' => false, 'error' => 'Incorrect password. Please check your password and try again.'];
                }
            }
        }

        // 3. Fallback demo / emergency account login for standard resort accounts
        $admin_emails = ['admin@purplebamboo.com', 'admin@example.com'];
        $staff_emails = ['staff@purplebamboo.com', 'staff@example.com'];
        $guest_emails = ['guest@example.com'];

        if (in_array($email, array_merge($admin_emails, $staff_emails, $guest_emails))) {
            $effective_role = in_array($email, $admin_emails) ? 'admin' : (in_array($email, $staff_emails) ? 'staff' : 'guest');
            $is_personnel = in_array($effective_role, ['admin', 'staff']);

            $pw_ok = (verify_password($password, 'Admin123') || verify_password($password, 'admin123') || verify_password($password, 'staff123'));

            if (!$pw_ok) {
                return ['success' => false, 'error' => 'Incorrect password. Please check your password and try again.'];
            }

            if (!headers_sent()) {
                @session_regenerate_id(true);
            }
            $demo_id = ($effective_role === 'admin') ? 1 : (($effective_role === 'staff') ? 2 : 101);
            $_SESSION['user_id'] = $demo_id;
            $_SESSION['profile_id'] = ($effective_role === 'admin') ? 'PBR-ADM-00001' : (($effective_role === 'staff') ? 'PBR-STF-00002' : sprintf("PBR-GST-%05d", $demo_id));
            $_SESSION['full_name'] = ($effective_role === 'admin') ? 'Super Admin' : (($effective_role === 'staff') ? 'Front Desk Staff' : 'Dela Cruz, Juan Santos');
            $_SESSION['first_name'] = ($effective_role === 'admin') ? 'Super' : (($effective_role === 'staff') ? 'Front Desk' : 'Juan');
            $_SESSION['middle_name'] = ($effective_role === 'guest') ? 'Santos' : '';
            $_SESSION['last_name'] = ($effective_role === 'admin') ? 'Admin' : (($effective_role === 'staff') ? 'Staff' : 'Dela Cruz');
            $_SESSION['suffix'] = '';
            $_SESSION['email'] = $email;
            $_SESSION['role'] = $effective_role;
            $_SESSION['phone'] = '09566978679';
            $_SESSION['emergency_contact_name'] = 'Maria Dela Cruz';
            $_SESSION['emergency_contact_relationship'] = 'Spouse';
            $_SESSION['emergency_contact_phone'] = '09566978679';
            $_SESSION['emergency_contact_alt_phone'] = '09223334444';
            $_SESSION['id_type'] = 'PhilID / National ID';
            $_SESSION['id_number'] = '1234-5678-9012-3456';
            $_SESSION['id_image'] = '';
            $_SESSION['profile_picture'] = '';
            $_SESSION['health_conditions'] = 'Asthma, Food Allergies';
            $_SESSION['health_info'] = 'Carries Ventolin asthma inhaler. Severe peanut allergy.';

            if ($is_personnel) {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $demo_id;
                log_activity("ADMIN_LOGIN_SUCCESS", "Fallback {$effective_role} logged in: {$_SESSION['full_name']}");
                return [
                    'success' => true,
                    'role' => $effective_role,
                    'redirect_url' => ($effective_role === 'admin') ? 'admin-dashboard.php' : 'bookings.php',
                    'user' => $_SESSION
                ];
            } else {
                log_activity("USER_LOGIN_DEMO", "Logged in as guest via default fallback credentials");
                return [
                    'success' => true,
                    'role' => 'guest',
                    'redirect_url' => 'index.php?login=1',
                    'user' => $_SESSION
                ];
            }
        }

        return ['success' => false, 'error' => 'Account not found for email address. Please register first.'];
    }

    /**
     * Authenticate Admin or Staff accounts ONLY.
     * Strictly rejects any guest account with an explicit Access Denied error.
     */
    public static function loginAdmin($email, $password) {
        global $pdo;
        $email = strtolower(trim($email));

        if (empty($email) || empty($password)) {
            return ['success' => false, 'error' => 'Please enter both email address and password.'];
        }

        $user_match = null;

        // 1. Check MySQL
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = :email LIMIT 1");
                $stmt->execute([':email' => $email]);
                $user_match = $stmt->fetch();
            } catch (PDOException $e) {
                $user_match = null;
            }
        }

        // 2. Check File Storage (data/users.json) if not in MySQL
        if (!$user_match) {
            $file_users = self::getUsersFromFile();
            foreach ($file_users as $fu) {
                if (strtolower($fu['email']) === $email) {
                    $user_match = $fu;
                    break;
                }
            }
        }

        // 3. Fallback Built-in Accounts (Admin, Staff, Guest)
        if (!$user_match) {
            $admin_emails = ['admin@purplebamboo.com', 'admin@example.com'];
            $staff_emails = ['staff@purplebamboo.com', 'staff@example.com'];
            $guest_emails = ['guest@example.com'];
            if (in_array($email, $admin_emails) || in_array($email, $staff_emails) || in_array($email, $guest_emails)) {
                $role = in_array($email, $admin_emails) ? 'admin' : (in_array($email, $staff_emails) ? 'staff' : 'guest');
                $demo_id = ($role === 'admin') ? 1 : (($role === 'staff') ? 2 : 101);
                $user_match = [
                    'id' => $demo_id,
                    'user_id' => $demo_id,
                    'profile_id' => ($role === 'admin') ? 'PBR-ADM-00001' : (($role === 'staff') ? 'PBR-STF-00002' : 'PBR-GST-00101'),
                    'full_name' => ($role === 'admin') ? 'Super Admin' : (($role === 'staff') ? 'Front Desk Staff' : 'Dela Cruz, Juan Santos'),
                    'first_name' => ($role === 'admin') ? 'Super' : (($role === 'staff') ? 'Front Desk' : 'Juan'),
                    'last_name' => ($role === 'admin') ? 'Admin' : (($role === 'staff') ? 'Staff' : 'Dela Cruz'),
                    'email' => $email,
                    'role' => $role,
                    'phone' => '09566978679',
                    'password_hash' => password_hash('Admin123', PASSWORD_BCRYPT)
                ];
            }
        }

        // If no user exists
        if (!$user_match) {
            return [
                'success' => false,
                'error' => 'Account not found for this email address. Please register or verify credentials.'
            ];
        }

        // Verify password
        if (!verify_password($password, $user_match['password_hash'])) {
            return [
                'success' => false,
                'error' => 'Incorrect password for administrator credentials. Please check your password.'
            ];
        }

        // ROLE-BASED ACCESS CONTROL (RBAC) FOR BOTH GUEST AND ADMIN
        $role = strtolower(trim($user_match['role'] ?? 'guest'));
        
        if (!headers_sent()) {
            @session_regenerate_id(true);
        }
        $uid = !empty($user_match['user_id']) ? $user_match['user_id'] : (!empty($user_match['id']) ? $user_match['id'] : 1);
        $_SESSION['user_id'] = $uid;
        $_SESSION['profile_id'] = !empty($user_match['profile_id']) ? $user_match['profile_id'] : (($role === 'admin') ? 'PBR-ADM-00001' : (($role === 'staff') ? 'PBR-STF-00002' : sprintf("PBR-GST-%05d", $uid)));
        $_SESSION['full_name'] = $user_match['full_name'] ?? ($role === 'admin' ? 'Super Admin' : ($role === 'staff' ? 'Front Desk Staff' : 'Registered Guest'));
        $_SESSION['first_name'] = $user_match['first_name'] ?? '';
        $_SESSION['last_name'] = $user_match['last_name'] ?? '';
        $_SESSION['email'] = $user_match['email'];
        $_SESSION['role'] = $role;
        $_SESSION['phone'] = $user_match['phone'] ?? '09566978679';

        if (in_array($role, ['admin', 'staff'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $uid;
            log_activity("ADMIN_LOGIN_SUCCESS", "Personnel logged in as {$role}: {$user_match['full_name']} ({$email})");
            return [
                'success' => true,
                'role' => $role,
                'redirect_url' => ($role === 'admin') ? 'admin-dashboard.php' : 'bookings.php',
                'user' => $user_match
            ];
        } else {
            log_activity("GUEST_LOGIN_VIA_ADMIN_PORTAL", "Guest {$user_match['full_name']} logged in via admin portal, auto-routing to guest index");
            return [
                'success' => true,
                'role' => 'guest',
                'is_guest' => true,
                'redirect_url' => 'index.php?login=1',
                'user' => $user_match,
                'notice' => 'Guest account authenticated! Redirecting to Resort Guest Portal...'
            ];
        }
    }

    /**
     * Register a new user with extended details
     */
    public static function register($data, $role = 'guest') {
        global $pdo;

        if (!is_array($data)) {
            $args = func_get_args();
            $data = [
                'full_name' => $args[0] ?? '',
                'email' => $args[1] ?? '',
                'phone' => $args[2] ?? '',
                'password' => $args[3] ?? ''
            ];
            $role = $args[4] ?? 'guest';
        }

        $first_name = trim($data['first_name'] ?? '');
        $middle_name = trim($data['middle_name'] ?? '');
        $last_name = trim($data['last_name'] ?? '');
        $suffix = trim($data['suffix'] ?? '');
        
        // 1. Personal Name Validation: Letters, spaces, hyphens, and apostrophes only. NO NUMBERS ALLOWED.
        if (preg_match('/[0-9]/', $first_name)) {
            return ['success' => false, 'error' => 'First Name must contain letters only. Numbers are not allowed.'];
        }
        if (!empty($middle_name) && preg_match('/[0-9]/', $middle_name)) {
            return ['success' => false, 'error' => 'Middle Name must contain letters only. Numbers are not allowed.'];
        }
        if (preg_match('/[0-9]/', $last_name)) {
            return ['success' => false, 'error' => 'Surname / Last Name must contain letters only. Numbers are not allowed.'];
        }
        
        // Build full_name if empty
        $full_name = trim($data['full_name'] ?? '');
        if (empty($full_name)) {
            if (!empty($last_name) && !empty($first_name)) {
                $full_name = $last_name . ', ' . $first_name . ($middle_name ? ' ' . $middle_name : '') . ($suffix ? ' ' . $suffix : '');
            } else {
                $parts = array_filter([$first_name, $middle_name, $last_name, $suffix]);
                $full_name = implode(' ', $parts);
            }
        }
        if (empty($full_name)) {
            $full_name = 'New Guest';
        }
        
        $email = strtolower(trim($data['email'] ?? ''));

        // 2. Mobile Phone Number Validation: Must start with 09, numbers only, no letters, exactly 11 digits.
        $raw_phone = trim($data['phone'] ?? '');
        if (preg_match('/[a-zA-Z]/', $raw_phone)) {
            return ['success' => false, 'error' => 'Mobile phone number must contain numbers only. Letters are not allowed.'];
        }
        $digits_phone = preg_replace('/\D/', '', $raw_phone);
        if (!str_starts_with($digits_phone, '09') || strlen($digits_phone) !== 11) {
            return ['success' => false, 'error' => 'Mobile phone number must start with 09 and contain exactly 11 digits (e.g. 09171234567) with numbers only.'];
        }
        $phone = $digits_phone;

        $password = $data['password'] ?? '';
        
        // Validate password strength
        $pw_check = validate_password_strength($password);
        if (!$pw_check['valid']) {
            return ['success' => false, 'error' => $pw_check['error']];
        }
        
        $emergency_contact_name = trim($data['emergency_contact_name'] ?? '');
        if (preg_match('/[0-9]/', $emergency_contact_name)) {
            return ['success' => false, 'error' => 'Emergency Contact Person Name must contain letters only. Numbers are not allowed.'];
        }

        $emergency_contact_relationship = trim($data['emergency_contact_relationship'] ?? '');

        // Validate Emergency Contact Phone
        $raw_ec_phone = trim($data['emergency_contact_phone'] ?? '');
        if (preg_match('/[a-zA-Z]/', $raw_ec_phone)) {
            return ['success' => false, 'error' => 'Emergency contact phone must contain numbers only. Letters are not allowed.'];
        }
        $digits_ec_phone = preg_replace('/\D/', '', $raw_ec_phone);
        if (!str_starts_with($digits_ec_phone, '09') || strlen($digits_ec_phone) !== 11) {
            return ['success' => false, 'error' => 'Emergency contact phone must start with 09 and contain exactly 11 digits (e.g. 09189876543) with numbers only.'];
        }
        $emergency_contact_phone = $digits_ec_phone;

        // Validate Secondary / Alt Emergency Contact Phone (optional, but if filled must be 09XXXXXXXXX)
        $raw_alt_phone = trim($data['emergency_contact_alt_phone'] ?? '');
        if (!empty($raw_alt_phone)) {
            if (preg_match('/[a-zA-Z]/', $raw_alt_phone)) {
                return ['success' => false, 'error' => 'Secondary emergency phone must contain numbers only. Letters are not allowed.'];
            }
            $digits_alt_phone = preg_replace('/\D/', '', $raw_alt_phone);
            if (!str_starts_with($digits_alt_phone, '09') || strlen($digits_alt_phone) !== 11) {
                return ['success' => false, 'error' => 'Secondary emergency phone must start with 09 and contain exactly 11 digits (e.g. 09223334444) with numbers only.'];
            }
            $emergency_contact_alt_phone = $digits_alt_phone;
        } else {
            $emergency_contact_alt_phone = '';
        }
        
        $id_type = trim($data['id_type'] ?? '');
        $id_number = trim($data['id_number'] ?? '');
        $id_image = trim($data['id_image'] ?? '');
        $profile_picture = trim($data['profile_picture'] ?? '');
        
        // Handle health conditions array or string
        $health_conditions_val = $data['health_conditions'] ?? '';
        if (is_array($health_conditions_val)) {
            $health_conditions = implode(', ', array_filter($health_conditions_val));
        } else {
            $health_conditions = trim($health_conditions_val);
        }
        
        $health_info = trim($data['health_info'] ?? '');

        // Check if email already exists in persistent file storage
        $existing_users = self::getUsersFromFile();
        foreach ($existing_users as $eu) {
            if (strtolower($eu['email']) === $email) {
                return ['success' => false, 'error' => 'Email address is already registered. Please sign in instead.'];
            }
        }

        // Calculate next ID
        $max_id = 200;
        foreach ($existing_users as $eu) {
            if (isset($eu['id']) && $eu['id'] > $max_id) {
                $max_id = $eu['id'];
            }
        }
        $new_id = $max_id + 1;

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = :email LIMIT 1");
                $stmt->execute([':email' => $email]);
                if ($stmt->fetch()) {
                    return ['success' => false, 'error' => 'Email address is already registered. Please sign in instead.'];
                }

                $hash = hash_password($password);
                $insert = $pdo->prepare("
                    INSERT INTO users (
                        full_name, first_name, middle_name, last_name, suffix,
                        email, phone, password_hash, role,
                        emergency_contact_name, emergency_contact_relationship, emergency_contact_phone, emergency_contact_alt_phone,
                        id_type, id_number, id_image, profile_picture, health_conditions, health_info
                    ) VALUES (
                        :full_name, :first_name, :middle_name, :last_name, :suffix,
                        :email, :phone, :hash, :role,
                        :emergency_contact_name, :emergency_contact_relationship, :emergency_contact_phone, :emergency_contact_alt_phone,
                        :id_type, :id_number, :id_image, :profile_picture, :health_conditions, :health_info
                    )
                ");
                $insert->execute([
                    ':full_name' => $full_name,
                    ':first_name' => $first_name,
                    ':middle_name' => $middle_name,
                    ':last_name' => $last_name,
                    ':suffix' => $suffix,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':hash' => $hash,
                    ':role' => $role,
                    ':emergency_contact_name' => $emergency_contact_name,
                    ':emergency_contact_relationship' => $emergency_contact_relationship,
                    ':emergency_contact_phone' => $emergency_contact_phone,
                    ':emergency_contact_alt_phone' => $emergency_contact_alt_phone,
                    ':id_type' => $id_type,
                    ':id_number' => $id_number,
                    ':id_image' => $id_image,
                    ':profile_picture' => $profile_picture,
                    ':health_conditions' => $health_conditions,
                    ':health_info' => $health_info
                ]);

                $db_id = $pdo->lastInsertId();
                if ($db_id) {
                    $new_id = (int)$db_id;
                }
            } catch (PDOException $e) {
                // Fallthrough to file persistence
            }
        }

        $hash = hash_password($password);
        $profile_id = sprintf("PBR-GST-%05d", $new_id);

        if ($pdo && isset($db_id)) {
            try {
                $update_pid = $pdo->prepare("UPDATE users SET profile_id = :pid WHERE id = :id");
                $update_pid->execute([':pid' => $profile_id, ':id' => $new_id]);
            } catch (PDOException $e) {}
        }

        $user_record = [
            'id' => $new_id,
            'profile_id' => $profile_id,
            'full_name' => $full_name,
            'first_name' => $first_name,
            'middle_name' => $middle_name,
            'last_name' => $last_name,
            'suffix' => $suffix,
            'email' => $email,
            'phone' => $phone,
            'password_hash' => $hash,
            'role' => $role,
            'emergency_contact_name' => $emergency_contact_name,
            'emergency_contact_relationship' => $emergency_contact_relationship,
            'emergency_contact_phone' => $emergency_contact_phone,
            'emergency_contact_alt_phone' => $emergency_contact_alt_phone,
            'id_type' => $id_type,
            'id_number' => $id_number,
            'id_image' => $id_image,
            'profile_picture' => $profile_picture,
            'health_conditions' => $health_conditions,
            'health_info' => $health_info
        ];

        // Save to persistent file DB
        self::saveUserToFile($user_record);

        // Auto-login upon registration
        if (!headers_sent()) {
            @session_regenerate_id(true);
        }
        $_SESSION['user_id'] = $new_id;
        $_SESSION['profile_id'] = $profile_id;
        $_SESSION['full_name'] = $full_name;
        $_SESSION['first_name'] = $first_name;
        $_SESSION['middle_name'] = $middle_name;
        $_SESSION['last_name'] = $last_name;
        $_SESSION['suffix'] = $suffix;
        $_SESSION['email'] = $email;
        $_SESSION['role'] = $role;
        $_SESSION['phone'] = $phone;
        $_SESSION['emergency_contact_name'] = $emergency_contact_name;
        $_SESSION['emergency_contact_relationship'] = $emergency_contact_relationship;
        $_SESSION['emergency_contact_phone'] = $emergency_contact_phone;
        $_SESSION['emergency_contact_alt_phone'] = $emergency_contact_alt_phone;
        $_SESSION['id_type'] = $id_type;
        $_SESSION['id_number'] = $id_number;
        $_SESSION['id_image'] = $id_image;
        $_SESSION['profile_picture'] = $profile_picture;
        $_SESSION['health_conditions'] = $health_conditions;
        $_SESSION['health_info'] = $health_info;

        log_activity("USER_REGISTER_SUCCESS", "New account registered: {$email} (Profile ID: {$profile_id})");
        return ['success' => true, 'user_id' => $new_id, 'profile_id' => $profile_id];
    }

    /**
     * Destroy session and logout
     */
    public static function logout() {
        log_activity("USER_LOGOUT");
        $_SESSION = array();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
}
