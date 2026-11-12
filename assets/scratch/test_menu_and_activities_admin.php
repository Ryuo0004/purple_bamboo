<?php
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../dining_store.php';
require_once __DIR__ . '/../inventory_store.php';

echo "=======================================================\n";
echo "=== 1. TEST DINING MENU STORE BACKEND DIRECT CRUD   ===\n";
echo "=======================================================\n";

// 1.1 Read all menu items
$menu = DiningStore::getAllMenuItemsAdmin();
echo "Total seeded menu items: " . count($menu) . "\n";
assert(count($menu) >= 8, "Expected at least 8 menu items");
echo "  [PASS] Read all dining menu items!\n";

// 1.2 Add a new menu item
$newMenuData = [
    'item_name' => 'Crispy Lechon Kawali with Mang Tomas',
    'category' => 'Main Dishes',
    'price' => '420.00',
    'description' => 'Crispy pork belly fried to golden perfection with liver sauce dip.',
    'allergens' => 'Soy, Gluten',
    'is_available' => 1,
    'image_url' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80'
];
$newMenuId = DiningStore::addMenuItem($newMenuData);
echo "Added menu item ID: {$newMenuId}\n";
assert($newMenuId > 0, "addMenuItem should return a valid ID");

$fetchedMenu = DiningStore::getMenuItemById($newMenuId);
assert($fetchedMenu !== null, "Created menu item should be retrievable");
assert(floatval($fetchedMenu['price']) == 420.00, "Price should match 420.00");
echo "  [PASS] addMenuItem succeeded!\n";

// 1.3 Edit the menu item
$editMenuData = [
    'item_name' => 'Crispy Lechon Kawali Supreme',
    'category' => 'Main Dishes',
    'price' => '450.00',
    'description' => 'Upgraded crispy pork belly with spiced vinegar and liver sauce.',
    'allergens' => 'Soy',
    'is_available' => 1,
    'image_url' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80'
];
DiningStore::saveMenuItem($newMenuId, $editMenuData);
$afterEditMenu = DiningStore::getMenuItemById($newMenuId);
assert($afterEditMenu['item_name'] === 'Crispy Lechon Kawali Supreme', "Name should be updated");
assert(floatval($afterEditMenu['price']) == 450.00, "Price should be updated to 450");
echo "  [PASS] saveMenuItem succeeded!\n";

// 1.4 Toggle availability
DiningStore::toggleMenuItemAvailability($newMenuId, 0);
$soldOut = DiningStore::getMenuItemById($newMenuId);
assert(intval($soldOut['is_available']) === 0, "Item should be marked sold out");
echo "  [PASS] toggleMenuItemAvailability (Sold Out) succeeded!\n";

DiningStore::toggleMenuItemAvailability($newMenuId, 1);
$avail = DiningStore::getMenuItemById($newMenuId);
assert(intval($avail['is_available']) === 1, "Item should be marked available");
echo "  [PASS] toggleMenuItemAvailability (Available) succeeded!\n";

// 1.5 Delete the item
DiningStore::deleteMenuItem($newMenuId);
$afterDel = DiningStore::getMenuItemById($newMenuId);
assert($afterDel === null, "Deleted menu item should not exist");
echo "  [PASS] deleteMenuItem succeeded!\n";


echo "\n=======================================================\n";
echo "=== 2. TEST POOL ACTIVITIES STORE BACKEND DIRECT    ===\n";
echo "=======================================================\n";

// 2.1 Read all activities
$acts = InventoryStore::getAllActivities(true);
echo "Total activities: " . count($acts) . "\n";
assert(count($acts) >= 3, "Expected at least 3 activities");
echo "  [PASS] Read all activities with formatted durations!\n";

// 2.2 Add new activity
$newActData = [
    'activity_name' => 'Lagoon Inflatable Aqua Park Course',
    'category' => 'pool',
    'price' => 450.00,
    'duration_minutes' => 90,
    'description' => 'Water trampoline, slides, and floating obstacles supervised by resort lifeguards.',
    'is_available' => 1,
    'image_url' => 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80'
];
$newActId = InventoryStore::addActivity($newActData);
echo "Added activity ID: {$newActId}\n";
assert($newActId > 0, "addActivity should return valid ID");

