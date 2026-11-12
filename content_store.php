<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';

class ContentStore {
    /**
     * Default Fallback Content for Purple Bamboo Resort Homepage
     */
    public static function getDefaults() {
        return [
            // 0. Resort Branding & Logo Profile
            'resort_logo' => 'assets/logo.jpg',
            'resort_brand_title' => 'PURPLE BAMBOO',
            'resort_brand_subtitle' => 'BEACH RESORT',
            'resort_name' => 'The Blessed Purple Bamboo Beach Resort',

            // 1. Hero Canvas Banner
            'hero_badge' => '🌟 Authentic Tropical Resort Sanctuary',
            'hero_title' => 'Welcome to The Blessed Purple Bamboo Resort',
            'hero_subtitle' => 'Experience serene kubos, infinity pools, and tropical bliss In Telbang, Alaminos City, Pangasinan',
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
     * Initialize database table if needed
     */
    private static function init() {
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
                try {
                    $pdo->exec("ALTER TABLE site_content ADD COLUMN IF NOT EXISTS updated_by VARCHAR(100) NULL;");
                    $pdo->exec("ALTER TABLE site_content ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;");
                } catch (PDOException $ex) {}
            } catch (PDOException $e) {
                // Table creation handled
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

        global $pdo;
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT content_value FROM site_content WHERE content_key = 'homepage' LIMIT 1");
                $stmt->execute();
                $val = $stmt->fetchColumn();
                if ($val) {
                    $saved = json_decode($val, true) ?: [];
                    if (isset($saved['hero_subtitle']) && strpos($saved['hero_subtitle'], 'Hermosa, Bataan') !== false) {
                        $saved['hero_subtitle'] = 'Experience serene kubos, infinity pools, and tropical bliss In Telbang, Alaminos City, Pangasinan';
                        if (isset($saved['resort_location']) && strpos($saved['resort_location'], 'Hermosa') !== false) {
                            $saved['resort_location'] = 'Telbang, Alaminos City, Pangasinan';
                        }
                        try {
                            $upStmt = $pdo->prepare("UPDATE site_content SET content_value = :val, updated_by = 'System Update', updated_at = NOW() WHERE content_key = 'homepage'");
                            $upStmt->execute([':val' => json_encode($saved, JSON_UNESCAPED_UNICODE)]);
                        } catch (PDOException $ex) {}
                    }
                } else {
                    // Seed initial defaults into MySQL
                    $insertStmt = $pdo->prepare("
                        INSERT INTO site_content (content_key, content_value, updated_by, updated_at)
                        VALUES ('homepage', :val, 'System Seed', NOW())
                    ");
                    $insertStmt->execute([':val' => json_encode($defaults, JSON_UNESCAPED_UNICODE)]);
                }
            } catch (PDOException $e) {
                error_log("ContentStore::getContent error: " . $e->getMessage());
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

        $success = false;

        // Persist strictly to MySQL
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
                $success = true;
            } catch (PDOException $e) {
                error_log("ContentStore::saveContent MySQL error: " . $e->getMessage());
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
                error_log("ContentStore::resetDefaults MySQL error: " . $e->getMessage());
            }
        }

        if (function_exists('log_activity')) {
            log_activity("HOMEPAGE_CONTENT_RESET", "Homepage content reset to defaults by {$updated_by}");
        }

        return true;
    }
}
