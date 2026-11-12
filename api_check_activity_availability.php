<?php
require_once __DIR__ . '/booking_store.php';
require_once __DIR__ . '/inventory_store.php';

header('Content-Type: application/json');

$date = !empty($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
$start_time = !empty($_GET['start_time']) ? trim($_GET['start_time']) : '09:00:00';

// Format start time if it comes as HH:MM instead of HH:MM:SS
if (strlen($start_time) == 5) {
    $start_time .= ':00';
}

// Parse single or multiple activity IDs
$raw_ids = $_GET['activity_ids'] ?? ($_GET['activity_id'] ?? '');
if (is_array($raw_ids)) {
    $act_id_list = array_map('intval', $raw_ids);
} else {
    $act_id_list = array_map('intval', explode(',', (string)$raw_ids));
}
$act_id_list = array_values(array_filter($act_id_list, function($id) { return $id > 0; }));

if (empty($act_id_list)) {
    echo json_encode(['error' => 'No activities specified', 'available' => 0, 'equipment' => []]);
    exit;
}

$all_inventory = InventoryStore::getAllActivities();
$activities_by_id = [];
foreach ($all_inventory as $act) {
    $activities_by_id[$act['activity_id']] = $act;
}

$selected_activities = [];
foreach ($act_id_list as $id) {
    if (isset($activities_by_id[$id])) {
        $selected_activities[] = $activities_by_id[$id];
    }
}

if (empty($selected_activities)) {
    echo json_encode(['error' => 'Activities not found', 'available' => 0, 'equipment' => []]);
    exit;
}

if ($date < date('Y-m-d')) {
    echo json_encode([
        'activity_ids' => $act_id_list,
        'date' => $date,
        'start_time' => $start_time,
        'available' => 0,
        'equipment' => [],
        'error' => 'Cannot check availability for past dates'
    ]);
    exit;
}

$min_available = 999999;
$combined_capacity = 0;
$act_names = [];
$any_slot_required = false;
$all_processed_equipment = [];
$seen_eq_names = [];
$pool_available = null;     // min availability across pool (unlimited) activities
$limited_available = null;  // min availability across non-pool (equipment/capacity limited) activities
$per_activity = [];

foreach ($selected_activities as $activity) {
    $act_id = $activity['activity_id'];
    $act_names[] = $activity['activity_name'];
    $duration = intval($activity['duration_minutes'] ?? 60);
    if ($duration <= 0) $duration = 60;

    $end_time = date('H:i:s', strtotime($start_time) + ($duration * 60));
    $avail = BookingStore::getActivityAvailability($act_id, $date, $start_time, $end_time);

    if ($avail < $min_available) {
        $min_available = $avail;
    }

    $capacity = intval($activity['capacity'] ?? 10);
    $totalUnits = !empty($activity['total_units']) ? intval($activity['total_units']) : $capacity;
    $reserved = max(0, $capacity - $avail);
    $act_min = $avail;

    if (($activity['activity_type'] ?? 'full_day') !== 'full_day') {
        $any_slot_required = true;
    }

    // Equipment processing
    $eqList = [];
    if (!empty($activity['equipment'])) {
        if (is_array($activity['equipment'])) {
            $eqList = $activity['equipment'];
        } else {
            $decoded = json_decode($activity['equipment'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $eqList = $decoded;
            }
        }
    }

    if (empty($eqList)) {
        $actNameLower = strtolower($activity['activity_name'] ?? '');
        if (strpos($actNameLower, 'kayak') !== false || strpos($actNameLower, 'paddle') !== false) {
            $eqList = [
                ['name' => 'Clear Kayak', 'qty' => 6, 'icon' => 'fa-sailboat'],
                ['name' => 'Paddle', 'qty' => 6, 'icon' => 'fa-water'],
                ['name' => 'Life Jacket', 'qty' => 10, 'icon' => 'fa-vest']
            ];
        } elseif (strpos($actNameLower, 'scuba') !== false || strpos($actNameLower, 'diving') !== false) {
            $eqList = [
                ['name' => 'Scuba Tank & Regulator', 'qty' => $capacity, 'icon' => 'fa-mask-snorkel'],
                ['name' => 'Wetsuit & Fins', 'qty' => $capacity, 'icon' => 'fa-person-swimming']
            ];
        } elseif (!empty($activity['total_units'])) {
            $eqList = [
                ['name' => 'Available Gear/Units (' . $activity['activity_name'] . ')', 'qty' => $totalUnits, 'icon' => 'fa-boxes-stacked']
            ];
        }
    }

    if (strtolower(trim($activity['category'] ?? '')) === 'pool') {
        $eqList = []; // Pool has no equipment limit
    }

    foreach ($eqList as $eq) {
        $eqName = $eq['name'] ?? 'Equipment';
        $itemQty = intval($eq['qty'] ?? $capacity);
        $itemRem = max(0, $itemQty - $reserved);

        if ($itemRem < $act_min) {
            $act_min = $itemRem;
        }
        if (!isset($seen_eq_names[$eqName])) {
            $seen_eq_names[$eqName] = true;
            $all_processed_equipment[] = [
                'name' => $eqName,
                'qty' => $itemQty,
                'remaining' => $itemRem,
                'icon' => !empty($eq['icon']) ? $eq['icon'] : 'fa-boxes-stacked'
            ];
            
            // Constrain overall availability by equipment availability
            if ($itemRem < $min_available) {
                $min_available = $itemRem;
            }
        }
    }

    $is_pool_act = strtolower(trim($activity['category'] ?? '')) === 'pool';
    $per_activity[] = [
        'activity_id' => intval($act_id),
        'name' => $activity['activity_name'],
        'is_pool' => $is_pool_act,
        'available' => $act_min
    ];
    if ($is_pool_act) {
        $pool_available = ($pool_available === null) ? $act_min : min($pool_available, $act_min);
    } else {
        $limited_available = ($limited_available === null) ? $act_min : min($limited_available, $act_min);
    }
}

if ($min_available === 999999) {
    $min_available = 0;
}

echo json_encode([
    'available' => $min_available,
    'activity_ids' => $act_id_list,
    'activity_names' => $act_names,
    'activity_name' => implode(', ', $act_names),
    'requires_time_slot' => $any_slot_required,
    'equipment' => $all_processed_equipment,
    'pool_available' => $pool_available,
    'limited_available' => $limited_available,
    'activities' => $per_activity
]);