$fetchedAct = InventoryStore::getActivityById($newActId);
assert($fetchedAct !== null, "Created activity should be retrievable");
assert(floatval($fetchedAct['price']) == 450.00, "Price should match 450");
assert($fetchedAct['duration'] === '90 Mins', "Duration should be formatted as 90 Mins");
echo "  [PASS] addActivity succeeded!\n";

// 2.3 Edit activity details & status
$editActData = [
    'activity_name' => 'Lagoon Inflatable Aqua Park Course VIP',
    'category' => 'outdoor',
    'price' => 550.00,
    'duration_minutes' => 120,
    'description' => 'Extended 2-hour aqua park session with complimentary refreshments.',
    'is_available' => 0, // Mark under maintenance
    'image_url' => 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80'
];
InventoryStore::saveActivityDetails($newActId, $editActData);
$afterEditAct = InventoryStore::getActivityById($newActId);
assert($afterEditAct['activity_name'] === 'Lagoon Inflatable Aqua Park Course VIP', "Name should be updated");
assert(floatval($afterEditAct['price']) == 550.00, "Price should be updated to 550");
assert($afterEditAct['duration'] === '2 Hours', "Duration should be formatted as 2 Hours");
assert(intval($afterEditAct['is_available']) === 0, "Status should be 0 (maintenance)");
echo "  [PASS] saveActivityDetails (edit details + maintenance status) succeeded!\n";

// 2.4 Toggle activity status back to available
InventoryStore::toggleActivityStatus($newActId, 1);
$afterToggleAct = InventoryStore::getActivityById($newActId);
assert(intval($afterToggleAct['is_available']) === 1, "Status should be toggled to 1 (available)");
echo "  [PASS] toggleActivityStatus succeeded!\n";

// 2.5 Delete activity
InventoryStore::deleteActivity($newActId);
$afterDelAct = InventoryStore::getActivityById($newActId);
assert($afterDelAct === null, "Deleted activity should not exist");
echo "  [PASS] deleteActivity succeeded!\n";


echo "\n=======================================================\n";
echo "=== 3. TEST HTTP ADMIN INTERACTIONS VIA CURL        ===\n";
echo "=======================================================\n";

$cookieFile = __DIR__ . '/admin_e2e_cookie.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

$baseUrl = 'http://localhost/purple_bamboo';

// Step 3.1: Admin login
$ch = curl_init("{$baseUrl}/admin-login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'email' => 'admin@purplebamboo.com',
        'password' => 'Admin123'
    ]),
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_FOLLOWLOCATION => true
]);
curl_exec($ch);
curl_close($ch);
echo "Admin logged in.\n";

// Step 3.2: Check manage_menu.php renders correctly
$ch = curl_init("{$baseUrl}/manage_menu.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieFile
]);
$menuHtml = curl_exec($ch);
curl_close($ch);

assert(strpos($menuHtml, 'Dining Menu Management') !== false, "Page title should appear");
assert(strpos($menuHtml, 'id="addMenuModal"') !== false, "addMenuModal should be in HTML");
assert(strpos($menuHtml, 'id="editMenuModal"') !== false, "editMenuModal should be in HTML");
assert(strpos($menuHtml, 'openAddMenuModal') !== false, "openAddMenuModal should exist");
assert(strpos($menuHtml, 'openEditMenuModal') !== false, "openEditMenuModal should exist");
echo "  [PASS] manage_menu.php rendered with full modals and admin controls!\n";

// Step 3.3: HTTP POST to add new menu item via manage_menu.php
$ch = curl_init("{$baseUrl}/manage_menu.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'action' => 'add_menu_item',
        'item_name' => 'Halo-Halo Bamboo Fiesta',
        'category' => 'Desserts & Snacks',
        'price' => '280.00',
        'description' => 'Giant coconut bowl filled with shaved ice, macapuno, ube, and leche flan.',
        'allergens' => 'Dairy, Eggs',
        'is_available' => '1',
        'image_url' => 'https://images.unsplash.com/photo-1563805042-7684c019e1cb?auto=format&fit=crop&w=600&q=80'
    ],
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_FOLLOWLOCATION => true
]);
$addMenuResp = curl_exec($ch);
curl_close($ch);

