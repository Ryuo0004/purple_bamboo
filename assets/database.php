<?php
// database.php - Smart MySQL Connection & Automatic Schema Initializer

$is_local = (
    !isset($_SERVER['HTTP_HOST']) ||
    in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1']) ||
    strpos($_SERVER['HTTP_HOST'], 'localhost:') === 0 ||
    php_sapi_name() === 'cli'
);

if ($is_local) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'purple_bamboo_db');
    define('DB_USER', 'root');
    define('DB_PASS', '');
} else {
    // ========================================================
    // InfinityFree Live MySQL Configuration
    // Update DB_HOST and DB_NAME from InfinityFree cPanel -> MySQL Databases:
    // ========================================================
    define('DB_HOST', 'sql204.infinityfree.com');
    define('DB_NAME', 'if0_43100428_purple_bamboo_db');
    define('DB_USER', 'if0_43100428');
    define('DB_PASS', 'Ol6HEpKMDRXm7O');
    define('DB_PORT', '3306');
}

// ========================================================
// Resort & Travel Concierge Global Configuration
// ========================================================
if (!defined('GOOGLE_MAPS_API_KEY')) {
    define('GOOGLE_MAPS_API_KEY', '');
}
if (!defined('RESORT_NAME')) {
    define('RESORT_NAME', 'Purple Bamboo Resort');
}
if (!defined('RESORT_LAT')) {
    define('RESORT_LAT', 16.173389);
}
if (!defined('RESORT_LNG')) {
    define('RESORT_LNG', 120.057846);
}
if (!defined('RESORT_ADDRESS')) {
    define('RESORT_ADDRESS', 'Zone 04 magamo-gamo, Alaminos, Philippines, 2404');
}
if (!defined('RESORT_PHONE')) {
    define('RESORT_PHONE', '0956 697 8679');
}
if (!defined('RESORT_EMAIL')) {
    define('RESORT_EMAIL', 'info@purplebambooresort.com');
}

$pdo = null;

try {
    // 1. Attempt connection to specific database
    $port = defined('DB_PORT') ? DB_PORT : '3306';
    $pdo = new PDO("mysql:host=" . DB_HOST . ";port=" . $port . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // 2. If database does not exist, connect to MySQL server and auto-create DB (local only)
    if ($is_local) {
        try {
            $pdo_init = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS);
            $pdo_init->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo_init->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            
            // Reconnect to newly created database
            $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $ex) {
            $pdo = null;
        }
    } else {
        $pdo = null;
    }
}

