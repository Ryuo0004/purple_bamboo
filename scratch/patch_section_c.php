<?php
$file = __DIR__ . '/../calendar.php';
$content = file_get_contents($file);

$start_marker = '// Filter bookings for this day according to active filters
                    $dayFilteredBookings = [];';

$end_marker = '</div>
                    </div>
                <?php endfor; ?>';

$p_start = strpos($content, $start_marker);
$p_end = strpos($content, $end_marker, $p_start);

if ($p_start === false || $p_end === false) {
    die("Markers not found! p_start=" . var_export($p_start, true) . ", p_end=" . var_export($p_end, true));
}

$replacement = '// Filter grouped reservations according to active filters
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

$content = substr($content, 0, $p_start) . $replacement . "\n                " . substr($content, $p_end + strlen("</div>\n                    </div>\n                "));

file_put_contents($file, $content);
echo "SUCCESS: Section C replaced successfully!\n";
?>
