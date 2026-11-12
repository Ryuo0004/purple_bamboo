<?php
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../inventory_store.php';

echo "=== CHECKING PDO ===\n";
var_dump($pdo !== null);

echo "=== SHOW COLUMNS FROM house_kubos ===\n";
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM house_kubos");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo "{$col['Field']} - {$col['Type']} - Null: {$col['Null']} - Key: {$col['Key']} - Default: {$col['Default']} - Extra: {$col['Extra']}\n";
    }
} catch (Exception $e) {
    echo "Error showing columns: " . $e->getMessage() . "\n";
}

echo "=== TRYING InventoryStore::addKubo ===\n";
$testData = [
    'name' => 'Debug Test Kubo',
    'category_id' => 1,
    'price' => '2500',
    'capacity' => '3',
    'status' => 'available',
    'badges' => 'WiFi, Aircon',
    'description' => 'Test description',
    'image_url' => 'assets/beach_kubo.jpg'
];

try {
    $newId = InventoryStore::addKubo($testData);
    echo "addKubo returned: ";
    var_dump($newId);
} catch (Exception $e) {
    echo "Exception during addKubo: " . $e->getMessage() . "\n";
}

echo "=== LAST 5 HOUSE KUBOS IN DB ===\n";
$stmt = $pdo->query("SELECT * FROM house_kubos ORDER BY kubo_id DESC LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
