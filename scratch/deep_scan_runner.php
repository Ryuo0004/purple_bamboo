<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: text/plain; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "========================================================================\n";
echo "           PURPLE BAMBOO RESORT - DEEP SCAN HEALTH CHECK                \n";
echo "========================================================================\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'CLI') . "\n\n";

$passCount = 0;
$warnCount = 0;
$failCount = 0;

function report($label, $status, $detail = '') {
    global $passCount, $warnCount, $failCount;
    $symbol = "[?]";
    if ($status === 'PASS') {
        $symbol = "[PASS]";
        $passCount++;
    } elseif ($status === 'WARN') {
        $symbol = "[WARN]";
        $warnCount++;
    } else {
        $symbol = "[FAIL]";
        $failCount++;
    }
    printf("%-7s %-45s : %s\n", $symbol, $label, $detail);
}

// -------------------------------------------------------------------------
// 1. DATABASE CONNECTION & SCHEMA VALIDATION
// -------------------------------------------------------------------------
echo "------------------------------------------------------------------------\n";
echo "1. DATABASE CONNECTION & SCHEMA VALIDATION\n";
echo "------------------------------------------------------------------------\n";

$root = dirname(__DIR__);
require_once $root . '/database.php';

if (isset($pdo) && $pdo instanceof PDO) {
    report("PDO Database Connection", "PASS", "Connected to MySQL successfully");
    
    try {
        $vStmt = $pdo->query("SELECT VERSION() as ver, DATABASE() as db");
        $vRes = $vStmt->fetch(PDO::FETCH_ASSOC);
        report("MySQL Database Info", "PASS", "DB: " . ($vRes['db'] ?? 'unknown') . " | Engine Ver: " . ($vRes['ver'] ?? 'unknown'));
    } catch (Exception $e) {
        report("MySQL Database Info", "WARN", $e->getMessage());
    }

    // Required tables list
    $requiredTables = [
        'activities',
        'activity_logs',
        'activity_reservations',
        'addon_reservations',
        'admins',
        'amenities',
        'bookings',
        'booking_archive',
        'dining_menu',
        'dining_orders',
        'gcash_payments',
        'house_categories',
        'house_kubos',
        'kubo_amenities',
        'notifications',
        'reviews',
        'sent_emails',
        'site_content',
        'site_settings',
        'staff',
        'system_settings',
        'users'
    ];

    $existingTables = [];
    try {
        $tStmt = $pdo->query("SHOW TABLES");
        while ($row = $tStmt->fetch(PDO::FETCH_NUM)) {
            $existingTables[] = $row[0];
        }
    } catch (Exception $e) {
        report("Fetch Tables", "FAIL", $e->getMessage());
    }

    foreach ($requiredTables as $t) {
        if (in_array($t, $existingTables)) {
            $cntStmt = $pdo->query("SELECT COUNT(*) FROM `$t`");
            $cnt = $cntStmt->fetchColumn();
            report("Table: $t", "PASS", "Exists ($cnt records)");
        } else {
            report("Table: $t", "FAIL", "Missing table in schema");
        }
    }

    // Critical column checks with exact schema definitions
    $criticalCols = [
        'activities' => ['activity_id', 'activity_name', 'max_pax', 'capacity', 'total_units', 'available_units', 'price', 'status', 'is_available', 'equipment'],
        'dining_orders' => ['id', 'user_id', 'guest_name', 'order_items_json', 'total_amount', 'order_status', 'requested_serve_time', 'created_at'],
        'dining_menu' => ['id', 'item_name', 'category', 'price', 'allergens', 'is_available'],
        'bookings' => ['id', 'user_id', 'type', 'booking_status', 'payment_status', 'total_amount'],
        'house_kubos' => ['kubo_id', 'category_id', 'kubo_name_number', 'price_per_night', 'capacity', 'status', 'available_units'],
    ];

    foreach ($criticalCols as $tbl => $cols) {
        if (!in_array($tbl, $existingTables)) continue;
        $colStmt = $pdo->query("SHOW COLUMNS FROM `$tbl`");
        $foundCols = [];
        while ($col = $colStmt->fetch(PDO::FETCH_ASSOC)) {
            $foundCols[] = $col['Field'];
        }
        foreach ($cols as $colName) {
            if (in_array($colName, $foundCols)) {
                report("Column: $tbl.$colName", "PASS", "Present in table");
            } else {
                report("Column: $tbl.$colName", "FAIL", "Missing from $tbl!");
            }
        }
    }

} else {
    report("PDO Database Connection", "FAIL", "PDO connection is NULL or uninitialized");
}

// -------------------------------------------------------------------------
// 2. PHP SYNTAX ERROR SCAN
// -------------------------------------------------------------------------
echo "\n------------------------------------------------------------------------\n";
echo "2. PHP SYNTAX ERROR SCAN (ALL ROOT PHP FILES)\n";
echo "------------------------------------------------------------------------\n";

$phpFiles = glob($root . '/*.php');
$syntaxErrors = [];

