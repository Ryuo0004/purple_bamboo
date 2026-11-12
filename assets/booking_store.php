<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/includes/GuestHelper.php';

class BookingStore {
    /**
     * Get PDO connection if available
     */
    private static function getPdo() {
        global $pdo;
        return (isset($pdo) && $pdo !== null) ? $pdo : null;
    }

    /**
     * Initialize DB table with seed data if empty
     */
    public static function init() {
        $pdo = self::getPdo();
        if ($pdo) {
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS bookings (
                        id VARCHAR(50) PRIMARY KEY,
                        guest_name VARCHAR(100) NOT NULL,
                        guest_email VARCHAR(100) NOT NULL,
                        guest_phone VARCHAR(50) NOT NULL,
                        type VARCHAR(20) DEFAULT 'house',
                        item_id INT DEFAULT 1,
                        item_name VARCHAR(100) NOT NULL,
                        check_in DATE NOT NULL,
                        check_out DATE NULL,
                        time_slot VARCHAR(50) NULL,
                        pax INT DEFAULT 1,
                        total_amount DECIMAL(10,2) NOT NULL,
                        gcash_ref VARCHAR(50) NULL,
                        payment_proof VARCHAR(255) NULL,
                        payment_status VARCHAR(30) DEFAULT 'proof_submitted',
                        booking_status VARCHAR(30) DEFAULT 'pending',
                        special_notes TEXT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

                // Ensure all expected columns exist if table was previously created with legacy schema
                $alterCols = [
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS id VARCHAR(50) NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS guest_name VARCHAR(100) NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS guest_email VARCHAR(100) NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS guest_phone VARCHAR(50) NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS profile_id VARCHAR(50) NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS user_id INT NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS type VARCHAR(20) DEFAULT 'house';",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS item_id INT DEFAULT 1;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS item_name VARCHAR(100) NULL;",
                    "ALTER TABLE bookings MODIFY COLUMN check_in DATETIME NULL;",
                    "ALTER TABLE bookings MODIFY COLUMN check_out DATETIME NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS time_slot VARCHAR(50) NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS pax INT DEFAULT 1;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS gcash_ref VARCHAR(50) NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS special_notes TEXT NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS payment_proof VARCHAR(255) NULL;",
                    "ALTER TABLE bookings MODIFY COLUMN booking_status VARCHAR(50) DEFAULT 'pending';",
                    "ALTER TABLE bookings MODIFY COLUMN payment_status VARCHAR(50) DEFAULT 'proof_submitted';",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS guest_list TEXT NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS rebook_deadline DATETIME NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS is_forfeited TINYINT(1) DEFAULT 0;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS resched_pending TINYINT(1) DEFAULT 0;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS resched_check_in DATETIME NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS resched_check_out DATETIME NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS resched_time_slot VARCHAR(50) NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS resched_reason TEXT NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS resched_created_at DATETIME NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS resched_count INT DEFAULT 0;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS rescheduled_from VARCHAR(100) NULL;",
                    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS resched_reject_reason TEXT NULL;"
                ];
                foreach ($alterCols as $alter) {
                    try { $pdo->exec($alter); } catch (PDOException $ex) {}
                }
                try {
                    $colCheck = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'resched_count'")->fetch();
                    if (!$colCheck) {
                        $pdo->exec("ALTER TABLE bookings ADD COLUMN resched_count INT DEFAULT 0");
                    }
                    $colCheck2 = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'rescheduled_from'")->fetch();
                    if (!$colCheck2) {
                        $pdo->exec("ALTER TABLE bookings ADD COLUMN rescheduled_from VARCHAR(100) NULL");
                    }
                    $colCheck3 = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'resched_reject_reason'")->fetch();
                    if (!$colCheck3) {
                        $pdo->exec("ALTER TABLE bookings ADD COLUMN resched_reject_reason TEXT NULL");
                    }
                } catch (PDOException $ex) {}

                // Auto-seeding disabled: Table remains empty when truncated
            } catch (PDOException $e) {
                error_log("BookingStore::init DB error: " . $e->getMessage());
            }
        }
    }



    /**
     * Cache registered users for quick lookup
     */
    private static $cached_registered_users = null;

