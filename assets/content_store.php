<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';

class ContentStore {
    private static $json_file = __DIR__ . '/data/homepage_content.json';

    /**
     * Default Fallback Content for Purple Bamboo Resort Homepage
     */
    public static function getDefaults() {
        return [
            // 1. Hero Canvas Banner
            'hero_badge' => '🌟 Authentic Tropical Resort Sanctuary',
            'hero_title' => 'Welcome to The Blessed Purple Bamboo Resort',
            'hero_subtitle' => 'Escape to authentic Filipino eco-hospitality in Alaminos City, Pangasinan. Experience traditional House Kubos, crystal-clear infinity pool passes, clear lagoon kayaking, and secure online GCash reservations.',
            'hero_btn1_text' => 'Explore House Kubos',
            'hero_btn1_url' => 'browse_kubos.php',
            'hero_btn2_text' => 'Pool & Activities',
            'hero_btn2_url' => 'browse_activities.php',
            'hero_pill1' => 'Instant GCash Verification',
            'hero_pill2' => 'Air-Conditioned Kubos',
            'hero_pill3' => '4.9 ★ Guest Rating (Verified Stay)',

            // 2. Features Strip (4 cards)
            'features' => [
                [
                    'icon' => 'fa-house-chimney',
                    'color' => '#7c3aed',
                    'title' => 'Aircon Bamboo Kubos',
                    'desc' => 'Private verandas & smart TV'
                ],
                [
                    'icon' => 'fa-person-swimming',
                    'color' => '#7c3aed',
                    'title' => 'Infinity Pool Slides',
                    'desc' => 'Adult & kids water park passes'
                ],
                [
                    'icon' => 'fa-water',
                    'color' => '#059669',
                    'title' => 'Clear Lagoon Kayaking',
                    'desc' => 'Transparent tandem kayaks'
                ],
                [
                    'icon' => 'fa-spa',
                    'color' => '#e11d48',
                    'title' => 'Bamboo Spa & Sauna',
                    'desc' => 'Warm aromatherapy massages'
                ]
            ],

            // 3. Section Titles & Subtitles
            'kubos_section_title' => 'Featured House Kubos',
            'kubos_section_subtitle' => 'Designed with eco-friendly bamboo aesthetics and modern air-conditioned comforts.',
            'activities_section_title' => 'Pool Resort Activities',
            'activities_section_subtitle' => 'Enjoy our infinity pools, clear kayaking, lagoon paddle boarding, and tropical bamboo spa massages.',
            'reviews_section_title' => 'Guest Reviews & Ratings',
            'reviews_section_subtitle' => 'Read what our verified guests say about their stay at Purple Bamboo Resort.',

            // 4. Guarantee / Booking Call To Action Banner
            'guarantee_badge' => 'Safe & Instant Guarantee',
            'guarantee_title' => 'Book Your Stay with Instant GCash Verification',
            'guarantee_subtitle' => 'Get an instant booking reference number immediately upon payment. No waiting time needed!',
            'guarantee_btn_text' => 'Check Dates & Reserve Now',
            'guarantee_btn_url' => 'check_availability.php',

            // 5. Resort Info, Contact & GCash Payment Settings
            'resort_location' => 'Alaminos City, Pangasinan',
            'resort_phone' => '0956 697 8679',
            'gcash_name' => 'PURPLE BAMBOO RESORT',
            'gcash_number' => '0956 697 8679',
            'gcash_qr_image' => 'assets/gcash_qr.jpg',
            'gcash_show_on_home' => false,
            'gcash_instructions' => 'Scan the QR code using your GCash or any InstaPay banking app, or transfer directly to 0956 697 8679. Keep your 13-digit reference number for instant booking confirmation.',
            'gcash_card_title' => 'Official GCash Payment Channel',
            'gcash_card_subtitle' => 'Scan with GCash or InstaPay for Instant Reservation Verification',

            // 6. Optional Top Announcement Bar
            'announcement_enabled' => false,
            'announcement_text' => 'Welcome to The Blessed Purple Bamboo Resort! Book online now with instant GCash verification.',
            'announcement_link' => 'browse_kubos.php',

            // Metadata
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => 'Default Setup'
        ];
    }

