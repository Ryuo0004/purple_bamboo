<?php
require_once __DIR__ . '/../database.php';
try {
    $pdo = getDB();
    if ($pdo) {
        $stmt = $pdo->query("SHOW COLUMNS FROM users");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<pre>";
        echo json_encode($columns, JSON_PRETTY_PRINT);
        echo "</pre>";
    } else {
        echo "Failed to connect to database.";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