    public static function getRegisteredUsersList() {
        if (self::$cached_registered_users !== null) {
            return self::$cached_registered_users;
        }
        $list = [];
        $pdo = self::getPdo();
        if ($pdo) {
            try {
                $stmt = $pdo->query("SELECT *, COALESCE(user_id, id) AS user_id, COALESCE(id, user_id) AS id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name, NULLIF(suffix, ''))), ''), full_name) AS full_name FROM users ORDER BY COALESCE(user_id, id) ASC");
                $list = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (PDOException $e) {
                error_log("getRegisteredUsersList error: " . $e->getMessage());
            }
        }
        self::$cached_registered_users = $list;
        return $list;
    }

    /**
     * Match a booking's guest email/phone to registered guest accounts
     * Email takes primary precedence over phone number
     */
    public static function getRegisteredUserForBooking($email, $phone = '') {
        $email = strtolower(trim($email ?? ''));
        $clean_phone = preg_replace('/[^0-9]/', '', $phone ?? '');

        $users = self::getRegisteredUsersList();

        // 1. Primary check: Exact Email match
        if (!empty($email)) {
            foreach ($users as $u) {
                $u_email = strtolower(trim($u['email'] ?? ''));
                if ($u_email === $email) {
                    return $u;
                }
            }
        }

        // 2. Secondary check: Phone number match (only if email didn't match)
        if (!empty($clean_phone) && strlen($clean_phone) >= 7) {
            foreach ($users as $u) {
                $u_phone = preg_replace('/[^0-9]/', '', $u['phone'] ?? '');
                if (!empty($u_phone) && strlen($u_phone) >= 7) {
                    if (substr($clean_phone, -10) === substr($u_phone, -10)) {
                        return $u;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Enrich bookings with registered guest profile details & normalized data
     */
    public static function enrichBookingsWithUserData($bookings) {
        if (!is_array($bookings)) return $bookings;
        $is_single = (isset($bookings['id']) && !isset($bookings[0]));
        $items = $is_single ? [$bookings] : $bookings;

        foreach ($items as &$b) {
            $email = $b['guest_email'] ?? '';
            $phone = $b['guest_phone'] ?? '';
            $regUser = self::getRegisteredUserForBooking($email, $phone);

            if ($regUser) {
                $b['is_registered'] = true;
                $b['user_id'] = $b['user_id'] ?? ($regUser['id'] ?? null);
                $b['profile_id'] = !empty($b['profile_id']) ? $b['profile_id'] : ($regUser['profile_id'] ?? GuestHelper::formatProfileId($regUser['id'] ?? 101));
                $b['registered_name'] = $regUser['full_name'] ?? $b['guest_name'];
                $b['registered_email'] = $regUser['email'] ?? $b['guest_email'];
                $b['registered_phone'] = $regUser['phone'] ?? $b['guest_phone'];
                $b['registered_user_id'] = $regUser['id'] ?? null;
                $b['registered_profile_picture'] = $regUser['profile_picture'] ?? '';
            } else {
                $b['is_registered'] = false;
                $b['profile_id'] = !empty($b['profile_id']) ? $b['profile_id'] : (!empty($b['user_id']) ? GuestHelper::formatProfileId($b['user_id']) : null);
                $b['registered_name'] = null;
            }

            // Standardize display values with GuestHelper
            $rawName = $b['registered_name'] ?? $b['guest_name'] ?? 'Resort Guest';
            $b['normalized_guest_name'] = GuestHelper::normalizeName($rawName);
            $b['formatted_guest_phone'] = GuestHelper::formatPhone($b['guest_phone'] ?? $b['registered_phone'] ?? '');
            if (empty($b['profile_id']) && !empty($b['user_id'])) {
                $b['profile_id'] = GuestHelper::formatProfileId($b['user_id']);
            }
        }

        return $is_single ? ($items[0] ?? null) : $items;
    }

    /**
     * Get all bookings with filtering & search
     */
    public static function getAllBookings($filters = []) {
        self::init();
        $pdo = self::getPdo();

        $search = strtolower(trim($filters['search'] ?? ''));
        $status = trim($filters['status'] ?? 'all');
        $type = trim($filters['type'] ?? 'all');
        $payment_status = trim($filters['payment_status'] ?? 'all');
        $date = trim($filters['date'] ?? '');

        if ($pdo) {
            try {
                $sql = "SELECT * FROM bookings WHERE 1=1";
                $params = [];

                if ($status !== 'all' && !empty($status)) {
                    $sql .= " AND booking_status = :status";
                    $params[':status'] = $status;
                }
                if ($type !== 'all' && !empty($type)) {
                    $sql .= " AND type = :type";
                    $params[':type'] = $type;
                }
                if ($payment_status !== 'all' && !empty($payment_status)) {
                    if ($payment_status === 'unpaid') {
                        $sql .= " AND (payment_status IS NULL OR payment_status != 'verified')";
                    } else {
                        $sql .= " AND payment_status = :pstatus";
                        $params[':pstatus'] = $payment_status;
                    }
                }
                if (!empty($date)) {
                    $sql .= " AND (check_in = :date OR (check_out IS NOT NULL AND :date BETWEEN check_in AND check_out))";
                    $params[':date'] = $date;
                }
                if (!empty($search)) {
                    $sql .= " AND (LOWER(id) LIKE :q OR LOWER(guest_name) LIKE :q OR LOWER(guest_phone) LIKE :q OR LOWER(guest_email) LIKE :q OR LOWER(item_name) LIKE :q)";
                    $params[':q'] = "%$search%";
                }

                $sql .= " ORDER BY created_at DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $db_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return self::enrichBookingsWithUserData($db_rows);
            } catch (PDOException $e) {
                error_log("BookingStore::getAll DB error: " . $e->getMessage());
            }
        }
        return [];
    }

    /**
     * Returns the user's pending booking (status 'pending') or null.
     * If $itemId is specified, checks specifically for that accommodation/activity item.
     * Users may book other kubos or activities freely.
     */
    public static function getPendingBookingForUser($user_id, $type = 'house', $itemId = null) {
        self::init();
        $pdo = self::getPdo();
        if ($pdo && !empty($user_id)) {
            try {
                $sql = "SELECT id, item_id, item_name FROM bookings WHERE user_id = :uid AND type = :type AND LOWER(booking_status) = 'pending'";
                $params = [':uid' => $user_id, ':type' => $type];
                if ($itemId !== null && intval($itemId) > 0) {
                    $sql .= " AND item_id = :item_id";
                    $params[':item_id'] = intval($itemId);
                }
                $sql .= " ORDER BY created_at DESC LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $res = $stmt->fetch(PDO::FETCH_ASSOC);
                return $res ?: null;
            } catch (PDOException $e) {
                error_log("BookingStore::getPendingBookingForUser DB error: " . $e->getMessage());
            }
        }
        return null;
    }

    /**
     * Check if a user already has an active (pending, confirmed, approved, reschedule_requested, or checked_in) booking for a specific house/item.
     * Prevents duplicate bookings while an active or pending reservation already exists.
     */
    public static function getActiveBookingForUser($user_id, $type = 'house', $itemId = null, $userEmail = null) {
        self::init();
        $pdo = self::getPdo();
        if ($pdo && (!empty($user_id) || !empty($userEmail))) {
            try {
                $userClauses = [];
                $params = [':type' => $type];
                if (!empty($user_id)) {
                    $userClauses[] = "user_id = :uid";
                    $params[':uid'] = $user_id;
                }
                if (!empty($userEmail)) {
                    $userClauses[] = "LOWER(guest_email) = :uemail";
                    $params[':uemail'] = strtolower(trim($userEmail));
                }
                $userSql = "(" . implode(" OR ", $userClauses) . ")";

                $sql = "SELECT * FROM bookings 
                        WHERE {$userSql} 
                          AND type = :type 
                          AND LOWER(booking_status) IN ('pending', 'confirmed', 'approved', 'resched', 'reschedule_requested', 'checked_in')";
                if ($itemId !== null && intval($itemId) > 0) {
                    $sql .= " AND item_id = :item_id";
                    $params[':item_id'] = intval($itemId);
                }
                $sql .= " ORDER BY created_at DESC LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $res = $stmt->fetch(PDO::FETCH_ASSOC);
                return $res ?: null;
            } catch (PDOException $e) {
                error_log("BookingStore::getActiveBookingForUser DB error: " . $e->getMessage());
            }
        }
        return null;
    }

    /**
     * Get single booking by ID
     */
    public static function getBookingById($id) {
        self::init();
        $pdo = self::getPdo();
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $id]);
                $res = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($res) return self::enrichBookingsWithUserData($res);
            } catch (PDOException $e) {
                error_log("BookingStore::getBookingById DB error: " . $e->getMessage());
            }
        }
        return null;
    }

    /**
     * Create a new booking record
     */
    public static function createBooking($data) {
        self::init();

        if (empty($data['id'])) {
            $prefix = ($data['type'] ?? 'house') === 'activity' ? 'ACT' : 'PBR';
            $data['id'] = $prefix . '-' . date('Y') . '-' . rand(1000, 9999);
        }

        // Auto-match registered account if profile_id not set
        $profile_id = $data['profile_id'] ?? null;
        $user_id = $data['user_id'] ?? null;
        $cleanName = GuestHelper::normalizeName($data['guest_name'] ?? 'Guest User');
        $cleanPhone = GuestHelper::formatPhone($data['guest_phone'] ?? '');

        if (empty($profile_id)) {
            $regUser = self::getRegisteredUserForBooking($data['guest_email'] ?? '', $data['guest_phone'] ?? '');
            if ($regUser) {
                $profile_id = $regUser['profile_id'] ?? GuestHelper::formatProfileId($regUser['id'] ?? 101);
                $user_id = $regUser['id'] ?? null;
                if (empty($data['guest_name']) || $data['guest_name'] === 'Guest User') {
                    $cleanName = GuestHelper::normalizeName($regUser['full_name'] ?? '');
                }
            }
        }

        $today_str = date('Y-m-d');
        $in_date_only = substr(trim($data['check_in'] ?? ''), 0, 10);
        if (!empty($in_date_only) && $in_date_only < $today_str) {
            throw new InvalidArgumentException("Booking date cannot be in the past.");
        }

        $booking = [
            'id' => $data['id'],
            'guest_name' => $cleanName,
            'guest_email' => strtolower(trim($data['guest_email'] ?? 'guest@example.com')),
            'guest_phone' => $cleanPhone,
            'profile_id' => $profile_id,
            'user_id' => $user_id,
            'type' => $data['type'] ?? 'house',
            'item_id' => intval($data['item_id'] ?? 1),
            'item_name' => $data['item_name'] ?? 'Beach Kubo',
            'check_in' => $data['check_in'] ?? date('Y-m-d'),
            'check_out' => $data['check_out'] ?? date('Y-m-d', strtotime('+1 day')),
            'time_slot' => $data['time_slot'] ?? '1 Night Stay',
            'pax' => intval($data['pax'] ?? 2),
            'total_amount' => floatval($data['total_amount'] ?? 0),
            'gcash_ref' => $data['gcash_ref'] ?? null,
            'payment_proof' => $data['payment_proof'] ?? null,
            'payment_status' => $data['payment_status'] ?? 'pending',
            'booking_status' => $data['booking_status'] ?? 'pending',
            'special_notes' => $data['special_notes'] ?? '',
            'guest_list' => $data['guest_list'] ?? '',
            'addons' => $data['addons'] ?? [],
            'created_at' => date('Y-m-d H:i:s')
        ];

        $pdo = self::getPdo();
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO bookings (id, guest_name, guest_email, guest_phone, profile_id, user_id, type, item_id, item_name, check_in, check_out, time_slot, start_time, end_time, pax, num_guests, total_amount, gcash_ref, payment_proof, payment_status, booking_status, special_notes, guest_list, created_at)
                    VALUES (:id, :guest_name, :guest_email, :guest_phone, :profile_id, :user_id, :type, :item_id, :item_name, :check_in, :check_out, :time_slot, :start_time, :end_time, :pax, :num_guests, :total_amount, :gcash_ref, :payment_proof, :payment_status, :booking_status, :special_notes, :guest_list, :created_at)
                ");
                $stmt->execute([
                    ':id' => $booking['id'],
                    ':guest_name' => $booking['guest_name'],
                    ':guest_email' => $booking['guest_email'],
                    ':guest_phone' => $booking['guest_phone'],
                    ':profile_id' => $booking['profile_id'],
                    ':user_id' => $booking['user_id'],
                    ':type' => $booking['type'],
                    ':item_id' => $booking['item_id'],
                    ':item_name' => $booking['item_name'],
                    ':check_in' => $booking['check_in'],
                    ':check_out' => !empty($booking['check_out']) ? $booking['check_out'] : null,
                    ':time_slot' => $booking['time_slot'],
                    ':start_time' => $data['start_time'] ?? null,
                    ':end_time' => $data['end_time'] ?? null,
                    ':pax' => $booking['pax'],
                    ':num_guests' => $booking['pax'] ?? 1,
                    ':total_amount' => $booking['total_amount'],
                    ':gcash_ref' => $booking['gcash_ref'],
                    ':payment_proof' => $booking['payment_proof'],
                    ':payment_status' => $booking['payment_status'],
                    ':booking_status' => $booking['booking_status'],
                    ':special_notes' => $booking['special_notes'],
                    ':guest_list' => $booking['guest_list'],
                    ':created_at' => $booking['created_at']
                ]);
                if (!empty($booking['addons']) && is_array($booking['addons'])) {
                    foreach ($booking['addons'] as $addon) {
                        try {
                            $stmt2 = $pdo->prepare("
                                INSERT INTO addon_reservations (reservation_id, activity_id, activity_date, start_time, end_time, quantity) 
                                VALUES (:reservation_id, :activity_id, :activity_date, :start_time, :end_time, :quantity)
                            ");
                            $stmt2->execute([
                                ':reservation_id' => $booking['id'],
                                ':activity_id' => $addon['activity_id'],
                                ':activity_date' => $addon['scheduled_date'],
                                ':start_time' => $addon['start_time'] ?? null,
                                ':end_time' => $addon['end_time'] ?? null,
                                ':quantity' => $addon['pax'] ?? $addon['qty'] ?? 1
                            ]);
                        } catch (PDOException $e2) {
                            error_log("BookingStore::createBooking addon DB error: " . $e2->getMessage());
                        }
                    }
                }
            } catch (PDOException $e) {
                error_log("BookingStore::createBooking DB error: " . $e->getMessage());
            }
        }

        // Automatically send booking confirmation & schedule email only if booking & payment are confirmed/approved
        $b_status = strtolower($booking['booking_status'] ?? '');
        $p_status = strtolower($booking['payment_status'] ?? '');
        if (in_array($b_status, ['approved', 'confirmed']) && $p_status === 'verified') {
            try {
                require_once __DIR__ . '/includes/EmailHelper.php';
                EmailHelper::sendBookingConfirmationEmail($booking);
            } catch (Throwable $e) {
                error_log("Failed to dispatch booking confirmation email: " . $e->getMessage());
            }
        }

        return $booking;
    }

    /**
     * Update booking status & payment status
     */
    public static function updateBookingStatus($id, $booking_status = null, $payment_status = null, $gcash_ref = null, $payment_proof = null, $special_notes = null, $rejection_reason = null, $rebook_deadline = null, $is_forfeited = null) {
        self::init();
        $pdo = self::getPdo();

        // Capture previous status to check if it transitions into confirmed
        $existing = self::getBookingById($id);
        $prev_b_status = strtolower($existing['booking_status'] ?? '');
        $prev_p_status = strtolower($existing['payment_status'] ?? '');
        $wasConfirmed = in_array($prev_b_status, ['approved', 'confirmed']) && ($prev_p_status === 'verified');

        if ($pdo) {
            try {
                $updates = [];
                $params = [':id' => $id];
                if ($booking_status !== null) {
                    $updates[] = "booking_status = :bstatus";
                    $params[':bstatus'] = $booking_status;
                }
                if ($payment_status !== null) {
                    $updates[] = "payment_status = :pstatus";
                    $params[':pstatus'] = $payment_status;
                }
                if ($gcash_ref !== null) {
                    $updates[] = "gcash_ref = :gref";
                    $params[':gref'] = $gcash_ref;
                }
                if ($payment_proof !== null) {
                    $updates[] = "payment_proof = :pproof";
                    $params[':pproof'] = $payment_proof;
                }
                if ($special_notes !== null) {
                    $updates[] = "special_notes = :snotes";
                    $params[':snotes'] = $special_notes;
                }
                if ($rejection_reason !== null) {
                    $updates[] = "rejection_reason = :rreason";
                    $params[':rreason'] = $rejection_reason;
                }
                if ($rebook_deadline !== null) {
                    $updates[] = "rebook_deadline = :rdeadline";
                    $params[':rdeadline'] = $rebook_deadline;
                }
                if ($is_forfeited !== null) {
                    $updates[] = "is_forfeited = :iforfeited";
                    $params[':iforfeited'] = $is_forfeited;
                }

                if (!empty($updates)) {
                    $sql = "UPDATE bookings SET " . implode(", ", $updates) . " WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                }
            } catch (PDOException $e) {
                // Fallback
            }
        }

        // Determine updated status
        $new_b_status = $booking_status !== null ? strtolower($booking_status) : $prev_b_status;
        $new_p_status = $payment_status !== null ? strtolower($payment_status) : $prev_p_status;
        $isNowConfirmed = in_array($new_b_status, ['approved', 'confirmed']) && ($new_p_status === 'verified');

        // Automatically dispatch confirmation email only when payment & booking become confirmed
        if (!$wasConfirmed && $isNowConfirmed) {
            try {
                $updatedBooking = self::getBookingById($id);
                if ($updatedBooking) {
                    require_once __DIR__ . '/includes/EmailHelper.php';
                    EmailHelper::sendBookingConfirmationEmail($updatedBooking);
                }
            } catch (Throwable $e) {
                error_log("Failed to dispatch confirmed booking email: " . $e->getMessage());
            }
        }

        // Auto-release accommodation if cancelled or checked out
        if (in_array($booking_status, ['cancelled', 'checked_out'])) {
            try {
                $b = self::getBookingById($id);
                if ($b && ($b['type'] ?? 'house') === 'house' && !empty($b['item_id'])) {
                    require_once __DIR__ . '/inventory_store.php';
                    $kubo = InventoryStore::getKuboById($b['item_id']);
                    if ($kubo && in_array(strtolower(trim($kubo['status'] ?? '')), ['occupied', 'unavailable'])) {
                        InventoryStore::updateKuboStatus($b['item_id'], 'available');
                    }
                    unset($_SESSION['kubos']);
                }
            } catch (Throwable $t) {}
        }

        return true;
    }

    /**
     * Process Rebooking
     */
    public static function processRebook($id, $check_in, $check_out) {
        self::init();
        $pdo = self::getPdo();
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("UPDATE bookings SET booking_status = 'pending', check_in = :cin, check_out = :cout, rebook_deadline = NULL, rejection_reason = NULL, is_forfeited = 0 WHERE id = :id");
                return $stmt->execute([
                    ':cin' => $check_in,
                    ':cout' => $check_out,
                    ':id' => $id
                ]);
            } catch (PDOException $e) {
                error_log("BookingStore::processRebook DB error: " . $e->getMessage());
            }
        }
        return false;
    }

