<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';

class ReviewStore {
    /**
     * Initial Seed Reviews
     */
    private static function getDefaultReviews() {
        return [
            [
                'id' => 1,
                'guest' => 'Vince Gian Ildefonso',
                'guest_id' => 201,
                'profile_id' => 'PBR-GST-00201',
                'booking_id' => null,
                'item' => 'Beach Kubo',
                'rating' => 5,
                'date' => 'September 10, 2026',
                'created_at' => '2026-09-10 14:30:00',
                'comment' => 'Outstanding resort stay! The Mahogany Kubo was clean and comfortable, the pool water was crystal clear, and the online GCash payment was verified in minutes.',
                'staff_reply' => 'Mabuhay Vince Gian! Thank you so much for staying with us at The Blessed Purple Bamboo Resort. We are delighted you enjoyed the kubo and pool, and we look forward to welcoming you back!',
                'staff_reply_by' => 'Super Admin',
                'staff_reply_at' => 'September 10, 2026 4:15 PM',
                'is_published' => 1
            ],
            [
                'id' => 2,
                'guest' => 'Iuhence Marquez',
                'guest_id' => 202,
                'profile_id' => 'PBR-GST-00202',
                'booking_id' => null,
                'item' => 'Orchid Honeymoon Kubo #2',
                'rating' => 5,
                'date' => 'September 14, 2026',
                'created_at' => '2026-09-14 11:20:00',
                'comment' => 'Very relaxing tropical vibe right beside the swimming pool. Staff were very accommodating and assisted us promptly with dining orders.',
                'staff_reply' => null,
                'staff_reply_by' => null,
                'staff_reply_at' => null,
                'is_published' => 1
            ],
            [
                'id' => 3,
                'guest' => 'Maria Santos',
                'guest_id' => null,
                'profile_id' => null,
                'booking_id' => null,
                'item' => 'Royal Purple Pavilion #3',
                'rating' => 4,
                'date' => 'September 16, 2026',
                'created_at' => '2026-09-16 09:45:00',
                'comment' => 'Perfect venue for our family get-together. The BBQ grill, spacious bamboo pavilion, and karaoke system were great. Keep up the good work!',
                'staff_reply' => 'Thank you Maria! We appreciate your wonderful feedback and hope your family had a memorable reunion with us.',
                'staff_reply_by' => 'Front Desk Staff',
                'staff_reply_at' => 'September 16, 2026 1:00 PM',
                'is_published' => 1
            ]
        ];
    }

    /**
     * Load all reviews directly from MySQL
     */
    public static function getAll($search = '', $filter = 'all') {
        self::init();
        global $pdo;

        $reviews = [];
        if ($pdo) {
            try {
                $sql = "SELECT review_id AS id, guest, user_name, guest_id, profile_id, booking_id, item, rating, comment, staff_reply, staff_reply_by, staff_reply_at, is_published, created_at FROM reviews WHERE 1=1";
                $params = [];

                if ($filter === 'pending_reply') {
                    $sql .= " AND (staff_reply IS NULL OR staff_reply = '')";
                } elseif ($filter === 'replied') {
                    $sql .= " AND (staff_reply IS NOT NULL AND staff_reply != '')";
                }

                if (!empty($search)) {
                    $sql .= " AND (guest LIKE :q OR item LIKE :q OR comment LIKE :q OR staff_reply LIKE :q)";
                    $params[':q'] = "%$search%";
                }

                $sql .= " ORDER BY review_id DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as $r) {
                    $guest = !empty($r['guest']) ? $r['guest'] : (!empty($r['user_name']) ? $r['user_name'] : 'Resort Guest');
                    $reviews[] = [
                        'id' => (int)$r['id'],
                        'guest' => $guest,
                        'guest_id' => $r['guest_id'],
                        'profile_id' => $r['profile_id'],
                        'booking_id' => $r['booking_id'],
                        'item' => !empty($r['item']) ? $r['item'] : 'General Resort Experience',
                        'rating' => (int)$r['rating'],
                        'date' => !empty($r['created_at']) ? date('F j, Y', strtotime($r['created_at'])) : date('F j, Y'),
                        'created_at' => $r['created_at'],
                        'comment' => $r['comment'],
                        'staff_reply' => $r['staff_reply'],
                        'staff_reply_by' => $r['staff_reply_by'],
                        'staff_reply_at' => $r['staff_reply_at'],
                        'is_published' => (int)($r['is_published'] ?? 1)
                    ];
                }
                return $reviews;
            } catch (Exception $e) {
                error_log("ReviewStore::getAll DB error: " . $e->getMessage());
            }
        }