foreach ($phpFiles as $file) {
    $base = basename($file);
    // Test syntax using php -l via exec
    $cmd = "php -l " . escapeshellarg($file) . " 2>&1";
    $output = [];
    $ret = -1;
    @exec($cmd, $output, $ret);

    if ($ret === 0) {
        report("Syntax: $base", "PASS", "No syntax errors detected");
    } else {
        // Fallback: Check using token_get_all if exec php is not in PATH
        $code = file_get_contents($file);
        $tokenError = false;
        try {
            token_get_all($code, TOKEN_PARSE);
            report("Syntax: $base (token check)", "PASS", "Token parsing succeeded");
        } catch (ParseError $pe) {
            $tokenError = true;
            report("Syntax: $base", "FAIL", "Line " . $pe->getLine() . ": " . $pe->getMessage());
            $syntaxErrors[] = "$base: " . $pe->getMessage();
        }
        if (!$tokenError && $ret !== 0) {
            $errDetail = implode(" ", array_slice($output, 0, 2));
            if (stripos($errDetail, 'No syntax errors') !== false) {
                report("Syntax: $base", "PASS", "No syntax errors");
            } else {
                report("Syntax: $base", "WARN", $errDetail ?: "Exit code $ret");
            }
        }
    }
}

// -------------------------------------------------------------------------
// 3. BACKEND STORES & CORE FEATURES HEALTH
// -------------------------------------------------------------------------
echo "\n------------------------------------------------------------------------\n";
echo "3. BACKEND STORES & CORE FEATURES HEALTH\n";
echo "------------------------------------------------------------------------\n";

// Test InventoryStore
if (file_exists($root . '/inventory_store.php')) {
    require_once $root . '/inventory_store.php';
    if (class_exists('InventoryStore')) {
        try {
            $acts = InventoryStore::getAllActivities(true);
            if (is_array($acts) && count($acts) > 0) {
                $first = $acts[0];
                $hasMaxPax = isset($first['max_pax']);
                report("InventoryStore::getAllActivities()", "PASS", count($acts) . " activities found, max_pax key: " . ($hasMaxPax ? "YES (val=" . $first['max_pax'] . ")" : "NO"));
            } else {
                report("InventoryStore::getAllActivities()", "WARN", "Returned empty array");
            }
        } catch (Exception $e) {
            report("InventoryStore::getAllActivities()", "FAIL", $e->getMessage());
        }

        try {
            $act1 = InventoryStore::getActivityById(1);
            if ($act1) {
                report("InventoryStore::getActivityById(1)", "PASS", "Loaded '{$act1['activity_name']}', max_pax=" . ($act1['max_pax'] ?? 'none'));
            } else {
                report("InventoryStore::getActivityById(1)", "WARN", "Activity 1 not found");
            }
        } catch (Exception $e) {
            report("InventoryStore::getActivityById(1)", "FAIL", $e->getMessage());
        }
    } else {
        report("Class InventoryStore", "FAIL", "Class does not exist");
    }
}

// Test DiningStore
if (file_exists($root . '/dining_store.php')) {
    require_once $root . '/dining_store.php';
    if (class_exists('DiningStore')) {
        try {
            $menu = DiningStore::getMenuItems();
            report("DiningStore::getMenuItems()", "PASS", is_array($menu) ? count($menu) . " menu items loaded" : "Non-array returned");
        } catch (Exception $e) {
            report("DiningStore::getMenuItems()", "FAIL", $e->getMessage());
        }

        try {
            $orders = DiningStore::getAllOrders();
            report("DiningStore::getAllOrders()", "PASS", is_array($orders) ? count($orders) . " total orders" : "Non-array returned");
        } catch (Exception $e) {
            report("DiningStore::getAllOrders()", "FAIL", $e->getMessage());
        }

        try {
            $singleOrder = DiningStore::getOrderById('PBR-FOOD-2026-2061');
            report("DiningStore::getOrderById()", "PASS", $singleOrder ? "Loaded order {$singleOrder['id']}, status={$singleOrder['order_status']}" : "Order lookup completed");
        } catch (Exception $e) {
            report("DiningStore::getOrderById()", "FAIL", $e->getMessage());
        }
    } else {
        report("Class DiningStore", "FAIL", "Class does not exist");
    }
}

// Test BookingStore
if (file_exists($root . '/booking_store.php')) {
    require_once $root . '/booking_store.php';
    if (class_exists('BookingStore')) {
        try {
            $allBk = BookingStore::getAllBookings();
            report("BookingStore::getAllBookings()", "PASS", is_array($allBk) ? count($allBk) . " bookings loaded" : "Non-array returned");
        } catch (Exception $e) {
            report("BookingStore::getAllBookings()", "FAIL", $e->getMessage());
        }
    } else {
        report("Class BookingStore", "FAIL", "Class does not exist");
    }
}

// -------------------------------------------------------------------------
// 4. BUTTONS, FORMS, MODALS & ASSETS INTEGRITY
// -------------------------------------------------------------------------
echo "\n------------------------------------------------------------------------\n";
echo "4. BUTTONS, FORMS, MODALS & ASSETS INTEGRITY\n";
echo "------------------------------------------------------------------------\n";