    /**
     * Reschedule an existing booking (Unit, Dates, Times, Total, with Availability check and Audit Trail)
     */
    public static function rescheduleBooking($id, $newCheckIn, $newCheckOut, $newItemId = null, $newItemName = null, $newTimeSlot = null, $newTotal = null, $reason = '', $rescheduledBy = 'Staff') {
        self::init();
        $pdo = self::getPdo();
        if (!$pdo) return ['success' => false, 'error' => 'Database connection unavailable'];

        $b = self::getBookingById($id);
        if (!$b) {
            return ['success' => false, 'error' => 'Booking not found'];
        }

        $currCount = intval($b['resched_count'] ?? 0);
        if ($currCount >= 2) {
            return ['success' => false, 'error' => 'This reservation has already reached the maximum limit of 2 reschedules.'];
        }

        $nextCount = $currCount + 1;
        $type = $b['type'] ?? 'house';
        $oldItemId = $b['item_id'] ?? 1;
        $oldItemName = $b['item_name'] ?? '';
        $oldCheckIn = $b['check_in'] ?? '';
        $oldCheckOut = $b['check_out'] ?? '';
        $oldTotal = floatval($b['total_amount'] ?? 0);

        $itemId = $newItemId ? intval($newItemId) : $oldItemId;
        $itemName = $newItemName ? trim($newItemName) : $oldItemName;

        // Clean & format dates
        $cInDate = date('Y-m-d H:i:s', strtotime($newCheckIn));
        $cOutDate = !empty($newCheckOut) ? date('Y-m-d H:i:s', strtotime($newCheckOut)) : date('Y-m-d H:i:s', strtotime($newCheckIn . ' +1 day'));

        // Check availability (strictly excluding current booking ID so no false self-conflict)
        if ($type === 'house') {
            $isAvail = self::isItemAvailable($itemId, substr($cInDate, 0, 10), substr($cOutDate, 0, 10), 'house', $id);
            if (!$isAvail) {
                return ['success' => false, 'error' => "Unit '{$itemName}' is already booked or has no available capacity for the selected dates."];
            }
        }

        // Calculate audit note
        $now = date('M d, Y g:i A');
        $audit = "Rescheduled (Attempt {$nextCount} of 2) on {$now} by {$rescheduledBy}: ";
        $changes = [];
        if ($oldItemName !== $itemName) {
            $changes[] = "Unit: '{$oldItemName}' -> '{$itemName}'";
        }
        $changes[] = "Dates: " . date('M d, Y g:i A', strtotime($oldCheckIn)) . " to " . date('M d, Y g:i A', strtotime($oldCheckOut)) . " -> " . date('M d, Y g:i A', strtotime($cInDate)) . " to " . date('M d, Y g:i A', strtotime($cOutDate));
        if ($newTotal !== null && floatval($newTotal) != $oldTotal) {
            $changes[] = "Amount: ₱" . number_format($oldTotal, 2) . " -> ₱" . number_format(floatval($newTotal), 2);
        }
        if (!empty($reason)) {
            $changes[] = "Reason: " . $reason;
        }
        $audit .= implode(" | ", $changes);

        $existingNotes = trim($b['special_notes'] ?? '');
        $finalNotes = $existingNotes ? ($existingNotes . "\n[Audit] " . $audit) : ("[Audit] " . $audit);

        $totalToSave = ($newTotal !== null) ? floatval($newTotal) : $oldTotal;
        $timeSlotToSave = $newTimeSlot ?: ($b['time_slot'] ?? '');

        try {
            $stmt = $pdo->prepare("
                UPDATE bookings 
                SET item_id = :item_id,
                    item_name = :item_name,
                    check_in = :check_in,
                    check_out = :check_out,
                    time_slot = :time_slot,
                    total_amount = :total_amount,
                    special_notes = :special_notes,
                    resched_count = :resched_count
                WHERE id = :id
            ");
            $stmt->execute([
                ':item_id' => $itemId,
                ':item_name' => $itemName,
                ':check_in' => $cInDate,
                ':check_out' => $cOutDate,
                ':time_slot' => $timeSlotToSave,
                ':total_amount' => $totalToSave,
                ':special_notes' => $finalNotes,
                ':resched_count' => $nextCount,
                ':id' => $id
            ]);

            if ($oldItemId != $itemId && class_exists('InventoryStore')) {
                $oldRemaining = self::getRemainingUnits($oldItemId, date('Y-m-d'), date('Y-m-d', strtotime('+1 day')));
                if ($oldRemaining > 0) {
                    InventoryStore::updateKuboStatus($oldItemId, 'available');
                }
            }

            return ['success' => true, 'message' => "Booking #{$id} was successfully rescheduled.", 'booking_id' => $id];
        } catch (PDOException $e) {
            error_log("BookingStore::rescheduleBooking error: " . $e->getMessage());
            return ['success' => false, 'error' => "Database error: " . $e->getMessage()];
        }
    }

    /**
     * Submit a customer reschedule request pending admin verification
     */
    public static function requestReschedule($id, $newCheckIn, $newCheckOut, $newTimeSlot = null, $reason = '') {
        self::init();
        $pdo = self::getPdo();
        if (!$pdo) return ['success' => false, 'error' => 'Database connection unavailable'];

        $b = self::getBookingById($id);
        if (!$b) {
            return ['success' => false, 'error' => 'Booking not found'];
        }

        $currCount = intval($b['resched_count'] ?? 0);
        if ($currCount >= 2) {
            return ['success' => false, 'error' => 'This reservation has already reached the maximum limit of 2 reschedules.'];
        }

        if (!empty($b['resched_pending']) || ($b['booking_status'] ?? '') === 'reschedule_requested') {
            return ['success' => false, 'error' => 'A reschedule request is already pending verification for this booking. You cannot reschedule again until it has been approved by admin.'];
        }

        $createdAtTs = !empty($b['created_at']) ? strtotime($b['created_at']) : 0;
        if ($createdAtTs > 0 && (time() - $createdAtTs) > (3 * 86400)) {
            return ['success' => false, 'error' => 'This reservation can no longer be rescheduled because more than 3 days have passed since it was booked. Rescheduling is only permitted within 3 days of booking.'];
        }

        $nextCount = $currCount + 1;
        $cInDate = date('Y-m-d H:i:s', strtotime($newCheckIn));
        $cOutDate = !empty($newCheckOut) ? date('Y-m-d H:i:s', strtotime($newCheckOut)) : date('Y-m-d H:i:s', strtotime($newCheckIn . ' +1 day'));
        $timeSlot = $newTimeSlot ?: ($b['time_slot'] ?? '');
        $now = date('M d, Y g:i A');

        $audit = "[Reschedule Request (Attempt {$nextCount} of 2)] Requested new dates: " . date('M d, Y', strtotime($cInDate)) . " to " . date('M d, Y', strtotime($cOutDate));
        if (!empty($reason)) {
            $audit .= " | Reason: " . $reason;
        }
        $audit .= " (Awaiting Admin Verification on {$now})";

        $existingNotes = trim($b['special_notes'] ?? '');
        $oldInFmt = date('M j', strtotime($b['check_in']));
        $oldOutFmt = !empty($b['check_out']) ? date('j', strtotime($b['check_out'])) : '';
        $oldRangeFmt = $oldOutFmt ? "{$oldInFmt}–{$oldOutFmt}" : $oldInFmt;
        $newInFmt = date('M j', strtotime($cInDate));
        $newOutFmt = !empty($cOutDate) ? date('j', strtotime($cOutDate)) : '';
        $newRangeFmt = $newOutFmt ? "{$newInFmt}–{$newOutFmt}" : $newInFmt;
        $timelineEntry = "[Timeline] {$now} | Reschedule requested (Attempt {$nextCount} of 2) | {$oldRangeFmt} → {$newRangeFmt}";

        $finalNotes = $existingNotes ? ($existingNotes . "\n" . $audit . "\n" . $timelineEntry) : ($audit . "\n" . $timelineEntry);

        try {
            $stmt = $pdo->prepare("
                UPDATE bookings 
                SET resched_pending = 1,
                    booking_status = 'reschedule_requested',
                    resched_check_in = :resched_check_in,
                    resched_check_out = :resched_check_out,
                    resched_time_slot = :resched_time_slot,
                    resched_reason = :resched_reason,
                    resched_created_at = NOW(),
                    special_notes = :special_notes
                WHERE id = :id
            ");
            $stmt->execute([
                ':resched_check_in' => $cInDate,
                ':resched_check_out' => $cOutDate,
                ':resched_time_slot' => $timeSlot,
                ':resched_reason' => $reason,
                ':special_notes' => $finalNotes,
                ':id' => $id
            ]);

            return ['success' => true, 'message' => "Reschedule request submitted for admin verification.", 'booking_id' => $id];
        } catch (PDOException $e) {
            error_log("BookingStore::requestReschedule error: " . $e->getMessage());
            return ['success' => false, 'error' => "Database error: " . $e->getMessage()];
        }
    }

    /**
     * Admin approves and confirms a pending reschedule request
     */
    public static function approveReschedule($id, $approvedBy = 'Admin') {
        self::init();
        $pdo = self::getPdo();
        if (!$pdo) return ['success' => false, 'error' => 'Database connection unavailable'];

        $b = self::getBookingById($id);
        if (!$b) return ['success' => false, 'error' => 'Booking not found'];
        if (empty($b['resched_pending']) || empty($b['resched_check_in'])) {
            return ['success' => false, 'error' => 'No pending reschedule request found for this booking'];
        }

        $newCheckIn = $b['resched_check_in'];
        $newCheckOut = $b['resched_check_out'];
        $newTimeSlot = $b['resched_time_slot'] ?? $b['time_slot'];
        $reason = $b['resched_reason'] ?? '';

        // Format old date range for "Rescheduled from Oct 16–18, 2026"
        $oldDateRange = date('M j, Y', strtotime($b['check_in']));
        if (!empty($b['check_out']) && date('Y-m-d', strtotime($b['check_in'])) !== date('Y-m-d', strtotime($b['check_out']))) {
            if (date('M Y', strtotime($b['check_in'])) === date('M Y', strtotime($b['check_out']))) {
                $oldDateRange = date('M j', strtotime($b['check_in'])) . '–' . date('j, Y', strtotime($b['check_out']));
            } else {
                $oldDateRange = date('M j, Y', strtotime($b['check_in'])) . ' – ' . date('M j, Y', strtotime($b['check_out']));
            }
        }

        // Call rescheduleBooking to apply dates, preserve original total price, and update audit trail
        $res = self::rescheduleBooking(
            $id,
            $newCheckIn,
            $newCheckOut,
            $b['item_id'],
            $b['item_name'],
            $newTimeSlot,
            floatval($b['total_amount']),
            "Admin verified & confirmed reschedule request: " . $reason,
            $approvedBy
        );

        if (!empty($res['success'])) {
            $nowStr = date('M j, Y · g:i A');
            $timelineEntry = "[Timeline] {$nowStr} | Reschedule approved | By: {$approvedBy}";

            try {
                $fresh = self::getBookingById($id);
                $currNotes = trim($fresh['special_notes'] ?? '');
                $updatedNotes = $currNotes ? ($currNotes . "\n" . $timelineEntry) : $timelineEntry;

                $stmt = $pdo->prepare("
                    UPDATE bookings 
                    SET resched_pending = 0,
                        resched_check_in = NULL,
                        resched_check_out = NULL,
                        resched_time_slot = NULL,
                        resched_reason = NULL,
                        resched_created_at = NULL,
                        rescheduled_from = :rescheduled_from,
                        booking_status = 'confirmed',
                        special_notes = :special_notes
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':rescheduled_from' => $oldDateRange,
                    ':special_notes' => $updatedNotes,
                    ':id' => $id
                ]);
            } catch (PDOException $e) {}
            return ['success' => true, 'message' => "Reschedule confirmed successfully by {$approvedBy}."];
        }

        return $res;
    }

    /**
     * Admin rejects/declines a pending reschedule request
     */
    public static function rejectReschedule($id, $declineReason = '', $declinedBy = 'Admin') {
        self::init();
        $pdo = self::getPdo();
        if (!$pdo) return ['success' => false, 'error' => 'Database connection unavailable'];

        $b = self::getBookingById($id);
        if (!$b) return ['success' => false, 'error' => 'Booking not found'];

        $now = date('M j, Y · g:i A');
        $audit = "[Reschedule Declined] Reschedule request declined on {$now} by {$declinedBy}";
        if (!empty($declineReason)) {
            $audit .= " | Reason: " . $declineReason;
        }
        $timelineEntry = "[Timeline] {$now} | Reschedule rejected | By: {$declinedBy}" . ($declineReason ? " (Reason: {$declineReason})" : "");

        $existingNotes = trim($b['special_notes'] ?? '');
        $finalNotes = $existingNotes ? ($existingNotes . "\n" . $audit . "\n" . $timelineEntry) : ($audit . "\n" . $timelineEntry);

        try {
            $stmt = $pdo->prepare("
                UPDATE bookings 
                SET resched_pending = 0,
                    resched_check_in = NULL,
                    resched_check_out = NULL,
                    resched_time_slot = NULL,
                    resched_reason = NULL,
                    resched_created_at = NULL,
                    resched_reject_reason = :reject_reason,
                    booking_status = 'confirmed',
                    special_notes = :special_notes
                WHERE id = :id
            ");
            $stmt->execute([
                ':reject_reason' => $declineReason,
                ':special_notes' => $finalNotes,
                ':id' => $id
            ]);
            return ['success' => true, 'message' => "Reschedule request declined."];
        } catch (PDOException $e) {
            error_log("BookingStore::rejectReschedule error: " . $e->getMessage());
            return ['success' => false, 'error' => "Database error: " . $e->getMessage()];
        }
    }

    /**
     * Delete booking by ID
     */
    public static function deleteBooking($id) {
        self::init();
        $pdo = self::getPdo();

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("DELETE FROM bookings WHERE id = :id");
                return $stmt->execute([':id' => $id]);
            } catch (PDOException $e) {
                error_log("BookingStore::deleteBooking DB error: " . $e->getMessage());
            }
        }
        return false;
    }

