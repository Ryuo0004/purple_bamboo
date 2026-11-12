<?php
/**
 * Migration Script: Normalize Guest Information in MySQL & JSON Bookings
 * Run via CLI: php migrate_normalize_guests.php
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/includes/GuestHelper.php';
require_once __DIR__ . '/booking_store.php';

echo "=== Purple Bamboo Resort: Guest Information Normalization Migration ===" . PHP_EOL;

$jsonFile = __DIR__ . '/data/bookings.json';
$updatedJsonCount = 0;
$updatedDbCount = 0;

// 1. Process JSON Bookings
if (file_exists($jsonFile)) {
    $bookings = json_decode(file_get_contents($jsonFile), true) ?: [];
    foreach ($bookings as &$b) {
        $oldName = $b['guest_name'] ?? '';
        $oldPhone = $b['guest_phone'] ?? '';
        $oldPid = $b['profile_id'] ?? null;
        $oldUid = $b['user_id'] ?? null;

        $regUser = BookingStore::getRegisteredUserForBooking($b['guest_email'] ?? '', $oldPhone);
        if ($regUser) {
            $b['user_id'] = $regUser['id'] ?? $oldUid;
            $b['profile_id'] = $regUser['profile_id'] ?? GuestHelper::formatProfileId($regUser['id'] ?? 101);
            $b['is_registered'] = true;
            $b['registered_name'] = $regUser['full_name'] ?? $oldName;
            $b['registered_email'] = $regUser['email'] ?? ($b['guest_email'] ?? '');
            $b['registered_phone'] = $regUser['phone'] ?? $oldPhone;
            $b['registered_user_id'] = $regUser['id'] ?? null;
        }

        $normalizedName = GuestHelper::normalizeName($b['registered_name'] ?? $oldName);
        $formattedPhone = GuestHelper::formatPhone($oldPhone);

        if ($normalizedName !== $oldName || $formattedPhone !== $oldPhone || $b['profile_id'] !== $oldPid || ($b['user_id'] ?? null) !== $oldUid) {
            $b['guest_name'] = $normalizedName;
            $b['guest_phone'] = $formattedPhone;
            $updatedJsonCount++;
        }
    }
    unset($b);

    file_put_contents($jsonFile, json_encode($bookings, JSON_PRETTY_PRINT));
    echo " [OK] JSON Store: Processed " . count($bookings) . " bookings, normalized " . $updatedJsonCount . " records." . PHP_EOL;
}

// 2. Process MySQL Database Bookings
if (isset($pdo) && $pdo !== null) {
    try {
        $stmt = $pdo->query("SELECT id, guest_name, guest_email, guest_phone, profile_id, user_id FROM bookings");
        $dbRows = $stmt->fetchAll();

        $updateStmt = $pdo->prepare("
            UPDATE bookings 
            SET guest_name = :gname, 
                guest_phone = :gphone, 
                profile_id = :pid, 
                user_id = :uid 
            WHERE id = :id
        ");

        foreach ($dbRows as $row) {
            $regUser = BookingStore::getRegisteredUserForBooking($row['guest_email'] ?? '', $row['guest_phone'] ?? '');
            $newUid = $row['user_id'];
            $newPid = $row['profile_id'];

            if ($regUser) {
                $newUid = $regUser['id'] ?? $row['user_id'];
                $newPid = $regUser['profile_id'] ?? GuestHelper::formatProfileId($regUser['id'] ?? 101);
            } elseif (empty($newPid) && !empty($newUid)) {
                $newPid = GuestHelper::formatProfileId($newUid);
            }

            $rawName = ($regUser && !empty($regUser['full_name'])) ? $regUser['full_name'] : $row['guest_name'];
            $normName = GuestHelper::normalizeName($rawName);
            $normPhone = GuestHelper::formatPhone($row['guest_phone']);

            $updateStmt->execute([
                ':gname' => $normName,
                ':gphone' => $normPhone,
                ':pid' => $newPid,
                ':uid' => $newUid,
                ':id' => $row['id']
            ]);
            $updatedDbCount++;
        }

        echo " [OK] MySQL Database: Successfully normalized " . $updatedDbCount . " booking rows." . PHP_EOL;
    } catch (PDOException $e) {
        echo " [WARN] MySQL Error: " . $e->getMessage() . PHP_EOL;
    }
}

echo "=== Normalization Complete! ===" . PHP_EOL;
