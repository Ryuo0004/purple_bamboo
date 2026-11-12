<?php
$file = __DIR__ . '/../calendar.php';
$content = file_get_contents($file);

// 1. Backup
file_put_contents($file . '.bak_before_grouping', $content);

// =========================================================================
// SECTION A: REPLACE BACKEND FETCHING & GROUPING LOGIC (lines ~64 - ~135)
// =========================================================================

$old_backend_search = '$all_bookings = BookingStore::getAllBookings();

// Also retrieve archived bookings so past reservations are always visible
global $pdo;
if ($pdo) {
    try {
        $stmtArch = $pdo->query("SELECT * FROM booking_archive");
        $archRows = $stmtArch->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!empty($archRows)) {
            $existing_ids = array_column($all_bookings, \'id\');
            foreach ($archRows as $arch) {
                if (!in_array($arch[\'id\'], $existing_ids)) {
                    $all_bookings[] = $arch;
                }
            }
        }
    } catch (PDOException $e) {
        // Table may not exist yet or empty
    }
}

// -------------------------------------------------------------
// CALCULATE PAST, PRESENT, FUTURE STATS & CALENDAR MAP
// -------------------------------------------------------------
$total_bookings_count = count($all_bookings);
$past_bookings_count = 0;
$present_bookings_count = 0;
$future_bookings_count = 0;

$calendar_map = []; // \'YYYY-MM-DD\' => [ bookings ]

foreach ($all_bookings as $b) {
    $status = strtolower($b[\'booking_status\'] ?? \'pending\');
    $cin = !empty($b[\'check_in\']) ? substr($b[\'check_in\'], 0, 10) : $today_str;
    $cout = !empty($b[\'check_out\']) ? substr($b[\'check_out\'], 0, 10) : $cin;
    if ($cout < $cin) $cout = $cin;

    // Timeline categorization
    if ($cout < $today_str || in_array($status, [\'checked_out\', \'completed\'])) {
        $past_bookings_count++;
        $timeline_cat = \'past\';
    } elseif ($cin <= $today_str && $cout >= $today_str) {
        $present_bookings_count++;
        $timeline_cat = \'present\';
    } else {
        $future_bookings_count++;
        $timeline_cat = \'future\';
    }

    // Populate calendar map across each day of stay
    $start_ts = strtotime($cin);
    $end_ts = strtotime($cout);

    for ($curr_ts = $start_ts; $curr_ts <= $end_ts; $curr_ts = strtotime(\'+1 day\', $curr_ts)) {
        $dStr = date(\'Y-m-d\', $curr_ts);

        $role = \'stay\';
        if ($cin === $cout) {
            $role = \'single\';
        } elseif ($dStr === $cin) {
            $role = \'checkin\';
        } elseif ($dStr === $cout) {
            $role = \'checkout\';
        }

        $bCopy = $b;
        $bCopy[\'role_in_stay\'] = $role;
        $bCopy[\'timeline_cat\'] = $timeline_cat;
        $calendar_map[$dStr][] = $bCopy;
    }
}';

$new_backend_replacement = '$all_bookings = BookingStore::getAllBookings();

// Also retrieve archived bookings so past reservations are always visible
global $pdo;
if ($pdo) {
    try {
        $stmtArch = $pdo->query("SELECT * FROM booking_archive");
        $archRows = $stmtArch->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!empty($archRows)) {
            $existing_ids = array_column($all_bookings, \'id\');
            foreach ($archRows as $arch) {
                if (!in_array($arch[\'id\'], $existing_ids)) {
                    $all_bookings[] = $arch;
                }
            }
        }
    } catch (PDOException $e) {}
}

// Fetch any associated addon_reservations for full activity item coverage
$addons_by_ref = [];
if ($pdo) {
    try {
        $stmtAddons = $pdo->query("
            SELECT ar.*, a.activity_name, a.price, a.duration, a.duration_minutes
            FROM addon_reservations ar
            LEFT JOIN activities a ON ar.activity_id = a.activity_id
        ");
        $addonRows = $stmtAddons->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($addonRows as $ar) {
            $refKey = trim($ar[\'reservation_id\'] ?? \'\');
            if ($refKey !== \'\') {
                $addons_by_ref[$refKey][] = $ar;
            }
        }
    } catch (Throwable $e) {}
}

// -------------------------------------------------------------
// GROUP BY BOOKING REFERENCE: ONE BOOKING = ONE CALENDAR RESERVATION
// -------------------------------------------------------------
$grouped_reservations = [];

foreach ($all_bookings as $b) {
    $ref = trim($b[\'id\'] ?? \'\');
    if ($ref === \'\') {
        $ref = \'BOOKING-\' . ($b[\'booking_id\'] ?? rand(1000, 9999));
    }

    $b_cin = !empty($b[\'check_in\']) ? substr($b[\'check_in\'], 0, 10) : $today_str;
    $b_cout = !empty($b[\'check_out\']) ? substr($b[\'check_out\'], 0, 10) : $b_cin;
    if ($b_cout < $b_cin) $b_cout = $b_cin;

    $b_type = strtolower($b[\'type\'] ?? \'house\');
    $item_title = trim($b[\'item_name\'] ?? \'\');
    if ($item_title === \'\') {
        $item_title = ($b_type === \'activity\') ? \'Resort Activity\' : \'House Kubo\';
    }

    if (!isset($grouped_reservations[$ref])) {
        $grouped_reservations[$ref] = [
            \'id\' => $ref,
            \'guest_name\' => $b[\'guest_name\'] ?? \'Resort Guest\',
            \'guest_email\' => $b[\'guest_email\'] ?? \'\',
            \'guest_phone\' => $b[\'guest_phone\'] ?? \'\',
            \'profile_id\' => $b[\'profile_id\'] ?? \'\',
            \'user_id\' => $b[\'user_id\'] ?? null,
            \'booking_status\' => $b[\'booking_status\'] ?? \'pending\',
            \'payment_status\' => $b[\'payment_status\'] ?? \'proof_submitted\',
            \'check_in\' => $b_cin,
            \'check_out\' => $b_cout,
            \'time_slot\' => $b[\'time_slot\'] ?? \'\',
            \'pax\' => intval($b[\'pax\'] ?? 1),
            \'total_amount\' => floatval($b[\'total_amount\'] ?? 0),
            \'special_notes\' => $b[\'special_notes\'] ?? \'\',
            \'guest_list\' => $b[\'guest_list\'] ?? \'\',
            \'created_at\' => $b[\'created_at\'] ?? \'\',
            \'has_house\' => ($b_type === \'house\'),
            \'has_activity\' => ($b_type === \'activity\'),
            \'items\' => [
                [
                    \'name\' => $item_title,
                    \'type\' => $b_type,
                    \'item_id\' => intval($b[\'item_id\'] ?? 0),
                    \'check_in\' => $b_cin,
                    \'check_out\' => $b_cout,
                    \'time_slot\' => $b[\'time_slot\'] ?? \'\',
                    \'pax\' => intval($b[\'pax\'] ?? 1),
                    \'icon\' => ($b_type === \'activity\') ? \'fa-water-ladder\' : \'fa-house-chimney\'
                ]
            ]
        ];
    } else {
        // Merge into existing parent booking with same reference ID
        $parent = &$grouped_reservations[$ref];
        if ($b_cin < $parent[\'check_in\']) $parent[\'check_in\'] = $b_cin;
        if ($b_cout > $parent[\'check_out\']) $parent[\'check_out\'] = $b_cout;
        if (floatval($b[\'total_amount\'] ?? 0) > $parent[\'total_amount\']) {
            $parent[\'total_amount\'] = floatval($b[\'total_amount\']);
        }
        if (!empty($b[\'special_notes\']) && strpos($parent[\'special_notes\'], $b[\'special_notes\']) === false) {
            $parent[\'special_notes\'] .= "\n" . $b[\'special_notes\'];
        }
        if ($b_type === \'house\') $parent[\'has_house\'] = true;
        if ($b_type === \'activity\') $parent[\'has_activity\'] = true;

        // Check if item is already recorded
        $item_exists = false;
        foreach ($parent[\'items\'] as $it) {
            if ($it[\'name\'] === $item_title && $it[\'type\'] === $b_type) {
                $item_exists = true;
                break;
            }
        }
        if (!$item_exists) {
            $parent[\'items\'][] = [
                \'name\' => $item_title,
                \'type\' => $b_type,
                \'item_id\' => intval($b[\'item_id\'] ?? 0),
                \'check_in\' => $b_cin,
                \'check_out\' => $b_cout,
                \'time_slot\' => $b[\'time_slot\'] ?? \'\',
                \'pax\' => intval($b[\'pax\'] ?? 1),
                \'icon\' => ($b_type === \'activity\') ? \'fa-water-ladder\' : \'fa-house-chimney\'
            ];
        }
        unset($parent);
    }
}

// Attach any addon_reservations to their parent booking
foreach ($addons_by_ref as $refKey => $addonList) {
    if (isset($grouped_reservations[$refKey])) {
        foreach ($addonList as $ad) {
            $actName = $ad[\'activity_name\'] ?? (\'Activity #\' . $ad[\'activity_id\']);
            $alreadyExists = false;
            foreach ($grouped_reservations[$refKey][\'items\'] as $it) {
                if ($it[\'name\'] === $actName && $it[\'type\'] === \'activity\') {
                    $alreadyExists = true;
                    break;
                }
            }
            if (!$alreadyExists) {
                $schedStr = !empty($ad[\'activity_date\']) ? date(\'M d, Y\', strtotime($ad[\'activity_date\'])) : \'\';
                if (!empty($ad[\'start_time\'])) {
                    $schedStr .= \' at \' . date(\'g:i A\', strtotime($ad[\'start_time\']));
                }
                $grouped_reservations[$refKey][\'items\'][] = [
                    \'name\' => $actName,
                    \'type\' => \'activity\',
                    \'item_id\' => intval($ad[\'activity_id\'] ?? 0),
                    \'check_in\' => $ad[\'activity_date\'] ?? $grouped_reservations[$refKey][\'check_in\'],
                    \'check_out\' => $ad[\'activity_date\'] ?? $grouped_reservations[$refKey][\'check_out\'],
                    \'time_slot\' => $schedStr,
                    \'pax\' => intval($ad[\'quantity\'] ?? 1),
                    \'icon\' => \'fa-water-ladder\'
                ];
                $grouped_reservations[$refKey][\'has_activity\'] = true;
            }
        }
    }
}

// Expand items if special_notes has line-item accommodations or activities
foreach ($grouped_reservations as &$res) {
    $notes = $res[\'special_notes\'] ?? \'\';
    
    // Check if notes list individual accommodations or activities: • 1x Beach Kubo...
    if (preg_match_all(\'/•\s*(?:\d+x\s*)?([^\n\r@•\(\]]+?)(?:\s*@|\s*\(|\s*\[|\s*-\s*₱|\s*$)/m\', $notes, $matches)) {
        $parsedNames = array_filter(array_map(\'trim\', $matches[1]));
        
        $hasCompound = false;
        foreach ($res[\'items\'] as $it) {
            if (stripos($it[\'name\'], \'More\') !== false || stripos($it[\'name\'], \'+\') !== false) {
                $hasCompound = true;
                break;
            }
        }
        
        if ($hasCompound && count($parsedNames) >= 2) {
            $newItems = [];
            foreach ($parsedNames as $pName) {
                if (empty($pName)) continue;
                $isAct = (stripos($notes, "[Pool & Activities Add-ons]") !== false && stripos($notes, $pName) > stripos($notes, "[Pool & Activities Add-ons]"));
                $newItems[] = [
                    \'name\' => $pName,
                    \'type\' => $isAct ? \'activity\' : \'house\',
                    \'item_id\' => 0,
                    \'check_in\' => $res[\'check_in\'],
                    \'check_out\' => $res[\'check_out\'],
                    \'time_slot\' => $res[\'time_slot\'],
                    \'pax\' => $res[\'pax\'],
                    \'icon\' => $isAct ? \'fa-water-ladder\' : \'fa-house-chimney\'
                ];
                if ($isAct) $res[\'has_activity\'] = true;
                else $res[\'has_house\'] = true;
            }
            if (!empty($newItems)) {
                $res[\'items\'] = $newItems;
            }
        }
    }

    // Format display title and summary
    $itemCount = count($res[\'items\']);
    if ($itemCount === 0) {
        $res[\'primary_item\'] = \'Resort Reservation\';
        $res[\'summary_text\'] = \'Reservation\';
    } elseif ($itemCount === 1) {
        $res[\'primary_item\'] = $res[\'items\'][0][\'name\'];
        $res[\'summary_text\'] = $res[\'items\'][0][\'name\'];
    } else {
        $res[\'primary_item\'] = $res[\'items\'][0][\'name\'];
        $actCount = 0;
        $houseCount = 0;
        foreach ($res[\'items\'] as $it) {
            if ($it[\'type\'] === \'activity\') $actCount++;
            else $houseCount++;
        }

        if ($houseCount >= 1 && $actCount === 1) {
            $res[\'summary_text\'] = $res[\'primary_item\'] . \' + 1 Activity\';
        } elseif ($houseCount >= 1 && $actCount > 1) {
            $res[\'summary_text\'] = $res[\'primary_item\'] . \' + \' . $actCount . \' Activities\';
        } else {
            $rem = $itemCount - 1;
            $res[\'summary_text\'] = $res[\'primary_item\'] . \' + \' . $rem . \' More\';
        }
    }
}
unset($res);

// -------------------------------------------------------------
// CALCULATE STATS & MAP TO CALENDAR ACROSS DATE RANGE
// -------------------------------------------------------------
$total_bookings_count = count($grouped_reservations);
$past_bookings_count = 0;
$present_bookings_count = 0;
$future_bookings_count = 0;

$calendar_map = []; // \'YYYY-MM-DD\' => [ grouped reservations active on this day ]

foreach ($grouped_reservations as $ref => $res) {
    $status = strtolower($res[\'booking_status\'] ?? \'pending\');
    $cin = $res[\'check_in\'];
    $cout = $res[\'check_out\'];
    if ($cout < $cin) $cout = $cin;

    // Timeline categorization
    if ($cout < $today_str || in_array($status, [\'checked_out\', \'completed\'])) {
        $past_bookings_count++;
        $timeline_cat = \'past\';
    } elseif ($cin <= $today_str && $cout >= $today_str) {
        $present_bookings_count++;
        $timeline_cat = \'present\';
    } else {
        $future_bookings_count++;
        $timeline_cat = \'future\';
    }

    $res[\'timeline_cat\'] = $timeline_cat;

    // Multi-day span across stay range
    $start_ts = strtotime($cin);
    $end_ts = strtotime($cout);

    for ($curr_ts = $start_ts; $curr_ts <= $end_ts; $curr_ts = strtotime(\'+1 day\', $curr_ts)) {
        $dStr = date(\'Y-m-d\', $curr_ts);
        $wDay = intval(date(\'w\', $curr_ts)); // 0 = Sun, 6 = Sat

        // Determine span type for continuous visual connection
        if ($cin === $cout) {
            $span_type = \'single\';
            $stay_role = \'single\';
        } elseif ($dStr === $cin) {
            $span_type = \'start\';
            $stay_role = \'checkin\';
        } elseif ($dStr === $cout) {
            $span_type = \'end\';
            $stay_role = \'checkout\';
        } else {
            $span_type = \'middle\';
            $stay_role = \'stay\';
        }

        $dayItem = $res;
        $dayItem[\'span_type\'] = $span_type;
        $dayItem[\'stay_role\'] = $stay_role;
        $dayItem[\'wday\'] = $wDay;
        $dayItem[\'curr_date\'] = $dStr;

        $calendar_map[$dStr][] = $dayItem;
    }
}';

$content = str_replace($old_backend_search, $new_backend_replacement, $content);

// =========================================================================
// SECTION B: INJECT REFINED CSS FOR CONNECTED MULTI-DAY BANNERS
// =========================================================================
$old_css_search = '/* Booking Event Pills */
        .cal-event-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
            overflow-y: auto;
            max-height: 140px;
        }
        .cal-event-pill {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 6px;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s ease;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.25;
        }
        .cal-event-pill:hover {
            transform: translateX(2px);
            filter: brightness(0.96);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }

        /* Kubo Accommodation Pill */
        .cal-pill-house {
            background: #f5f3ff;
            color: #5b21b6;
            border-color: #ddd6fe;
        }
        .cal-pill-house:hover {
            background: #ede9fe;
            border-color: #c4b5fd;
        }

        /* Activity Pill */
        .cal-pill-activity {
            background: #ecfdf5;
            color: #065f46;
            border-color: #a7f3d0;
        }
        .cal-pill-activity:hover {
            background: #d1fae5;
            border-color: #6ee7b7;
        }

        /* Timeline / Stay Role Badges */
        .cal-role-tag {
            font-size: 0.62rem;
            padding: 1px 4px;
            border-radius: 4px;
            text-transform: uppercase;
            font-weight: 800;
            flex-shrink: 0;
        }
        .cal-role-in {
            background: #10b981;
            color: #ffffff;
        }
        .cal-role-stay {
            background: #8b5cf6;
            color: #ffffff;
        }
        .cal-role-out {
            background: #f97316;
            color: #ffffff;
        }
        .cal-role-single {
            background: #0284c7;
            color: #ffffff;
        }

        /* Past / Checked Out Indicator */
        .cal-pill-past {
            opacity: 0.75;
            filter: grayscale(0.2);
        }';

$new_css_replacement = '/* Grouped Booking Event Banners (Continuous Multi-Day Reservation) */
        .cal-event-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
            overflow-y: auto;
            max-height: 140px;
            padding: 2px 0;
        }
        .cal-event-banner {
            position: relative;
            display: flex;
            flex-direction: column;
            padding: 5px 7px;
            font-size: 0.72rem;
            font-weight: 700;
            line-height: 1.25;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.15s ease, filter 0.15s ease;
            box-sizing: border-box;
            user-select: none;
            z-index: 2;
            overflow: hidden;
            border: 1.5px solid transparent;
        }
        .cal-event-banner:hover {
            z-index: 5;
            filter: brightness(0.95);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.12);
        }

        /* Unified Status Appearances (Requirement 8) */
        /* Confirmed / Approved -> Royal Purple Theme */
        .cal-banner-confirmed {
            background: #f5f3ff;
            color: #5b21b6;
            border-color: #c4b5fd;
        }
        .cal-banner-confirmed .banner-meta { color: #7c3aed; }

        /* Checked-In / Active Stay -> Vibrant Purple/Indigo */
        .cal-banner-checked_in {
            background: #ede9fe;
            color: #4338ca;
            border-color: #a78bfa;
        }
        .cal-banner-checked_in .banner-meta { color: #6d28d9; }

        /* Pending Approval / Proof Submitted -> Warm Amber/Gold */
        .cal-banner-pending,
        .cal-banner-proof_submitted {
            background: #fffbeb;
            color: #92400e;
            border-color: #fcd34d;
        }
        .cal-banner-pending .banner-meta,
        .cal-banner-proof_submitted .banner-meta { color: #b45309; }

        /* Completed / Checked Out -> Slate Muted */
        .cal-banner-completed,
        .cal-banner-checked_out {
            background: #f1f5f9;
            color: #475569;
            border-color: #cbd5e1;
            opacity: 0.85;
        }
        .cal-banner-completed .banner-meta,
        .cal-banner-checked_out .banner-meta { color: #64748b; }

        /* Cancelled -> Muted Red */
        .cal-banner-cancelled {
            background: #fff1f2;
            color: #9f1239;
            border-color: #fecdd3;
            opacity: 0.75;
        }

        /* Continuous Multi-Day Connected Banners (Requirement 3 & 9) */
        .cal-span-single {
            border-radius: 7px;
            margin-left: 0;
            margin-right: 0;
        }
        .cal-span-start {
            border-top-left-radius: 7px;
            border-bottom-left-radius: 7px;
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
            margin-right: -9px; /* Seamless continuous bar across cells */
            border-right: none !important;
        }
        .cal-span-middle {
            border-radius: 0;
            margin-left: -9px;
            margin-right: -9px;
            border-left: none !important;
            border-right: none !important;
        }
        .cal-span-end {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
            border-top-right-radius: 7px;
            border-bottom-right-radius: 7px;
            margin-left: -9px;
            border-left: none !important;
        }

        /* Row boundary breaks (Saturday -> Sunday wrap) */
        .cal-span-break-right {
            border-top-right-radius: 5px !important;
            border-bottom-right-radius: 5px !important;
            margin-right: 0 !important;
            border-right: 1.5px solid inherit !important;
        }
        .cal-span-break-left {
            border-top-left-radius: 5px !important;
            border-bottom-left-radius: 5px !important;
            margin-left: 0 !important;
            border-left: 1.5px solid inherit !important;
        }

        /* Banner Internal Content Elements */
        .cal-banner-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 4px;
            overflow: hidden;
            white-space: nowrap;
        }
        .cal-banner-guest-title {
            display: flex;
            align-items: center;
            gap: 5px;
            font-weight: 800;
            font-size: 0.75rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .cal-banner-ref-tag {
            font-size: 0.62rem;
            font-weight: 800;
            opacity: 0.85;
            font-family: \'Outfit\', sans-serif;
            flex-shrink: 0;
        }
        .cal-banner-sub-row {
            font-size: 0.68rem;
            font-weight: 600;
            margin-top: 1px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            opacity: 0.92;
        }
        .cal-banner-role-badge {
            font-size: 0.58rem;
            font-weight: 800;
            padding: 1px 4px;
            border-radius: 3px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            flex-shrink: 0;
            background: rgba(0, 0, 0, 0.08);
        }';

$content = str_replace($old_css_search, $new_css_replacement, $content);

// =========================================================================
// SECTION C: UPDATE CELL RENDERING LOOP IN CALENDAR GRID
// =========================================================================
$old_cell_loop_search = '                    $is_past = ($dateKey < $today_str);
                    $is_future = ($dateKey > $today_str);
                    
                    $dayAllBookings = $calendar_map[$dateKey] ?? [];

                    // Filter bookings for this day according to active filters
                    $dayFilteredBookings = [];
                    foreach ($dayAllBookings as $b) {
                        // Type filter
                        if ($type_filter !== \'all\' && ($b[\'type\'] ?? \'\') !== $type_filter) {
                            continue;
                        }
                        // Specific item filter
                        if ($item_filter > 0 && intval($b[\'item_id\'] ?? 0) !== $item_filter) {
                            continue;
                        }
                        // Status filter
                        if ($status_filter !== \'all\' && strtolower($b[\'booking_status\'] ?? \'\') !== $status_filter) {
                            continue;
                        }
                        // Timeline scope filter
                        if ($time_filter !== \'all\' && ($b[\'timeline_cat\'] ?? \'\') !== $time_filter) {
                            continue;
                        }
                        $dayFilteredBookings[] = $b;
                    }
                    $bCount = count($dayFilteredBookings);

                    // Structured data for Day Schedule Modal
                    $dayModalList = [];
                    foreach ($dayFilteredBookings as $bItem) {
                        $dayModalList[] = [
                            \'id\' => $bItem[\'id\'] ?? \'\',
                            \'guest_name\' => $bItem[\'guest_name\'] ?? \'Resort Guest\',
                            \'guest_email\' => $bItem[\'guest_email\'] ?? \'\',
                            \'guest_phone\' => $bItem[\'guest_phone\'] ?? \'\',
                            \'profile_id\' => $bItem[\'profile_id\'] ?? \'\',
                            \'item_name\' => $bItem[\'item_name\'] ?? ($bItem[\'type\'] === \'activity\' ? \'Activity\' : \'House Kubo\'),
                            \'type\' => ucfirst($bItem[\'type\'] ?? \'house\'),
                            \'check_in\' => $bItem[\'check_in\'] ?? \'\',
                            \'check_out\' => $bItem[\'check_out\'] ?? \'\',
                            \'time_slot\' => $bItem[\'time_slot\'] ?? \'\',
                            \'pax\' => $bItem[\'pax\'] ?? 1,
                            \'total_amount\' => number_format(floatval($bItem[\'total_amount\'] ?? 0), 2),
                            \'payment_status\' => ucfirst($bItem[\'payment_status\'] ?? \'proof_submitted\'),
                            \'booking_status\' => ucfirst($bItem[\'booking_status\'] ?? \'pending\'),
                            \'stay_role\' => strtoupper($bItem[\'role_in_stay\'] ?? \'stay\'),
                            \'timeline\' => strtoupper($bItem[\'timeline_cat\'] ?? \'future\'),
                            \'notes\' => $bItem[\'special_notes\'] ?? \'\'
                        ];
                    }
                    $dayBookingsJson = htmlspecialchars(json_encode($dayModalList), ENT_QUOTES, \'UTF-8\');
                    $formattedDateStr = date(\'l, F j, Y\', strtotime($dateKey));
                    $timelineStatus = $is_today ? \'TODAY\' : ($is_past ? \'PAST\' : \'UPCOMING\');
                ?>
                    <div class="cal-day-cell <?= $is_today ? \'cal-cell-today\' : ($is_past ? \'cal-cell-past\' : \'cal-cell-future\') ?>" 
                         data-date="<?= $dateKey ?>"
                         data-day="<?= $d ?>"
                         data-is-today="<?= $is_today ? \'1\' : \'0\' ?>"
                         data-is-past="<?= $is_past ? \'1\' : \'0\' ?>"
                         onclick="openDateScheduleModal(\'<?= $dateKey ?>\', \'<?= $formattedDateStr ?>\', \'<?= $timelineStatus ?>\', <?= $dayBookingsJson ?>)"
                         title="Click to view schedule and bookings for <?= $formattedDateStr ?>">
                        
                        <div class="cal-cell-top">
                            <span class="cal-cell-num">
                                <?= $d ?>
                                <?php if ($is_today): ?>
                                    <span class="cal-today-pill"><i class="fa-solid fa-star"></i> Today</span>
                                <?php endif; ?>
                            </span>

                            <?php if ($bCount > 0): ?>
                                <span class="cal-count-badge" title="<?= $bCount ?> Booking(s) on <?= date(\'M d\', strtotime($dateKey)) ?>">
                                    <?= $bCount ?> <?= $bCount === 1 ? \'Book\' : \'Books\' ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Booking Event Pills -->
                        <div class="cal-event-list">
                            <?php if ($bCount === 0): ?>
                                <div class="cal-empty-prompt">No bookings</div>
                            <?php else: ?>
                                <?php foreach ($dayFilteredBookings as $b): 
                                    $bType = $b[\'type\'] ?? \'house\';
                                    $stayRole = $b[\'role_in_stay\'] ?? \'stay\';
                                    $timelineCat = $b[\'timeline_cat\'] ?? \'future\';
                                    $bStatus = strtolower($b[\'booking_status\'] ?? \'pending\');
                                    $guestName = $b[\'guest_name\'] ?? \'Resort Guest\';
                                    $itemName = $b[\'item_name\'] ?? ($bType === \'house\ ? \'Kubo House\' : \'Activity\');
                                    
                                    // Role Tag display
                                    $roleTagClass = \'cal-role-stay\';
                                    $roleLabel = \'STAY\';
                                    if ($stayRole === \'checkin\') {
                                        $roleTagClass = \'cal-role-in\';
                                        $roleLabel = \'IN\';
                                    } elseif ($stayRole === \'checkout\') {
                                        $roleTagClass = \'cal-role-out\';
                                        $roleLabel = \'OUT\';
                                    } elseif ($stayRole === \'single\') {
                                        $roleTagClass = \'cal-role-single\';
                                        $roleLabel = \'DAY\';
                                    }

                                    // Type styling
                                    $pillTypeClass = ($bType === \'activity\') ? \'cal-pill-activity\' : \'cal-pill-house\';
                                    if ($timelineCat === \'past\' || in_array($bStatus, [\'completed\', \'checked_out\'])) {
                                        $pillTypeClass .= \' cal-pill-past\';
                                    }

                                    // JSON data for Modal
                                    $modalDataJson = htmlspecialchars(json_encode([
                                        \'id\' => $b[\'id\'] ?? \'\',
                                        \'guest_name\' => $guestName,
                                        \'guest_email\' => $b[\'guest_email\'] ?? \'\',
                                        \'guest_phone\' => $b[\'guest_phone\'] ?? \'\',
                                        \'profile_id\' => $b[\'profile_id\'] ?? \'\',
                                        \'item_name\' => $itemName,
                                        \'type\' => ucfirst($bType),
                                        \'check_in\' => $b[\'check_in\'] ?? \'\',
                                        \'check_out\' => $b[\'check_out\'] ?? \'\',
                                        \'time_slot\' => $b[\'time_slot\'] ?? \'\',
                                        \'pax\' => $b[\'pax\'] ?? 1,
                                        \'total_amount\' => number_format(floatval($b[\'total_amount\'] ?? 0), 2),
                                        \'payment_status\' => ucfirst($b[\'payment_status\'] ?? \'proof_submitted\'),
                                        \'booking_status\' => ucfirst($b[\'booking_status\'] ?? \'pending\'),
                                        \'stay_role\' => strtoupper($stayRole),
                                        \'timeline\' => strtoupper($timelineCat),
                                        \'notes\' => $b[\'special_notes\'] ?? \'\'
                                    ]), ENT_QUOTES, \'UTF-8\');
                                ?>
                                    <div class="cal-event-pill <?= $pillTypeClass ?>" 
                                         onclick="event.stopPropagation(); openBookingDetailModal(<?= $modalDataJson ?>)"
                                         data-guest="<?= strtolower(htmlspecialchars($guestName)) ?>"
                                         data-item="<?= strtolower(htmlspecialchars($itemName)) ?>"
                                         data-ref="<?= strtolower(htmlspecialchars($b[\'id\'] ?? \'\')) ?>"
                                         data-timeline="<?= $timelineCat ?>"
                                         title="Click to view details | <?= htmlspecialchars($itemName) ?> - <?= htmlspecialchars($guestName) ?> (<?= strtoupper($stayRole) ?>)">
                                         
                                         <span class="cal-role-tag <?= $roleTagClass ?>"><?= $roleLabel ?></span>
                                         <i class="fa-solid <?= $bType === \'activity\' ? \'fa-water-ladder\' : \'fa-house-chimney\' ?>" style="font-size: 0.7rem; flex-shrink: 0;"></i>
                                         <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                             <?= htmlspecialchars($itemName) ?> · <?= htmlspecialchars($guestName) ?>
                                         </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                        </div>
                    </div>';

$new_cell_loop_replacement = '                    $is_past = ($dateKey < $today_str);
                    $is_future = ($dateKey > $today_str);
                    
                    $dayAllBookings = $calendar_map[$dateKey] ?? [];

                    // Filter grouped reservations according to active filters
                    $dayFilteredBookings = [];
                    foreach ($dayAllBookings as $b) {
                        // Type filter
                        if ($type_filter !== \'all\') {
                            if ($type_filter === \'house\' && empty($b[\'has_house\'])) continue;
                            if ($type_filter === \'activity\' && empty($b[\'has_activity\'])) continue;
                        }
                        // Specific item filter
                        if ($item_filter > 0) {
                            $matchesItem = false;
                            foreach ($b[\'items\'] as $it) {
                                if (isset($it[\'item_id\']) && intval($it[\'item_id\']) === $item_filter) {
                                    $matchesItem = true;
                                    break;
                                }
                            }
                            if (!$matchesItem) continue;
                        }
                        // Status filter
                        if ($status_filter !== \'all\' && strtolower($b[\'booking_status\'] ?? \'\') !== $status_filter) {
                            continue;
                        }
                        // Timeline scope filter
                        if ($time_filter !== \'all\' && ($b[\'timeline_cat\'] ?? \'\') !== $time_filter) {
                            continue;
                        }
                        $dayFilteredBookings[] = $b;
                    }
                    $bCount = count($dayFilteredBookings);

                    // Structured data for Day Schedule Modal (Grouped reservations)
                    $dayModalList = [];
                    foreach ($dayFilteredBookings as $bItem) {
                        $dayModalList[] = [
                            \'id\' => $bItem[\'id\'] ?? \'\',
                            \'guest_name\' => $bItem[\'guest_name\'] ?? \'Resort Guest\',
                            \'guest_email\' => $bItem[\'guest_email\'] ?? \'\',
                            \'guest_phone\' => $bItem[\'guest_phone\'] ?? \'\',
                            \'profile_id\' => $bItem[\'profile_id\'] ?? \'\',
                            \'item_name\' => $bItem[\'summary_text\'] ?? \'Reservation\',
                            \'primary_item\' => $bItem[\'primary_item\'] ?? \'\',
                            \'summary_text\' => $bItem[\'summary_text\'] ?? \'\',
                            \'items\' => $bItem[\'items\'] ?? [],
                            \'type\' => ($bItem[\'has_house\'] ? \'House Kubo\' : \'Activity\'),
                            \'check_in\' => $bItem[\'check_in\'] ?? \'\',
                            \'check_out\' => $bItem[\'check_out\'] ?? \'\',
                            \'time_slot\' => $bItem[\'time_slot\'] ?? \'\',
                            \'pax\' => $bItem[\'pax\'] ?? 1,
                            \'total_amount\' => number_format(floatval($bItem[\'total_amount\'] ?? 0), 2),
                            \'payment_status\' => ucfirst($bItem[\'payment_status\'] ?? \'proof_submitted\'),
                            \'booking_status\' => ucfirst($bItem[\'booking_status\'] ?? \'pending\'),
                            \'stay_role\' => strtoupper($bItem[\'stay_role\'] ?? \'stay\'),
                            \'timeline\' => strtoupper($bItem[\'timeline_cat\'] ?? \'future\'),
                            \'notes\' => $bItem[\'special_notes\'] ?? \'\'
                        ];
                    }
                    $dayBookingsJson = htmlspecialchars(json_encode($dayModalList), ENT_QUOTES, \'UTF-8\');
                    $formattedDateStr = date(\'l, F j, Y\', strtotime($dateKey));
                    $timelineStatus = $is_today ? \'TODAY\' : ($is_past ? \'PAST\' : \'UPCOMING\');
                ?>
                    <div class="cal-day-cell <?= $is_today ? \'cal-cell-today\' : ($is_past ? \'cal-cell-past\' : \'cal-cell-future\') ?>" 
                         data-date="<?= $dateKey ?>"
                         data-day="<?= $d ?>"
                         data-is-today="<?= $is_today ? \'1\' : \'0\' ?>"
                         data-is-past="<?= $is_past ? \'1\' : \'0\' ?>"
                         onclick="openDateScheduleModal(\'<?= $dateKey ?>\', \'<?= $formattedDateStr ?>\', \'<?= $timelineStatus ?>\', <?= $dayBookingsJson ?>)"
                         title="Click to view schedule and bookings for <?= $formattedDateStr ?>">
                        
                        <div class="cal-cell-top">
                            <span class="cal-cell-num">
                                <?= $d ?>
                                <?php if ($is_today): ?>
                                    <span class="cal-today-pill"><i class="fa-solid fa-star"></i> Today</span>
                                <?php endif; ?>
                            </span>

                            <?php if ($bCount > 0): ?>
                                <span class="cal-count-badge" title="<?= $bCount ?> Reservation(s) active on <?= date(\'M d\', strtotime($dateKey)) ?>">
                                    <?= $bCount ?> <?= $bCount === 1 ? \'Booking\' : \'Bookings\' ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Connected Continuous Booking Banners (One Booking = One Calendar Reservation) -->
                        <div class="cal-event-list">
                            <?php if ($bCount === 0): ?>
                                <div class="cal-empty-prompt">No bookings</div>
                            <?php else: ?>
                                <?php foreach ($dayFilteredBookings as $b): 
                                    $bRef = $b[\'id\'] ?? \'\';
                                    $guestName = $b[\'guest_name\'] ?? \'Resort Guest\';
                                    $summaryText = $b[\'summary_text\'] ?? ($b[\'primary_item\'] ?? \'Reservation\');
                                    $bStatusRaw = strtolower($b[\'booking_status\'] ?? \'pending\');
                                    $spanType = $b[\'span_type\'] ?? \'single\';
                                    $stayRole = $b[\'stay_role\'] ?? \'stay\';
                                    $wDay = intval($b[\'wday\'] ?? 0);

                                    // Break continuity cleanly at Saturday (6) or Sunday (0) row boundaries
                                    $breakClass = \'\';
                                    if ($wDay === 6 && ($spanType === \'start\' || $spanType === \'middle\')) {
                                        $breakClass = \' cal-span-break-right\';
                                    } elseif ($wDay === 0 && ($spanType === \'middle\' || $spanType === \'end\')) {
                                        $breakClass = \' cal-span-break-left\';
                                    }

                                    // Primary status theme class (Requirement 8)
                                    $statusThemeClass = \'cal-banner-pending\';
                                    if ($bStatusRaw === \'confirmed\') $statusThemeClass = \'cal-banner-confirmed\';
                                    elseif ($bStatusRaw === \'checked_in\') $statusThemeClass = \'cal-banner-checked_in\';
                                    elseif ($bStatusRaw === \'completed\' || $bStatusRaw === \'checked_out\') $statusThemeClass = \'cal-banner-completed\';
                                    elseif ($bStatusRaw === \'cancelled\') $statusThemeClass = \'cal-banner-cancelled\';

                                    // Primary icon: accommodation vs activity
                                    $primaryIcon = $b[\'has_house\'] ? \'fa-house-chimney\' : \'fa-water-ladder\';

                                    // JSON data for Details Modal
                                    $modalDataJson = htmlspecialchars(json_encode([
                                        \'id\' => $bRef,
                                        \'guest_name\' => $guestName,
                                        \'guest_email\' => $b[\'guest_email\'] ?? \'\',
                                        \'guest_phone\' => $b[\'guest_phone\'] ?? \'\',
                                        \'profile_id\' => $b[\'profile_id\'] ?? \'\',
                                        \'item_name\' => $summaryText,
                                        \'primary_item\' => $b[\'primary_item\'] ?? \'\',
                                        \'summary_text\' => $summaryText,
                                        \'items\' => $b[\'items\'] ?? [],
                                        \'type\' => ($b[\'has_house\'] ? \'House Kubo\' : \'Activity\'),
                                        \'check_in\' => $b[\'check_in\'] ?? \'\',
                                        \'check_out\' => $b[\'check_out\'] ?? \'\',
                                        \'time_slot\' => $b[\'time_slot\'] ?? \'\',
                                        \'pax\' => $b[\'pax\'] ?? 1,
                                        \'total_amount\' => number_format(floatval($b[\'total_amount\'] ?? 0), 2),
                                        \'payment_status\' => ucfirst($b[\'payment_status\'] ?? \'proof_submitted\'),
                                        \'booking_status\' => ucfirst($b[\'booking_status\'] ?? \'pending\'),
                                        \'stay_role\' => strtoupper($stayRole),
                                        \'timeline\' => strtoupper($b[\'timeline_cat\'] ?? \'future\'),
                                        \'notes\' => $b[\'special_notes\'] ?? \'\'
                                    ]), ENT_QUOTES, \'UTF-8\');

                                    // All items as searchable string for client-side filter
                                    $allItemsSearch = strtolower($summaryText);
                                    foreach ($b[\'items\'] as $it) {
                                        $allItemsSearch .= \' \' . strtolower($it[\'name\'] ?? \'\');
                                    }
                                ?>
                                    <div class="cal-event-banner <?= $statusThemeClass ?> cal-span-<?= $spanType ?><?= $breakClass ?>" 
                                         onclick="event.stopPropagation(); openBookingDetailModal(<?= $modalDataJson ?>)"
                                         data-guest="<?= strtolower(htmlspecialchars($guestName)) ?>"
                                         data-item="<?= htmlspecialchars($allItemsSearch) ?>"
                                         data-ref="<?= strtolower(htmlspecialchars($bRef)) ?>"
                                         data-timeline="<?= $b[\'timeline_cat\'] ?? \'future\' ?>"
                                         title="<?= htmlspecialchars($guestName) ?> · #<?= htmlspecialchars($bRef) ?> (<?= htmlspecialchars($summaryText) ?>)">
                                        
                                        <?php if ($spanType === \'start\' || $spanType === \'single\'): ?>
                                            <!-- Top Header: Guest Name & Reference -->
                                            <div class="cal-banner-header-row">
                                                <span class="cal-banner-guest-title">
                                                    <i class="fa-solid <?= $primaryIcon ?>" style="font-size: 0.68rem; flex-shrink: 0;"></i>
                                                    <?= htmlspecialchars($guestName) ?>
                                                </span>
                                                <span class="cal-banner-ref-tag">#<?= htmlspecialchars($bRef) ?></span>
                                            </div>
                                            <!-- Subtitle: Main item + additional items (Requirement 5) -->
                                            <div class="cal-banner-sub-row">
                                                <?= htmlspecialchars($summaryText) ?>
                                            </div>

                                        <?php elseif ($spanType === \'middle\'): ?>
                                            <!-- Middle of Stay: Connected continuous bar -->
                                            <div class="cal-banner-header-row">
                                                <span class="cal-banner-guest-title" style="font-size: 0.7rem;">
                                                    <i class="fa-solid fa-arrows-left-right" style="font-size: 0.62rem; opacity: 0.8;"></i>
                                                    <?= htmlspecialchars($guestName) ?>
                                                </span>
                                                <span class="cal-banner-ref-tag" style="font-size: 0.6rem;">#<?= htmlspecialchars($bRef) ?></span>
                                            </div>
                                            <div class="cal-banner-sub-row" style="font-size: 0.65rem;">
                                                <?= htmlspecialchars($summaryText) ?>
                                            </div>

                                        <?php elseif ($spanType === \'end\'): ?>
                                            <!-- Checkout Day: Ending continuous bar -->
                                            <div class="cal-banner-header-row">
                                                <span class="cal-banner-guest-title" style="font-size: 0.7rem;">
                                                    <i class="fa-solid fa-arrow-right-from-bracket" style="font-size: 0.62rem; opacity: 0.85;"></i>
                                                    <?= htmlspecialchars($guestName) ?>
                                                </span>
                                                <span class="cal-banner-role-badge">OUT</span>
                                            </div>
                                            <div class="cal-banner-sub-row" style="font-size: 0.65rem;">
                                                Check-out · #<?= htmlspecialchars($bRef) ?>
                                            </div>
                                        <?php endif; ?>

                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                        </div>
                    </div>';

$content = str_replace($old_cell_loop_search, $new_cell_loop_replacement, $content);

// =========================================================================
// SECTION D: ENHANCE BOOKING DETAIL MODAL MARKUP (Show all items with icons)
// =========================================================================
$old_modal_grid_search = '            <div style="background: #f8fafc; border-radius: 10px; padding: 0.85rem; border: 1px solid #e2e8f0;" class="cal-modal-grid">
                <div class="cal-modal-field">
                    <label>Booked Item / Unit</label>
                    <div id="modalItemName" style="color: #7c3aed;">--</div>
                </div>
                <div class="cal-modal-field">
                    <label>Category</label>
                    <div id="modalType">--</div>
                </div>
                <div class="cal-modal-field">
                    <label>Check-In Date</label>
                    <div id="modalCheckIn">--</div>
                </div>
                <div class="cal-modal-field">
                    <label>Check-Out / Time</label>
                    <div id="modalCheckOut">--</div>
                </div>
                <div class="cal-modal-field">
                    <label>Guests (Pax)</label>
                    <div id="modalPax">--</div>
                </div>
                <div class="cal-modal-field">
                    <label>Total Amount</label>
                    <div id="modalAmount" style="color: #059669; font-size: 1.05rem;">₱0.00</div>
                </div>
            </div>';

$new_modal_grid_replacement = '            <div style="background: #f8fafc; border-radius: 10px; padding: 0.85rem; border: 1px solid #e2e8f0;" class="cal-modal-grid">
                <div class="cal-modal-field">
                    <label>Primary Accommodation / Booking</label>
                    <div id="modalItemName" style="color: #7c3aed; font-weight: 700;">--</div>
                </div>
                <div class="cal-modal-field">
                    <label>Category</label>
                    <div id="modalType">--</div>
                </div>
                <div class="cal-modal-field">
                    <label>Stay Schedule (Check-In)</label>
                    <div id="modalCheckIn">--</div>
                </div>
                <div class="cal-modal-field">
                    <label>Stay Schedule (Check-Out)</label>
                    <div id="modalCheckOut">--</div>
                </div>
                <div class="cal-modal-field">
                    <label>Guests (Pax)</label>
                    <div id="modalPax">--</div>
                </div>
                <div class="cal-modal-field">
                    <label>Total Reservation Amount</label>
                    <div id="modalAmount" style="color: #059669; font-size: 1.05rem; font-weight: 800;">₱0.00</div>
                </div>
            </div>

            <!-- Grouped Booked Items & Activity Breakdown (Requirement 6) -->
            <div id="modalItemsBreakdownSection" style="margin-top: 1rem; border-top: 1px solid #e2e8f0; padding-top: 0.85rem;">
                <span style="font-size: 0.75rem; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-list-check" style="color: #7c3aed;"></i> Booked Items & Activities in this Reservation
                </span>
                <div id="modalItemsContainer" style="margin-top: 0.6rem; display: flex; flex-direction: column; gap: 8px;">
                    <!-- Rendered dynamically via JS -->
                </div>
            </div>';

$content = str_replace($old_modal_grid_search, $new_modal_grid_replacement, $content);

// =========================================================================
// SECTION E: UPDATE JAVASCRIPT MODAL RENDERING FUNCTIONS
// =========================================================================
$old_js_date_modal = '    } else {
        bookings.forEach(b => {
            const isActivity = (b.type || \'\').toLowerCase() === \'activity\';
            const role = (b.stay_role || \'STAY\').toUpperCase();
            
            let roleBadge = \'\';
            if (role === \'CHECKIN\' || role === \'IN\') {
                roleBadge = \'<span class="admin-badge admin-badge-success" style="font-size: 0.68rem;"><i class="fa-solid fa-arrow-right-to-bracket"></i> Check-in Today</span>\';
            } else if (role === \'CHECKOUT\' || role === \'OUT\') {
                roleBadge = \'<span class="admin-badge admin-badge-danger" style="font-size: 0.68rem; background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5;"><i class="fa-solid fa-arrow-right-from-bracket"></i> Check-out Today</span>\';
            } else if (role === \'SINGLE\' || role === \'DAY\') {
                roleBadge = \'<span class="admin-badge admin-badge-info" style="font-size: 0.68rem;"><i class="fa-solid fa-clock"></i> Day Activity</span>\';
            } else {
                roleBadge = \'<span class="admin-badge admin-badge-purple" style="font-size: 0.68rem;"><i class="fa-solid fa-bed"></i> In-House Stay</span>\';
            }

            const statusSt = (b.booking_status || \'Pending\').toLowerCase();
            let statusBadge = `<span class="admin-badge admin-badge-gold" style="font-size: 0.68rem;">${b.booking_status}</span>`;
            if (statusSt === \'confirmed\') statusBadge = `<span class="admin-badge admin-badge-success" style="font-size: 0.68rem;">Confirmed</span>`;
            else if (statusSt === \'checked_in\') statusBadge = `<span class="admin-badge admin-badge-purple" style="font-size: 0.68rem;">Checked-In</span>`;
            else if (statusSt === \'checked_out\' || statusSt === \'completed\') statusBadge = `<span class="admin-badge admin-badge-info" style="font-size: 0.68rem;">Completed</span>`;

            const card = document.createElement(\'div\');
            card.className = `cal-day-booking-card ${isActivity ? \'card-activity\' : \'card-house\'}`;
            card.style.cursor = \'pointer\';
            card.onclick = function() {
                openBookingDetailModal(b);
            };
            card.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="font-weight: 800; font-size: 0.95rem; color: #7c3aed; font-family: \'Outfit\', sans-serif;">
                            #${b.id}
                        </span>
                        ${roleBadge}
                        ${statusBadge}
                    </div>
                    <span style="font-weight: 800; color: #059669; font-size: 0.95rem;">₱${b.total_amount}</span>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <div style="font-size: 1.05rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid ${isActivity ? \'fa-water-ladder\' : \'fa-house-chimney\'}" style="color: ${isActivity ? \'#059669\' : \'#7c3aed\'}; font-size: 0.95rem;"></i>
                            ${b.item_name}
                        </div>
                        <div style="font-size: 0.85rem; font-weight: 700; color: #334155; margin-top: 2px;">
                            <i class="fa-solid fa-user" style="color: #94a3b8; font-size: 0.8rem;"></i> ${b.guest_name}
                            ${b.profile_id ? `<span class="admin-badge admin-badge-purple" style="font-size: 0.62rem; margin-left: 4px;">#${b.profile_id}</span>` : \'\'}
                        </div>
                        <div style="font-size: 0.78rem; color: #64748b; margin-top: 2px;">
                            <i class="fa-solid fa-phone" style="font-size: 0.72rem;"></i> ${b.guest_phone || \'No phone\'} · 
                            <i class="fa-solid fa-envelope" style="font-size: 0.72rem;"></i> ${b.guest_email || \'No email\'}
                        </div>
                    </div>

                    <div style="text-align: right; display: flex; flex-direction: column; gap: 4px;">
                        <span style="font-size: 0.75rem; font-weight: 700; color: #475569;">
                            <i class="fa-solid fa-users"></i> ${b.pax} Guests
                        </span>
                        <span style="font-size: 0.75rem; color: #64748b;">
                            ${b.check_in} ${b.check_out ? \'→ \' + b.check_out : (b.time_slot ? \'· \' + b.time_slot : \'\')}
                        </span>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 6px; border-top: 1px solid #f1f5f9; padding-top: 8px;">
                    <a href="bookings.php?search=${encodeURIComponent(b.id)}" onclick="event.stopPropagation();" class="admin-btn admin-btn-sm admin-btn-primary">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Open in Manifest
                    </a>
                </div>
            `;
            listEl.appendChild(card);
        });
    }';

$new_js_date_modal = '    } else {
        bookings.forEach(b => {
            const role = (b.stay_role || \'STAY\').toUpperCase();
            
            let roleBadge = \'\';
            if (role === \'CHECKIN\' || role === \'IN\') {
                roleBadge = \'<span class="admin-badge admin-badge-success" style="font-size: 0.68rem;"><i class="fa-solid fa-arrow-right-to-bracket"></i> Check-in Today</span>\';
            } else if (role === \'CHECKOUT\' || role === \'OUT\') {
                roleBadge = \'<span class="admin-badge admin-badge-danger" style="font-size: 0.68rem; background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5;"><i class="fa-solid fa-arrow-right-from-bracket"></i> Check-out Today</span>\';
            } else if (role === \'SINGLE\' || role === \'DAY\') {
                roleBadge = \'<span class="admin-badge admin-badge-info" style="font-size: 0.68rem;"><i class="fa-solid fa-clock"></i> Day Reservation</span>\';
            } else {
                roleBadge = \'<span class="admin-badge admin-badge-purple" style="font-size: 0.68rem;"><i class="fa-solid fa-bed"></i> In-House Stay</span>\';
            }

            const statusSt = (b.booking_status || \'Pending\').toLowerCase();
            let statusBadge = `<span class="admin-badge admin-badge-gold" style="font-size: 0.68rem;">${b.booking_status}</span>`;
            if (statusSt === \'confirmed\') statusBadge = `<span class="admin-badge admin-badge-success" style="font-size: 0.68rem;">Confirmed</span>`;
            else if (statusSt === \'checked_in\') statusBadge = `<span class="admin-badge admin-badge-purple" style="font-size: 0.68rem;">Checked-In</span>`;
            else if (statusSt === \'checked_out\' || statusSt === \'completed\') statusBadge = `<span class="admin-badge admin-badge-info" style="font-size: 0.68rem;">Completed</span>`;

            // Build items list preview inside card
            let itemsPreviewHtml = \'\';
            const items = b.items || [];
            if (items.length > 0) {
                itemsPreviewHtml = \'<div style="margin-top: 8px; display: flex; flex-direction: column; gap: 4px; background: #f8fafc; border-radius: 8px; padding: 6px 10px; border: 1px solid #e2e8f0;">\';
                items.forEach(it => {
                    const isAct = (it.type || \'\').toLowerCase() === \'activity\';
                    const itIcon = isAct ? \'fa-water-ladder\' : \'fa-house-chimney\';
                    const itColor = isAct ? \'#059669\' : \'#7c3aed\';
                    itemsPreviewHtml += `
                        <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.8rem;">
                            <span style="font-weight: 700; color: #1e293b;">
                                <i class="fa-solid ${itIcon}" style="color: ${itColor}; margin-right: 5px; font-size: 0.75rem;"></i> ${escapeHtml(it.name)}
                            </span>
                            <span style="font-size: 0.72rem; color: #64748b;">
                                ${escapeHtml(it.time_slot || (it.check_in && it.check_out && it.check_in !== it.check_out ? (it.check_in + \' → \' + it.check_out) : (it.check_in || \'\')))}
                            </span>
                        </div>
                    `;
                });
                itemsPreviewHtml += \'</div>\';
            }

            const card = document.createElement(\'div\');
            card.className = \'cal-day-booking-card card-house\';
            card.style.cursor = \'pointer\';
            card.onclick = function() {
                openBookingDetailModal(b);
            };
            card.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="font-weight: 800; font-size: 0.95rem; color: #7c3aed; font-family: \'Outfit\', sans-serif;">
                            #${b.id}
                        </span>
                        ${roleBadge}
                        ${statusBadge}
                    </div>
                    <span style="font-weight: 800; color: #059669; font-size: 0.95rem;">₱${b.total_amount}</span>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; margin-top: 6px;">
                    <div>
                        <div style="font-size: 1.05rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-house-chimney" style="color: #7c3aed; font-size: 0.95rem;"></i>
                            ${escapeHtml(b.summary_text || b.item_name)}
                        </div>
                        <div style="font-size: 0.85rem; font-weight: 700; color: #334155; margin-top: 2px;">
                            <i class="fa-solid fa-user" style="color: #94a3b8; font-size: 0.8rem;"></i> ${escapeHtml(b.guest_name)}
                            ${b.profile_id ? `<span class="admin-badge admin-badge-purple" style="font-size: 0.62rem; margin-left: 4px;">#${escapeHtml(b.profile_id)}</span>` : \'\'}
                        </div>
                        <div style="font-size: 0.78rem; color: #64748b; margin-top: 2px;">
                            <i class="fa-solid fa-phone" style="font-size: 0.72rem;"></i> ${escapeHtml(b.guest_phone || \'No phone\')} · 
                            <i class="fa-solid fa-envelope" style="font-size: 0.72rem;"></i> ${escapeHtml(b.guest_email || \'No email\')}
                        </div>
                    </div>

                    <div style="text-align: right; display: flex; flex-direction: column; gap: 4px;">
                        <span style="font-size: 0.75rem; font-weight: 700; color: #475569;">
                            <i class="fa-solid fa-users"></i> ${b.pax} Guests
                        </span>
                        <span style="font-size: 0.75rem; color: #64748b;">
                            ${b.check_in} ${b.check_out && b.check_out !== b.check_in ? \'→ \' + b.check_out : \'\'}
                        </span>
                    </div>
                </div>

                ${itemsPreviewHtml}

                <div style="display: flex; justify-content: flex-end; gap: 6px; border-top: 1px solid #f1f5f9; padding-top: 8px; margin-top: 8px;">
                    <button type="button" class="admin-btn admin-btn-sm admin-btn-gold" style="font-size: 0.75rem;">
                        <i class="fa-solid fa-eye"></i> View Grouped Booking Details
                    </button>
                    <a href="bookings.php?search=${encodeURIComponent(b.id)}" onclick="event.stopPropagation();" class="admin-btn admin-btn-sm admin-btn-primary" style="font-size: 0.75rem;">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Open in Manifest
                    </a>
                </div>
            `;
            listEl.appendChild(card);
        });
    }';

$content = str_replace($old_js_date_modal, $new_js_date_modal, $content);

// =========================================================================
// SECTION F: UPDATE SINGLE BOOKING DETAIL MODAL JS (Render item breakdown)
// =========================================================================
$old_js_detail_modal = 'function openBookingDetailModal(data) {
    if (!data) return;
    document.getElementById(\'modalRefId\').textContent = data.id || \'N/A\';
    document.getElementById(\'modalGuestName\').textContent = data.guest_name || \'N/A\';
    document.getElementById(\'modalProfileId\').textContent = data.profile_id ? (\'Profile #\' + data.profile_id) : \'Guest Visitor\';
    document.getElementById(\'modalGuestPhone\').textContent = data.guest_phone || \'None provided\';
    document.getElementById(\'modalGuestEmail\').textContent = data.guest_email || \'None provided\';
    document.getElementById(\'modalItemName\').textContent = data.item_name || \'Accommodation\';
    document.getElementById(\'modalType\').textContent = data.type || \'House\';
    document.getElementById(\'modalCheckIn\').textContent = data.check_in || \'N/A\';
    document.getElementById(\'modalCheckOut\').textContent = data.check_out || (data.time_slot ? data.time_slot : \'Same Day\');
    document.getElementById(\'modalPax\').textContent = (data.pax || 1) + \' Guest(s)\';
    document.getElementById(\'modalAmount\').textContent = \'₱\' + (data.total_amount || \'0.00\');

    // Status Badge
    const statusDiv = document.getElementById(\'modalStatusBadge\');
    const st = (data.booking_status || \'pending\').toLowerCase();
    let badgeClass = \'admin-badge-gold\';
    if (st === \'confirmed\') badgeClass = \'admin-badge-success\';
    else if (st === \'checked_in\') badgeClass = \'admin-badge-purple\';
    else if (st === \'checked_out\' || st === \'completed\') badgeClass = \'admin-badge-info\';
    else if (st === \'cancelled\') badgeClass = \'admin-badge-danger\';
    statusDiv.innerHTML = `<span class="admin-badge ${badgeClass}"><i class="fa-solid fa-circle-dot"></i> ${data.booking_status} (${data.timeline})</span>`;

    // Notes
    const notesWrapper = document.getElementById(\'modalNotesWrapper\');
    const notesEl = document.getElementById(\'modalNotes\');
    if (data.notes && data.notes.trim() !== \'\') {
        notesWrapper.style.display = \'block\';
        notesEl.textContent = data.notes;
    } else {
        notesWrapper.style.display = \'none\';
    }

    // Direct link to Booking Management
    const manifestLink = document.getElementById(\'modalManifestLink\');
    manifestLink.href = \'bookings.php?search=\' + encodeURIComponent(data.id);

    document.getElementById(\'calModalBackdrop\').classList.add(\'open\');
}';

$new_js_detail_modal = 'function escapeHtml(text) {
    if (!text) return \'\';
    return String(text).replace(/&/g, \'&amp;\').replace(/</g, \'&lt;\').replace(/>/g, \'&gt;\').replace(/"/g, \'&quot;\').replace(/\'/g, \'&#039;\');
}

function openBookingDetailModal(data) {
    if (!data) return;
    document.getElementById(\'modalRefId\').textContent = data.id || \'N/A\';
    document.getElementById(\'modalGuestName\').textContent = data.guest_name || \'N/A\';
    document.getElementById(\'modalProfileId\').textContent = data.profile_id ? (\'Profile #\' + data.profile_id) : \'Guest Visitor\';
    document.getElementById(\'modalGuestPhone\').textContent = data.guest_phone || \'None provided\';
    document.getElementById(\'modalGuestEmail\').textContent = data.guest_email || \'None provided\';
    document.getElementById(\'modalItemName\').textContent = data.summary_text || data.item_name || \'Resort Reservation\';
    document.getElementById(\'modalType\').textContent = data.type || \'House Kubo Stay\';
    document.getElementById(\'modalCheckIn\').textContent = data.check_in || \'N/A\';
    document.getElementById(\'modalCheckOut\').textContent = (data.check_out && data.check_out !== data.check_in) ? data.check_out : (data.time_slot || \'Same Day\');
    document.getElementById(\'modalPax\').textContent = (data.pax || 1) + \' Guest(s)\';
    document.getElementById(\'modalAmount\').textContent = \'₱\' + (data.total_amount || \'0.00\');

    // Status Badge
    const statusDiv = document.getElementById(\'modalStatusBadge\');
    const st = (data.booking_status || \'pending\').toLowerCase();
    let badgeClass = \'admin-badge-gold\';
    if (st === \'confirmed\') badgeClass = \'admin-badge-success\';
    else if (st === \'checked_in\') badgeClass = \'admin-badge-purple\';
    else if (st === \'checked_out\' || st === \'completed\') badgeClass = \'admin-badge-info\';
    else if (st === \'cancelled\') badgeClass = \'admin-badge-danger\';
    statusDiv.innerHTML = `<span class="admin-badge ${badgeClass}"><i class="fa-solid fa-circle-dot"></i> ${data.booking_status}</span>`;

    // Render All Booked Items & Activities in this Reservation (Requirement 6)
    const itemsContainer = document.getElementById(\'modalItemsContainer\');
    if (itemsContainer) {
        itemsContainer.innerHTML = \'\';
        const items = data.items || [];
        if (items.length > 0) {
            items.forEach(it => {
                const isAct = (it.type || \'\').toLowerCase() === \'activity\';
                const icon = isAct ? \'fa-water-ladder\' : \'fa-house-chimney\';
                const iconColor = isAct ? \'#059669\' : \'#7c3aed\';
                const badgeClass = isAct ? \'admin-badge-success\' : \'admin-badge-purple\';
                const badgeLabel = isAct ? \'Activity\' : \'House Kubo\';

                let schedText = \'\';
                if (it.check_in && it.check_out && it.check_in !== it.check_out) {
                    schedText = `${it.check_in} → ${it.check_out}`;
                } else if (it.time_slot) {
                    schedText = it.time_slot;
                } else if (it.check_in) {
                    schedText = it.check_in;
                }

                const itemRow = document.createElement(\'div\');
                itemRow.style.cssText = \'display: flex; align-items: center; justify-content: space-between; background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 10px 14px; gap: 10px;\';
                itemRow.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 38px; height: 38px; border-radius: 10px; background: ${isAct ? \'#ecfdf5\' : \'#f5f3ff\'}; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <i class="fa-solid ${icon}" style="color: ${iconColor}; font-size: 1.05rem;"></i>
                        </div>
                        <div>
                            <div style="font-weight: 800; color: #0f172a; font-size: 0.92rem; font-family: Outfit, sans-serif;">
                                ${escapeHtml(it.name)}
                            </div>
                            <div style="font-size: 0.78rem; color: #64748b; margin-top: 2px;">
                                <i class="fa-regular fa-calendar-check" style="color: #7c3aed;"></i> ${escapeHtml(schedText || \'Included in Reservation\')}
                            </div>
                        </div>
                    </div>
                    <span class="admin-badge ${badgeClass}" style="font-size: 0.7rem; flex-shrink: 0;">${badgeLabel}</span>
                `;
                itemsContainer.appendChild(itemRow);
            });
        } else {
            itemsContainer.innerHTML = \'<div style="font-size: 0.85rem; color: #64748b; padding: 6px;">1x Standard Reservation</div>\';
        }
    }

    // Notes
    const notesWrapper = document.getElementById(\'modalNotesWrapper\');
    const notesEl = document.getElementById(\'modalNotes\');
    if (data.notes && data.notes.trim() !== \'\') {
        notesWrapper.style.display = \'block\';
        notesEl.textContent = data.notes;
    } else {
        notesWrapper.style.display = \'none\';
    }

    // Direct link to Booking Management
    const manifestLink = document.getElementById(\'modalManifestLink\');
    manifestLink.href = \'bookings.php?search=\' + encodeURIComponent(data.id);

    document.getElementById(\'calModalBackdrop\').classList.add(\'open\');
}';

$content = str_replace($old_js_detail_modal, $new_js_detail_modal, $content);

// Save updated calendar.php
file_put_contents($file, $content);
echo "SUCCESS: calendar.php updated successfully!\n";
?>