    /**
     * Return executive summary KPI statistics
     */
    public static function getStats() {
        $all = self::getAllBookings();
        $total_revenue = 0;
        $pending_count = 0;
        $checked_in_count = 0;
        $gcash_pending_count = 0;
        $gcash_total_count = 0;

        $completed_count = 0;
        $cancelled_count = 0;

        foreach ($all as $b) {
            $b_status = strtolower($b['booking_status'] ?? 'pending');
            $p_status = strtolower($b['payment_status'] ?? 'proof_submitted');

            if ($b_status !== 'cancelled') {
                $total_revenue += floatval($b['total_amount'] ?? 0);
            }
            if ($b_status === 'pending' || $p_status === 'proof_submitted') {
                $pending_count++;
            }
            if (!empty($b['gcash_ref']) || !empty($b['payment_proof']) || in_array($p_status, ['proof_submitted', 'verified'])) {
                $gcash_total_count++;
                if ($p_status === 'proof_submitted' || $b_status === 'pending') {
                    $gcash_pending_count++;
                }
            }
            if ($b_status === 'checked_in') {
                $checked_in_count++;
            }
            if (in_array($b_status, ['checked_out', 'completed'])) {
                $completed_count++;
            }
            if (in_array($b_status, ['cancelled', 'rejected', 'forfeited'])) {
                $cancelled_count++;
            }
        }

        // Include any records in MySQL booking_archive table or fallback JSON
        global $pdo;
        if ($pdo) {
            try {
                $archived = $pdo->query("SELECT * FROM booking_archive")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $existingIds = array_column($all, 'id');
                foreach ($archived as $ab) {
                    if (!in_array($ab['id'] ?? '', $existingIds)) {
                        $st = strtolower($ab['booking_status'] ?? '');
                        if (in_array($st, ['checked_out', 'completed'])) $completed_count++;
                        else $cancelled_count++;
                    }
                }
            } catch (Exception $e) {}
        }
        $archive_file = __DIR__ . '/data/booking_archive.json';
        if (file_exists($archive_file)) {
            $archived = json_decode(@file_get_contents($archive_file), true) ?: [];
            $existingIds = array_column($all, 'id');
            foreach ($archived as $ab) {
                if (!in_array($ab['id'] ?? '', $existingIds)) {
                    $st = strtolower($ab['booking_status'] ?? '');
                    if (in_array($st, ['checked_out', 'completed'])) $completed_count++;
                    else $cancelled_count++;
                }
            }
        }

        $active_count = 0;
        foreach ($all as $b) {
            $b_status = strtolower($b['booking_status'] ?? 'pending');
            if (!in_array($b_status, ['checked_out', 'completed', 'cancelled', 'rejected', 'forfeited'])) {
                $active_count++;
            }
        }

        return [
            'total_bookings' => count($all),
            'active_bookings' => $active_count,
            'active_count' => $active_count,
            'total_revenue' => $total_revenue,
            'pending_count' => $pending_count,
            'checked_in_count' => $checked_in_count,
            'completed_count' => $completed_count,
            'cancelled_count' => $cancelled_count,
            'history_count' => $completed_count + $cancelled_count,
            'gcash_pending_count' => $gcash_pending_count,
            'gcash_total_count' => $gcash_total_count
        ];
    }

