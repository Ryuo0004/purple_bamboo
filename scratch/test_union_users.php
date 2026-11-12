<?php
require_once __DIR__ . '/../database.php';
header('Content-Type: application/json');

try {
    $stmt = $pdo->query("
        SELECT id, profile_id, CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name, NULLIF(suffix, '')) AS full_name, first_name, last_name, email, phone, role, status, is_blocked, block_reason, blocked_at, blocked_by, emergency_contact_name, emergency_contact_relationship, emergency_contact_phone, id_type, id_number, health_conditions, created_at FROM users
        UNION ALL
        SELECT id, profile_id, CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name, NULLIF(suffix, '')) AS full_name, first_name, last_name, email, phone, 'staff' AS role, status, (CASE WHEN status='blocked' THEN 1 ELSE 0 END) AS is_blocked, '' AS block_reason, NULL AS blocked_at, NULL AS blocked_by, '' AS emergency_contact_name, '' AS emergency_contact_relationship, '' AS emergency_contact_phone, 'Staff ID' AS id_type, '' AS id_number, '' AS health_conditions, created_at FROM staff
        UNION ALL
        SELECT id, profile_id, CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name, NULLIF(suffix, '')) AS full_name, first_name, last_name, email, phone, 'admin' AS role, status, (CASE WHEN status='blocked' THEN 1 ELSE 0 END) AS is_blocked, '' AS block_reason, NULL AS blocked_at, NULL AS blocked_by, '' AS emergency_contact_name, '' AS emergency_contact_relationship, '' AS emergency_contact_phone, 'System Admin' AS id_type, '' AS id_number, '' AS health_conditions, created_at FROM admins
        ORDER BY created_at DESC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'count' => count($users), 'users' => $users]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
