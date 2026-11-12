<?php
require_once __DIR__ . '/../database.php';
header('Content-Type: application/json');

$results = [];

try {
    // 1. Create admins table
    $pdo->exec("CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        profile_id VARCHAR(50) NULL,
        first_name VARCHAR(50) NOT NULL,
        middle_name VARCHAR(50) NULL,
        last_name VARCHAR(50) NOT NULL,
        suffix VARCHAR(20) NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        phone VARCHAR(50) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(20) DEFAULT 'admin',
        status ENUM('active', 'blocked') DEFAULT 'active',
        profile_picture VARCHAR(255) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $results['admins_table'] = 'created_or_exists';

    // 2. Create staff table
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff (
        id INT AUTO_INCREMENT PRIMARY KEY,
        profile_id VARCHAR(50) NULL,
        first_name VARCHAR(50) NOT NULL,
        middle_name VARCHAR(50) NULL,
        last_name VARCHAR(50) NOT NULL,
        suffix VARCHAR(20) NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        phone VARCHAR(50) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(20) DEFAULT 'staff',
        status ENUM('active', 'blocked') DEFAULT 'active',
        department VARCHAR(100) DEFAULT 'Front Desk',
        position VARCHAR(100) DEFAULT 'Staff Member',
        profile_picture VARCHAR(255) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $results['staff_table'] = 'created_or_exists';

    // 3. Migrate existing admin users from `users` to `admins`
    $adminsToMigrate = $pdo->query("SELECT * FROM users WHERE LOWER(role) = 'admin'")->fetchAll(PDO::FETCH_ASSOC);
    $migratedAdmins = 0;
    foreach ($adminsToMigrate as $adm) {
        $check = $pdo->prepare("SELECT id FROM admins WHERE LOWER(email) = LOWER(?)");
        $check->execute([$adm['email']]);
        if (!$check->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO admins (id, profile_id, first_name, middle_name, last_name, suffix, email, phone, password_hash, role, status, profile_picture, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $adm['id'],
                $adm['profile_id'] ?: sprintf("PBR-ADM-%05d", $adm['id']),
                $adm['first_name'],
                $adm['middle_name'],
                $adm['last_name'],
                $adm['suffix'],
                $adm['email'],
                $adm['phone'],
                $adm['password_hash'],
                'admin',
                $adm['status'] ?: 'active',
                $adm['profile_picture'],
                $adm['created_at'] ?: date('Y-m-d H:i:s')
            ]);
            $migratedAdmins++;
        }
    }
    $results['migrated_admins'] = $migratedAdmins;

    // 4. Migrate existing staff users from `users` to `staff`
    $staffToMigrate = $pdo->query("SELECT * FROM users WHERE LOWER(role) = 'staff'")->fetchAll(PDO::FETCH_ASSOC);
    $migratedStaff = 0;
    foreach ($staffToMigrate as $stf) {
        $check = $pdo->prepare("SELECT id FROM staff WHERE LOWER(email) = LOWER(?)");
        $check->execute([$stf['email']]);
        if (!$check->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO staff (id, profile_id, first_name, middle_name, last_name, suffix, email, phone, password_hash, role, status, profile_picture, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $stf['id'],
                $stf['profile_id'] ?: sprintf("PBR-STF-%05d", $stf['id']),
                $stf['first_name'],
                $stf['middle_name'],
                $stf['last_name'],
                $stf['suffix'],
                $stf['email'],
                $stf['phone'],
                $stf['password_hash'],
                'staff',
                $stf['status'] ?: 'active',
                $stf['profile_picture'],
                $stf['created_at'] ?: date('Y-m-d H:i:s')
            ]);
            $migratedStaff++;
        }
    }
    $results['migrated_staff'] = $migratedStaff;

    // Remove staff and admin records from users table so users table is purely for guests
    $pdo->exec("DELETE FROM users WHERE LOWER(role) IN ('admin', 'staff')");
    $results['cleaned_users_table'] = true;

    // Current counts
    $results['admins_count'] = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
    $results['staff_count'] = (int)$pdo->query("SELECT COUNT(*) FROM staff")->fetchColumn();
    $results['users_count'] = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $results['tables'] = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['success' => true, 'data' => $results], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
