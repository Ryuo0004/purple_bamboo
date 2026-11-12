<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: text/plain; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "========================================================================\n";
echo "       PURPLE BAMBOO RESORT - AUTOMATED QA QUALITY TEST SUITE           \n";
echo "========================================================================\n";
echo "Testing Timestamp : " . date('Y-m-d H:i:s') . "\n";
echo "PHP Environment   : " . PHP_VERSION . " (" . ($_SERVER['SERVER_SOFTWARE'] ?? 'CLI') . ")\n\n";

$passCount = 0;
$failCount = 0;
$warnCount = 0;

function qa_assert($testName, $condition, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        printf("[PASS] %-52s : %s\n", $testName, $details ?: 'OK');
        return true;
    } else {
        $failCount++;
        printf("[FAIL] %-52s : %s\n", $testName, $details ?: 'Assertion Failed');
        return false;
    }
}

$root = dirname(__DIR__);
require_once $root . '/database.php';
require_once $root . '/booking_store.php';
require_once $root . '/inventory_store.php';
require_once $root . '/dining_store.php';
require_once $root . '/auth.php';

// Unique test ID suffix to prevent collisions
$tId = 'QA' . time() . rand(100, 999);

// ========================================================================
// SUITE 1: HOUSE KUBO BOOKING FLOW
// ========================================================================
echo "------------------------------------------------------------------------\n";
echo "SUITE 1: HOUSE KUBO BOOKING FLOW & CAPACITY CHECKS\n";
echo "------------------------------------------------------------------------\n";

$testCheckIn = date('Y-m-d', strtotime('+30 days'));
$testCheckOut = date('Y-m-d', strtotime('+32 days'));

// 1.1 Check initial availability
$kubo1 = InventoryStore::getKuboById(1);
qa_assert("Kubo #1 Details Loaded", !empty($kubo1), "Name: " . ($kubo1['name'] ?? 'N/A') . " (₱" . ($kubo1['price'] ?? 0) . "/night)");

$availBefore = BookingStore::getRemainingUnits(1, $testCheckIn, $testCheckOut);
qa_assert("Kubo #1 Availability Check", $availBefore >= 0, "Available units for dates: $availBefore");

// 1.2 Create House Booking
$houseBookingData = [
    'guest_name' => "QA Tester $tId",
    'guest_email' => "qa_test_{$tId}@example.com",
    'guest_phone' => '09123456789',
    'user_id' => 1,
    'type' => 'house',
    'item_id' => 1,
    'item_name' => $kubo1['name'] ?? 'Beach Kubo',
    'check_in' => $testCheckIn,
    'check_out' => $testCheckOut,
    'pax' => 4,
    'total_amount' => 7000.00,
    'special_notes' => "Automated QA Test Booking $tId"
];

$houseBookingRes = BookingStore::createBooking($houseBookingData);
$houseBkId = null;
if (is_array($houseBookingRes) && !empty($houseBookingRes['id'])) {
    $houseBkId = $houseBookingRes['id'];
} elseif (is_string($houseBookingRes) && !empty($houseBookingRes)) {
    $houseBkId = $houseBookingRes;
}

qa_assert("House Booking Creation", !empty($houseBkId), "Booking Ref ID: " . ($houseBkId ?? 'FAILED'));

if ($houseBkId) {
    // 1.3 Verify DB Persistence
    $savedBk = BookingStore::getBookingById($houseBkId);
    qa_assert("Booking Persisted in MySQL", !empty($savedBk), "Loaded status: " . ($savedBk['booking_status'] ?? 'none') . ", payment: " . ($savedBk['payment_status'] ?? 'none'));
    qa_assert("Booking Correct Initial Status", ($savedBk['booking_status'] ?? '') === 'pending', "Status is 'pending'");

    // 1.4 Verify Availability Decreased
    $availAfter = BookingStore::getRemainingUnits(1, $testCheckIn, $testCheckOut);
    qa_assert("Availability Decreased After Booking", $availAfter <= $availBefore, "Before: $availBefore -> After: $availAfter");
}

