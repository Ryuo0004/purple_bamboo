<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';

class ReviewStore {
    private static $json_file = __DIR__ . '/data/reviews.json';

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
     * Load all reviews from JSON and sync MySQL
     */
    public static function getAll($search = '', $filter = 'all') {
        self::init();

        $reviews = [];
        if (file_exists(self::$json_file)) {
            $content = file_get_contents(self::$json_file);
            $reviews = json_decode($content, true) ?: [];
        }

        if (empty($reviews)) {
            $reviews = self::getDefaultReviews();
            self::saveToFile($reviews);
        }

        // Filter by status (all, pending_reply, replied)
        if ($filter === 'pending_reply') {
            $reviews = array_filter($reviews, function($r) {
                return empty($r['staff_reply']);
            });
        } elseif ($filter === 'replied') {
            $reviews = array_filter($reviews, function($r) {
                return !empty($r['staff_reply']);
            });
        }

        // Search keyword filter
        if (!empty($search)) {
            $search_lower = strtolower(trim($search));
            $reviews = array_filter($reviews, function($r) use ($search_lower) {
                return (
                    strpos(strtolower($r['guest'] ?? ''), $search_lower) !== false ||
                    strpos(strtolower($r['item'] ?? ''), $search_lower) !== false ||
                    strpos(strtolower($r['comment'] ?? ''), $search_lower) !== false ||
                    strpos(strtolower($r['staff_reply'] ?? ''), $search_lower) !== false
                );
            });
        }

        // Sort descending by ID / created_at (newest first)
        usort($reviews, function($a, $b) {
            $tA = strtotime($a['created_at'] ?? '') ?: ($a['id'] ?? 0);
            $tB = strtotime($b['created_at'] ?? '') ?: ($b['id'] ?? 0);
            return $tB <=> $tA;
        });

        return array_values($reviews);
    }

    /**
     * Add new review from guest
     */
    public static function addReview($data) {
        self::init();
        $reviews = self::getAll();

        $max_id = 0;
        foreach ($reviews as $r) {
            if (isset($r['id']) && $r['id'] > $max_id) {
                $max_id = (int)$r['id'];
            }
        }
        $new_id = $max_id + 1;

        $guest_name = trim($data['guest'] ?? '');
        if (empty($guest_name)) {
            $guest_name = $_SESSION['full_name'] ?? 'Resort Guest';
        }

        $item_name = trim($data['item'] ?? 'General Resort Experience');
        $rating = max(1, min(5, (int)($data['rating'] ?? 5)));
        $comment = trim($data['comment'] ?? '');
        $date_str = date('F j, Y');
        $now_str = date('Y-m-d H:i:s');
        $guest_id = $_SESSION['user_id'] ?? ($data['guest_id'] ?? null);
        $profile_id = $_SESSION['profile_id'] ?? ($data['profile_id'] ?? null);
        $booking_id = $data['booking_id'] ?? null;

        $new_review = [
            'id' => $new_id,
            'guest' => $guest_name,
            'guest_id' => $guest_id,
            'profile_id' => $profile_id,
            'booking_id' => $booking_id,
            'item' => $item_name,
            'rating' => $rating,
            'date' => $date_str,
            'created_at' => $now_str,
            'comment' => $comment,
            'staff_reply' => null,
            'staff_reply_by' => null,
            'staff_reply_at' => null,
            'is_published' => 1
        ];

        array_unshift($reviews, $new_review);
        self::saveToFile($reviews);

        // Sync to MySQL
        global $pdo;
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("INSERT INTO reviews 
                    (guest_id, guest_name, item_name, rating, comment, is_published, created_at) 
                    VALUES (?, ?, ?, ?, ?, 1, NOW())");
                $stmt->execute([
                    $guest_id,
                    $guest_name,
                    $item_name,
                    $rating,
                    $comment
                ]);
            } catch (Exception $e) {
                error_log("ReviewStore MySQL insert error: " . $e->getMessage());
            }
        }

        return $new_review;
    }

    /**
     * Staff/Admin Reply to Review
     */
    public static function addReply($review_id, $reply_text, $reply_by = null) {
        self::init();
        $reviews = self::getAll();

        if (empty($reply_by)) {
            $reply_by = $_SESSION['full_name'] ?? 'Resort Management';
        }

        $reply_text = trim($reply_text);
        if (empty($reply_text)) {
            return false;
        }

        $reply_date = date('F j, Y g:i A');
        $updated = false;

        foreach ($reviews as &$r) {
            if ((int)$r['id'] === (int)$review_id) {
                $r['staff_reply'] = $reply_text;
                $r['staff_reply_by'] = $reply_by;
                $r['staff_reply_at'] = $reply_date;
                $updated = true;
                break;
            }
        }

        if ($updated) {
            self::saveToFile($reviews);

            // Sync to MySQL
            global $pdo;
            if ($pdo) {
                try {
                    $stmt = $pdo->prepare("UPDATE reviews SET 
                        staff_reply = ?, 
                        staff_reply_by = ?, 
                        staff_reply_at = NOW() 
                        WHERE review_id = ? OR (guest_name = ? AND comment = ?)");
                    $stmt->execute([
                        $reply_text,
                        $reply_by,
                        $review_id,
                        $r['guest'] ?? '',
                        $r['comment'] ?? ''
                    ]);
                } catch (Exception $e) {
                    error_log("ReviewStore MySQL reply error: " . $e->getMessage());
                }
            }
        }

        return $updated;
    }

    /**
     * Delete review
     */
    public static function deleteReview($review_id) {
        self::init();
        $reviews = self::getAll();
        $filtered = [];

        foreach ($reviews as $r) {
            if ((int)$r['id'] !== (int)$review_id) {
                $filtered[] = $r;
            }
        }

        self::saveToFile($filtered);

        // Sync to MySQL
        global $pdo;
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("DELETE FROM reviews WHERE review_id = ?");
                $stmt->execute([$review_id]);
            } catch (Exception $e) {
                error_log("ReviewStore MySQL delete error: " . $e->getMessage());
            }
        }

        return true;
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
     * Ensure storage directory & JSON file exist
     */
    private static function init() {
        $dir = dirname(self::$json_file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        if (!file_exists(self::$json_file)) {
            self::saveToFile(self::getDefaultReviews());
        }
    }

    /**
     * Write reviews to data/reviews.json
     */
    private static function saveToFile($reviews) {
        file_put_contents(self::$json_file, json_encode(array_values($reviews), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