    /**
     * Initialize JSON file and database table if needed
     */
    private static function init() {
        $dir = dirname(self::$json_file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        if (!file_exists(self::$json_file)) {
            file_put_contents(self::$json_file, json_encode(self::getDefaults(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        global $pdo;
        if ($pdo) {
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS site_content (
                        content_key VARCHAR(50) PRIMARY KEY,
                        content_value LONGTEXT NOT NULL,
                        updated_by VARCHAR(100) NULL,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
            } catch (PDOException $e) {
                // Ignore DB error, file fallback active
            }
        }
    }

    /**
     * Get all homepage content
     */
    public static function getContent() {
        self::init();

        $defaults = self::getDefaults();
        $saved = [];

        if (file_exists(self::$json_file)) {
            $raw = @file_get_contents(self::$json_file);
            if ($raw) {
                $saved = json_decode($raw, true) ?: [];
            }
        }

        // Deep merge with defaults to avoid missing keys
        $content = array_merge($defaults, $saved);

        // Ensure features array is properly structured with 4 items
        if (!isset($content['features']) || !is_array($content['features']) || count($content['features']) < 4) {
            $content['features'] = $defaults['features'];
        }

        return $content;
    }

    /**
     * Save updated homepage content
     */
    public static function saveContent($newData, $updated_by = 'Administrator') {
        self::init();

        $current = self::getContent();

        // Update fields safely
        foreach ($newData as $k => $v) {
            if ($k === 'features' && is_array($v)) {
                $clean_features = [];
                foreach ($v as $f) {
                    $clean_features[] = [
                        'icon' => trim($f['icon'] ?? 'fa-star'),
                        'color' => trim($f['color'] ?? '#7c3aed'),
                        'title' => trim($f['title'] ?? 'Feature Title'),
                        'desc' => trim($f['desc'] ?? 'Feature description')
                    ];
                }
                $current['features'] = $clean_features;
            } elseif ($k === 'announcement_enabled' || $k === 'gcash_show_on_home') {
                $current[$k] = (bool)$v;
            } else {
                $current[$k] = is_string($v) ? trim($v) : $v;
            }
        }

        $current['updated_at'] = date('Y-m-d H:i:s');
        $current['updated_by'] = $updated_by;

        // Save to JSON
        $success = (bool)@file_put_contents(self::$json_file, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Sync to MySQL
        global $pdo;
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO site_content (content_key, content_value, updated_by, updated_at)
                    VALUES ('homepage', :val, :by, NOW())
                    ON DUPLICATE KEY UPDATE content_value = :val2, updated_by = :by2, updated_at = NOW()
                ");
                $json_val = json_encode($current, JSON_UNESCAPED_UNICODE);
                $stmt->execute([
                    ':val' => $json_val,
                    ':by' => $updated_by,
                    ':val2' => $json_val,
                    ':by2' => $updated_by
                ]);
            } catch (PDOException $e) {
                // Ignore DB error
            }
        }

        if (function_exists('log_activity')) {
            log_activity("HOMEPAGE_CONTENT_UPDATED", "Homepage content edited by {$updated_by}");
        }

        return $success;
    }

    /**
     * Reset homepage content to default
     */
    public static function resetDefaults($updated_by = 'Administrator') {
        self::init();
        $defaults = self::getDefaults();
        $defaults['updated_at'] = date('Y-m-d H:i:s');
        $defaults['updated_by'] = $updated_by . ' (Reset to Defaults)';

        @file_put_contents(self::$json_file, json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        global $pdo;
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO site_content (content_key, content_value, updated_by, updated_at)
                    VALUES ('homepage', :val, :by, NOW())
                    ON DUPLICATE KEY UPDATE content_value = :val2, updated_by = :by2, updated_at = NOW()
                ");
                $json_val = json_encode($defaults, JSON_UNESCAPED_UNICODE);
                $stmt->execute([
                    ':val' => $json_val,
                    ':by' => $updated_by,
                    ':val2' => $json_val,
                    ':by2' => $updated_by
                ]);
            } catch (PDOException $e) {
                // Ignore DB error
            }
        }

        if (function_exists('log_activity')) {
            log_activity("HOMEPAGE_CONTENT_RESET", "Homepage content reset to defaults by {$updated_by}");
        }

        return true;
    }
}