    /**
     * Return monthly revenue performance for a given year (12 months array)
     */
    public static function getMonthlyRevenue($year = null) {
        if ($year === null) {
            $year = intval(date('Y'));
        }
        $all = self::getAllBookings();
        $monthly = [
            1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0,
            5 => 0.0, 6 => 0.0, 7 => 0.0, 8 => 0.0,
            9 => 0.0, 10 => 0.0, 11 => 0.0, 12 => 0.0
        ];

        foreach ($all as $b) {
            $b_status = strtolower($b['booking_status'] ?? '');
            if (in_array($b_status, ['cancelled', 'rejected', 'forfeited'])) {
                continue;
            }
            $dateStr = !empty($b['check_in']) ? $b['check_in'] : ($b['created_at'] ?? '');
            if (!empty($dateStr)) {
                $t = strtotime($dateStr);
                if ($t && intval(date('Y', $t)) === intval($year)) {
                    $m = intval(date('n', $t));
                    if (isset($monthly[$m])) {
                        $monthly[$m] += floatval($b['total_amount'] ?? 0);
                    }
                }
            }
        }

        return $monthly;
    }

    /**
     * Get all active booked ranges for a specific item (house kubo or activity)
     * Active bookings = status NOT IN ('cancelled', 'rejected')
     */
    public static function getBookedRangesForItem($itemId, $type = 'house') {
        $all = self::getAllBookings(['type' => $type]);
        $ranges = [];
        $itemId = intval($itemId);

        foreach ($all as $b) {
            if (intval($b['item_id'] ?? 0) !== $itemId) continue;
            $status = strtolower(trim($b['booking_status'] ?? 'pending'));
            if (in_array($status, ['cancelled', 'rejected', 'checked_out'])) continue;

            $rawIn = $b['check_in'] ?? '';
            $rawOut = $b['check_out'] ?? '';
            if (empty($rawIn)) continue;
            $cIn = date('Y-m-d', strtotime($rawIn));
            $cOut = !empty($rawOut) ? date('Y-m-d', strtotime($rawOut)) : date('Y-m-d', strtotime($cIn . ' +1 day'));
            if ($cOut <= $cIn) {
                $cOut = date('Y-m-d', strtotime($cIn . ' +1 day'));
            }

            $ranges[] = [
                'start' => $cIn,
                'end' => $cOut,
                'id' => $b['id'] ?? '',
                'guest_name' => $b['guest_name'] ?? 'Guest',
                'status' => $status
            ];
        }
        return $ranges;
    }