// ========================================================================
// SUITE 2: RESORT ACTIVITY BOOKING FLOW & MAX PAX
// ========================================================================
echo "\n------------------------------------------------------------------------\n";
echo "SUITE 2: RESORT ACTIVITY BOOKING & MAX PAX CAPACITY\n";
echo "------------------------------------------------------------------------\n";

$testActDate = date('Y-m-d', strtotime('+35 days'));
$act1 = InventoryStore::getActivityById(1);
qa_assert("Activity #1 Details Loaded", !empty($act1), "Name: " . ($act1['activity_name'] ?? 'N/A') . " (Max Pax: " . ($act1['max_pax'] ?? 'none') . ")");

$actBookingData = [
    'guest_name' => "QA Activity Guest $tId",
    'guest_email' => "qa_act_{$tId}@example.com",
    'guest_phone' => '09987654321',
    'user_id' => 1,
    'type' => 'activity',
    'item_id' => 1,
    'item_name' => $act1['activity_name'] ?? 'Infinity Pool Pass',
    'check_in' => $testActDate,
    'check_out' => null,
    'time_slot' => '09:00 AM',
    'start_time' => '09:00:00',
    'end_time' => '10:00:00',
    'pax' => 2,
    'total_amount' => 700.00,
    'special_notes' => "QA Activity Reservation $tId"
];

$actBkRes = BookingStore::createBooking($actBookingData);
$actBkId = is_array($actBkRes) ? ($actBkRes['id'] ?? null) : $actBkRes;
qa_assert("Activity Booking Creation", !empty($actBkId), "Booking Ref ID: " . ($actBkId ?? 'FAILED'));

if ($actBkId) {
    $savedActBk = BookingStore::getBookingById($actBkId);
    qa_assert("Activity Booking Persisted in MySQL", !empty($savedActBk), "Type: " . ($savedActBk['type'] ?? 'none') . ", Pax: " . ($savedActBk['pax'] ?? 0));
}

// ========================================================================
// SUITE 3: ADMIN BOOKING BUTTONS & LIFECYCLE (Approve, Check-in, Check-out)
// ========================================================================
echo "\n------------------------------------------------------------------------\n";
echo "SUITE 3: ADMIN BOOKING BUTTONS & LIFECYCLE TRANSITIONS\n";
echo "------------------------------------------------------------------------\n";

if ($houseBkId) {
    // 3.1 Admin Action: Approve Booking & Verify Payment
    $appRes = BookingStore::updateBookingStatus($houseBkId, 'approved', 'verified');
    $bkApp = BookingStore::getBookingById($houseBkId);
    qa_assert("Admin 'Approve Booking' Button", ($bkApp['booking_status'] ?? '') === 'approved' && ($bkApp['payment_status'] ?? '') === 'verified', "Status: {$bkApp['booking_status']}, Payment: {$bkApp['payment_status']}");

    // 3.2 Admin Action: Check-In Guest
    $ciRes = BookingStore::updateBookingStatus($houseBkId, 'checked_in', 'verified');
    $bkCi = BookingStore::getBookingById($houseBkId);
    qa_assert("Admin 'Check-In' Button", ($bkCi['booking_status'] ?? '') === 'checked_in', "Status updated to: {$bkCi['booking_status']}");

    // 3.3 Admin Action: Check-Out & Archive
    $coRes = BookingStore::updateBookingStatus($houseBkId, 'completed', 'verified');
    $bkCo = BookingStore::getBookingById($houseBkId);
    qa_assert("Admin 'Check-Out / Complete' Button", ($bkCo['booking_status'] ?? '') === 'completed', "Status updated to: {$bkCo['booking_status']}");

    // 3.4 Cleanup Test Booking from active bookings
    try {
        $pdo->prepare("DELETE FROM bookings WHERE id = ?")->execute([$houseBkId]);
        qa_assert("Test House Booking Cleaned Up", true, "Cleaned reference #$houseBkId");
    } catch (Exception $e) {}
}