        return self::getDefaultReviews();
    }

    /**
     * Get bookings actually experienced by a user
     * A booking is experienced if:
     * 1. Belongs to the user (user_id, profile_id, or guest_email).
     * 2. Not cancelled, rejected, or forfeited.
     * 3. booking_status is 'completed', 'checked_out', 'checked_in',
     *    OR payment_status is 'verified' and check_out date (or check_in date) has passed.
     */
    public static function getUserExperiencedBookings($user_id, $profile_id = null, $user_email = null) {
        self::init();
        global $pdo;
        if (!$pdo) {
            return [];
        }

        try {
            $user_id_val = !empty($user_id) ? (int)$user_id : null;
            $profile_id_val = !empty($profile_id) ? trim($profile_id) : null;
            $email_val = !empty($user_email) ? strtolower(trim($user_email)) : null;

            if (empty($user_id_val) && empty($profile_id_val) && empty($email_val)) {
                return [];
            }

            $sql = "
                SELECT id, guest_name, guest_email, user_id, profile_id, type, item_id, item_name,
                       check_in, check_out, time_slot, booking_status, payment_status, created_at
                FROM bookings
                WHERE (
                    (:uid IS NOT NULL AND user_id = :uid)
                    OR (:pid IS NOT NULL AND profile_id = :pid)
                    OR (:email IS NOT NULL AND LOWER(TRIM(guest_email)) = :email)
                )
                AND booking_status NOT IN ('cancelled', 'rejected', 'forfeited')
                AND (is_forfeited IS NULL OR is_forfeited = 0)
                AND (
                    booking_status IN ('completed', 'checked_out', 'checked_in')
                    OR (payment_status = 'verified' AND check_out IS NOT NULL AND check_out <= NOW())
                    OR (payment_status = 'verified' AND check_in <= NOW())
                )
                ORDER BY COALESCE(check_out, check_in) DESC, created_at DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':uid' => $user_id_val,
                ':pid' => $profile_id_val,
                ':email' => $email_val
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("ReviewStore::getUserExperiencedBookings error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get list of booking_ids already reviewed
     */
    public static function getReviewedBookingIdsForUser($user_id = null, $profile_id = null, $user_email = null) {
        self::init();
        global $pdo;
        if (!$pdo) {
            return [];
        }

        try {
            $sql = "SELECT DISTINCT booking_id FROM reviews WHERE booking_id IS NOT NULL AND booking_id != ''";
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (Exception $e) {
            error_log("ReviewStore::getReviewedBookingIdsForUser error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if a user can review a specific booking
     */
    public static function canUserReviewBooking($booking_id, $user_id, $profile_id = null, $user_email = null) {
        $booking_id = trim($booking_id);
        if (empty($booking_id)) {
            return ['allowed' => false, 'error' => 'Please select a valid reservation to review.'];
        }

        $experienced = self::getUserExperiencedBookings($user_id, $profile_id, $user_email);
        $found = null;
        foreach ($experienced as $b) {
            if ($b['id'] === $booking_id) {
                $found = $b;
                break;
            }
        }

        if (!$found) {
            return ['allowed' => false, 'error' => 'You can only leave reviews for reservations or accommodations you have actually experienced at our resort.'];
        }

        $reviewedIds = self::getReviewedBookingIdsForUser($user_id, $profile_id, $user_email);
        if (in_array($booking_id, $reviewedIds)) {
            return ['allowed' => false, 'error' => 'You have already submitted a review for this booking.'];
        }

        return ['allowed' => true, 'booking' => $found];
    }

    /**
     * Add new review from guest into MySQL with verified stay check
     */
    public static function addReview($data) {
        self::init();
        global $pdo;

        $guest_name = trim($data['guest'] ?? '');
        if (empty($guest_name)) {
            $guest_name = $_SESSION['full_name'] ?? 'Resort Guest';
        }

        $guest_id = $_SESSION['user_id'] ?? ($data['guest_id'] ?? null);
        $profile_id = $_SESSION['profile_id'] ?? ($data['profile_id'] ?? null);
        $user_email = $_SESSION['email'] ?? ($data['user_email'] ?? null);
        $booking_id = trim($data['booking_id'] ?? '');

        // Verify experienced stay requirement
        if (empty($booking_id)) {
            return ['error' => 'Please select an experienced reservation to review. Reviews are not open for unverified accommodations.'];
        }

        $check = self::canUserReviewBooking($booking_id, $guest_id, $profile_id, $user_email);
        if (!$check['allowed']) {
            return ['error' => $check['error']];
        }

        $booking = $check['booking'];
        // Use genuine accommodation name from booking
        $item_name = !empty($booking['item_name']) ? trim($booking['item_name']) : trim($data['item'] ?? 'Purple Bamboo Stay');

        $rating = max(1, min(5, (int)($data['rating'] ?? 5)));
        $comment = trim($data['comment'] ?? '');
        $now = date('Y-m-d H:i:s');

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO reviews (booking_id, user_name, guest, guest_id, profile_id, item, rating, comment, is_approved, is_published, created_at)
                    VALUES (:bid, :uname, :guest, :gid, :pid, :item, :rating, :comment, 1, 1, :created_at)
                ");
                $stmt->execute([
                    ':bid' => $booking_id,
                    ':uname' => $guest_name,
                    ':guest' => $guest_name,
                    ':gid' => $guest_id,
                    ':pid' => $profile_id,
                    ':item' => $item_name,
                    ':rating' => $rating,
                    ':comment' => $comment,
                    ':created_at' => $now
                ]);
                $new_id = (int)$pdo->lastInsertId();

                if (function_exists('log_activity')) {
                    log_activity("REVIEW_POSTED", "New {$rating}-star verified stay review submitted by {$guest_name} for {$item_name} (Booking #{$booking_id})");
                }

                return [
                    'id' => $new_id,
                    'guest' => $guest_name,
                    'guest_id' => $guest_id,
                    'profile_id' => $profile_id,
                    'booking_id' => $booking_id,
                    'item' => $item_name,
                    'rating' => $rating,
                    'date' => date('F j, Y'),
                    'created_at' => $now,
                    'comment' => $comment,
                    'staff_reply' => null,
                    'staff_reply_by' => null,
                    'staff_reply_at' => null,
                    'is_published' => 1
                ];
            } catch (Exception $e) {
                error_log("ReviewStore::addReview error: " . $e->getMessage());
                return ['error' => 'Database error while saving your review. Please try again.'];
            }
        }

        return ['error' => 'Database connection unavailable.'];
    }

    /**
     * Add / update staff reply to review in MySQL
     */
    public static function addReply($review_id, $reply_text, $reply_by = null) {
        self::init();
        global $pdo;

        if (empty($reply_by)) {
            $reply_by = $_SESSION['full_name'] ?? 'Front Desk Team';
        }

        $reply_text = trim($reply_text);
        if (empty($reply_text)) {
            return false;
        }

        $reply_date = date('F j, Y g:i A');

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE reviews 
                    SET staff_reply = :reply, staff_reply_by = :by, staff_reply_at = :at
                    WHERE review_id = :id
                ");
                $stmt->execute([
                    ':reply' => $reply_text,
                    ':by' => $reply_by,
                    ':at' => $reply_date,
                    ':id' => $review_id
                ]);

                if (function_exists('log_activity')) {
                    log_activity("REVIEW_REPLIED", "Staff {$reply_by} replied to review #{$review_id}");
                }
                return true;
            } catch (Exception $e) {
                error_log("ReviewStore::addReply error: " . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * Delete review in MySQL
     */
    public static function deleteReview($review_id) {
        self::init();
        global $pdo;

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("DELETE FROM reviews WHERE review_id = ?");
                $stmt->execute([$review_id]);
                return true;
            } catch (Exception $e) {
                error_log("ReviewStore::deleteReview error: " . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * Calculate summary statistics
     */
    public static function getStats() {
        $reviews = self::getAll();
        $total = count($reviews);
        $total_rating = 0;
        $with_replies = 0;
        $stars = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

        foreach ($reviews as $r) {
            $rating = (int)($r['rating'] ?? 5);
            $total_rating += $rating;
            if (isset($stars[$rating])) {
                $stars[$rating]++;
            }
            if (!empty($r['staff_reply'])) {
                $with_replies++;
            }
        }

        $avg = $total > 0 ? round($total_rating / $total, 1) : 0;

        return [
            'total_reviews' => $total,
            'avg_rating' => $avg,
            'with_replies' => $with_replies,
            'pending_replies' => max(0, $total - $with_replies),
            'star_counts' => $stars
        ];
    }

    /**
     * Ensure reviews table exists in MySQL
     */
    private static function init() {
        global $pdo;
        if ($pdo) {
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS reviews (
                        review_id INT AUTO_INCREMENT PRIMARY KEY,
                        booking_id VARCHAR(50) NULL,
                        user_name VARCHAR(100) NOT NULL,
                        guest VARCHAR(100) NULL,
                        guest_id INT NULL,
                        profile_id VARCHAR(50) NULL,
                        item VARCHAR(100) NULL,
                        rating INT DEFAULT 5,
                        comment TEXT NOT NULL,
                        staff_reply TEXT NULL,
                        staff_reply_by VARCHAR(100) NULL,
                        staff_reply_at VARCHAR(50) NULL,
                        is_approved TINYINT(1) DEFAULT 1,
                        is_published TINYINT(1) DEFAULT 1,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
            } catch (Exception $e) {}
        }
    }
}