    /**
     * Get array of individual occupied night dates ('YYYY-MM-DD') for a specific item
     */
    public static function getBookedDatesForItem($itemId, $type = 'house') {
        $itemId = intval($itemId);
        if ($type === 'house') {
            $units = self::getKuboUnits($itemId);
            if (class_exists('InventoryStore')) {
                $kubo = InventoryStore::getKuboById($itemId);
                if ($kubo) {
                    $st = strtolower(trim($kubo['status'] ?? 'available'));
                    if (in_array($st, ['renovation', 'maintenance', 'unavailable']) || $units <= 0) {
                        // All dates are blocked because 0 units are available
                        $dates = [];
                        $today = strtotime(date('Y-m-d'));
                        for ($i = 0; $i < 365; $i++) {
                            $dates[] = date('Y-m-d', strtotime("+$i days", $today));
                        }
                        return $dates;
                    }
                }
            }
        } else {
            $units = 1;
        }

        $ranges = self::getBookedRangesForItem($itemId, $type);
        $counts = [];

        foreach ($ranges as $r) {
            $start = strtotime($r['start']);
            $end = strtotime($r['end']);
            if (!$start || !$end) continue;

            // Overnight stay occupying [start, end) means each night from start up to end-1 day is occupied
            for ($curr = $start; $curr < $end; $curr = strtotime('+1 day', $curr)) {
                $d = date('Y-m-d', $curr);
                $counts[$d] = ($counts[$d] ?? 0) + 1;
            }
        }

        // A date is only blocked once every unit of the kubo is taken (0 units remaining)
        $dates = [];
        foreach ($counts as $d => $c) {
            if ($units <= 0 || $c >= $units) $dates[] = $d;
        }
        sort($dates);
        return $dates;
    }