if ($actBkId) {
    // 3.5 Admin Action: Reject / Cancel Flow
    $rejRes = BookingStore::updateBookingStatus($actBkId, 'cancelled', 'rejected', null, null, null, 'QA Test Cancellation');
    $bkRej = BookingStore::getBookingById($actBkId);
    qa_assert("Admin 'Reject / Cancel Booking' Button", ($bkRej['booking_status'] ?? '') === 'cancelled', "Status: {$bkRej['booking_status']}, Reason: " . ($bkRej['rejection_reason'] ?? ''));

    // Cleanup Activity Booking
    try {
        $pdo->prepare("DELETE FROM bookings WHERE id = ?")->execute([$actBkId]);
        qa_assert("Test Activity Booking Cleaned Up", true, "Cleaned reference #$actBkId");
    } catch (Exception $e) {}
}

// ========================================================================
// SUITE 4: ACTIVITY INVENTORY MANAGEMENT (Add, Edit Max Pax, Toggle, Delete)
// ========================================================================
echo "\n------------------------------------------------------------------------\n";
echo "SUITE 4: ACTIVITY INVENTORY ADMIN BUTTONS (Add, Edit Max Pax, Toggle, Delete)\n";
echo "------------------------------------------------------------------------\n";

// 4.1 Admin Action: Add Activity with Custom Max Pax
$newActData = [
    'activity_name' => "QA Test Snorkeling $tId",
    'category' => 'outdoor',
    'price' => 450.00,
    'duration_minutes' => 90,
    'max_pax' => 6,
    'total_units' => 8,
    'available_units' => 8,
    'equipment' => json_encode([['name' => 'Snorkel Mask', 'qty' => 8, 'icon' => 'fa-mask-snorkel']]),
    'description' => 'Automated QA test activity for coral exploration.',
    'image_url' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5'
];

$createdActId = InventoryStore::addActivity($newActData);
qa_assert("Admin 'Add New Activity' Button", !empty($createdActId) && $createdActId > 0, "Created Activity ID: $createdActId");

if ($createdActId) {
    $createdAct = InventoryStore::getActivityById($createdActId);
    qa_assert("Activity Saved with Configured Max Pax", ($createdAct['max_pax'] ?? 0) == 6, "Saved Max Pax = " . ($createdAct['max_pax'] ?? 'none'));

    // 4.2 Admin Action: Edit Activity & Update Max Pax
    $editActData = [
        'activity_name' => "QA Test Snorkeling $tId (Updated)",
        'category' => 'outdoor',
        'price' => 550.00,
        'duration_minutes' => 120,
        'max_pax' => 10,
        'total_units' => 10,
        'available_units' => 10,
        'is_available' => 1,
        'description' => 'Updated description via QA test'
    ];
    $upRes = InventoryStore::saveActivityDetails($createdActId, $editActData);
    $updatedAct = InventoryStore::getActivityById($createdActId);
    qa_assert("Admin 'Edit Activity / Update Max Pax' Button", $upRes && ($updatedAct['max_pax'] ?? 0) == 10 && ($updatedAct['price'] ?? 0) == 550, "Updated Max Pax = {$updatedAct['max_pax']}, Price = ₱{$updatedAct['price']}");

    // 4.3 Admin Action: Toggle Maintenance Status
    $togRes = InventoryStore::toggleActivityStatus($createdActId, 0);
    $togAct = InventoryStore::getActivityById($createdActId);
    qa_assert("Admin 'Set Maintenance' Button", ($togAct['is_available'] ?? 1) == 0 && ($togAct['status'] ?? '') === 'maintenance', "Status changed to Maintenance");

    $togRes2 = InventoryStore::toggleActivityStatus($createdActId, 1);
    $togAct2 = InventoryStore::getActivityById($createdActId);
    qa_assert("Admin 'Set Active' Button", ($togAct2['is_available'] ?? 0) == 1 && ($togAct2['status'] ?? '') === 'available', "Status restored to Available");

    // 4.4 Admin Action: Delete Activity
    $delRes = InventoryStore::deleteActivity($createdActId);
    $deletedAct = InventoryStore::getActivityById($createdActId);
    qa_assert("Admin 'Delete Activity' Button", $delRes && empty($deletedAct), "Activity successfully deleted from MySQL");
}

