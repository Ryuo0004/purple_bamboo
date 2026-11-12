<?php
session_start();
$_SESSION['role'] = 'admin';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['action'] = 'add_kubo';
$_POST['name'] = 'Kubo Test Simulation ' . time();
$_POST['category_id'] = '1';
$_POST['price'] = '3500';
$_POST['capacity'] = '4';
$_POST['available_units'] = '5';
$_POST['status'] = 'available';
$_POST['badges'] = 'WiFi, Aircon';
$_POST['description'] = 'Simulated post test';

ob_start();
// Include the top logic of browse_kubos.php (or mock header redirect)
require_once __DIR__ . '/../inventory_store.php';

$action = $_POST['action'];
$new_id = InventoryStore::addKubo($_POST, null);
echo "New ID: " . var_export($new_id, true) . "\n";
echo "Session flash: " . var_export($_SESSION['status_flash'] ?? null, true) . "\n";

// Check if it appears in getAllKubos()
$kubos = InventoryStore::getAllKubos();
$found = false;
foreach ($kubos as $k) {
    if (intval($k['kubo_id']) === intval($new_id)) {
        $found = true;
        echo "Found in getAllKubos: " . $k['name'] . "\n";
        break;
    }
}
echo "Found: " . ($found ? "YES" : "NO") . "\n";

if ($new_id) {
    InventoryStore::deleteKubo($new_id);
    echo "Cleaned up.\n";
}
?>
