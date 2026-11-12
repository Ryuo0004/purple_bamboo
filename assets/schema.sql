    -- Purple Bamboo Resort Relational Database Schema
    -- Database Name: purple_bamboo_db

    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        profile_id VARCHAR(50) NULL,
        first_name VARCHAR(50) NULL,
        middle_name VARCHAR(50) NULL,
        last_name VARCHAR(50) NULL,
        suffix VARCHAR(20) NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        phone VARCHAR(50) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('admin', 'staff', 'guest') DEFAULT 'guest',
        status ENUM('active', 'blocked') DEFAULT 'active',
        is_blocked TINYINT(1) DEFAULT 0,
        block_reason VARCHAR(255) NULL,
        blocked_at DATETIME NULL,
        blocked_by VARCHAR(100) NULL,
        gcash_name VARCHAR(100) NULL,
        emergency_contact_name VARCHAR(100) NULL,
        emergency_contact_relationship VARCHAR(50) NULL,
        emergency_contact_phone VARCHAR(50) NULL,
        emergency_contact_alt_phone VARCHAR(50) NULL,
        id_type VARCHAR(50) NULL,
        id_number VARCHAR(100) NULL,
        id_image VARCHAR(255) NULL,
        profile_picture VARCHAR(255) NULL,
        health_conditions VARCHAR(255) NULL,
        health_info TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS admins (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS staff (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS house_categories (
        category_id INT AUTO_INCREMENT PRIMARY KEY,
        category_name VARCHAR(100) NOT NULL,
        description TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS house_kubos (
        kubo_id INT AUTO_INCREMENT PRIMARY KEY,
        kubo_name_number VARCHAR(100) NOT NULL,
        category_id INT NULL,
        description TEXT NULL,
        price_per_night DECIMAL(10,2) NOT NULL,
        capacity INT DEFAULT 2,
        total_units INT NOT NULL DEFAULT 5,
        available_units INT NOT NULL DEFAULT 5,
        is_available TINYINT(1) DEFAULT 1,
        primary_image VARCHAR(255) NULL,
        status ENUM('available', 'occupied', 'maintenance', 'renovation') DEFAULT 'available',
        badges VARCHAR(255) NULL,
        renovation_notes TEXT NULL,
        renovation_start DATE NULL,
        renovation_end DATE NULL,
        FOREIGN KEY (category_id) REFERENCES house_categories(category_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS activities (
        activity_id INT AUTO_INCREMENT PRIMARY KEY,
        activity_name VARCHAR(100) NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        duration VARCHAR(50) DEFAULT '1 Hour',
        max_pax INT DEFAULT 2,
        total_units INT DEFAULT 6,
        available_units INT DEFAULT 4,
        equipment TEXT NULL,
        status ENUM('available', 'maintenance') DEFAULT 'available',
        description TEXT NULL,
        image VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS bookings (
        id VARCHAR(50) PRIMARY KEY,
        profile_id VARCHAR(50) NULL,
        user_id INT NULL,
        guest_name VARCHAR(100) NOT NULL,
        guest_email VARCHAR(100) NOT NULL,
        guest_phone VARCHAR(50) NOT NULL,
        type VARCHAR(20) DEFAULT 'house',
        item_id INT DEFAULT 1,
        item_name VARCHAR(100) NOT NULL,
        check_in DATE NOT NULL,
        check_out DATE NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        time_slot VARCHAR(50) NULL,
        pax INT DEFAULT 1,
        num_guests INT DEFAULT 1,
        total_amount DECIMAL(10,2) NOT NULL,
        gcash_ref VARCHAR(50) NULL,
        payment_proof VARCHAR(255) NULL,
        payment_status VARCHAR(30) DEFAULT 'proof_submitted',
        booking_status VARCHAR(30) DEFAULT 'pending',
        special_notes TEXT NULL,
        guest_list TEXT NULL,
        rejection_reason TEXT NULL,
        rebook_deadline DATETIME NULL,
        is_forfeited TINYINT(1) DEFAULT 0,
        resched_pending TINYINT(1) DEFAULT 0,
        resched_check_in DATETIME NULL,
        resched_check_out DATETIME NULL,
        resched_time_slot VARCHAR(50) NULL,
        resched_reason TEXT NULL,
        resched_created_at DATETIME NULL,
        resched_count INT DEFAULT 0,
        rescheduled_from VARCHAR(100) NULL,
        resched_reject_reason TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (type),
        INDEX (booking_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS addon_reservations (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS booking_archive (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS payment_proofs (
        proof_id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id VARCHAR(50) NOT NULL,
        gcash_ref_no VARCHAR(100) NOT NULL,
        proof_image VARCHAR(255) NULL,
        amount_paid DECIMAL(10,2) NOT NULL,
        verification_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        verified_by VARCHAR(100) NULL,
        verified_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS reviews (
        review_id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id VARCHAR(50) NULL,
        user_name VARCHAR(100) NOT NULL,
        item_name VARCHAR(100) NULL,
        rating INT DEFAULT 5,
        comment TEXT NOT NULL,
        staff_reply TEXT NULL,
        replied_by VARCHAR(100) NULL,
        replied_at DATETIME NULL,
        is_approved TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS guest_reviews (
        review_id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id VARCHAR(50) NULL,
        user_name VARCHAR(100) NOT NULL,
        item_name VARCHAR(100) NULL,
        rating INT DEFAULT 5,
        comment TEXT NOT NULL,
        staff_reply TEXT NULL,
        replied_by VARCHAR(100) NULL,
        replied_at DATETIME NULL,
        is_approved TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS activity_logs (
        log_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        user_email VARCHAR(100) NULL,
        action VARCHAR(255) NOT NULL,
        ip_address VARCHAR(50) NULL,
        details TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS dining_menu (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_name VARCHAR(100) NOT NULL,
        category ENUM('Appetizers', 'Main Dishes', 'Drinks & Cocktails', 'Desserts & Snacks') DEFAULT 'Main Dishes',
        price DECIMAL(10,2) NOT NULL,
        description TEXT NULL,
        image VARCHAR(255) NULL,
        allergens VARCHAR(255) NULL,
        is_available TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS dining_orders (
        id VARCHAR(50) PRIMARY KEY,
        user_id INT NULL,
        guest_name VARCHAR(100) NOT NULL,
        guest_email VARCHAR(100) NOT NULL,
        guest_phone VARCHAR(50) NOT NULL,
        kubo_location VARCHAR(100) NOT NULL,
        order_items_json TEXT NOT NULL,
        total_amount DECIMAL(10,2) NOT NULL,
        guest_allergies VARCHAR(255) NULL,
        allergy_notes TEXT NULL,
        requested_serve_time VARCHAR(50) DEFAULT 'ASAP (15-20 Mins)',
        order_status VARCHAR(50) DEFAULT 'pending',
        kitchen_notes TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        prepared_at DATETIME NULL,
        served_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS site_content (
        id INT PRIMARY KEY DEFAULT 1,
        content_key VARCHAR(100) NULL,
        content_value TEXT NULL,
        content_json LONGTEXT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS site_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS sent_emails (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- Seed Categories
    INSERT IGNORE INTO house_categories (category_id, category_name, description) VALUES
    (1, 'Family Suite', 'Spacious bamboo houses designed for medium to large families.'),
    (2, 'Couples & Honeymoon', 'Cozy romantic pavilions with poolside views.'),
    (3, 'Group Villa', 'Multi-storey villas equipped with private barbecue grills.');

    -- Seed House Kubos
    INSERT IGNORE INTO house_kubos (kubo_id, kubo_name_number, category_id, description, price_per_night, capacity, available_units, primary_image, status, badges) VALUES
    (1, 'Beach Kubo', 1, 'Spacious air-conditioned Kubo with private veranda, refrigerator, and 6-person capacity.', 3500.00, 6, 5, 'assets/beach_kubo.jpg', 'available', 'Free WiFi, Aircon'),
    (2, 'Orchid Honeymoon Kubo #2', 2, 'Cozy bamboo pavilion steps away from the main infinity pool. Romantic queen bed setup.', 2200.00, 2, 5, 'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=600&q=80', 'available', 'Poolside, Smart TV'),
    (3, 'Royal Purple Pavilion #3', 3, '2-Storey bamboo villa equipped with private grilling station, sound system, and up to 15 guests capacity.', 5800.00, 15, 5, 'https://images.unsplash.com/photo-1618773928121-c32242e63f39?auto=format&fit=crop&w=600&q=80', 'available', 'Up to 15 Pax, BBQ Grill');

    -- Seed Water Activities
    INSERT IGNORE INTO activities (activity_id, activity_name, price, duration, max_pax, total_units, available_units, equipment, description, image) VALUES
    (1, 'Main Infinity Pool Pass', 350.00, 'Full Day', 10, 20, 15, 'Floaties, Life Jacket', 'Access to crystal-clear infinity pool with lounge chairs and kids pool zone.', 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80'),
    (2, 'Clear-Bottom Kayak & Paddle Board', 500.00, '1 Hour', 1, 6, 4, 'Kayak, Paddle, Life Jacket', 'Transparent kayak equipment for scenic pool and lagoon exploration.', 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=600&q=80'),
    (3, 'Traditional Bamboo Herbal Massage', 1200.00, '60 Mins', 2, 4, 3, 'Heated bamboo sticks, coconut oil', 'Relaxing traditional Hilot massage using heated bamboo sticks and coconut oil.', 'https://images.unsplash.com/photo-1544161515-4ab6ce6db874?auto=format&fit=crop&w=600&q=80');

    -- Seed Dining Menu Items
    INSERT IGNORE INTO dining_menu (id, item_name, category, price, description, image, allergens) VALUES
    (1, 'Sizzling Garlic Butter Tiger Prawns', 'Main Dishes', 680.00, 'Fresh tiger prawns sauteed in rich garlic butter sauce, chili flakes, and scallions.', 'https://images.unsplash.com/photo-1565680018434-b513d5e5fd47?auto=format&fit=crop&w=600&q=80', 'Seafood / Shellfish, Dairy'),
    (2, 'Purple Bamboo Special Grilled Chicken Inasal', 'Main Dishes', 450.00, 'Charcoal-grilled marinated chicken leg with annatto lemongrass oil and soy dip.', 'https://images.unsplash.com/photo-1598515214211-89d3c73ae83b?auto=format&fit=crop&w=600&q=80', 'Soy'),
    (3, 'Creamy Peanut Kare-Kare Tender Beef', 'Main Dishes', 580.00, 'Traditional Filipino stew cooked in rich toasted peanut & sesame sauce with native vegetables.', 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80', 'Nuts / Peanuts, Seafood / Shellfish'),
    (4, 'Crispy Calamares with Dip', 'Appetizers', 340.00, 'Deep-fried squid rings served with spicy garlic mayo and tartar sauce.', 'https://images.unsplash.com/photo-1599487488170-d11ec9c172f0?auto=format&fit=crop&w=600&q=80', 'Seafood / Shellfish, Eggs, Gluten'),
    (5, 'Fresh Tropical Dragonfruit & Mango Smoothie', 'Drinks & Cocktails', 180.00, 'Blended fresh mangoes, dragonfruit, coconut milk, and shaved ice.', 'https://images.unsplash.com/photo-1553530666-ba11a7da3888?auto=format&fit=crop&w=600&q=80', 'Dairy-Free, Vegan'),
    (6, 'Purple Velvet Ube Cream Shake', 'Drinks & Cocktails', 220.00, 'Signature purple yam thick milkshake topped with whipped cream, macapuno, and caramel.', 'https://images.unsplash.com/photo-1572490122747-3968b75cc699?auto=format&fit=crop&w=600&q=80', 'Dairy'),
    (7, 'Bamboo Coconut Halo-Halo Supreme', 'Desserts & Snacks', 250.00, 'Shaved ice with sweet beans, nata de coco, ube halaya, leche flan, and scoop of ube ice cream.', 'https://images.unsplash.com/photo-1563805042-7684c019e1cb?auto=format&fit=crop&w=600&q=80', 'Dairy, Eggs'),
    (8, 'Signature Poolside Mai Tai Cocktail', 'Drinks & Cocktails', 320.00, 'Dark rum, orange curaçao, almond syrup, and fresh lime juice served in bamboo tumbler.', 'https://images.unsplash.com/photo-1514362545857-3bc16c4c7d1b?auto=format&fit=crop&w=600&q=80', 'Nuts / Peanuts');

    CREATE TABLE IF NOT EXISTS admins (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS staff (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

   