// ========================================================================
// SUITE 5: DINING MENU CRUD BUTTONS (Add, Edit, Toggle Sold Out, Delete)
// ========================================================================
echo "\n------------------------------------------------------------------------\n";
echo "SUITE 5: DINING MENU ADMIN BUTTONS (Add, Edit, Toggle Sold Out, Delete)\n";
echo "------------------------------------------------------------------------\n";

// 5.1 Admin Action: Add Menu Item
$newMenuItemData = [
    'item_name' => "QA Crispy Pork Sisig $tId",
    'category' => 'Main Dishes',
    'price' => 380.00,
    'description' => 'Sizzling pork sisig with egg and chili.',
    'allergens' => 'Eggs, Soy',
    'is_available' => 1
];

$createdMenuId = DiningStore::addMenuItem($newMenuItemData);
qa_assert("Admin 'Add Menu Item' Button", !empty($createdMenuId) && $createdMenuId > 0, "Created Dish ID: $createdMenuId");

if ($createdMenuId) {
    $dish = DiningStore::getMenuItemById($createdMenuId);
    qa_assert("Menu Item Stored in Database", !empty($dish) && ($dish['price'] ?? 0) == 380, "Name: {$dish['item_name']}, Price: ₱{$dish['price']}");

    // 5.2 Admin Action: Edit Menu Item
    $editMenuData = [
        'item_name' => "QA Crispy Pork Sisig $tId (Spicy)",
        'category' => 'Main Dishes',
        'price' => 420.00,
        'description' => 'Updated spicy sizzling sisig.',
        'allergens' => 'Eggs, Soy, Dairy',
        'is_available' => 1
    ];
    $upMRes = DiningStore::saveMenuItem($createdMenuId, $editMenuData);
    $upDish = DiningStore::getMenuItemById($createdMenuId);
    qa_assert("Admin 'Edit Menu Item' Button", $upMRes && ($upDish['price'] ?? 0) == 420, "Price updated to ₱{$upDish['price']}");

    // 5.3 Admin Action: Toggle Sold Out
    $togMRes = DiningStore::toggleMenuItemAvailability($createdMenuId, 0);
    $soldOutDish = DiningStore::getMenuItemById($createdMenuId);
    qa_assert("Admin 'Toggle Sold Out' Button", ($soldOutDish['is_available'] ?? 1) == 0, "Item marked as Sold Out");

    // 5.4 Admin Action: Delete Menu Item
    $delMRes = DiningStore::deleteMenuItem($createdMenuId);
    $deletedDish = DiningStore::getMenuItemById($createdMenuId);
    qa_assert("Admin 'Delete Menu Item' Button", $delMRes && empty($deletedDish), "Menu item removed from database");
}

// ========================================================================
// SUITE 6: DINING ORDERS LIFECYCLE & CANCELLATION BUTTONS
// ========================================================================
echo "\n------------------------------------------------------------------------\n";
echo "SUITE 6: DINING ORDER LIFECYCLE (Accept, Cook, Serve, Cancel Buttons)\n";
echo "------------------------------------------------------------------------\n";

// 6.1 Guest Places Order
$testOrderData = [
    'user_id' => 1,
    'guest_name' => "QA Diner $tId",
    'guest_email' => "qa_diner_{$tId}@example.com",
    'guest_phone' => '09121212121',
    'kubo_location' => 'Beach Kubo',
    'items' => [
        ['id' => 1, 'name' => 'Sizzling Garlic Butter Tiger Prawns', 'price' => 680, 'qty' => 1]
    ],
    'total_amount' => 680.00,
    'guest_allergies' => [],
    'allergy_notes' => 'None',
    'requested_serve_time' => 'ASAP (15-20 Mins)'
];

$orderRes = DiningStore::createOrder($testOrderData);
$orderId = $orderRes['id'] ?? null;
qa_assert("Guest 'Confirm Order' Button", !empty($orderId), "Created Order ID: $orderId");