    /**
     * Total number of units configured for a house kubo.
     * Returns 0 if under maintenance, renovation, or configured with 0 units.
     */
    public static function getKuboUnits($kuboId) {
        if (class_exists('InventoryStore')) {
            $k = InventoryStore::getKuboById(intval($kuboId));
            if ($k) {
                $st = strtolower(trim($k['status'] ?? 'available'));
                if (in_array($st, ['renovation', 'maintenance', 'unavailable'])) {
                    return 0;
                }
                if (isset($k['available_units'])) {
                    return max(0, intval($k['available_units']));
                }
            }
        }
        return 0;
    }

    /**
     * Units still free for a kubo across the given stay (total units - max overlapping bookings on any night).
     * Strictly returns 0 if kubo is unavailable, in renovation/maintenance, or has 0 units remaining.
     */
    public static function getRemainingUnits($itemId, $checkIn, $checkOut, $excludeBookingId = null) {
        $itemId = intval($itemId);
        if (class_exists('InventoryStore')) {
            $kubo = InventoryStore::getKuboById($itemId);
            if ($kubo) {
                $st = strtolower(trim($kubo['status'] ?? 'available'));
                if (in_array($st, ['renovation', 'maintenance', 'unavailable'])) {
                    return 0;
                }
                if (isset($kubo['available_units']) && intval($kubo['available_units']) <= 0) {
                    return 0;
                }
            }
        }
        $total = self::getKuboUnits($itemId);
        if ($total <= 0) return 0;

        if (empty($checkIn)) return $total;
        $checkIn = date('Y-m-d', strtotime($checkIn));
        if (empty($checkOut) || $checkOut <= $checkIn) {
            $checkOut = date('Y-m-d', strtotime($checkIn . ' +1 day'));
        } else {
            $checkOut = date('Y-m-d', strtotime($checkOut));
        }

        $perNight = [];
        foreach (self::getBookedRangesForItem($itemId, 'house') as $r) {
            if ($excludeBookingId && ($r['id'] ?? '') === $excludeBookingId) continue;
            if (!($checkIn < $r['end'] && $checkOut > $r['start'])) continue;
            $s = max(strtotime($checkIn), strtotime($r['start']));
            $e = min(strtotime($checkOut), strtotime($r['end']));
            for ($c = $s; $c < $e; $c = strtotime('+1 day', $c)) {
                $d = date('Y-m-d', $c);
                $perNight[$d] = ($perNight[$d] ?? 0) + 1;
            }
        }
        $max = empty($perNight) ? 0 : max($perNight);
        return max(0, $total - $max);
    }

