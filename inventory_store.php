<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/migrate_renovation.php';
require_once __DIR__ . '/migrate_activities.php';

class InventoryStore {
    /**
     * Default fallback kubos if DB query is empty
     */
    private static function getDefaultKubos() {
        return [
            [
                'kubo_id' => 1,
                'name' => 'Beach Kubo',
                'category' => 'Family Suite',
                'price' => 3500.00,
                'capacity' => 6,
                'available_units' => 5,
                'image' => 'assets/beach_kubo.jpg',
                'description' => 'Spacious air-conditioned Kubo with private veranda, refrigerator, and 6-person capacity.',
                'badges' => 'Free WiFi, Aircon',
                'status' => 'available',
                'renovation_notes' => null,
                'renovation_start' => null,
                'renovation_end' => null
            ],
            [
                'kubo_id' => 2,
                'name' => 'Orchid Honeymoon Kubo #2',
                'category' => 'Couples & Honeymoon',
                'price' => 2200.00,
                'capacity' => 2,
                'available_units' => 5,
                'image' => 'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=600&q=80',
                'description' => 'Cozy bamboo pavilion steps away from the main infinity pool. Romantic queen bed setup.',
                'badges' => 'Poolside, Smart TV',
                'status' => 'available',
                'renovation_notes' => null,
                'renovation_start' => null,
                'renovation_end' => null
            ],
            [
                'kubo_id' => 3,
                'name' => 'Royal Purple Pavilion #3',
                'category' => 'Group Villa',
                'price' => 5800.00,
                'capacity' => 15,
                'available_units' => 5,
                'image' => 'https://images.unsplash.com/photo-1618773928121-c32242e63f39?auto=format&fit=crop&w=600&q=80',
                'description' => '2-Storey bamboo villa equipped with private grilling station, sound system, and up to 15 guests capacity.',
                'badges' => 'Up to 15 Pax, BBQ Grill',
                'status' => 'available',
                'renovation_notes' => null,
                'renovation_start' => null,
                'renovation_end' => null
            ]
        ];
    }

    /**
     * Get all house kubos from DB
     */
    public static function getAllKubos() {
        global $pdo;

        if ($pdo) {
            try {
                $stmt = $pdo->query("
                    SELECT k.*, k.kubo_id, k.kubo_name_number AS name, k.description, k.price_per_night AS price, 
                           k.capacity, k.primary_image AS image, k.badges, k.status, 
                           k.renovation_notes, k.renovation_start, k.renovation_end,
                           k.category_id,
                           c.category_name AS category
                    FROM house_kubos k
                    LEFT JOIN house_categories c ON k.category_id = c.category_id
                    ORDER BY k.kubo_id ASC
                ");
                $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($res)) {
                    foreach ($res as &$row) {
                        $row['available_units'] = isset($row['available_units']) ? intval($row['available_units']) : 5;
                    }
                    unset($row);
                    $_SESSION['kubos'] = $res;
                    return $res;
                }
            } catch (PDOException $e) {
                error_log("InventoryStore::getAllKubos error: " . $e->getMessage());
            }
        }

        if (!empty($_SESSION['kubos'])) {
            return $_SESSION['kubos'];
        }

        $default = self::getDefaultKubos();
        $_SESSION['kubos'] = $default;
        return $default;
    }

