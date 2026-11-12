<?php
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../inventory_store.php';

echo "Testing addKubo...\n";

$data = [
    'name' => 'Test Bamboo Kubo ' . time(),
    'category_id' => 1,
    'price' => 3200,
    'capacity' => 4,
    'available_units' => 5,
    'status' => 'available',
    'badges' => 'Free WiFi, Aircon',
    'description' => 'Test description',
    'image_url' => 'assets/beach_kubo.jpg'
];

try {
    $new_id = InventoryStore::addKubo($data);
    echo "addKubo returned ID: " . var_export($new_id, true) . "\n";
    
    $fetched = InventoryStore::getKuboById($new_id);
    echo "Fetched kubo: " . var_export($fetched, true) . "\n";
    
    if ($new_id && $pdo) {
        $stmt = $pdo->prepare("SELECT * FROM house_kubos WHERE kubo_id = ?");
        $stmt->execute([$new_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "Direct DB row: " . var_export($row, true) . "\n";
        
        // Clean up
        $del = $pdo->prepare("DELETE FROM house_kubos WHERE kubo_id = ?");
        $del->execute([$new_id]);
        echo "Cleaned up test kubo.\n";
    }
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
?>
