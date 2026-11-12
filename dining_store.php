<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';

class DiningStore {
    /**
     * Auto-initialize database tables and seed menu if empty
     */
    public static function init() {
        global $pdo;
        if ($pdo) {
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

                $diningCols = [
                    "ALTER TABLE `dining_orders` ADD COLUMN IF NOT EXISTS `payment_method` VARCHAR(50) DEFAULT 'cash'",
                    "ALTER TABLE `dining_orders` ADD COLUMN IF NOT EXISTS `payment_status` VARCHAR(50) DEFAULT 'unpaid'",
                    "ALTER TABLE `dining_orders` ADD COLUMN IF NOT EXISTS `gcash_ref` VARCHAR(50) NULL",
                    "ALTER TABLE `dining_orders` ADD COLUMN IF NOT EXISTS `payment_proof` VARCHAR(255) NULL",
                    "ALTER TABLE `dining_orders` ADD COLUMN IF NOT EXISTS `paid_at` DATETIME NULL",
                    "ALTER TABLE `dining_orders` ADD COLUMN IF NOT EXISTS `payment_notes` TEXT NULL"
                ];
                foreach ($diningCols as $altSql) {
                    try { $pdo->exec($altSql); } catch (Exception $e) {}
                }

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
                $menuCount = (int)$pdo->query("SELECT COUNT(*) FROM `dining_menu`")->fetchColumn();
                if ($menuCount === 0) {
                    $defaultItems = self::getDefaultMenuItems();
                    $ins = $pdo->prepare("INSERT INTO `dining_menu` (`item_name`, `category`, `price`, `description`, `image`, `allergens`, `is_available`) VALUES (?, ?, ?, ?, ?, ?, 1)");
                    foreach ($defaultItems as $row) {
                        $ins->execute([$row['item_name'], $row['category'], $row['price'], $row['description'], $row['image'], $row['allergens']]);
                    }
                }
            } catch (Exception $e) {}
        }
    }

    /**
     * JSON Backup Path
     */
    private static function getJsonFilePath() {
        $dataDir = __DIR__ . '/data';
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0777, true);
        }
        return $dataDir . '/dining_orders.json';
    }

    /**
     * Load orders from JSON backup
     */
    public static function loadOrdersFromJson() {
        $path = self::getJsonFilePath();
        if (file_exists($path)) {
            $raw = @file_get_contents($path);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * Save order to JSON backup
     */
    public static function saveOrderToJson($orderRecord) {
        $path = self::getJsonFilePath();
        $orders = self::loadOrdersFromJson();
        $orders = array_values(array_filter($orders, function($o) use ($orderRecord) {
            return ($o['id'] ?? '') !== ($orderRecord['id'] ?? '');
        }));
        array_unshift($orders, $orderRecord);
        @file_put_contents($path, json_encode($orders, JSON_PRETTY_PRINT));
    }

    /**
     * Default Fallback Seed Menu Items
     */
    private static function getDefaultMenuItems() {
        return [
            [
                'id' => 1,
                'item_name' => 'Sizzling Garlic Butter Tiger Prawns',
                'category' => 'Main Dishes',
                'price' => 680.00,
                'description' => 'Fresh tiger prawns sauteed in rich garlic butter sauce, chili flakes, and fresh scallions.',
                'image' => 'https://images.unsplash.com/photo-1565680018434-b513d5e5fd47?auto=format&fit=crop&w=600&q=80',
                'allergens' => 'Seafood / Shellfish, Dairy',
                'is_available' => 1
            ],
            [
                'id' => 2,
                'item_name' => 'Purple Bamboo Special Grilled Chicken Inasal',
                'category' => 'Main Dishes',
                'price' => 450.00,
                'description' => 'Charcoal-grilled marinated chicken leg with annatto lemongrass oil and soy dip.',
                'image' => 'https://images.unsplash.com/photo-1598515214211-89d3c73ae83b?auto=format&fit=crop&w=600&q=80',
                'allergens' => 'Soy',
                'is_available' => 1
            ],
            [
                'id' => 3,
                'item_name' => 'Creamy Peanut Kare-Kare Tender Beef',
                'category' => 'Main Dishes',
                'price' => 580.00,
                'description' => 'Traditional Filipino stew cooked in rich toasted peanut & sesame sauce with native vegetables.',
                'image' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80',
                'allergens' => 'Nuts / Peanuts, Seafood / Shellfish',
                'is_available' => 1
            ],
            [
                'id' => 4,
                'item_name' => 'Crispy Calamares with Dip',
                'category' => 'Appetizers',
                'price' => 340.00,
                'description' => 'Deep-fried squid rings served with spicy garlic mayo and tartar sauce.',
                'image' => 'https://images.unsplash.com/photo-1599487488170-d11ec9c172f0?auto=format&fit=crop&w=600&q=80',
                'allergens' => 'Seafood / Shellfish, Eggs, Gluten',
                'is_available' => 1
            ],
            [
                'id' => 5,
                'item_name' => 'Fresh Tropical Dragonfruit & Mango Smoothie',
                'category' => 'Drinks & Cocktails',
                'price' => 180.00,
                'description' => 'Blended fresh mangoes, dragonfruit, coconut milk, and shaved ice.',
                'image' => 'https://images.unsplash.com/photo-1553530666-ba11a7da3888?auto=format&fit=crop&w=600&q=80',
                'allergens' => 'Dairy-Free, Vegan',
                'is_available' => 1
            ],
            [
                'id' => 6,
                'item_name' => 'Purple Velvet Ube Cream Shake',
                'category' => 'Drinks & Cocktails',
                'price' => 220.00,
                'description' => 'Signature purple yam thick milkshake topped with whipped cream, macapuno, and caramel.',
                'image' => 'https://images.unsplash.com/photo-1572490122747-3968b75cc699?auto=format&fit=crop&w=600&q=80',
                'allergens' => 'Dairy',
                'is_available' => 1
            ],
            [
                'id' => 7,
                'item_name' => 'Bamboo Coconut Halo-Halo Supreme',
                'category' => 'Desserts & Snacks',
                'price' => 250.00,
                'description' => 'Shaved ice with sweet beans, nata de coco, ube halaya, leche flan, and scoop of ube ice cream.',
                'image' => 'https://images.unsplash.com/photo-1563805042-7684c019e1cb?auto=format&fit=crop&w=600&q=80',
                'allergens' => 'Dairy, Eggs',
                'is_available' => 1
            ],
            [
                'id' => 8,
                'item_name' => 'Signature Poolside Mai Tai Cocktail',
                'category' => 'Drinks & Cocktails',
                'price' => 320.00,
                'description' => 'Dark rum, orange curaçao, almond syrup, and fresh lime juice served in bamboo tumbler.',
                'image' => 'https://images.unsplash.com/photo-1514362545857-3bc16c4c7d1b?auto=format&fit=crop&w=600&q=80',
                'allergens' => 'Nuts / Peanuts',
                'is_available' => 1
            ]
        ];
    }

    /**
     * Fetch all menu items filtered by category
     */
    public static function getMenuItems($category = null) {
        global $pdo;
        if ($pdo) {
            try {
                if ($category && $category !== 'All') {
                    $stmt = $pdo->prepare("SELECT * FROM dining_menu WHERE category = :cat AND is_available = 1 ORDER BY id ASC");
                    $stmt->execute([':cat' => $category]);
                } else {
                    $stmt = $pdo->query("SELECT * FROM dining_menu WHERE is_available = 1 ORDER BY category ASC, id ASC");
                }
                $items = $stmt->fetchAll();
                if (!empty($items)) {
                    return $items;
                }
            } catch (PDOException $e) {
                // Fallback
            }
        }
        
        $items = self::getDefaultMenuItems();
        if ($category && $category !== 'All') {
            $items = array_values(array_filter($items, function($i) use ($category) {
                return $i['category'] === $category;
            }));
        }
        return $items;
    }

    /**
     * Fetch all menu items for Admin (including unavailable / sold-out items)
     */
    public static function getAllMenuItemsAdmin($category = null) {
        global $pdo;
        if ($pdo) {
            try {
                if ($category && $category !== 'All') {
                    $stmt = $pdo->prepare("SELECT * FROM dining_menu WHERE category = :cat ORDER BY id ASC");
                    $stmt->execute([':cat' => $category]);
                } else {
                    $stmt = $pdo->query("SELECT * FROM dining_menu ORDER BY category ASC, id ASC");
                }
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($items)) {
                    return $items;
                }
            } catch (PDOException $e) {
                error_log("DiningStore Admin fetch error: " . $e->getMessage());
            }
        }
        
        $items = self::getDefaultMenuItems();
        if ($category && $category !== 'All') {
            $items = array_values(array_filter($items, function($i) use ($category) {
                return $i['category'] === $category;
            }));
        }
        return $items;
    }

    /**
     * Get a single menu item by ID
     */
    public static function getMenuItemById($id) {
        global $pdo;
        $id = intval($id);
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM dining_menu WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($item) return $item;
            } catch (PDOException $e) {}
        }
        $items = self::getDefaultMenuItems();
        foreach ($items as $item) {
            if (intval($item['id']) === $id) return $item;
        }
        return null;
    }

    /**
     * Save/Update a dining menu item
     */
    public static function saveMenuItem($id, $data, $image_file = null) {
        global $pdo;
        $id = intval($id);
        $existing = self::getMenuItemById($id);

        $item_name = !empty($data['item_name']) ? trim($data['item_name']) : ($existing['item_name'] ?? 'Menu Item');
        $category = !empty($data['category']) ? trim($data['category']) : ($existing['category'] ?? 'Main Dishes');
        $price = isset($data['price']) && $data['price'] !== '' ? floatval($data['price']) : floatval($existing['price'] ?? 0);
        $description = isset($data['description']) ? trim($data['description']) : ($existing['description'] ?? '');
        $allergens = isset($data['allergens']) ? trim($data['allergens']) : ($existing['allergens'] ?? '');
        $is_available = isset($data['is_available']) ? intval($data['is_available']) : intval($existing['is_available'] ?? 1);

        // Image Handling
        $image = !empty($data['existing_image']) ? trim($data['existing_image']) : ($existing['image'] ?? '');
        if (!empty($data['image_url'])) {
            $image = trim($data['image_url']);
        }
        if ($image_file && !empty($image_file['name']) && $image_file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($image_file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                $dir = __DIR__ . '/uploads/menu';
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                $filename = 'menu_' . $id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($image_file['tmp_name'], $dir . '/' . $filename)) {
                    $image = 'uploads/menu/' . $filename;
                }
            }
        }

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE dining_menu 
                    SET item_name = :name,
                        category = :category,
                        price = :price,
                        description = :description,
                        image = :image,
                        allergens = :allergens,
                        is_available = :is_available
                    WHERE id = :id
                ");
                return $stmt->execute([
                    ':name' => $item_name,
                    ':category' => $category,
                    ':price' => $price,
                    ':description' => $description,
                    ':image' => $image,
                    ':allergens' => $allergens,
                    ':is_available' => $is_available,
                    ':id' => $id
                ]);
            } catch (PDOException $e) {
                error_log("DiningStore update error: " . $e->getMessage());
                return false;
            }
        }
        return true;
    }

    /**
     * Add a brand new dining menu item
     */
    public static function addMenuItem($data, $image_file = null) {
        global $pdo;
        $item_name = trim($data['item_name'] ?? 'New Menu Item');
        $category = trim($data['category'] ?? 'Main Dishes');
        $price = floatval($data['price'] ?? 0);
        $description = trim($data['description'] ?? '');
        $allergens = trim($data['allergens'] ?? '');
        $is_available = isset($data['is_available']) ? intval($data['is_available']) : 1;
        $image = trim($data['image_url'] ?? 'assets/beach_kubo.jpg');

        $new_id = null;
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO dining_menu (item_name, category, price, description, image, allergens, is_available)
                    VALUES (:name, :category, :price, :description, :image, :allergens, :is_available)
                ");
                $stmt->execute([
                    ':name' => $item_name,
                    ':category' => $category,
                    ':price' => $price,
                    ':description' => $description,
                    ':image' => $image,
                    ':allergens' => $allergens,
                    ':is_available' => $is_available
                ]);
                $new_id = intval($pdo->lastInsertId());
            } catch (PDOException $e) {
                error_log("DiningStore insert error: " . $e->getMessage());
            }
        }

        // Handle uploaded image file if present
        if ($new_id && $image_file && !empty($image_file['name']) && $image_file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($image_file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                $dir = __DIR__ . '/uploads/menu';
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                $filename = 'menu_' . $new_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($image_file['tmp_name'], $dir . '/' . $filename)) {
                    $image = 'uploads/menu/' . $filename;
                    if ($pdo) {
                        try {
                            $up = $pdo->prepare("UPDATE dining_menu SET image = ? WHERE id = ?");
                            $up->execute([$image, $new_id]);
                        } catch (Exception $ex) {}
                    }
                }
            }
        }

        return $new_id;
    }

    /**
     * Delete a dining menu item
     */
    public static function deleteMenuItem($id) {
        global $pdo;
        $id = intval($id);
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("DELETE FROM dining_menu WHERE id = ?");
                return $stmt->execute([$id]);
            } catch (PDOException $e) {
                error_log("DiningStore delete error: " . $e->getMessage());
                return false;
            }
        }
        return true;
    }

    /**
     * Quick toggle item availability (1 = Available, 0 = Sold Out)
     */
    public static function toggleMenuItemAvailability($id, $is_available) {
        global $pdo;
        $id = intval($id);
        $is_available = $is_available ? 1 : 0;
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("UPDATE dining_menu SET is_available = ? WHERE id = ?");
                return $stmt->execute([$is_available, $id]);
            } catch (PDOException $e) {
                return false;
            }
        }
        return true;
    }

    /**
     * Cross-check selected guest allergies against ordered item allergen tags
     */
    public static function checkAllergenConflicts($guestAllergies, $orderItems) {
        $conflicts = [];
        if (empty($guestAllergies)) {
            return $conflicts;
        }

        $allergiesList = is_array($guestAllergies) ? $guestAllergies : array_map('trim', explode(',', $guestAllergies));
        $allMenuItems = self::getMenuItems();
        $menuLookup = [];
        foreach ($allMenuItems as $mi) {
            $menuLookup[$mi['id']] = $mi;
            $menuLookup[$mi['item_name']] = $mi;
        }

        foreach ($orderItems as $item) {
            $itemName = $item['name'] ?? ($item['item_name'] ?? '');
            $menuItem = $menuLookup[$itemName] ?? null;
            if ($menuItem && !empty($menuItem['allergens'])) {
                $itemAllergens = array_map('trim', explode(',', strtolower($menuItem['allergens'])));
                foreach ($allergiesList as $userAllergy) {
                    $userAllergyClean = strtolower(trim($userAllergy));
                    foreach ($itemAllergens as $ia) {
                        if (!empty($userAllergyClean) && (strpos($ia, $userAllergyClean) !== false || strpos($userAllergyClean, $ia) !== false)) {
                            $conflicts[] = [
                                'item' => $itemName,
                                'allergy' => $userAllergy,
                                'item_allergens' => $menuItem['allergens']
                            ];
                        }
                    }
                }
            }
        }

        return $conflicts;
    }

    /**
     * Create a new Dining Order
     */
    public static function createOrder($data) {
        global $pdo;
        self::init();

        $order_id = 'PBR-FOOD-' . date('Y') . '-' . rand(1000, 9999);
        $raw_uid = $data['user_id'] ?? ($_SESSION['user_id'] ?? null);
        $user_id = (!empty($raw_uid) && is_numeric($raw_uid)) ? intval($raw_uid) : null;
        $guest_name = trim($data['guest_name'] ?? ($_SESSION['full_name'] ?? 'Resort Guest'));
        $guest_email = trim($data['guest_email'] ?? ($_SESSION['email'] ?? 'guest@example.com'));
        $guest_phone = trim($data['guest_phone'] ?? ($_SESSION['phone'] ?? '09170000000'));
        $kubo_location = trim($data['kubo_location'] ?? 'Beach Kubo');
        
        $items = $data['items'] ?? [];
        $order_items_json = is_string($items) ? $items : json_encode($items);
        $total_amount = floatval($data['total_amount'] ?? 0);
        
        $guest_allergies = is_array($data['guest_allergies'] ?? null) 
            ? implode(', ', array_filter($data['guest_allergies'])) 
            : trim($data['guest_allergies'] ?? ($_SESSION['health_conditions'] ?? ''));
            
        $allergy_notes = trim($data['allergy_notes'] ?? ($_SESSION['health_info'] ?? ''));
        $requested_serve_time = trim($data['requested_serve_time'] ?? 'ASAP (15-20 Mins)');
        $kitchen_notes = trim($data['kitchen_notes'] ?? '');

        // Payment attributes
        $payment_method = strtolower(trim($data['payment_method'] ?? 'cash'));
        if (!in_array($payment_method, ['gcash', 'cash', 'charge_to_room'])) {
            $payment_method = 'cash';
        }

        $gcash_ref = !empty($data['gcash_ref']) ? trim($data['gcash_ref']) : null;
        $payment_proof = !empty($data['payment_proof']) ? trim($data['payment_proof']) : null;

        if ($payment_method === 'gcash') {
            $payment_status = (!empty($gcash_ref) || !empty($payment_proof)) ? 'proof_submitted' : 'unpaid';
        } elseif ($payment_method === 'charge_to_room') {
            $has_active_stay = false;
            if (!empty($user_id) || !empty($guest_email)) {
                require_once __DIR__ . '/booking_store.php';
                $all_b = BookingStore::getAllBookings();
                foreach ($all_b as $b) {
                    $m_user = (!empty($user_id) && ($b['user_id'] ?? null) == $user_id) 
                           || (!empty($guest_email) && strtolower(trim($b['guest_email'] ?? '')) === strtolower(trim($guest_email)));
                    if ($m_user && strtolower(trim($b['type'] ?? 'house')) === 'house' && strtolower(trim($b['booking_status'] ?? '')) === 'checked_in') {
                        $has_active_stay = true;
                        break;
                    }
                }
            }
            if ($has_active_stay) {
                $payment_status = 'charged_to_room';
            } else {
                $payment_method = 'cash';
                $payment_status = 'unpaid';
            }
        } else {
            $payment_status = $data['payment_status'] ?? 'unpaid';
        }
        $paid_at = ($payment_status === 'verified' || $payment_status === 'paid') ? date('Y-m-d H:i:s') : null;
        $payment_notes = trim($data['payment_notes'] ?? '');

        $order_record = [
            'id' => $order_id,
            'user_id' => $user_id,
            'guest_name' => $guest_name,
            'guest_email' => $guest_email,
            'guest_phone' => $guest_phone,
            'kubo_location' => $kubo_location,
            'order_items_json' => $order_items_json,
            'total_amount' => $total_amount,
            'guest_allergies' => $guest_allergies,
            'allergy_notes' => $allergy_notes,
            'requested_serve_time' => $requested_serve_time,
            'order_status' => 'pending',
            'kitchen_notes' => $kitchen_notes,
            'payment_method' => $payment_method,
            'payment_status' => $payment_status,
            'gcash_ref' => $gcash_ref,
            'payment_proof' => $payment_proof,
            'paid_at' => $paid_at,
            'payment_notes' => $payment_notes,
            'created_at' => date('Y-m-d H:i:s'),
            'prepared_at' => null,
            'served_at' => null
        ];

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO dining_orders (
                        id, user_id, guest_name, guest_email, guest_phone, kubo_location,
                        order_items_json, total_amount, guest_allergies, allergy_notes,
                        requested_serve_time, order_status, kitchen_notes,
                        payment_method, payment_status, gcash_ref, payment_proof, paid_at, payment_notes,
                        created_at
                    ) VALUES (
                        :id, :user_id, :guest_name, :guest_email, :guest_phone, :kubo_location,
                        :order_items_json, :total_amount, :guest_allergies, :allergy_notes,
                        :requested_serve_time, 'pending', :kitchen_notes,
                        :payment_method, :payment_status, :gcash_ref, :payment_proof, :paid_at, :payment_notes,
                        NOW()
                    )
                ");
                $stmt->execute([
                    ':id' => $order_id,
                    ':user_id' => $user_id,
                    ':guest_name' => $guest_name,
                    ':guest_email' => $guest_email,
                    ':guest_phone' => $guest_phone,
                    ':kubo_location' => $kubo_location,
                    ':order_items_json' => $order_items_json,
                    ':total_amount' => $total_amount,
                    ':guest_allergies' => $guest_allergies,
                    ':allergy_notes' => $allergy_notes,
                    ':requested_serve_time' => $requested_serve_time,
                    ':kitchen_notes' => $kitchen_notes,
                    ':payment_method' => $payment_method,
                    ':payment_status' => $payment_status,
                    ':gcash_ref' => $gcash_ref,
                    ':payment_proof' => $payment_proof,
                    ':paid_at' => $paid_at,
                    ':payment_notes' => $payment_notes
                ]);
                if (function_exists('log_activity')) {
                    log_activity("DINING_ORDER_CREATED", "Food order #{$order_id} created for {$guest_name} [Payment: {$payment_method}/{$payment_status}]");
                }
            } catch (PDOException $e) {
                error_log("DiningStore insert error: " . $e->getMessage());
            }
        }

        // Always save to persistent JSON storage
        self::saveOrderToJson($order_record);

        // Store in Session for fallback persistence
        if (!isset($_SESSION['dining_orders'])) {
            $_SESSION['dining_orders'] = [];
        }
        array_unshift($_SESSION['dining_orders'], $order_record);

        return $order_record;
    }

    /**
     * Submit GCash payment proof for dining order
     */
    public static function submitPaymentProof($order_id, $gcash_ref, $proof_file) {
        global $pdo;
        self::init();

        $order = self::getOrderById($order_id);
        if (!$order) {
            return ['success' => false, 'error' => 'Order not found.'];
        }

        $clean_ref = preg_replace('/[^0-9]/', '', $gcash_ref);
        if (strlen($clean_ref) !== 13) {
            return ['success' => false, 'error' => 'Please enter a valid 13-digit GCash Reference Number (e.g. 1002938475612).'];
        }

        $proof_path = null;
        if (is_array($proof_file)) {
            if (empty($proof_file) || ($proof_file['error'] ?? -1) !== UPLOAD_ERR_OK) {
                return ['success' => false, 'error' => 'Please select a valid payment proof receipt screenshot.'];
            }

            $upload_dir = __DIR__ . '/uploads/receipts/';
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0777, true);
            }

            $ext = strtolower(pathinfo($proof_file['name'] ?? '', PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($ext, $allowed)) {
                return ['success' => false, 'error' => 'Only image files (JPG, PNG, WEBP) are allowed.'];
            }

            $safe_id = preg_replace('/[^A-Za-z0-9_-]/', '', $order_id);
            $new_name = 'dining_receipt_' . $safe_id . '_' . time() . '.' . $ext;
            $target = $upload_dir . $new_name;

            if (!move_uploaded_file($proof_file['tmp_name'], $target)) {
                return ['success' => false, 'error' => 'Failed to save uploaded receipt image.'];
            }
            $proof_path = 'uploads/receipts/' . $new_name;
        } elseif (is_string($proof_file) && !empty($proof_file)) {
            $proof_path = $proof_file;
        } else {
            return ['success' => false, 'error' => 'Please select a valid payment proof receipt screenshot.'];
        }

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE dining_orders 
                    SET payment_method = 'gcash', payment_status = 'proof_submitted', gcash_ref = :gref, payment_proof = :proof
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':gref' => $clean_ref,
                    ':proof' => $proof_path,
                    ':id' => $order_id
                ]);
            } catch (Exception $e) {
                error_log("DiningStore::submitPaymentProof DB error: " . $e->getMessage());
            }
        }

        // Update JSON
        $json_orders = self::loadOrdersFromJson();
        foreach ($json_orders as &$jo) {
            if (($jo['id'] ?? '') === $order_id) {
                $jo['payment_method'] = 'gcash';
                $jo['payment_status'] = 'proof_submitted';
                $jo['gcash_ref'] = $clean_ref;
                $jo['payment_proof'] = $proof_path;
            }
        }
        @file_put_contents(self::getJsonFilePath(), json_encode($json_orders, JSON_PRETTY_PRINT));

        if (function_exists('log_activity')) {
            log_activity("DINING_PAYMENT_SUBMITTED", "GCash payment proof submitted for Dining Order #{$order_id} (Ref: {$clean_ref})");
        }

        return ['success' => true, 'proof_path' => $proof_path, 'gcash_ref' => $clean_ref];
    }

    /**
     * Update Dining Order Payment Status (e.g. verified, paid, rejected)
     */
    public static function updatePaymentStatus($order_id, $payment_status, $payment_notes = '', $admin_name = '') {
        global $pdo;
        self::init();

        $statusClean = strtolower(trim($payment_status));
        $now = date('Y-m-d H:i:s');
        $paid_at = in_array($statusClean, ['verified', 'paid']) ? $now : null;

        if ($pdo) {
            try {
                $sql = "UPDATE dining_orders SET payment_status = :st, payment_notes = :notes";
                $params = [':st' => $statusClean, ':notes' => $payment_notes, ':id' => $order_id];
                if ($paid_at) {
                    $sql .= ", paid_at = :paid_at";
                    $params[':paid_at'] = $paid_at;
                }
                $sql .= " WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } catch (Exception $e) {
                error_log("DiningStore::updatePaymentStatus error: " . $e->getMessage());
            }
        }

        // Update JSON
        $json_orders = self::loadOrdersFromJson();
        foreach ($json_orders as &$jo) {
            if (($jo['id'] ?? '') === $order_id) {
                $jo['payment_status'] = $statusClean;
                if (!empty($payment_notes)) $jo['payment_notes'] = $payment_notes;
                if ($paid_at) $jo['paid_at'] = $paid_at;
            }
        }
        @file_put_contents(self::getJsonFilePath(), json_encode($json_orders, JSON_PRETTY_PRINT));

        if (function_exists('log_activity')) {
            $byText = !empty($admin_name) ? " by {$admin_name}" : "";
            log_activity("DINING_PAYMENT_STATUS", "Dining Order #{$order_id} payment status changed to {$statusClean}{$byText}");
        }

        return true;
    }

    /**
     * Fetch All Dining Orders with live Serve Time monitoring metadata
     */
    public static function getAllOrders($filters = []) {
        global $pdo;
        self::init();
        $orders = [];

        if ($pdo) {
            try {
                $sql = "SELECT * FROM dining_orders ORDER BY created_at DESC";
                $orders = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log("DiningStore fetch error: " . $e->getMessage());
                // Fallback to offline JSON storage only if database connection/query failed
                $orders = self::loadOrdersFromJson();
            }
        } else {
            // Offline fallback when no DB connection
            $orders = self::loadOrdersFromJson();
        }

        // Sort all orders by created_at DESC
        usort($orders, function($a, $b) {
            $tA = strtotime($a['created_at'] ?? '2026-01-01');
            $tB = strtotime($b['created_at'] ?? '2026-01-01');
            return $tB - $tA;
        });

        // Filter search & user email
        if (!empty($filters['user_email'])) {
            $emailClean = strtolower(trim($filters['user_email']));
            $orders = array_values(array_filter($orders, function($o) use ($emailClean) {
                return strtolower(trim($o['guest_email'] ?? '')) === $emailClean;
            }));
        }

        if (!empty($filters['search'])) {
            $s = strtolower(trim($filters['search']));
            $orders = array_values(array_filter($orders, function($o) use ($s) {
                return strpos(strtolower($o['id'] ?? ''), $s) !== false || 
                       strpos(strtolower($o['guest_name'] ?? ''), $s) !== false ||
                       strpos(strtolower($o['guest_email'] ?? ''), $s) !== false ||
                       strpos(strtolower($o['guest_phone'] ?? ''), $s) !== false ||
                       strpos(strtolower($o['kubo_location'] ?? ''), $s) !== false;
            }));
        }

        // Calculate Serve Duration & Time Monitoring Metrics for each order
        foreach ($orders as &$ord) {
            $createdTs = strtotime($ord['created_at'] ?? 'now');
            $servedTs = !empty($ord['served_at']) ? strtotime($ord['served_at']) : time();
            
            $elapsedSecs = max(0, $servedTs - $createdTs);
            $ord['elapsed_mins'] = round($elapsedSecs / 60);
            
            $currSt = strtolower(trim($ord['order_status'] ?? 'pending'));
            if ($currSt === 'served' || $currSt === 'completed') {
                $ord['serve_duration_text'] = "Served in " . $ord['elapsed_mins'] . " mins";
            } elseif ($currSt === 'cancelled' || $currSt === 'canceled') {
                $ord['serve_duration_text'] = "Order Cancelled";
            } elseif ($currSt === 'pending') {
                $ord['serve_duration_text'] = "Awaiting Acceptance (" . $ord['elapsed_mins'] . " mins)";
            } elseif ($currSt === 'accepted') {
                $ord['serve_duration_text'] = "Queued in Kitchen (" . $ord['elapsed_mins'] . " mins)";
            } else {
                $ord['serve_duration_text'] = "In Progress: " . $ord['elapsed_mins'] . " mins elapsed";
                if ($ord['elapsed_mins'] > 25) {
                    $ord['is_overdue'] = true;
                }
            }

            // Flag Allergen Conflict Warning
            $itemsArr = is_array($ord['order_items_json']) ? $ord['order_items_json'] : json_decode($ord['order_items_json'], true);
            $ord['items_parsed'] = $itemsArr ?: [];
            $ord['allergy_conflicts'] = self::checkAllergenConflicts($ord['guest_allergies'] ?? '', $ord['items_parsed']);
        }

        return $orders;
    }

    /**
     * Update Dining Order Status & Time Monitoring Markers
     */
    public static function updateOrderStatus($orderId, $status, $kitchenNotes = '') {
        global $pdo;

        $statusClean = strtolower(trim($status));
        // Normalize aliases
        if ($statusClean === 'delivering') {
            $statusClean = 'out_for_serving';
        }

        if ($pdo) {
            try {
                $pdo->exec("ALTER TABLE dining_orders MODIFY COLUMN order_status VARCHAR(50) DEFAULT 'pending'");
            } catch (PDOException $e) {}

            try {
                if ($statusClean === 'served' || $statusClean === 'completed') {
                    $stmt = $pdo->prepare("UPDATE dining_orders SET order_status = :st, kitchen_notes = :notes, served_at = NOW() WHERE id = :id");
                    $stmt->execute([':st' => $statusClean, ':notes' => $kitchenNotes, ':id' => $orderId]);
                } elseif (in_array($statusClean, ['preparing', 'ready', 'out_for_serving', 'delivering'])) {
                    $stmt = $pdo->prepare("UPDATE dining_orders SET order_status = :st, kitchen_notes = :notes, prepared_at = IFNULL(prepared_at, NOW()) WHERE id = :id");
                    $stmt->execute([':st' => $statusClean, ':notes' => $kitchenNotes, ':id' => $orderId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE dining_orders SET order_status = :st, kitchen_notes = :notes WHERE id = :id");
                    $stmt->execute([':st' => $statusClean, ':notes' => $kitchenNotes, ':id' => $orderId]);
                }
                if (function_exists('log_activity')) {
                    log_activity("DINING_ORDER_STATUS", "Order #{$orderId} status changed to {$statusClean}");
                }
            } catch (PDOException $e) {
                error_log("DiningStore update error: " . $e->getMessage());
            }
        }

        // Update in persistent JSON backup file
        $json_orders = self::loadOrdersFromJson();
        $updated_json = false;
        foreach ($json_orders as &$jo) {
            if (($jo['id'] ?? '') === $orderId) {
                $jo['order_status'] = $statusClean;
                if (!empty($kitchenNotes)) $jo['kitchen_notes'] = $kitchenNotes;
                if (in_array($statusClean, ['preparing', 'ready', 'out_for_serving', 'delivering']) && empty($jo['prepared_at'])) {
                    $jo['prepared_at'] = date('Y-m-d H:i:s');
                }
                if ($statusClean === 'served' || $statusClean === 'completed') {
                    $jo['served_at'] = date('Y-m-d H:i:s');
                }
                $updated_json = true;
            }
        }
        if ($updated_json) {
            @file_put_contents(self::getJsonFilePath(), json_encode($json_orders, JSON_PRETTY_PRINT));
        }

        // Fallback Session Update
        if (!empty($_SESSION['dining_orders'])) {
            foreach ($_SESSION['dining_orders'] as &$o) {
                if ($o['id'] === $orderId) {
                    $o['order_status'] = $statusClean;
                    if (!empty($kitchenNotes)) {
                        $o['kitchen_notes'] = $kitchenNotes;
                    }
                    if (in_array($statusClean, ['preparing', 'ready', 'out_for_serving', 'delivering']) && empty($o['prepared_at'])) {
                        $o['prepared_at'] = date('Y-m-d H:i:s');
                    }
                    if ($statusClean === 'served' || $statusClean === 'completed') {
                        $o['served_at'] = date('Y-m-d H:i:s');
                    }
                }
            }
        }

        return true;
    }

    /**
     * Get specific order by ID
     */
    public static function getOrderById($orderId) {
        if (empty($orderId)) return null;
        $orders = self::getAllOrders();
        foreach ($orders as $ord) {
            if (($ord['id'] ?? '') === $orderId) {
                return $ord;
            }
        }
        return null;
    }

    /**
     * Calculate Summary Statistics for Kitchen Serve Time Monitoring
     */
    public static function getStats() {
        $orders = self::getAllOrders();
        $total = count($orders);
        $pending = 0;
        $preparing = 0;
        $served = 0;
        $totalServeMins = 0;
        $servedCount = 0;
        $allergyAlertsCount = 0;

        foreach ($orders as $o) {
            $st = strtolower(trim($o['order_status'] ?? 'pending'));
            if (in_array($st, ['pending', 'accepted'])) $pending++;
            if (in_array($st, ['preparing', 'ready', 'out_for_serving', 'delivering'])) $preparing++;
            if (in_array($st, ['served', 'completed'])) {
                $served++;
                $servedCount++;
                $totalServeMins += $o['elapsed_mins'] ?? 0;
            }
            if (!empty($o['allergy_conflicts']) || !empty($o['guest_allergies'])) {
                $allergyAlertsCount++;
            }
        }

        $avgServeMins = $servedCount > 0 ? round($totalServeMins / $servedCount) : 18;

        return [
            'total_orders' => $total,
            'pending_count' => $pending,
            'preparing_count' => $preparing,
            'served_count' => $served,
            'avg_serve_mins' => $avgServeMins,
            'allergy_alerts_count' => $allergyAlertsCount
        ];
    }
}
?>
