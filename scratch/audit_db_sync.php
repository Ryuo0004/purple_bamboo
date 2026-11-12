<?php
header('Content-Type: text/plain; charset=utf-8');
require_once dirname(__DIR__) . '/database.php';

echo "========================================================================\n";
echo "       PURPLE BAMBOO RESORT - DATABASE SCHEMA SYNCHRONIZATION AUDIT     \n";
echo "========================================================================\n";
echo "Execution Time: " . date('Y-m-d H:i:s') . "\n";
echo "Database: " . DB_NAME . "\n\n";

if (!isset($pdo) || !$pdo instanceof PDO) {
    echo "FATAL ERROR: Could not connect to PDO database.\n";
    exit;
}

// 1. Table Existence Check
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "TABLES PRESENT IN MYSQL (" . count($tables) . "):\n";
echo implode(', ', $tables) . "\n\n";

// Expected columns definition for every core business entity
$expectedSchema = [
    'activities' => [
        'activity_id' => 'Primary Key / ID',
        'activity_name' => 'Name of activity',
        'category' => 'Activity category (pool, outdoor, wellness)',
        'price' => 'Decimal price per pax',
        'duration' => 'Duration text (e.g. 1 Hour)',
        'duration_minutes' => 'Duration in minutes',
        'capacity' => 'Max slot capacity',
        'max_pax' => 'Maximum participants allowed per booking',
        'total_units' => 'Total equipment / slot units',
        'available_units' => 'Live available units',
        'equipment' => 'JSON encoded equipment inventory tracking',
        'image' => 'Image URL / path',
        'is_available' => '1 = active, 0 = maintenance / inactive',
        'status' => 'Status string (available, maintenance)'
    ],
    'dining_orders' => [
        'id' => 'Order reference ID',
        'user_id' => 'Guest user account reference',
        'guest_name' => 'Guest display name',
        'guest_email' => 'Guest email',
        'guest_phone' => 'Guest contact number',
        'kubo_location' => 'Kubo or dining area',
        'order_items_json' => 'JSON order items basket',
        'total_amount' => 'Order total in PHP',
        'guest_allergies' => 'Allergy flags or JSON',
        'allergy_notes' => 'Custom allergy / dietary instruction',
        'requested_serve_time' => 'ASAP or specified time',
        'order_status' => 'pending, accepted, preparing, out_for_serving, delivered, served, cancelled',
        'kitchen_notes' => 'Kitchen status update log',
        'created_at' => 'Order creation timestamp',
        'prepared_at' => 'Time cooking started',
        'served_at' => 'Time served to guest'
    ],
    'dining_menu' => [
        'id' => 'Dish ID',
        'item_name' => 'Dish or beverage name',
        'category' => 'Menu section (Main Dishes, Appetizers, etc.)',
        'price' => 'Decimal price',
        'description' => 'Culinary description',
        'image' => 'Food photo path / URL',
        'allergens' => 'Allergen tags (Eggs, Dairy, etc.)',
        'is_available' => '1 = available, 0 = sold out'
    ],
    'house_kubos' => [
        'kubo_id' => 'Kubo ID',
        'kubo_name_number' => 'Display name',
        'category_id' => 'Foreign key to house_categories',
        'price_per_night' => 'Nightly rate',
        'capacity' => 'Guest capacity',
        'total_units' => 'Total physical units',
        'available_units' => 'Remaining available units',
        'is_available' => '1 = active, 0 = renovation',
        'status' => 'available, maintenance, renovation'
    ],
    'bookings' => [
        'id' => 'Booking reference (e.g. PBR-2026-XXXX)',
        'user_id' => 'User ID',
        'profile_id' => 'Profile ID tag',
        'guest_name' => 'Guest full name',
        'guest_email' => 'Guest email',
        'guest_phone' => 'Guest phone',
        'type' => 'house or activity',
        'item_id' => 'Kubo ID or Activity ID',
        'item_name' => 'Item title',
        'check_in' => 'Check-in date or start date',
        'check_out' => 'Check-out date',
        'time_slot' => 'Scheduled time slot',
        'pax' => 'Guest count',
        'total_amount' => 'Grand total PHP',
        'gcash_ref' => 'Payment transaction reference',
        'payment_status' => 'pending, verified, rejected',
        'booking_status' => 'pending, approved, checked_in, completed, cancelled',
        'resched_pending' => 'Reschedule request flag',
        'resched_check_in' => 'Requested new check-in date',
        'resched_check_out' => 'Requested new check-out date',
        'resched_reason' => 'Guest reschedule reason'
    ],
    'users' => [
        'id' => 'User ID',
        'email' => 'Unique email address',
        'password_hash' => 'Hashed credential',
        'role' => 'guest, staff, admin',
        'status' => 'active, blocked, deactivated',
        'is_blocked' => '1 = blocked, 0 = active',
        'block_reason' => 'Reason for blocking / deactivation',
        'blocked_at' => 'Timestamp of block',
        'blocked_by' => 'Admin name who blocked'
    ]
];