assert(strpos($addMenuResp, 'Halo-Halo Bamboo Fiesta') !== false, "New menu item should appear in page");
assert(strpos($addMenuResp, '280.00') !== false, "Price 280.00 should appear in page");
echo "  [PASS] HTTP POST add_menu_item succeeded and rendered live!\n";

// Find ID for HTTP test delete
$menuAll = DiningStore::getAllMenuItemsAdmin();
$targetMenuId = null;
foreach ($menuAll as $m) {
    if ($m['item_name'] === 'Halo-Halo Bamboo Fiesta') {
        $targetMenuId = $m['id'];
        break;
    }
}
assert($targetMenuId !== null, "Target menu item must be found");

// Step 3.4: HTTP POST to delete menu item
$ch = curl_init("{$baseUrl}/manage_menu.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'action' => 'delete_menu_item',
        'id' => strval($targetMenuId)
    ],
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_FOLLOWLOCATION => true
]);
$delMenuResp = curl_exec($ch);
curl_close($ch);

assert(strpos($delMenuResp, 'deleted successfully') !== false, "Deletion flash message should be shown");
echo "  [PASS] HTTP POST delete_menu_item succeeded!\n";

// Step 3.5: Check browse_activities.php renders dynamic activities and admin modals
$ch = curl_init("{$baseUrl}/browse_activities.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieFile
]);
$actHtml = curl_exec($ch);
curl_close($ch);

assert(strpos($actHtml, 'id="addActivityModal"') !== false, "addActivityModal should be in HTML");
assert(strpos($actHtml, 'id="editActivityModal"') !== false, "editActivityModal should be in HTML");
assert(strpos($actHtml, 'openAddActivityModal') !== false, "openAddActivityModal should exist");
assert(strpos($actHtml, 'openEditActivityModal') !== false, "openEditActivityModal should exist");
assert(strpos($actHtml, 'Main Infinity Pool Pass') !== false, "Main Infinity Pool Pass should appear");
echo "  [PASS] browse_activities.php dynamic admin view rendered with modals!\n";

// Step 3.6: HTTP POST edit_activity to update price and toggle status on Activity #1
$ch = curl_init("{$baseUrl}/browse_activities.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'action' => 'edit_activity',
        'activity_id' => '1',
        'activity_name' => 'Main Infinity Pool Pass',
        'category' => 'pool',
        'price' => '380.00',
        'duration_minutes' => '480',
        'description' => 'Full access to crystal-clear infinity pool with complimentary towel and lounge chairs.',
        'is_available' => '1',
        'existing_image' => 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80'
    ],
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_FOLLOWLOCATION => true
]);
$editActResp = curl_exec($ch);
curl_close($ch);

assert(strpos($editActResp, '380') !== false, "Updated price ₱380 should appear on activity card");
echo "  [PASS] HTTP POST edit_activity updated price to ₱380!\n";

// Restore Activity #1 price to 350
InventoryStore::saveActivityDetails(1, [
    'activity_name' => 'Main Infinity Pool Pass',
    'category' => 'pool',
    'price' => 350.00,
    'duration_minutes' => 480,
    'description' => 'Access to crystal-clear infinity pool with lounge chairs and kids pool zone.',
    'is_available' => 1
]);
echo "  [PASS] Activity #1 price restored to ₱350.\n";

// Step 3.7: Check book_activities.php renders dynamic options
$ch = curl_init("{$baseUrl}/book_activities.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieFile
]);
$bookHtml = curl_exec($ch);
curl_close($ch);

assert(strpos($bookHtml, 'Main Infinity Pool Pass (₱350') !== false, "Dynamic option should be in select");
assert(strpos($bookHtml, 'Clear-Bottom Kayak') !== false, "Kayak option should be in select");
echo "  [PASS] book_activities.php rendered dynamic options from InventoryStore!\n";

if (file_exists($cookieFile)) unlink($cookieFile);

echo "\n=======================================================\n";
echo "=== ALL MENU AND POOL ACTIVITIES TESTS PASSED 100%! ===\n";
echo "=======================================================\n";
