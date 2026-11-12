<?php
require_once __DIR__ . '/../database.php';
header('Content-Type: text/plain');

try {
    $pdo->exec("
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
    ");
    echo "dining_orders created successfully!\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dining_menu (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(100) NOT NULL,
            category VARCHAR(50) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            description TEXT NULL,
            image VARCHAR(255) NULL,
            allergens VARCHAR(255) NULL,
            is_available TINYINT(1) DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "dining_menu created successfully!\n";

    // Check count of menu items, seed if empty
    $menuCount = $pdo->query("SELECT COUNT(*) FROM dining_menu")->fetchColumn();
    if ($menuCount == 0) {
        require_once __DIR__ . '/../dining_store.php';
        $defaultItems = [
            ['Sizzling Garlic Butter Tiger Prawns', 'Main Dishes', 680.00, 'Fresh tiger prawns sauteed in rich garlic butter sauce, chili flakes, and fresh scallions.', 'https://images.unsplash.com/photo-1565680018434-b513d5e5fd47?auto=format&fit=crop&w=600&q=80', 'Seafood / Shellfish, Dairy'],
            ['Purple Bamboo Special Grilled Chicken Inasal', 'Main Dishes', 450.00, 'Charcoal-grilled marinated chicken leg with annatto lemongrass oil and soy dip.', 'https://images.unsplash.com/photo-1598515214211-89d3c73ae83b?auto=format&fit=crop&w=600&q=80', 'Soy'],
            ['Creamy Peanut Kare-Kare Tender Beef', 'Main Dishes', 580.00, 'Traditional Filipino stew cooked in rich toasted peanut & sesame sauce with native vegetables.', 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80', 'Nuts / Peanuts, Seafood / Shellfish'],
            ['Crispy Calamares with Dip', 'Appetizers', 340.00, 'Deep-fried squid rings served with spicy garlic mayo and tartar sauce.', 'https://images.unsplash.com/photo-1599487488170-d11ec9c172f0?auto=format&fit=crop&w=600&q=80', 'Seafood / Shellfish, Eggs, Gluten'],
            ['Fresh Tropical Dragonfruit & Mango Smoothie', 'Drinks & Cocktails', 180.00, 'Blended fresh mangoes, dragonfruit, coconut milk, and shaved ice.', 'https://images.unsplash.com/photo-1553530666-ba11a7da3888?auto=format&fit=crop&w=600&q=80', 'Dairy-Free, Vegan'],
            ['Purple Velvet Ube Cream Shake', 'Drinks & Cocktails', 220.00, 'Signature purple yam thick milkshake topped with whipped cream, macapuno, and caramel.', 'https://images.unsplash.com/photo-1572490122747-3968b75cc699?auto=format&fit=crop&w=600&q=80', 'Dairy'],
            ['Bamboo Coconut Halo-Halo Supreme', 'Desserts & Snacks', 250.00, 'Shaved ice with sweet beans, nata de coco, ube halaya, leche flan, and scoop of ube ice cream.', 'https://images.unsplash.com/photo-1563805042-7684c019e1cb?auto=format&fit=crop&w=600&q=80', 'Dairy, Eggs'],
            ['Signature Poolside Mai Tai Cocktail', 'Drinks & Cocktails', 320.00, 'Dark rum, orange curaçao, almond syrup, and fresh lime juice served in bamboo tumbler.', 'https://images.unsplash.com/photo-1514362545857-3bc16c4c7d1b?auto=format&fit=crop&w=600&q=80', 'Nuts / Peanuts']
        ];
        $ins = $pdo->prepare("INSERT INTO dining_menu (item_name, category, price, description, image, allergens, is_available) VALUES (?, ?, ?, ?, ?, ?, 1)");
        foreach ($defaultItems as $row) {
            $ins->execute($row);
        }
        echo "Seeded " . count($defaultItems) . " menu items.\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