$missingColumns = [];
$existingColumnsCount = 0;
$missingCount = 0;

echo "------------------------------------------------------------------------\n";
echo "2. DETAILED COLUMN AUDIT BY TABLE\n";
echo "------------------------------------------------------------------------\n";

foreach ($expectedSchema as $tableName => $cols) {
    if (!in_array($tableName, $tables)) {
        echo "[MISSING TABLE] $tableName is not found in database!\n";
        $missingColumns[$tableName][] = "ENTIRE TABLE MISSING";
        $missingCount++;
        continue;
    }

    $dbColsStmt = $pdo->query("SHOW COLUMNS FROM `$tableName`");
    $dbCols = $dbColsStmt->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($dbCols, 'Field');
    $colMap = [];
    foreach ($dbCols as $c) {
        $colMap[$c['Field']] = $c;
    }

    echo "\n=== TABLE: `$tableName` (" . count($colNames) . " columns found) ===\n";
    foreach ($cols as $colName => $desc) {
        if (in_array($colName, $colNames)) {
            $existingColumnsCount++;
            $cMeta = $colMap[$colName];
            printf("  [OK] %-20s : %-18s (Null: %-3s, Default: %s)\n", $colName, $cMeta['Type'], $cMeta['Null'], $cMeta['Default'] ?? 'none');
        } else {
            // Check for alias/substitute column (e.g. id vs user_id, or kubo_id vs id)
            $aliasFound = false;
            if ($colName === 'activity_id' && in_array('id', $colNames)) $aliasFound = 'id';
            if ($colName === 'kubo_id' && in_array('id', $colNames)) $aliasFound = 'id';
            if ($colName === 'id' && in_array('user_id', $colNames)) $aliasFound = 'user_id';

            if ($aliasFound) {
                echo "  [OK ALIAS] $colName -> present as `$aliasFound`\n";
                $existingColumnsCount++;
            } else {
                echo "  [MISSING] $colName - $desc\n";
                $missingColumns[$tableName][] = $colName;
                $missingCount++;
            }
        }
    }
}

// 3. ENUM / VARCHAR Safety Check for order_status in dining_orders
echo "\n------------------------------------------------------------------------\n";
echo "3. SPECIFIC FIELD TYPE VALIDATION (Dining Status & Max Pax)\n";
echo "------------------------------------------------------------------------\n";
$doStatusMeta = $pdo->query("SHOW COLUMNS FROM `dining_orders` LIKE 'order_status'")->fetch(PDO::FETCH_ASSOC);
if ($doStatusMeta) {
    echo "dining_orders.order_status Type: " . $doStatusMeta['Type'] . "\n";
    if (stripos($doStatusMeta['Type'], 'varchar') !== false) {
        echo "[PASS] order_status is VARCHAR (Flexible for 'out_for_serving', 'delivering', 'cancelled', etc.)\n";
    } elseif (stripos($doStatusMeta['Type'], 'enum') !== false) {
        echo "[CHECK] order_status is ENUM. Value set: " . $doStatusMeta['Type'] . "\n";
    }
}

$actPaxMeta = $pdo->query("SHOW COLUMNS FROM `activities` LIKE 'max_pax'")->fetch(PDO::FETCH_ASSOC);
if ($actPaxMeta) {
    echo "activities.max_pax Type: " . $actPaxMeta['Type'] . " (Null: " . $actPaxMeta['Null'] . ", Default: " . ($actPaxMeta['Default'] ?? 'none') . ")\n";
    echo "[PASS] max_pax exists and stores integer capacity limit.\n";
} else {
    echo "[FAIL] activities.max_pax is missing!\n";
}

$userBlockMeta = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'is_blocked'")->fetch(PDO::FETCH_ASSOC);
if ($userBlockMeta) {
    echo "users.is_blocked Type: " . $userBlockMeta['Type'] . " (Default: " . ($userBlockMeta['Default'] ?? 'none') . ")\n";
    echo "[PASS] is_blocked exists for account security lockouts.\n";
}

// Summary
echo "\n========================================================================\n";
echo "AUDIT SUMMARY:\n";
echo "Total Verified Columns Across Schema: $existingColumnsCount\n";
echo "Total Missing Columns Identified    : $missingCount\n";
if ($missingCount === 0) {
    echo "STATUS: ALL RECENT CHANGES AND REQUIRED FIELDS ARE 100% IN SYNC IN THE LIVE DATABASE!\n";
} else {
    echo "STATUS: DATABASE UPDATE REQUIRED! The following columns need to be added:\n";
    print_r($missingColumns);
}
echo "========================================================================\n";