    /**
     * Get all room/house categories from DB
     */
    public static function getAllCategories() {
        global $pdo;
        if ($pdo) {
            try {
                $stmt = $pdo->query("SELECT category_id, category_name, description FROM house_categories ORDER BY category_id ASC");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) return $rows;
            } catch (PDOException $e) {}
        }
        return [
            ['category_id' => 1, 'category_name' => 'Family Suite', 'description' => 'Spacious bamboo houses designed for medium to large families.'],
            ['category_id' => 2, 'category_name' => 'Couples & Honeymoon', 'description' => 'Cozy romantic pavilions with poolside views.'],
            ['category_id' => 3, 'category_name' => 'Group Villa', 'description' => 'Multi-storey villas equipped with private barbecue grills.']
        ];
    }

    /**
     * Get single kubo by ID
     */
    public static function getKuboById($id) {
        $kubos = self::getAllKubos();
        foreach ($kubos as $k) {
            if (intval($k['kubo_id']) === intval($id)) {
                return $k;
            }
        }
        return $kubos[0] ?? null;
    }

    /**
     * Update kubo details: Name, Category, Price, Capacity, Picture/Image, Status, Badges, Description, Renovation
     */
    public static function saveKuboDetails($kubo_id, $data, $image_file = null) {
        $kubo_id = intval($kubo_id);
        $existing = self::getKuboById($kubo_id);

        $name = (isset($data['name']) && trim($data['name']) !== '') ? trim($data['name']) : ($existing['name'] ?? 'Bamboo Kubo');
        $category_id = (!empty($data['category_id'])) ? intval($data['category_id']) : intval($existing['category_id'] ?? 1);
        $price = (isset($data['price']) && $data['price'] !== '') ? floatval($data['price']) : floatval($existing['price'] ?? 0);
        $capacity = (!empty($data['capacity'])) ? intval($data['capacity']) : intval($existing['capacity'] ?? 2);
        $status = (!empty($data['status'])) ? strtolower(trim($data['status'])) : ($existing['status'] ?? 'available');
        $badges = isset($data['badges']) ? trim($data['badges']) : ($existing['badges'] ?? '');
        $description = isset($data['description']) ? trim($data['description']) : ($existing['description'] ?? '');
        $reno_notes = isset($data['renovation_notes']) ? trim($data['renovation_notes']) : ($existing['renovation_notes'] ?? '');
        $reno_start = !empty($data['renovation_start']) ? $data['renovation_start'] : ($existing['renovation_start'] ?? null);
        $reno_end = !empty($data['renovation_end']) ? $data['renovation_end'] : ($existing['renovation_end'] ?? null);

        $available_units = isset($data['available_units']) ? intval($data['available_units']) : intval($existing['available_units'] ?? 5);

        // Image handling:
        // Priority 1: Newly uploaded file
        // Priority 2: Custom image URL/path input if provided
        // Priority 3: Retain existing image
        $image = !empty($data['existing_image']) ? trim($data['existing_image']) : ($existing['image'] ?? 'assets/beach_kubo.jpg');
        if (!empty($data['image_url'])) {
            $image = trim($data['image_url']);
        }
        if ($image_file && !empty($image_file['name']) && $image_file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($image_file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                $dir = __DIR__ . '/uploads/kubos';
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                $filename = 'kubo_' . $kubo_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($image_file['tmp_name'], $dir . '/' . $filename)) {
                    $image = 'uploads/kubos/' . $filename;
                }
            }
        }

        // 1. Update MySQL
        global $pdo;
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE house_kubos 
                    SET kubo_name_number = :name,
                        category_id = :category_id,
                        price_per_night = :price,
                        capacity = :capacity,
                        available_units = :available_units,
                        primary_image = :image,
                        status = :status,
                        badges = :badges,
                        description = :description,
                        renovation_notes = :renovation_notes,
                        renovation_start = :renovation_start,
                        renovation_end = :renovation_end
                    WHERE kubo_id = :kubo_id
                ");
                $stmt->execute([
                    ':name' => $name,
                    ':category_id' => $category_id,
                    ':price' => $price,
                    ':capacity' => $capacity,
                    ':available_units' => $available_units,
                    ':image' => $image,
                    ':status' => $status,
                    ':badges' => $badges,
                    ':description' => $description,
                    ':renovation_notes' => in_array($status, ['renovation', 'maintenance']) ? $reno_notes : null,
                    ':renovation_start' => in_array($status, ['renovation', 'maintenance']) ? $reno_start : null,
                    ':renovation_end' => in_array($status, ['renovation', 'maintenance']) ? $reno_end : null,
                    ':kubo_id' => $kubo_id
                ]);
            } catch (PDOException $e) {
                try {
                    $stmt = $pdo->prepare("
                        UPDATE house_kubos 
                        SET kubo_name_number = :name,
                            category_id = :category_id,
                            price_per_night = :price,
                            capacity = :capacity,
                            primary_image = :image,
                            status = :status,
                            badges = :badges,
                            description = :description,
                            renovation_notes = :renovation_notes,
                            renovation_start = :renovation_start,
                            renovation_end = :renovation_end
                        WHERE kubo_id = :kubo_id
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':category_id' => $category_id,
                        ':price' => $price,
                        ':capacity' => $capacity,
                        ':image' => $image,
                        ':status' => $status,
                        ':badges' => $badges,
                        ':description' => $description,
                        ':renovation_notes' => in_array($status, ['renovation', 'maintenance']) ? $reno_notes : null,
                        ':renovation_start' => in_array($status, ['renovation', 'maintenance']) ? $reno_start : null,
                        ':renovation_end' => in_array($status, ['renovation', 'maintenance']) ? $reno_end : null,
                        ':kubo_id' => $kubo_id
                    ]);
                } catch (PDOException $ex) {}
            }
        }

        $_SESSION['kubos'] = self::getAllKubos();
        return true;
    }

    /**
     * Add a brand new Kubo unit to inventory
     */
    public static function addKubo($data, $image_file = null) {
        global $pdo;
        $name = trim($data['name'] ?? 'New Bamboo Kubo');
        $category_id = intval($data['category_id'] ?? 1);
        $price = floatval($data['price'] ?? 3000);
        $capacity = intval($data['capacity'] ?? 4);
        $available_units = isset($data['available_units']) ? intval($data['available_units']) : 5;
        $status = strtolower(trim($data['status'] ?? 'available'));
        $badges = trim($data['badges'] ?? 'Free WiFi, Aircon');
        $description = trim($data['description'] ?? '');
        $reno_notes = trim($data['renovation_notes'] ?? '');
        $reno_start = !empty($data['renovation_start']) ? $data['renovation_start'] : null;
        $reno_end = !empty($data['renovation_end']) ? $data['renovation_end'] : null;

        $image = trim($data['image_url'] ?? 'assets/beach_kubo.jpg');
        if (empty($image)) $image = 'assets/beach_kubo.jpg';

        $new_id = null;
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO house_kubos (kubo_name_number, category_id, price_per_night, capacity, available_units, primary_image, status, badges, description, renovation_notes, renovation_start, renovation_end)
                    VALUES (:name, :category_id, :price, :capacity, :available_units, :image, :status, :badges, :description, :renovation_notes, :renovation_start, :renovation_end)
                ");
                $stmt->execute([
                    ':name' => $name,
                    ':category_id' => $category_id,
                    ':price' => $price,
                    ':capacity' => $capacity,
                    ':available_units' => $available_units,
                    ':image' => $image,
                    ':status' => $status,
                    ':badges' => $badges,
                    ':description' => $description,
                    ':renovation_notes' => in_array($status, ['renovation', 'maintenance']) ? $reno_notes : null,
                    ':renovation_start' => in_array($status, ['renovation', 'maintenance']) ? $reno_start : null,
                    ':renovation_end' => in_array($status, ['renovation', 'maintenance']) ? $reno_end : null
                ]);
                $new_id = intval($pdo->lastInsertId());
            } catch (PDOException $e) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO house_kubos (kubo_name_number, category_id, price_per_night, capacity, primary_image, status, badges, description, renovation_notes, renovation_start, renovation_end)
                        VALUES (:name, :category_id, :price, :capacity, :image, :status, :badges, :description, :renovation_notes, :renovation_start, :renovation_end)
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':category_id' => $category_id,
                        ':price' => $price,
                        ':capacity' => $capacity,
                        ':image' => $image,
                        ':status' => $status,
                        ':badges' => $badges,
                        ':description' => $description,
                        ':renovation_notes' => in_array($status, ['renovation', 'maintenance']) ? $reno_notes : null,
                        ':renovation_start' => in_array($status, ['renovation', 'maintenance']) ? $reno_start : null,
                        ':renovation_end' => in_array($status, ['renovation', 'maintenance']) ? $reno_end : null
                    ]);
                    $new_id = intval($pdo->lastInsertId());
                } catch (PDOException $ex) {}
            }
        }

        if (!$new_id) {
            $existing = self::getAllKubos();
            $max_id = 0;
            foreach ($existing as $ex) {
                if (intval($ex['kubo_id']) > $max_id) $max_id = intval($ex['kubo_id']);
            }
            $new_id = $max_id + 1;
        }

        // Upload file if present
        if ($image_file && !empty($image_file['name']) && $image_file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($image_file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                $dir = __DIR__ . '/uploads/kubos';
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                $filename = 'kubo_' . $new_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($image_file['tmp_name'], $dir . '/' . $filename)) {
                    $image = 'uploads/kubos/' . $filename;
                    if ($pdo) {
                        try {
                            $up = $pdo->prepare("UPDATE house_kubos SET primary_image = ? WHERE kubo_id = ?");
                            $up->execute([$image, $new_id]);
                        } catch (Exception $ex) {}
                    }
                }
            }
        }

        $_SESSION['kubos'] = self::getAllKubos();
        return $new_id;
    }

    /**
     * Delete a Kubo from inventory
     */
    public static function deleteKubo($kubo_id) {
        global $pdo;
        $kubo_id = intval($kubo_id);
        if ($pdo) {
            try {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE type = 'house' AND item_id = ?");
                $chk->execute([$kubo_id]);
                if ($chk->fetchColumn() > 0) {
                    return ['success' => false, 'msg' => 'Cannot delete this accommodation because it has existing booking history. Please set its status to Maintenance or Renovation instead.'];
                }

                $stmt = $pdo->prepare("DELETE FROM house_kubos WHERE kubo_id = ?");
                $stmt->execute([$kubo_id]);
            } catch (PDOException $e) {
                error_log("DB delete error: " . $e->getMessage());
                return ['success' => false, 'msg' => 'Database error occurred.'];
            }
        }

        $_SESSION['kubos'] = self::getAllKubos();
        return ['success' => true];
    }

    /**
     * Update kubo status only (available, occupied, maintenance, renovation)
     */
    public static function updateKuboStatus($kubo_id, $status, $renovation_notes = null, $renovation_start = null, $renovation_end = null) {
        return self::saveKuboDetails($kubo_id, [
            'status' => $status,
            'renovation_notes' => $renovation_notes,
            'renovation_start' => $renovation_start,
            'renovation_end' => $renovation_end
        ]);
    }

    /**
     * Get all water and pool activities from MySQL or fallback
     */
    public static function getAllActivities($include_all = true) {
        global $pdo;
        if ($pdo) {
            try {
                $stmt = $pdo->query("SELECT * FROM activities ORDER BY activity_id ASC");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    foreach ($rows as &$r) {
                        // Normalize duration (optional)
                        if (isset($r['duration_minutes']) && $r['duration_minutes'] !== null && trim((string)$r['duration_minutes']) !== '') {
                            $dm = intval($r['duration_minutes']);
                            if ($dm > 0) {
                                $r['duration_minutes'] = $dm;
                                if (empty($r['duration']) || $r['duration'] === 'No limit') {
                                    $r['duration'] = ($dm >= 480) ? 'Full Day' : ($dm >= 60 && $dm % 60 === 0 ? ($dm / 60) . ' Hour' . (($dm / 60) > 1 ? 's' : '') : ($dm . ' Mins'));
                                }
                            } else {
                                $r['duration_minutes'] = null;
                                $r['duration'] = 'No limit';
                            }
                        } else {
                            if (!empty($r['duration']) && $r['duration'] !== 'No limit' && $r['duration'] !== 'No time limit') {
                                if ($r['duration'] === 'Full Day') {
                                    $r['duration_minutes'] = 480;
                                } elseif (preg_match('/(\d+)\s*Min/i', $r['duration'], $m)) {
                                    $r['duration_minutes'] = intval($m[1]);
                                } elseif (preg_match('/(\d+)\s*Hour/i', $r['duration'], $m)) {
                                    $r['duration_minutes'] = intval($m[1]) * 60;
                                } else {
                                    $r['duration_minutes'] = null;
                                }
                            } else {
                                $r['duration_minutes'] = null;
                                $r['duration'] = 'No limit';
                            }
                        }

                        // Normalize capacity / available units / max pax
                        $r['max_pax'] = !empty($r['max_pax']) ? intval($r['max_pax']) : (!empty($r['capacity']) ? intval($r['capacity']) : 2);
                        $r['capacity'] = !empty($r['capacity']) ? intval($r['capacity']) : $r['max_pax'];
                        $r['total_units'] = !empty($r['total_units']) ? intval($r['total_units']) : $r['capacity'];
                        $r['available_units'] = isset($r['available_units']) ? intval($r['available_units']) : max(0, $r['total_units'] - 1);
                        $r['equipment'] = !empty($r['equipment']) ? $r['equipment'] : '';

                        // Normalize availability & status
                        if (isset($r['is_available'])) {
                            $r['is_available'] = intval($r['is_available']);
                        } else {
                            $r['is_available'] = (($r['status'] ?? 'available') === 'available') ? 1 : 0;
                        }
                        if (empty($r['status'])) {
                            $r['status'] = $r['is_available'] ? 'available' : 'maintenance';
                        }

                        // Normalize image
                        $r['image'] = !empty($r['image']) ? $r['image'] : (!empty($r['activity_image']) ? $r['activity_image'] : '');

                        // Normalize category
                        $cat = !empty($r['category']) ? $r['category'] : 'pool';
                        if (empty($r['category'])) {
                            if (stripos($r['activity_name'], 'kayak') !== false || stripos($r['activity_name'], 'paddle') !== false) $cat = 'outdoor';
                            elseif (stripos($r['activity_name'], 'massage') !== false || stripos($r['activity_name'], 'spa') !== false) $cat = 'wellness';
                            $r['category'] = $cat;
                        }

                        if ($cat === 'pool') $r['category_label'] = 'Water Activity';
                        elseif ($cat === 'outdoor') $r['category_label'] = 'Water Sport';
                        elseif ($cat === 'wellness') $r['category_label'] = 'Wellness & Spa';
                        elseif ($cat === 'indoor') $r['category_label'] = 'Indoor Leisure';
                        else $r['category_label'] = 'Resort Activity';
                    }
                    unset($r);

                    if (!$include_all) {
                        $rows = array_values(array_filter($rows, function($a) {
                            return !empty($a['is_available']);
                        }));
                    }

                    return $rows;
                }
            } catch (PDOException $e) {
                error_log("InventoryStore activities fetch error: " . $e->getMessage());
            }
        }

        return [
            [
                'activity_id' => 1,
                'activity_name' => 'Main Infinity Pool Pass',
                'category' => 'pool',
                'category_label' => 'Water Activity',
                'price' => 350.00,
                'duration_minutes' => 480,
                'duration' => 'Full Day',
                'max_pax' => 10,
                'capacity' => 10,
                'total_units' => 6,
                'available_units' => 4,
                'description' => 'Access to crystal-clear infinity pool with lounge chairs and kids pool zone.',
                'image' => 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80',
                'is_available' => 1
            ],
            [
                'activity_id' => 2,
                'activity_name' => 'Clear-Bottom Kayak & Paddle Board',
                'category' => 'outdoor',
                'category_label' => 'Water Sport',
                'price' => 500.00,
                'duration_minutes' => 60,
                'duration' => '1 Hour',
                'max_pax' => 2,
                'capacity' => 2,
                'total_units' => 6,
                'available_units' => 4,
                'description' => 'Transparent kayak equipment for scenic pool and lagoon exploration.',
                'image' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=600&q=80',
                'is_available' => 1
            ],
            [
                'activity_id' => 3,
                'activity_name' => 'Traditional Bamboo Herbal Massage',
                'category' => 'wellness',
                'category_label' => 'Wellness & Spa',
                'price' => 1200.00,
                'duration_minutes' => 60,
                'duration' => '60 Mins',
                'max_pax' => 2,
                'capacity' => 2,
                'total_units' => 4,
                'available_units' => 3,
                'description' => 'Relaxing traditional Hilot massage using heated bamboo sticks and coconut oil.',
                'image' => 'https://images.unsplash.com/photo-1544161515-4ab6ce6db874?auto=format&fit=crop&w=600&q=80',
                'is_available' => 1
            ]
        ];
    }

    /**
     * Get single activity by ID
     */
    public static function getActivityById($activity_id) {
        global $pdo;
        $activity_id = intval($activity_id);
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM activities WHERE activity_id = ? LIMIT 1");
                $stmt->execute([$activity_id]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($item) {
                    $item['image'] = !empty($item['image']) ? $item['image'] : (!empty($item['activity_image']) ? $item['activity_image'] : '');
                    if (isset($item['duration_minutes']) && $item['duration_minutes'] !== null && trim((string)$item['duration_minutes']) !== '') {
                        $dm = intval($item['duration_minutes']);
                        if ($dm > 0) {
                            $item['duration_minutes'] = $dm;
                            if (empty($item['duration']) || $item['duration'] === 'No limit') {
                                $item['duration'] = ($dm >= 480) ? 'Full Day' : ($dm >= 60 && $dm % 60 === 0 ? ($dm / 60) . ' Hour' . (($dm / 60) > 1 ? 's' : '') : ($dm . ' Mins'));
                            }
                        } else {
                            $item['duration_minutes'] = null;
                            $item['duration'] = 'No limit';
                        }
                    } else {
                        if (!empty($item['duration']) && $item['duration'] !== 'No limit' && $item['duration'] !== 'No time limit') {
                            if ($item['duration'] === 'Full Day') {
                                $item['duration_minutes'] = 480;
                            } elseif (preg_match('/(\d+)\s*Min/i', $item['duration'], $m)) {
                                $item['duration_minutes'] = intval($m[1]);
                            } elseif (preg_match('/(\d+)\s*Hour/i', $item['duration'], $m)) {
                                $item['duration_minutes'] = intval($m[1]) * 60;
                            } else {
                                $item['duration_minutes'] = null;
                            }
                        } else {
                            $item['duration_minutes'] = null;
                            $item['duration'] = 'No limit';
                        }
                    }
                    $item['is_available'] = isset($item['is_available']) ? intval($item['is_available']) : (($item['status'] ?? 'available') === 'available' ? 1 : 0);
                    $item['category'] = !empty($item['category']) ? $item['category'] : 'pool';
                    $item['max_pax'] = !empty($item['max_pax']) ? intval($item['max_pax']) : (!empty($item['capacity']) ? intval($item['capacity']) : 2);
                    $item['capacity'] = !empty($item['capacity']) ? intval($item['capacity']) : $item['max_pax'];
                    $item['total_units'] = !empty($item['total_units']) ? intval($item['total_units']) : $item['capacity'];
                    $item['available_units'] = isset($item['available_units']) ? intval($item['available_units']) : max(0, $item['total_units'] - 1);
                    $item['equipment'] = !empty($item['equipment']) ? $item['equipment'] : '';
                    return $item;
                }
            } catch (PDOException $e) {
                error_log("InventoryStore getActivityById error: " . $e->getMessage());
            }
        }
        $all = self::getAllActivities(true);
        foreach ($all as $a) {
            if (intval($a['activity_id']) === $activity_id) return $a;
        }
        return null;
    }

    /**
     * Save/Update activity details, price, photo, duration, and status
     */
    public static function saveActivityDetails($activity_id, $data, $image_file = null) {
        global $pdo;
        $activity_id = intval($activity_id);
        $existing = self::getActivityById($activity_id);

        $name = !empty($data['activity_name']) ? trim($data['activity_name']) : ($existing['activity_name'] ?? 'Activity');
        $category = !empty($data['category']) ? trim($data['category']) : ($existing['category'] ?? 'pool');
        $price = isset($data['price']) && $data['price'] !== '' ? floatval($data['price']) : floatval($existing['price'] ?? 0);
        // Duration handling: optional, allow empty / no time limit
        $duration_minutes = null;
        if (isset($data['duration_minutes']) && trim((string)$data['duration_minutes']) !== '') {
            $parsed_mins = intval($data['duration_minutes']);
            if ($parsed_mins > 0) {
                $duration_minutes = $parsed_mins;
            }
        } elseif (!isset($data['duration_minutes']) && isset($existing['duration_minutes'])) {
            $duration_minutes = ($existing['duration_minutes'] && intval($existing['duration_minutes']) > 0) ? intval($existing['duration_minutes']) : null;
        }

        if ($duration_minutes !== null && $duration_minutes > 0) {
            $duration_str = ($duration_minutes >= 480) ? 'Full Day' : ($duration_minutes >= 60 && $duration_minutes % 60 === 0 ? ($duration_minutes / 60) . ' Hour' . (($duration_minutes / 60) > 1 ? 's' : '') : $duration_minutes . ' Mins');
            $activity_type = ($duration_minutes >= 480) ? 'full_day' : 'time_based';
        } else {
            $duration_minutes = null;
            $duration_str = 'No limit';
            $activity_type = 'full_day';
        }
        $description = isset($data['description']) ? trim($data['description']) : ($existing['description'] ?? '');
        $is_available = isset($data['is_available']) ? intval($data['is_available']) : intval($existing['is_available'] ?? 1);
        $status = $is_available ? 'available' : 'maintenance';
        $max_pax = isset($data['max_pax']) && $data['max_pax'] !== '' ? intval($data['max_pax']) : (isset($existing['max_pax']) ? intval($existing['max_pax']) : (isset($data['capacity']) && $data['capacity'] !== '' ? intval($data['capacity']) : 2));
        if ($max_pax < 1) $max_pax = 1;
        $capacity = $max_pax;
        $total_units = isset($data['total_units']) && $data['total_units'] !== '' ? intval($data['total_units']) : (isset($existing['total_units']) ? intval($existing['total_units']) : 6);
        $available_units = isset($data['available_units']) && $data['available_units'] !== '' ? intval($data['available_units']) : intval($existing['available_units'] ?? max(0, $total_units - 2));
        $equipment = isset($data['equipment']) ? (is_array($data['equipment']) ? json_encode($data['equipment']) : trim($data['equipment'])) : ($existing['equipment'] ?? '');

        // Image Handling
        $image = !empty($data['existing_image']) ? trim($data['existing_image']) : ($existing['image'] ?? '');
        if (!empty($data['image_url'])) {
            $image = trim($data['image_url']);
        }
        if ($image_file && !empty($image_file['name']) && $image_file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($image_file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                $dir = __DIR__ . '/uploads/activities';
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                $filename = 'activity_' . $activity_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($image_file['tmp_name'], $dir . '/' . $filename)) {
                    $image = 'uploads/activities/' . $filename;
                }
            }
        }

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE activities 
                    SET activity_name = :name,
                        category = :category,
                        price = :price,
                        duration = :duration_str,
                        duration_minutes = :duration_mins,
                        max_pax = :max_pax,
                        capacity = :capacity,
                        total_units = :total_units,
                        available_units = :available_units,
                        equipment = :equipment,
                        activity_type = :activity_type,
                        description = :description,
                        image = :image,
                        activity_image = :image2,
                        status = :status,
                        is_available = :is_available
                    WHERE activity_id = :id
                ");
                return $stmt->execute([
                    ':name' => $name,
                    ':category' => $category,
                    ':price' => $price,
                    ':duration_str' => $duration_str,
                    ':duration_mins' => $duration_minutes,
                    ':max_pax' => $max_pax,
                    ':capacity' => $capacity,
                    ':total_units' => $total_units,
                    ':available_units' => $available_units,
                    ':equipment' => $equipment,
                    ':activity_type' => $activity_type,
                    ':description' => $description,
                    ':image' => $image,
                    ':image2' => $image,
                    ':status' => $status,
                    ':is_available' => $is_available,
                    ':id' => $activity_id
                ]);
            } catch (PDOException $e) {
                error_log("Activity update error: " . $e->getMessage());
                return false;
            }
        }
        return true;
    }

    /**
     * Add a brand new activity unit to inventory
     */
    public static function addActivity($data, $image_file = null) {
        global $pdo;
        $name = trim($data['activity_name'] ?? 'New Activity');
        $category = trim($data['category'] ?? 'pool');
        $price = floatval($data['price'] ?? 350);
        // Duration handling: optional, allow empty / no time limit
        $duration_minutes = null;
        if (isset($data['duration_minutes']) && trim((string)$data['duration_minutes']) !== '') {
            $parsed_mins = intval($data['duration_minutes']);
            if ($parsed_mins > 0) {
                $duration_minutes = $parsed_mins;
            }
        }

        if ($duration_minutes !== null && $duration_minutes > 0) {
            $duration_str = ($duration_minutes >= 480) ? 'Full Day' : ($duration_minutes >= 60 && $duration_minutes % 60 === 0 ? ($duration_minutes / 60) . ' Hour' . (($duration_minutes / 60) > 1 ? 's' : '') : $duration_minutes . ' Mins');
            $activity_type = ($duration_minutes >= 480) ? 'full_day' : 'time_based';
        } else {
            $duration_minutes = null;
            $duration_str = 'No limit';
            $activity_type = 'full_day';
        }
        $description = trim($data['description'] ?? '');
        $is_available = isset($data['is_available']) ? intval($data['is_available']) : 1;
        $status = $is_available ? 'available' : 'maintenance';
        $max_pax = isset($data['max_pax']) && $data['max_pax'] !== '' ? intval($data['max_pax']) : (isset($data['capacity']) && $data['capacity'] !== '' ? intval($data['capacity']) : 2);
        if ($max_pax < 1) $max_pax = 1;
        $capacity = $max_pax;
        $total_units = isset($data['total_units']) && $data['total_units'] !== '' ? intval($data['total_units']) : 6;
        $available_units = isset($data['available_units']) && $data['available_units'] !== '' ? intval($data['available_units']) : max(0, $total_units - 2);
        $equipment = isset($data['equipment']) ? (is_array($data['equipment']) ? json_encode($data['equipment']) : trim($data['equipment'])) : '';
        $image = trim($data['image_url'] ?? 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?auto=format&fit=crop&w=600&q=80');

        $new_id = null;
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO activities (activity_name, category, price, duration, duration_minutes, max_pax, capacity, total_units, available_units, equipment, activity_type, description, image, activity_image, status, is_available)
                    VALUES (:name, :category, :price, :duration_str, :duration_mins, :max_pax, :capacity, :total_units, :available_units, :equipment, :activity_type, :description, :image, :image2, :status, :is_available)
                ");
                $stmt->execute([
                    ':name' => $name,
                    ':category' => $category,
                    ':price' => $price,
                    ':duration_str' => $duration_str,
                    ':duration_mins' => $duration_minutes,
                    ':max_pax' => $max_pax,
                    ':capacity' => $capacity,
                    ':total_units' => $total_units,
                    ':available_units' => $available_units,
                    ':equipment' => $equipment,
                    ':activity_type' => $activity_type,
                    ':description' => $description,
                    ':image' => $image,
                    ':image2' => $image,
                    ':status' => $status,
                    ':is_available' => $is_available
                ]);
                $new_id = intval($pdo->lastInsertId());
            } catch (PDOException $e) {
                error_log("Activity insert error: " . $e->getMessage());
            }
        }

        // Upload file if provided
        if ($new_id && $image_file && !empty($image_file['name']) && $image_file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($image_file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                $dir = __DIR__ . '/uploads/activities';
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                $filename = 'activity_' . $new_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($image_file['tmp_name'], $dir . '/' . $filename)) {
                    $image = 'uploads/activities/' . $filename;
                    if ($pdo) {
                        try {
                            $up = $pdo->prepare("UPDATE activities SET image = ?, activity_image = ? WHERE activity_id = ?");
                            $up->execute([$image, $image, $new_id]);
                        } catch (Exception $ex) {}
                    }
                }
            }
        }

        return $new_id;
    }

    /**
     * Delete an activity from inventory
     */
    public static function deleteActivity($activity_id) {
        global $pdo;
        $activity_id = intval($activity_id);
        if ($pdo) {
            try {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM addon_reservations WHERE activity_id = ?");
                $chk->execute([$activity_id]);
                if ($chk->fetchColumn() > 0) {
                    return ['success' => false, 'msg' => 'Cannot delete this activity because it has existing booking history. Please toggle its availability instead.'];
                }

                $chk2 = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE type = 'activity' AND item_id = ?");
                $chk2->execute([$activity_id]);
                if ($chk2->fetchColumn() > 0) {
                    return ['success' => false, 'msg' => 'Cannot delete this activity because it has existing booking history. Please toggle its availability instead.'];
                }

                $stmt = $pdo->prepare("DELETE FROM activities WHERE activity_id = ?");
                $stmt->execute([$activity_id]);
                return ['success' => true];
            } catch (PDOException $e) {
                error_log("Activity delete error: " . $e->getMessage());
                return ['success' => false, 'msg' => 'Database error occurred.'];
            }
        }
        return ['success' => true];
    }

    /**
     * Quick toggle activity availability (1 = Available, 0 = Under Maintenance / Unavailable)
     */
    public static function toggleActivityStatus($activity_id, $is_available) {
        global $pdo;
        $activity_id = intval($activity_id);
        $is_available = $is_available ? 1 : 0;
        $status = $is_available ? 'available' : 'maintenance';
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("UPDATE activities SET is_available = :avail, status = :status WHERE activity_id = :id");
                return $stmt->execute([
                    ':avail' => $is_available,
                    ':status' => $status,
                    ':id' => $activity_id
                ]);
            } catch (PDOException $e) {
                return false;
            }
        }
        return true;
    }
}
?>