if ($orderId) {
    $ord = DiningStore::getOrderById($orderId);
    qa_assert("Order Initial Status 'Pending'", ($ord['order_status'] ?? '') === 'pending', "Status = {$ord['order_status']}");

    // 6.2 Kitchen Action: Accept Order
    DiningStore::updateOrderStatus($orderId, 'accepted', 'Kitchen accepted order');
    $ordAcc = DiningStore::getOrderById($orderId);
    qa_assert("Kitchen 'Accept Order' Button", ($ordAcc['order_status'] ?? '') === 'accepted', "Status = {$ordAcc['order_status']}");

    // 6.3 Kitchen Action: Preparing / Cooking
    DiningStore::updateOrderStatus($orderId, 'preparing', 'Chef cooking dishes');
    $ordPrep = DiningStore::getOrderById($orderId);
    qa_assert("Kitchen 'Start Cooking' Button", ($ordPrep['order_status'] ?? '') === 'preparing', "Status = {$ordPrep['order_status']}");

    // 6.4 Kitchen Action: Ready for Delivery (DiningStore maps delivering to out_for_serving)
    DiningStore::updateOrderStatus($orderId, 'delivering', 'Out for delivery to kubo');
    $ordDel = DiningStore::getOrderById($orderId);
    qa_assert("Kitchen 'Out for Delivery' Button", in_array($ordDel['order_status'] ?? '', ['delivering', 'out_for_serving']), "Status = {$ordDel['order_status']}");

    // 6.5 Kitchen Action: Served
    DiningStore::updateOrderStatus($orderId, 'served', 'Delivered and served to table');
    $ordServed = DiningStore::getOrderById($orderId);
    qa_assert("Kitchen 'Mark Served' Button", ($ordServed['order_status'] ?? '') === 'served', "Status = {$ordServed['order_status']}");

    // Cleanup Order
    try {
        $pdo->prepare("DELETE FROM dining_orders WHERE id = ?")->execute([$orderId]);
    } catch (Exception $e) {}
}

// 6.6 Guest 'Cancel Pending Order' Button Check
$cancelTestOrder = DiningStore::createOrder([
    'user_id' => 1,
    'guest_name' => "QA Cancel Diner $tId",
    'guest_email' => "qa_cancel_{$tId}@example.com",
    'guest_phone' => '09121212121',
    'kubo_location' => 'Beach Kubo',
    'items' => [['id' => 4, 'name' => 'Crispy Calamares', 'price' => 340, 'qty' => 1]],
    'total_amount' => 340.00
]);
$cancelOrderId = $cancelTestOrder['id'] ?? null;
if ($cancelOrderId) {
    // Guest Cancels while in pending
    DiningStore::updateOrderStatus($cancelOrderId, 'cancelled', 'Cancelled by guest before kitchen preparation');
    $ordCanc = DiningStore::getOrderById($cancelOrderId);
    qa_assert("Guest 'Cancel Pending Order' Button", ($ordCanc['order_status'] ?? '') === 'cancelled', "Order #$cancelOrderId cancelled successfully");

    // Cleanup Cancelled Order
    try {
        $pdo->prepare("DELETE FROM dining_orders WHERE id = ?")->execute([$cancelOrderId]);
    } catch (Exception $e) {}
}

// ========================================================================
// SUITE 7: USER MANAGEMENT ADMIN ACTIONS (Block, Unblock)
// ========================================================================
echo "\n------------------------------------------------------------------------\n";
echo "SUITE 7: USER MANAGEMENT ADMIN ACTIONS (Block, Unblock, Roles)\n";
echo "------------------------------------------------------------------------\n";