    /**
     * Check if a specific item is available for the given check_in and check_out dates.
     * Returns true if available, false if there is an overlapping active reservation or 0 units remaining.
     */
    public static function isItemAvailable($itemId, $checkIn, $checkOut, $type = 'house', $excludeBookingId = null) {
        if (empty($checkIn)) return true;
        $checkIn = date('Y-m-d', strtotime($checkIn));
        if (empty($checkOut) || $checkOut <= $checkIn) {
            $checkOut = date('Y-m-d', strtotime($checkIn . ' +1 day'));
        } else {
            $checkOut = date('Y-m-d', strtotime($checkOut));
        }

        // House kubos can have several units: available strictly while at least one unit is free
        if ($type === 'house') {
            return self::getRemainingUnits($itemId, $checkIn, $checkOut, $excludeBookingId) > 0;
        }
        $all = self::getAllBookings(['type' => $type]);
        $itemId = intval($itemId);

        foreach ($all as $b) {
            if (intval($b['item_id'] ?? 0) !== $itemId) continue;
            if ($excludeBookingId && ($b['id'] ?? '') === $excludeBookingId) continue;

            $status = strtolower(trim($b['booking_status'] ?? 'pending'));
            if (in_array($status, ['cancelled', 'rejected', 'checked_out'])) continue;

            $rawIn = $b['check_in'] ?? '';
            $rawOut = !empty($b['check_out']) ? $b['check_out'] : date('Y-m-d', strtotime($rawIn . ' +1 day'));
            if (empty($rawIn)) continue;
            $bIn = date('Y-m-d', strtotime($rawIn));
            $bOut = date('Y-m-d', strtotime($rawOut));
            if ($bOut <= $bIn) {
                $bOut = date('Y-m-d', strtotime($bIn . ' +1 day'));
            }

            // Overlap condition for stay intervals [checkIn, checkOut) and [bIn, bOut):
            // (checkIn < bOut) AND (checkOut > bIn)
            if ($checkIn < $bOut && $checkOut > $bIn) {
                return false; // Conflict found! Room is already booked.
            }
        }
        return true; // No conflict, room is available.
    }

    /**
     * Check how many of an activity are available for a given date and time.
     */
    public static function getActivityAvailability($activityId, $date, $startTime, $endTime) {
        $pdo = self::getPdo();
        if (!$pdo) return 0;
        
        $activityId = intval($activityId);
        
        // 1. Get capacity and type
        $stmt = $pdo->prepare("SELECT capacity, activity_type FROM activities WHERE activity_id = ?");
        $stmt->execute([$activityId]);
        $activity = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$activity) return 0;
        
        $capacity = intval($activity['capacity'] ?? 100);
        $type = $activity['activity_type'] ?? 'full_day';
        
        // 2. Calculate already reserved quantity
        $reserved = 0;
        
        if ($type === 'full_day') {
            // Full-day: match by date portion of check_in datetime
            $stmt = $pdo->prepare("SELECT SUM(pax) as total_reserved FROM bookings WHERE type = 'activity' AND item_id = ? AND DATE(check_in) = ? AND booking_status NOT IN ('cancelled', 'rejected')");
            $stmt->execute([$activityId, $date]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            $reserved = intval($res['total_reserved'] ?? 0);

            // addon_reservations may not exist yet — skip safely
            try {
                $stmt2 = $pdo->prepare("SELECT SUM(quantity) as addon_reserved FROM addon_reservations WHERE activity_id = ? AND activity_date = ?");
                $stmt2->execute([$activityId, $date]);
                $res2 = $stmt2->fetch(PDO::FETCH_ASSOC);
                $reserved += intval($res2['addon_reserved'] ?? 0);
            } catch (PDOException $e) {
                // Table doesn't exist yet, ignore
            }
        } else {
            // Time-based: check overlapping
            // existing_start < requested_end AND existing_end > requested_start
            // Time-based: use TIME(check_in)/TIME(check_out) — the bookings table
            // stores time inside the check_in/check_out datetime columns.
            // Overlap: existing_start < requested_end AND existing_end > requested_start
            $stmt = $pdo->prepare("
                SELECT SUM(pax) as total_reserved 
                FROM bookings 
                WHERE type = 'activity' 
                AND item_id = ? 
                AND DATE(check_in) = ? 
                AND booking_status NOT IN ('cancelled', 'rejected') 
                AND TIME(check_in) < ? 
                AND TIME(check_out) > ?
            ");
            $stmt->execute([$activityId, $date, $endTime, $startTime]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            $reserved = intval($res['total_reserved'] ?? 0);

            // addon_reservations may not exist yet — skip safely
            try {
                $stmt2 = $pdo->prepare("
                    SELECT SUM(quantity) as addon_reserved 
                    FROM addon_reservations 
                    WHERE activity_id = ? 
                    AND activity_date = ? 
                    AND start_time < ? 
                    AND end_time > ?
                ");
                $stmt2->execute([$activityId, $date, $endTime, $startTime]);
                $res2 = $stmt2->fetch(PDO::FETCH_ASSOC);
                $reserved += intval($res2['addon_reserved'] ?? 0);
            } catch (PDOException $e) {
                // Table doesn't exist yet, ignore
            }
        }
        
        return max(0, $capacity - $reserved);
    }
}
