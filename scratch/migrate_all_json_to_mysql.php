<?php
// scratch/migrate_all_json_to_mysql.php
require_once __DIR__ . '/../database.php';

header('Content-Type: text/plain');

if (!$pdo) {
    die("ERROR: PDO connection to MySQL failed.\n");
}

echo "=== MIGRATING ALL JSON DATA TO MYSQL ===\n\n";

// 1. Ensure required tables exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS site_content (
        content_key VARCHAR(50) PRIMARY KEY,
        content_value LONGTEXT NOT NULL,
        updated_by VARCHAR(100) NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS booking_archive (
        id VARCHAR(50) PRIMARY KEY,
        guest_name VARCHAR(100) NOT NULL,
        guest_email VARCHAR(100) NULL,
        guest_phone VARCHAR(50) NULL,
        profile_id VARCHAR(50) NULL,
        user_id INT NULL,
        type VARCHAR(20) DEFAULT 'house',
        item_id INT DEFAULT 1,
        item_name VARCHAR(100) NOT NULL,
        check_in DATETIME NULL,
        check_out DATETIME NULL,
        time_slot VARCHAR(50) NULL,
        start_time VARCHAR(20) NULL,
        end_time VARCHAR(20) NULL,
        pax INT DEFAULT 1,
        num_guests INT DEFAULT 1,
        total_amount DECIMAL(10,2) NOT NULL,
        gcash_ref VARCHAR(50) NULL,
        payment_proof VARCHAR(255) NULL,
        payment_status VARCHAR(50) DEFAULT 'proof_submitted',
        booking_status VARCHAR(50) DEFAULT 'completed',
        special_notes TEXT NULL,
        rejection_reason TEXT NULL,
        archive_reason TEXT NULL,
        archived_by VARCHAR(100) NULL,
        archived_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS sent_emails (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ref_id VARCHAR(50) NULL,
        to_email VARCHAR(100) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        sent_status VARCHAR(50) DEFAULT 'delivered',
        error_message TEXT NULL,
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        meta_json TEXT NULL,
        html_file VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Ensure bookings table has all modern columns
$alterCols = [
    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS profile_id VARCHAR(50) NULL;",
    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS user_id INT NULL;",
    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS start_time VARCHAR(20) NULL;",
    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS end_time VARCHAR(20) NULL;",
    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS num_guests INT DEFAULT 1;",
    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS guest_list TEXT NULL;",
    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL;",
    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS rebook_deadline DATETIME NULL;",
    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS is_forfeited TINYINT(1) DEFAULT 0;",
    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS checked_in_at DATETIME NULL;",
    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS checked_out_at DATETIME NULL;",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_id VARCHAR(50) NULL;",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255) NULL;",
    "ALTER TABLE reviews ADD COLUMN IF NOT EXISTS guest VARCHAR(100) NULL;",
    "ALTER TABLE reviews ADD COLUMN IF NOT EXISTS guest_id INT NULL;",
    "ALTER TABLE reviews ADD COLUMN IF NOT EXISTS profile_id VARCHAR(50) NULL;",
    "ALTER TABLE reviews ADD COLUMN IF NOT EXISTS item VARCHAR(100) NULL;",
    "ALTER TABLE reviews ADD COLUMN IF NOT EXISTS staff_reply TEXT NULL;",
    "ALTER TABLE reviews ADD COLUMN IF NOT EXISTS staff_reply_by VARCHAR(100) NULL;",
    "ALTER TABLE reviews ADD COLUMN IF NOT EXISTS staff_reply_at VARCHAR(50) NULL;",
    "ALTER TABLE reviews ADD COLUMN IF NOT EXISTS is_published TINYINT(1) DEFAULT 1;"
];
foreach ($alterCols as $alter) {
    try { $pdo->exec($alter); } catch (PDOException $ex) {}
}

echo "Tables & schema verified.\n\n";

// 2. Migrate data/users.json -> MySQL users
$usersFile = __DIR__ . '/../data/users.json';
if (file_exists($usersFile)) {
    $usersData = json_decode(file_get_contents($usersFile), true) ?: [];
    $syncedUsers = 0;
    foreach ($usersData as $u) {
        $email = strtolower(trim($u['email'] ?? ''));
        if (empty($email)) continue;

        $chk = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = ?");
        $chk->execute([$email]);
        if (!$chk->fetch()) {
            $stmt = $pdo->prepare("
                INSERT INTO users (first_name, middle_name, last_name, suffix, email, phone, password_hash, role, profile_id, profile_picture, created_at)
                VALUES (:first_name, :middle_name, :last_name, :suffix, :email, :phone, :password_hash, :role, :profile_id, :profile_picture, :created_at)
            ");
            $stmt->execute([
                ':first_name' => $u['first_name'] ?? 'User',
                ':middle_name' => $u['middle_name'] ?? null,
                ':last_name' => $u['last_name'] ?? '',
                ':suffix' => $u['suffix'] ?? null,
                ':email' => $email,
                ':phone' => $u['phone'] ?? '',
                ':password_hash' => $u['password_hash'] ?? password_hash('Guest123', PASSWORD_BCRYPT),
                ':role' => $u['role'] ?? 'guest',
                ':profile_id' => $u['profile_id'] ?? null,
                ':profile_picture' => $u['profile_picture'] ?? '',
                ':created_at' => $u['created_at'] ?? date('Y-m-d H:i:s')
            ]);
            $syncedUsers++;
        }
    }
    echo "Users migrated: $syncedUsers\n";
}

// 3. Migrate data/bookings.json -> MySQL bookings
$bookingsFile = __DIR__ . '/../data/bookings.json';
if (file_exists($bookingsFile)) {
    $bookingsData = json_decode(file_get_contents($bookingsFile), true) ?: [];
    $syncedBookings = 0;
    foreach ($bookingsData as $b) {
        $id = $b['id'] ?? '';
        if (empty($id)) continue;

        $chk = $pdo->prepare("SELECT id FROM bookings WHERE id = ?");
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            $stmt = $pdo->prepare("
                INSERT INTO bookings (id, guest_name, guest_email, guest_phone, profile_id, user_id, type, item_id, item_name, check_in, check_out, time_slot, start_time, end_time, pax, num_guests, total_amount, gcash_ref, payment_proof, payment_status, booking_status, special_notes, guest_list, created_at)
                VALUES (:id, :guest_name, :guest_email, :guest_phone, :profile_id, :user_id, :type, :item_id, :item_name, :check_in, :check_out, :time_slot, :start_time, :end_time, :pax, :num_guests, :total_amount, :gcash_ref, :payment_proof, :payment_status, :booking_status, :special_notes, :guest_list, :created_at)
            ");
            $stmt->execute([
                ':id' => $b['id'],
                ':guest_name' => $b['guest_name'] ?? 'Guest',
                ':guest_email' => $b['guest_email'] ?? '',
                ':guest_phone' => $b['guest_phone'] ?? '',
                ':profile_id' => $b['profile_id'] ?? null,
                ':user_id' => $b['user_id'] ?? null,
                ':type' => $b['type'] ?? 'house',
                ':item_id' => $b['item_id'] ?? 1,
                ':item_name' => $b['item_name'] ?? '',
                ':check_in' => $b['check_in'] ?? date('Y-m-d H:i:s'),
                ':check_out' => !empty($b['check_out']) ? $b['check_out'] : null,
                ':time_slot' => $b['time_slot'] ?? '',
                ':start_time' => $b['start_time'] ?? null,
                ':end_time' => $b['end_time'] ?? null,
                ':pax' => $b['pax'] ?? 1,
                ':num_guests' => $b['pax'] ?? 1,
                ':total_amount' => $b['total_amount'] ?? 0,
                ':gcash_ref' => $b['gcash_ref'] ?? null,
                ':payment_proof' => $b['payment_proof'] ?? null,
                ':payment_status' => $b['payment_status'] ?? 'pending',
                ':booking_status' => $b['booking_status'] ?? 'pending',
                ':special_notes' => $b['special_notes'] ?? '',
                ':guest_list' => $b['guest_list'] ?? '',
                ':created_at' => $b['created_at'] ?? date('Y-m-d H:i:s')
            ]);
            $syncedBookings++;
        }
    }
    echo "Active Bookings migrated: $syncedBookings\n";
}

// 4. Migrate data/booking_archive.json -> MySQL booking_archive
$archiveFile = __DIR__ . '/../data/booking_archive.json';
if (file_exists($archiveFile)) {
    $archiveData = json_decode(file_get_contents($archiveFile), true) ?: [];
    $syncedArchive = 0;
    foreach ($archiveData as $b) {
        $id = $b['id'] ?? '';
        if (empty($id)) continue;

        $chk = $pdo->prepare("SELECT id FROM booking_archive WHERE id = ?");
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            $stmt = $pdo->prepare("
                INSERT INTO booking_archive (id, guest_name, guest_email, guest_phone, profile_id, user_id, type, item_id, item_name, check_in, check_out, time_slot, start_time, end_time, pax, num_guests, total_amount, gcash_ref, payment_proof, payment_status, booking_status, special_notes, rejection_reason, archive_reason, archived_by, archived_at, created_at)
                VALUES (:id, :guest_name, :guest_email, :guest_phone, :profile_id, :user_id, :type, :item_id, :item_name, :check_in, :check_out, :time_slot, :start_time, :end_time, :pax, :num_guests, :total_amount, :gcash_ref, :payment_proof, :payment_status, :booking_status, :special_notes, :rejection_reason, :archive_reason, :archived_by, :archived_at, :created_at)
            ");
            $stmt->execute([
                ':id' => $b['id'],
                ':guest_name' => $b['guest_name'] ?? 'Guest',
                ':guest_email' => $b['guest_email'] ?? '',
                ':guest_phone' => $b['guest_phone'] ?? '',
                ':profile_id' => $b['profile_id'] ?? null,
                ':user_id' => $b['user_id'] ?? null,
                ':type' => $b['type'] ?? 'house',
                ':item_id' => $b['item_id'] ?? 1,
                ':item_name' => $b['item_name'] ?? '',
                ':check_in' => $b['check_in'] ?? date('Y-m-d H:i:s'),
                ':check_out' => !empty($b['check_out']) ? $b['check_out'] : null,
                ':time_slot' => $b['time_slot'] ?? '',
                ':start_time' => $b['start_time'] ?? null,
                ':end_time' => $b['end_time'] ?? null,
                ':pax' => $b['pax'] ?? 1,
                ':num_guests' => $b['pax'] ?? 1,
                ':total_amount' => $b['total_amount'] ?? 0,
                ':gcash_ref' => $b['gcash_ref'] ?? null,
                ':payment_proof' => $b['payment_proof'] ?? null,
                ':payment_status' => $b['payment_status'] ?? 'pending',
                ':booking_status' => $b['booking_status'] ?? 'completed',
                ':special_notes' => $b['special_notes'] ?? '',
                ':rejection_reason' => $b['rejection_reason'] ?? null,
                ':archive_reason' => $b['archive_reason'] ?? 'Stay completed or archived',
                ':archived_by' => $b['archived_by'] ?? 'System',
                ':archived_at' => $b['archived_at'] ?? date('Y-m-d H:i:s'),
                ':created_at' => $b['created_at'] ?? date('Y-m-d H:i:s')
            ]);
            $syncedArchive++;
        }
    }
    echo "Archive Bookings migrated: $syncedArchive\n";
}

// 5. Migrate data/homepage_content.json -> MySQL site_content
$contentFile = __DIR__ . '/../data/homepage_content.json';
if (file_exists($contentFile)) {
    $contentJson = file_get_contents($contentFile);
    if (!empty($contentJson)) {
        $stmt = $pdo->prepare("
            INSERT INTO site_content (content_key, content_value, updated_by, updated_at)
            VALUES ('homepage', :val, 'Migration', NOW())
            ON DUPLICATE KEY UPDATE content_value = :val2, updated_at = NOW()
        ");
        $stmt->execute([':val' => $contentJson, ':val2' => $contentJson]);
        echo "Homepage CMS content migrated to site_content.\n";
    }
}

// 6. Migrate data/reviews.json -> MySQL reviews
$reviewsFile = __DIR__ . '/../data/reviews.json';
if (file_exists($reviewsFile)) {
    $reviewsData = json_decode(file_get_contents($reviewsFile), true) ?: [];
    $syncedReviews = 0;
    foreach ($reviewsData as $r) {
        $id = $r['id'] ?? null;
        if (!$id) continue;

        $chk = $pdo->prepare("SELECT review_id FROM reviews WHERE review_id = ?");
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            $stmt = $pdo->prepare("
                INSERT INTO reviews (review_id, booking_id, user_name, guest, guest_id, profile_id, item, rating, comment, staff_reply, staff_reply_by, staff_reply_at, is_approved, is_published, created_at)
                VALUES (:rid, :bid, :uname, :guest, :gid, :pid, :item, :rating, :comment, :reply, :reply_by, :reply_at, 1, 1, :created_at)
            ");
            $guest = $r['guest'] ?? $r['user_name'] ?? 'Guest';
            $stmt->execute([
                ':rid' => $id,
                ':bid' => $r['booking_id'] ?? null,
                ':uname' => $guest,
                ':guest' => $guest,
                ':gid' => $r['guest_id'] ?? null,
                ':pid' => $r['profile_id'] ?? null,
                ':item' => $r['item'] ?? 'Resort Experience',
                ':rating' => $r['rating'] ?? 5,
                ':comment' => $r['comment'] ?? '',
                ':reply' => $r['staff_reply'] ?? null,
                ':reply_by' => $r['staff_reply_by'] ?? null,
                ':reply_at' => $r['staff_reply_at'] ?? null,
                ':created_at' => $r['created_at'] ?? date('Y-m-d H:i:s')
            ]);
            $syncedReviews++;
        }
    }
    echo "Reviews migrated: $syncedReviews\n";
}

// 7. Migrate data/sent_emails.json -> MySQL sent_emails
$emailsFile = __DIR__ . '/../data/sent_emails.json';
if (file_exists($emailsFile)) {
    $emailsData = json_decode(file_get_contents($emailsFile), true) ?: [];
    $syncedEmails = 0;
    foreach ($emailsData as $em) {
        $refId = $em['ref_id'] ?? '';
        if (empty($refId)) continue;

        $chk = $pdo->prepare("SELECT id FROM sent_emails WHERE ref_id = ?");
        $chk->execute([$refId]);
        if (!$chk->fetch()) {
            $stmt = $pdo->prepare("
                INSERT INTO sent_emails (ref_id, to_email, subject, sent_status, error_message, sent_at, meta_json, html_file)
                VALUES (:ref_id, :to_email, :subject, :sent_status, :error_message, :sent_at, :meta_json, :html_file)
            ");
            $stmt->execute([
                ':ref_id' => $refId,
                ':to_email' => $em['to_email'] ?? '',
                ':subject' => $em['subject'] ?? '',
                ':sent_status' => $em['sent_status'] ?? 'delivered',
                ':error_message' => $em['error'] ?? null,
                ':sent_at' => $em['sent_at'] ?? date('Y-m-d H:i:s'),
                ':meta_json' => !empty($em['meta']) ? json_encode($em['meta']) : null,
                ':html_file' => $em['html_file'] ?? null
            ]);
            $syncedEmails++;
        }
    }
    echo "Sent emails migrated: $syncedEmails\n";
}

echo "\n=== MIGRATION COMPLETE! ALL DATA IS NOW IN MYSQL! ===\n";