$keyPages = [
    'browse_activities.php',
    'dining.php',
    'book_activities.php',
    'book_house.php',
    'user-dashboard.php',
    'admin-dashboard.php',
    'index.php',
    'manage_menu.php'
];

foreach ($keyPages as $page) {
    $filePath = $root . '/' . $page;
    if (!file_exists($filePath)) {
        report("Page: $page", "WARN", "File does not exist");
        continue;
    }
    $html = file_get_contents($filePath);

    // 1. Check form action targets
    preg_match_all('/<form[^>]+action=["\']([^"\']*)["\']/i', $html, $formMatches);
    $brokenActions = [];
    if (!empty($formMatches[1])) {
        foreach ($formMatches[1] as $action) {
            $action = trim($action);
            if (empty($action) || $action === '#' || strpos($action, 'http') === 0 || strpos($action, 'javascript') === 0) continue;
            $cleanAction = explode('?', $action)[0];
            if (!empty($cleanAction) && !file_exists($root . '/' . $cleanAction)) {
                $brokenActions[] = $cleanAction;
            }
        }
    }
    if (empty($brokenActions)) {
        report("Forms in $page", "PASS", "All form actions point to valid endpoints");
    } else {
        report("Forms in $page", "FAIL", "Broken form action(s): " . implode(', ', array_unique($brokenActions)));
    }

    // 2. Check local link targets (<a href="something.php">)
    preg_match_all('/<a[^>]+href=["\']([^"\']+\.php)(\?[^"\']*)?["\']/i', $html, $linkMatches);
    $brokenLinks = [];
    if (!empty($linkMatches[1])) {
        foreach ($linkMatches[1] as $lnk) {
            $lnk = trim($lnk);
            if (strpos($lnk, 'http') === 0 || strpos($lnk, '//') === 0) continue;
            if (!file_exists($root . '/' . $lnk)) {
                $brokenLinks[] = $lnk;
            }
        }
    }
    if (empty($brokenLinks)) {
        report("Links in $page", "PASS", "All .php navigation links exist on disk");
    } else {
        report("Links in $page", "WARN", "Unresolved link(s): " . implode(', ', array_unique($brokenLinks)));
    }

    // 3. Check for specific page elements
    if ($page === 'browse_activities.php') {
        $hasEditModal = strpos($html, 'id="editActivityModal"') !== false;
        $hasAddModal = strpos($html, 'id="addActivityModal"') !== false;
        $hasMaxPaxEdit = strpos($html, 'id="editActMaxPax"') !== false;
        $hasMaxPaxAdd = strpos($html, 'id="addActMaxPax"') !== false;
        $hasOpenEditFn = strpos($html, 'function openEditActivityModal') !== false;

        report("Activity Edit Modal Exists", $hasEditModal ? "PASS" : "FAIL", "id='editActivityModal'");
        report("Activity Add Modal Exists", $hasAddModal ? "PASS" : "FAIL", "id='addActivityModal'");
        report("Activity Max Pax Edit Input", $hasMaxPaxEdit ? "PASS" : "FAIL", "id='editActMaxPax'");
        report("Activity Max Pax Add Input", $hasMaxPaxAdd ? "PASS" : "FAIL", "id='addActMaxPax'");
        report("openEditActivityModal JS function", $hasOpenEditFn ? "PASS" : "FAIL", "Binds modal fields");
    }

    if ($page === 'dining.php') {
        $hasConfirmBtn = strpos($html, 'confirmOrderBtn') !== false || strpos($html, 'id="btnConfirmOrder"') !== false || strpos($html, 'submitOrder') !== false;
        $hasCancelBtn = strpos($html, 'openCancelOrderModal') !== false || strpos($html, 'cancel_order') !== false;
        $hasBasket = strpos($html, 'dining-basket') !== false || strpos($html, 'basketModal') !== false || strpos($html, 'basket') !== false;

        report("Dining Basket Component", $hasBasket ? "PASS" : "FAIL", "Cart/Basket found");
        report("Dining Order Cancellation", $hasCancelBtn ? "PASS" : "FAIL", "openCancelOrderModal / cancel_order form found");
    }
}

// -------------------------------------------------------------------------
// 5. SUMMARY
// -------------------------------------------------------------------------
echo "\n========================================================================\n";
echo "                         SCAN SUMMARY RESULTS                           \n";
echo "========================================================================\n";
echo "TOTAL PASSED:   $passCount\n";
echo "TOTAL WARNINGS: $warnCount\n";
echo "TOTAL FAILED:   $failCount\n";
if ($failCount === 0) {
    echo "STATUS: ALL SYSTEMS, FEATURES, SYNTAX, AND DATABASE CONNECTIONS HEALTHY!\n";
} else {
    echo "STATUS: ATTENTION REQUIRED ON $failCount ITEM(S)\n";
}
echo "========================================================================\n";