// 3. Auto-initialize tables from schema.sql if any core table is missing
if ($pdo !== null) {
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'users'");
        if ($tableCheck->rowCount() === 0) {
            $schemaFile = __DIR__ . '/schema.sql';
            if (file_exists($schemaFile)) {
                $sqlCommands = file_get_contents($schemaFile);
                $pdo->exec($sqlCommands);
            }
        }

        // Auto-create admins table if missing on server
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `admins` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `profile_id` varchar(50) DEFAULT NULL,
                  `first_name` varchar(50) NOT NULL,
                  `middle_name` varchar(50) DEFAULT NULL,
                  `last_name` varchar(50) NOT NULL,
                  `suffix` varchar(20) DEFAULT NULL,
                  `email` varchar(100) NOT NULL,
                  `phone` varchar(50) NOT NULL,
                  `password_hash` varchar(255) NOT NULL,
                  `role` varchar(20) DEFAULT 'admin',
                  `status` varchar(20) NOT NULL DEFAULT 'active',
                  `profile_picture` varchar(255) DEFAULT NULL,
                  `created_at` datetime DEFAULT current_timestamp(),
                  `block_reason` varchar(255) DEFAULT NULL,
                  `blocked_at` datetime DEFAULT NULL,
                  `blocked_by` varchar(100) DEFAULT NULL,
                  `reset_otp` varchar(10) DEFAULT NULL,
                  `reset_expires` datetime DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `email` (`email`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            ");
        } catch (Exception $e) {}

        // Auto-create staff table if missing on server
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `staff` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `profile_id` varchar(50) DEFAULT NULL,
                  `first_name` varchar(50) NOT NULL,
                  `middle_name` varchar(50) DEFAULT NULL,
                  `last_name` varchar(50) NOT NULL,
                  `suffix` varchar(20) DEFAULT NULL,
                  `email` varchar(100) NOT NULL,
                  `phone` varchar(50) NOT NULL,
                  `password_hash` varchar(255) NOT NULL,
                  `role` varchar(20) DEFAULT 'staff',
                  `status` varchar(20) NOT NULL DEFAULT 'active',
                  `department` varchar(100) DEFAULT 'Front Desk',
                  `position` varchar(100) DEFAULT 'Staff Member',
                  `profile_picture` varchar(255) DEFAULT NULL,
                  `created_at` datetime DEFAULT current_timestamp(),
                  `block_reason` varchar(255) DEFAULT NULL,
                  `blocked_at` datetime DEFAULT NULL,
                  `blocked_by` varchar(100) DEFAULT NULL,
                  `reset_otp` varchar(10) DEFAULT NULL,
                  `reset_expires` datetime DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `email` (`email`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            ");
        } catch (Exception $e) {}

        // Auto-create dining_orders and dining_menu tables if missing
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `dining_orders` (
                    `id` VARCHAR(50) PRIMARY KEY,
                    `user_id` INT NULL,
                    `guest_name` VARCHAR(100) NOT NULL,
                    `guest_email` VARCHAR(100) NOT NULL,
                    `guest_phone` VARCHAR(50) NOT NULL,
                    `kubo_location` VARCHAR(100) NOT NULL,
                    `order_items_json` TEXT NOT NULL,
                    `total_amount` DECIMAL(10,2) NOT NULL,
                    `guest_allergies` VARCHAR(255) NULL,
                    `allergy_notes` TEXT NULL,
                    `requested_serve_time` VARCHAR(50) DEFAULT 'ASAP (15-20 Mins)',
                    `order_status` VARCHAR(50) DEFAULT 'pending',
                    `kitchen_notes` TEXT NULL,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `prepared_at` DATETIME NULL,
                    `served_at` DATETIME NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `dining_menu` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `item_name` VARCHAR(100) NOT NULL,
                    `category` VARCHAR(50) NOT NULL,
                    `price` DECIMAL(10,2) NOT NULL,
                    `description` TEXT NULL,
                    `image` VARCHAR(255) NULL,
                    `allergens` VARCHAR(255) NULL,
                    `is_available` TINYINT(1) DEFAULT 1
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // Seed dining_menu if empty
            $menuCount = (int)$pdo->query("SELECT COUNT(*) FROM `dining_menu`")->fetchColumn();
            if ($menuCount === 0) {
                $defaultMenu = [
                    ['Sizzling Garlic Butter Tiger Prawns', 'Main Dishes', 680.00, 'Fresh tiger prawns sauteed in rich garlic butter sauce, chili flakes, and fresh scallions.', 'https://images.unsplash.com/photo-1565680018434-b513d5e5fd47?auto=format&fit=crop&w=600&q=80', 'Seafood / Shellfish, Dairy'],
                    ['Purple Bamboo Special Grilled Chicken Inasal', 'Main Dishes', 450.00, 'Charcoal-grilled marinated chicken leg with annatto lemongrass oil and soy dip.', 'https://images.unsplash.com/photo-1598515214211-89d3c73ae83b?auto=format&fit=crop&w=600&q=80', 'Soy'],
                    ['Creamy Peanut Kare-Kare Tender Beef', 'Main Dishes', 580.00, 'Traditional Filipino stew cooked in rich toasted peanut & sesame sauce with native vegetables.', 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80', 'Nuts / Peanuts, Seafood / Shellfish'],
                    ['Crispy Calamares with Dip', 'Appetizers', 340.00, 'Deep-fried squid rings served with spicy garlic mayo and tartar sauce.', 'https://images.unsplash.com/photo-1599487488170-d11ec9c172f0?auto=format&fit=crop&w=600&q=80', 'Seafood / Shellfish, Eggs, Gluten'],
                    ['Fresh Tropical Dragonfruit & Mango Smoothie', 'Drinks & Cocktails', 180.00, 'Blended fresh mangoes, dragonfruit, coconut milk, and shaved ice.', 'https://images.unsplash.com/photo-1553530666-ba11a7da3888?auto=format&fit=crop&w=600&q=80', 'Dairy-Free, Vegan'],
                    ['Purple Velvet Ube Cream Shake', 'Drinks & Cocktails', 220.00, 'Signature purple yam thick milkshake topped with whipped cream, macapuno, and caramel.', 'https://images.unsplash.com/photo-1572490122747-3968b75cc699?auto=format&fit=crop&w=600&q=80', 'Dairy'],
                    ['Bamboo Coconut Halo-Halo Supreme', 'Desserts & Snacks', 250.00, 'Shaved ice with sweet beans, nata de coco, ube halaya, leche flan, and scoop of ube ice cream.', 'https://images.unsplash.com/photo-1563805042-7684c019e1cb?auto=format&fit=crop&w=600&q=80', 'Dairy, Eggs'],
                    ['Signature Poolside Mai Tai Cocktail', 'Drinks & Cocktails', 320.00, 'Dark rum, orange curaçao, almond syrup, and fresh lime juice served in bamboo tumbler.', 'https://images.unsplash.com/photo-1514362545857-3bc16c4c7d1b?auto=format&fit=crop&w=600&q=80', 'Nuts / Peanuts']
                ];
                $ins = $pdo->prepare("INSERT INTO `dining_menu` (`item_name`, `category`, `price`, `description`, `image`, `allergens`, `is_available`) VALUES (?, ?, ?, ?, ?, ?, 1)");
                foreach ($defaultMenu as $item) {
                    $ins->execute($item);
                }
            }
        } catch (Exception $e) {}

        // Auto-seed default Super Admin if admins table is empty
        try {
            $admCount = (int)$pdo->query("SELECT COUNT(*) FROM `admins`")->fetchColumn();
            if ($admCount === 0) {
                $pdo->exec("
                    INSERT INTO `admins` (`id`, `profile_id`, `first_name`, `middle_name`, `last_name`, `suffix`, `email`, `phone`, `password_hash`, `role`, `status`, `created_at`)
                    VALUES (1, 'PBR-ADM-00001', 'Super', '', 'Admin', '', 'admin@purplebamboo.com', '09566978679', '$2y$10$wgFeZChm8wEhZV/cxy9pfuvjAGnaJlhO69jW.koFCI305TjltPi0a', 'admin', 'active', NOW())
                    ON DUPLICATE KEY UPDATE id=id
                ");
            }
        } catch (Exception $e) {}

        // Helper function for safe column alteration
        $ensureColumn = function($table, $column, $definition) use ($pdo) {
            try {
                $colCheck = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetch();
                if (!$colCheck) {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN $column $definition");
                }
            } catch (PDOException $e) {}
        };

        // Ensure status column in users, staff, admins can support 'active', 'deactivated', 'blocked'
        try {
            $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'active'");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("ALTER TABLE `staff` MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'active'");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("ALTER TABLE `admins` MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'active'");
        } catch (PDOException $e) {}

        // Ensure all required columns exist in 'users'
        $ensureColumn('users', 'profile_id', "VARCHAR(50) NULL AFTER id");
        $ensureColumn('users', 'status', "VARCHAR(20) DEFAULT 'active'");
        $ensureColumn('users', 'is_blocked', "TINYINT(1) DEFAULT 0");
        $ensureColumn('users', 'block_reason', "VARCHAR(255) NULL");
        $ensureColumn('users', 'blocked_at', "DATETIME NULL");
        $ensureColumn('users', 'blocked_by', "VARCHAR(100) NULL");
        $ensureColumn('users', 'gcash_name', "VARCHAR(100) NULL");

        // Ensure staff and admins have block/deactivate tracking columns
        $ensureColumn('staff', 'block_reason', "VARCHAR(255) NULL");
        $ensureColumn('staff', 'blocked_at', "DATETIME NULL");
        $ensureColumn('staff', 'blocked_by', "VARCHAR(100) NULL");

        $ensureColumn('admins', 'block_reason', "VARCHAR(255) NULL");
        $ensureColumn('admins', 'blocked_at', "DATETIME NULL");
        $ensureColumn('admins', 'blocked_by', "VARCHAR(100) NULL");
        $ensureColumn('users', 'emergency_contact_name', "VARCHAR(100) NULL");
        $ensureColumn('users', 'emergency_contact_relationship', "VARCHAR(50) NULL");
        $ensureColumn('users', 'emergency_contact_phone', "VARCHAR(50) NULL");
        $ensureColumn('users', 'emergency_contact_alt_phone', "VARCHAR(50) NULL");
        $ensureColumn('users', 'id_type', "VARCHAR(50) NULL");
        $ensureColumn('users', 'id_number', "VARCHAR(100) NULL");
        $ensureColumn('users', 'id_image', "VARCHAR(255) NULL");
        $ensureColumn('users', 'profile_picture', "VARCHAR(255) NULL");
        $ensureColumn('users', 'health_conditions', "VARCHAR(255) NULL");
        $ensureColumn('users', 'health_info', "TEXT NULL");
        $ensureColumn('users', 'reset_otp', "VARCHAR(10) NULL");
        $ensureColumn('users', 'reset_expires', "DATETIME NULL");
        $ensureColumn('staff', 'reset_otp', "VARCHAR(10) NULL");
        $ensureColumn('staff', 'reset_expires', "DATETIME NULL");
        $ensureColumn('admins', 'reset_otp', "VARCHAR(10) NULL");
        $ensureColumn('admins', 'reset_expires', "DATETIME NULL");

        // Ensure all required columns exist in 'house_kubos'
        $ensureColumn('house_kubos', 'category_id', "INT NULL AFTER kubo_name_number");
        $ensureColumn('house_kubos', 'available_units', "INT NOT NULL DEFAULT 5");
        $ensureColumn('house_kubos', 'badges', "VARCHAR(255) NULL");
        $ensureColumn('house_kubos', 'renovation_notes', "TEXT NULL");
        $ensureColumn('house_kubos', 'renovation_start', "DATE NULL");
        $ensureColumn('house_kubos', 'renovation_end', "DATE NULL");
        try {
            $pdo->exec("ALTER TABLE `house_kubos` MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'available'");
        } catch (PDOException $e) {}

        // Ensure all required columns exist in 'activities'
        $ensureColumn('activities', 'category', "VARCHAR(50) DEFAULT 'pool'");
        $ensureColumn('activities', 'duration', "VARCHAR(50) DEFAULT '1 Hour'");
        $ensureColumn('activities', 'duration_minutes', "INT NULL DEFAULT 60");
        $ensureColumn('activities', 'capacity', "INT DEFAULT 5");
        $ensureColumn('activities', 'activity_type', "ENUM('full_day','time_based') DEFAULT 'time_based'");
        $ensureColumn('activities', 'max_pax', "INT DEFAULT 2");
        $ensureColumn('activities', 'total_units', "INT DEFAULT 6");
        $ensureColumn('activities', 'available_units', "INT DEFAULT 4");
        $ensureColumn('activities', 'equipment', "TEXT NULL");
        $ensureColumn('activities', 'image', "VARCHAR(255) NULL");
        $ensureColumn('activities', 'activity_image', "VARCHAR(255) NULL");
        $ensureColumn('activities', 'is_available', "TINYINT(1) DEFAULT 1");
        $ensureColumn('activities', 'maintenance_notes', "VARCHAR(255) NULL");
        try {
            $pdo->exec("ALTER TABLE `activities` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'available'");
        } catch (PDOException $e) {}

        // Ensure all required columns exist in 'addon_reservations'
        $ensureColumn('addon_reservations', 'price', "DECIMAL(10,2) NULL");
        $ensureColumn('addon_reservations', 'total_price', "DECIMAL(10,2) NULL");
        $ensureColumn('addon_reservations', 'status', "VARCHAR(50) DEFAULT 'confirmed'");

        // Ensure all required columns exist in 'bookings'
        $ensureColumn('bookings', 'profile_id', "VARCHAR(50) NULL AFTER id");
        $ensureColumn('bookings', 'start_time', "TIME NULL");
        $ensureColumn('bookings', 'end_time', "TIME NULL");
        $ensureColumn('bookings', 'num_guests', "INT DEFAULT 1");
        $ensureColumn('bookings', 'guest_list', "TEXT NULL");
        $ensureColumn('bookings', 'payment_status', "VARCHAR(50) DEFAULT 'pending'");
        $ensureColumn('bookings', 'payment_proof', "VARCHAR(255) NULL");
        $ensureColumn('bookings', 'rejection_reason', "TEXT NULL");
        $ensureColumn('bookings', 'rebook_deadline', "DATETIME NULL");
        $ensureColumn('bookings', 'is_forfeited', "TINYINT(1) DEFAULT 0");
        $ensureColumn('bookings', 'resched_pending', "TINYINT(1) DEFAULT 0");
        $ensureColumn('bookings', 'resched_check_in', "DATETIME NULL");
        $ensureColumn('bookings', 'resched_check_out', "DATETIME NULL");
        $ensureColumn('bookings', 'resched_time_slot', "VARCHAR(50) NULL");
        $ensureColumn('bookings', 'resched_reason', "TEXT NULL");
        $ensureColumn('bookings', 'resched_created_at', "DATETIME NULL");
        $ensureColumn('bookings', 'resched_count', "INT DEFAULT 0");
        $ensureColumn('bookings', 'rescheduled_from', "VARCHAR(100) NULL");
        $ensureColumn('bookings', 'resched_reject_reason', "TEXT NULL");

        // Ensure house_categories table exists and has widened category_name
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `house_categories` (
                `category_id` INT AUTO_INCREMENT PRIMARY KEY,
                `category_name` VARCHAR(150) NOT NULL,
                `description` TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            try {
                $pdo->exec("ALTER TABLE `house_categories` MODIFY COLUMN `category_name` VARCHAR(150) NOT NULL");
            } catch (PDOException $e) {}

            $ensureColumn('house_categories', 'description', "TEXT NULL");
            $ensureColumn('house_categories', 'base_price', "DECIMAL(10,2) DEFAULT 0.00");
            $ensureColumn('house_categories', 'max_capacity', "INT DEFAULT 2");
            $ensureColumn('house_categories', 'category_image', "VARCHAR(255) NULL");

            $catCount = (int)$pdo->query("SELECT COUNT(*) FROM `house_categories`")->fetchColumn();
            if ($catCount === 0) {
                $pdo->exec("INSERT IGNORE INTO `house_categories` (`category_id`, `category_name`, `description`) VALUES
                    (1, 'Family Suite', 'Spacious bamboo houses designed for medium to large families.'),
                    (2, 'Couples & Honeymoon', 'Cozy romantic pavilions with poolside views.'),
                    (3, 'Group Villa', 'Multi-storey villas equipped with private barbecue grills.')
                ");
            }
        } catch (Exception $e) {}

        // Ensure auxiliary tables exist
        $pdo->exec("CREATE TABLE IF NOT EXISTS sent_emails (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ref_id VARCHAR(50) NULL,
            to_email VARCHAR(100) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            sent_status VARCHAR(50) DEFAULT 'delivered',
            error_message TEXT NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            meta_json TEXT NULL,
            html_file VARCHAR(255) NULL,
            INDEX (ref_id),
            INDEX (to_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS addon_reservations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reservation_id VARCHAR(50) NOT NULL,
            activity_id INT NOT NULL,
            activity_date DATE NOT NULL,
            start_time TIME NULL,
            end_time TIME NULL,
            quantity INT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (reservation_id),
            INDEX (activity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS booking_archive (
            id VARCHAR(50) PRIMARY KEY,
            guest_name VARCHAR(100) NOT NULL,
            guest_email VARCHAR(100) NOT NULL,
            guest_phone VARCHAR(50) NOT NULL,
            type VARCHAR(20) DEFAULT 'house',
            item_id INT DEFAULT 1,
            item_name VARCHAR(100) NOT NULL,
            check_in DATE NOT NULL,
            check_out DATE NULL,
            time_slot VARCHAR(50) NULL,
            pax INT DEFAULT 1,
            total_amount DECIMAL(10,2) NOT NULL,
            gcash_ref VARCHAR(50) NULL,
            booking_status VARCHAR(30) DEFAULT 'completed',
            archived_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS site_content (
            id INT PRIMARY KEY DEFAULT 1,
            content_key VARCHAR(100) NULL,
            content_value TEXT NULL,
            content_json LONGTEXT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

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
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (PDOException $e) {
        // Suppress if already initialized
    }
}

function getDB() {
    global $pdo;
    return $pdo;
}

function getSiteSetting($key, $default = null) {
    global $pdo;
    if (!$pdo) return $default;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false) ? $val : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

function saveSiteSetting($key, $value) {
    global $pdo;
    if (!$pdo) return false;
    try {
        $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
        return $stmt->execute([$key, $value]);
    } catch (PDOException $e) {
        return false;
    }
}
?>