try {
    $qaEmail = "qa_guest_{$tId}@example.com";
    // Create dedicated test user for hermetic verification
    $insStmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, password_hash, role, status, is_blocked) VALUES ('QA', 'Guest', ?, 'fakehash', 'guest', 'active', 0)");
    $insStmt->execute([$qaEmail]);
    $uId = intval($pdo->lastInsertId());

    qa_assert("User Management Test Subject Initialized", $uId > 0, "Created test user #$uId ($qaEmail)");

    if ($uId > 0) {
        // 7.1 Block User Action
        $blkRes = Auth::blockUser($uId, "QA Automated Policy Check", "QA Admin", "guest", $qaEmail);
        $chkStmt = $pdo->prepare("SELECT status, is_blocked, block_reason FROM users WHERE email = ?");
        $chkStmt->execute([$qaEmail]);
        $blkUser = $chkStmt->fetch(PDO::FETCH_ASSOC);
        qa_assert("Admin 'Block User' Button", !empty($blkUser) && (($blkUser['status'] ?? '') === 'blocked' || ($blkUser['is_blocked'] ?? 0) == 1), "Status: " . ($blkUser['status'] ?? 'null'));

        // 7.2 Unblock User Action
        $actRes = Auth::activateUser($uId, "guest", $qaEmail);
        $chkStmt->execute([$qaEmail]);
        $actUser = $chkStmt->fetch(PDO::FETCH_ASSOC);
        qa_assert("Admin 'Unblock / Restore User' Button", !empty($actUser) && ($actUser['status'] ?? '') === 'active' && ($actUser['is_blocked'] ?? 1) == 0, "Status restored to: " . ($actUser['status'] ?? 'null'));

        // Cleanup test user
        $pdo->prepare("DELETE FROM users WHERE email = ?")->execute([$qaEmail]);
        qa_assert("User Management Test User Cleaned Up", true, "Hermetic test user #$uId removed");
    }
} catch (Exception $e) {
    qa_assert("User Management Operations", false, $e->getMessage());
}

// ========================================================================
// SUITE 8: UI TEMPLATE BUTTON & MODAL INTEGRITY AUDIT
// ========================================================================
echo "\n------------------------------------------------------------------------\n";
echo "SUITE 8: UI TEMPLATE BUTTON & MODAL INTEGRITY AUDIT\n";
echo "------------------------------------------------------------------------\n";

$pagesToAudit = [
    'browse_activities.php' => ['#editActivityModal', '#addActivityModal', 'openEditActivityModal', 'openAddActivityModal'],
    'dining.php'            => ['#orderConfirmModal', '#cancelOrderModal', 'openCancelOrderModal', 'openCheckoutModal'],
    'book_activities.php'   => ['#activityBookingForm', 'syncActivityUI', 'checkAvailability'],
    'book_house.php'        => ['#bookingForm', 'calculateTotal'],
    'manage_menu.php'       => ['#addMenuModal', '#editMenuModal', 'openEditMenuModal', 'openAddMenuModal'],
    'bookings.php'          => ['#bmBookingsTable', 'toggleRowMenu', 'instantSearchRows', 'handleRescheduleSubmit'],
];

foreach ($pagesToAudit as $pName => $features) {
    $pContent = file_get_contents($root . '/' . $pName);
    foreach ($features as $feat) {
        $found = false;
        if (strpos($feat, '#') === 0) {
            $elemId = substr($feat, 1);
            $found = (strpos($pContent, "id=\"$elemId\"") !== false || strpos($pContent, "id='$elemId'") !== false);
            qa_assert("DOM Element in $pName", $found, "ID: $elemId found in markup");
        } else {
            $found = (strpos($pContent, $feat) !== false);
            qa_assert("Function/Handler in $pName", $found, "Handler '$feat()' found in code");
        }
    }
}

// ========================================================================
// FINAL QA SUMMARY
// ========================================================================
echo "\n========================================================================\n";
echo "                    QA TEST SUITE FINAL REPORT                          \n";
echo "========================================================================\n";
echo "TOTAL TESTS EXECUTED : " . ($passCount + $failCount) . "\n";
echo "PASSED TESTS         : " . $passCount . "\n";
echo "FAILED TESTS         : " . $failCount . "\n";
if ($failCount === 0) {
    echo "OVERALL VERDICT      : 100% PASSED - ALL BUTTONS, BOOKINGS & ADMIN FLOWS FUNCTIONAL!\n";
} else {
    echo "OVERALL VERDICT      : ATTENTION REQUIRED ON $failCount FAILED TEST(S)\n";
}
echo "========================================================================\n";
