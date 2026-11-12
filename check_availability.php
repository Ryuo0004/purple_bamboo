<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/inventory_store.php';
require_once __DIR__ . '/booking_store.php';

$today_str = date('Y-m-d');
$check_in = trim($_GET['check_in'] ?? $today_str);
if (!strtotime($check_in) || $check_in < $today_str) {
    $check_in = $today_str;
}
$default_checkout = date('Y-m-d', strtotime($check_in . ' +1 day'));
$check_out = trim($_GET['check_out'] ?? $default_checkout);
if (!strtotime($check_out) || $check_out <= $check_in) {
    $check_out = $default_checkout;
}
$guests = intval($_GET['guests'] ?? 4);

$kubos = InventoryStore::getAllKubos();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Real-Time Availability | Purple Bamboo Resort</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <?php include 'nav.php'; ?>

    <div class="container">
        <div class="page-header">
            <div>
                <h1>Real-Time Date Availability Search</h1>
                <p>Checking available House Kubos and Resort Activity slots for selected stay dates.</p>
            </div>
        </div>

        <div class="card" style="margin-bottom: 2.5rem; background: #ffffff; border: 1.5px solid var(--purple-accent); box-shadow: var(--shadow-md);">
            <form action="check_availability.php" method="GET" id="availFilterForm">
                <div style="margin-bottom: 1.25rem;">
                    <h3 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-bottom: 0.25rem; font-size: 1.25rem; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-regular fa-calendar-days" style="color: #7c3aed;"></i> Select Stay Dates (Monthly Grid Calendar)
                    </h3>
                    <p style="color: var(--text-muted); font-size: 0.88rem; margin: 0;">Click on your arrival (check-in) and departure (check-out) dates on the interactive calendar below.</p>
                </div>

                <div id="availabilityCalendarPicker" style="max-width: 560px; margin: 0 auto 1rem auto;"></div>
                <input type="hidden" name="check_in" id="availCheckIn" value="<?= htmlspecialchars($check_in) ?>" required>
                <input type="hidden" name="check_out" id="availCheckOut" value="<?= htmlspecialchars($check_out) ?>" required>

                <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e9d5ff;">
                    <div style="flex: 1; min-width: 200px;">
                        <label style="font-weight: 700; font-size: 0.88rem; color: #1e1b4b; margin-bottom: 0.35rem; display: block;">
                            <i class="fa-solid fa-users" style="color: #7c3aed;"></i> Number of Guests
                        </label>
                        <select name="guests" class="form-control" style="background: #faf5ff; border: 1.5px solid #d8b4fe;">
                            <option value="2" <?= $guests == 2 ? 'selected' : '' ?>>2 Guests (Couple)</option>
                            <option value="4" <?= $guests == 4 ? 'selected' : '' ?>>4 Guests (Family)</option>
                            <option value="8" <?= $guests == 8 ? 'selected' : '' ?>>8 Guests (Barkada)</option>
                            <option value="15" <?= $guests == 15 ? 'selected' : '' ?>>15+ Guests (Large Group)</option>
                        </select>
                    </div>
                    <div style="align-self: flex-end;">
                        <button type="submit" class="btn btn-gold" style="padding: 0.75rem 1.75rem; font-weight: 800;">
                            <i class="fa-solid fa-arrows-rotate"></i> Update Availability
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <h2 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-bottom: 1.5rem;">
            Accommodations Status (<?= date('M d, Y', strtotime($check_in)) ?> - <?= date('M d, Y', strtotime($check_out)) ?>)
        </h2>

        <div class="grid">
            <?php foreach ($kubos as $k): 
                $st = strtolower($k['status'] ?? 'available');
                $is_reno = ($st === 'renovation');
                $is_maint = in_array($st, ['maintenance', 'unavailable']);
                $units_total = BookingStore::getKuboUnits($k['kubo_id']);
                $units_left = ($is_reno || $is_maint || $units_total <= 0) ? 0 : BookingStore::getRemainingUnits($k['kubo_id'], $check_in, $check_out);
                $is_booked = ($units_total <= 0) || !BookingStore::isItemAvailable($k['kubo_id'], $check_in, $check_out, 'house') || $units_left <= 0;
                $is_avail = ($st === 'available' && !$is_booked && $units_left > 0 && $units_total > 0);

                // Active booking check for logged-in user
                $user_has_active_booking = false;
                $user_active_status = '';
                $user_active_ref = '';
                $is_admin_session = (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'staff']));
                if (!empty($_SESSION['user_id']) && !$is_admin_session) {
                    $u_act = BookingStore::getActiveBookingForUser($_SESSION['user_id'], 'house', $k['kubo_id'], $_SESSION['email'] ?? null);
                    if ($u_act) {
                        $user_has_active_booking = true;
                        $user_active_status = strtolower($u_act['booking_status'] ?? 'pending');
                        $user_active_ref = $u_act['id'] ?? '';
                    }
                }
            ?>
                <?php if ($user_has_active_booking): ?>
                    <div class="card" style="border-left: 5px solid #d97706; background: #fffbeb;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 0.5rem;">
                            <span class="badge" style="background: #fef3c7; color: #92400e; border: 1.5px solid #fcd34d; font-weight: 800;">
                                <i class="fa-solid fa-clock-rotate-left"></i> Already Booked by You (<?= ucfirst($user_active_status) ?>)
                            </span>
                            <span style="font-weight: 700; color: #b45309;">₱<?= number_format($k['price']) ?> / night</span>
                        </div>
                        <h3 style="font-family: 'Outfit', sans-serif; margin: 0.5rem 0; color: #78350f;"><?= htmlspecialchars($k['name']) ?></h3>
                        <p style="color: #92400e; font-size: 0.9rem; margin-bottom: 0.75rem;">
                            Max <?= htmlspecialchars($k['capacity'] ?? 4) ?> guests • <?= htmlspecialchars($k['description'] ?? '') ?>
                        </p>
                        <div style="background: #ffffff; border: 1px solid #fde68a; border-radius: 8px; padding: 0.75rem 0.9rem; margin-bottom: 0.9rem; font-size: 0.85rem; color: #92400e; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-ban" style="color: #d97706; font-size: 1rem;"></i>
                            <span>You already have an active <strong><?= ucfirst($user_active_status) ?></strong> reservation (#<?= htmlspecialchars($user_active_ref) ?>) for this accommodation. You cannot book it again while an active reservation exists.</span>
                        </div>
                        <a href="history.php" class="btn" style="width: 100%; background: #d97706; color: #ffffff; border: none; font-weight: 700; text-align: center; text-decoration: none; padding: 0.75rem; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                            <i class="fa-solid fa-clock-rotate-left"></i> View Your Existing Reservation
                        </a>
                    </div>
                <?php elseif ($is_avail): ?>
                    <div class="card" style="border-left: 5px solid var(--emerald);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                            <span class="badge badge-success"><i class="fa-solid fa-check"></i> Instant Booking Available</span>
                            <span style="font-weight: 700; color: var(--bamboo-gold);">₱<?= number_format($k['price']) ?> / night</span>
                        </div>
                        <h3 style="font-family: 'Outfit', sans-serif; margin: 0.5rem 0;"><?= htmlspecialchars($k['name']) ?></h3>
                        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1rem;">
                            Max <?= htmlspecialchars($k['capacity'] ?? 4) ?> guests • <?= htmlspecialchars($k['description'] ?? '') ?>
                        </p>
                        <a href="book_house.php?id=<?= $k['kubo_id'] ?>&check_in=<?= urlencode($check_in) ?>&check_out=<?= urlencode($check_out) ?>" class="btn-book-kubo" style="width: 100%;">
                            <i class="fa-solid fa-calendar-check btn-icon"></i>
                            <span>Book Kubo For These Dates</span>
                            <i class="fa-solid fa-arrow-right btn-arrow"></i>
                        </a>
                    </div>
                <?php elseif ($is_reno): ?>
                    <div class="card" style="border-left: 5px solid #ea580c; background: #fff7ed;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                            <span class="badge" style="background: #ea580c; color: #fff; font-weight: 800;"><i class="fa-solid fa-hammer"></i> Under Renovation — Not Available</span>
                            <span style="font-weight: 700; color: #9a3412;">₱<?= number_format($k['price']) ?> / night</span>
                        </div>
                        <h3 style="font-family: 'Outfit', sans-serif; margin: 0.5rem 0; color: #9a3412;"><?= htmlspecialchars($k['name']) ?></h3>
                        
                        <!-- Damage notes (High contrast) -->
                        <div style="background: #ffffff; border-radius: 6px; padding: 0.7rem 0.85rem; margin: 0.5rem 0 1rem; border: 1px solid #fed7aa; border-left: 4px solid #ea580c;">
                            <div style="font-size: 0.82rem; font-weight: 800; color: #9a3412; margin-bottom: 0.25rem;">
                                <i class="fa-solid fa-wrench" style="color: #ea580c;"></i> Damage / Reason for Renovation:
                            </div>
                            <div style="font-size: 0.92rem; color: #0f172a; font-weight: 700; line-height: 1.45;">
                                <?= !empty($k['renovation_notes']) ? htmlspecialchars($k['renovation_notes']) : 'This room is currently undergoing repairs and maintenance.' ?>
                            </div>
                        </div>

                        <button disabled class="btn" style="width: 100%; background: #ea580c; color: #ffffff; cursor: not-allowed; border: none; opacity: 0.9; font-weight: 700;">
                            <i class="fa-solid fa-ban"></i> Not Available (Under Renovation)
                        </button>
                    </div>
                <?php elseif ($is_booked): ?>
                    <div class="card" style="border-left: 5px solid #dc2626; background: #fff5f5;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 0.5rem;">
                            <span class="badge" style="background: #fee2e2; color: #991b1b; border: 1.5px solid #fca5a5; font-weight: 800;">
                                <i class="fa-solid fa-calendar-xmark"></i> Already Booked for Selected Dates
                            </span>
                            <span style="font-weight: 700; color: var(--bamboo-gold);">₱<?= number_format($k['price']) ?> / night</span>
                        </div>
                        <h3 style="font-family: 'Outfit', sans-serif; margin: 0.5rem 0; color: #991b1b;"><?= htmlspecialchars($k['name']) ?></h3>
                        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 0.75rem;">
                            Max <?= htmlspecialchars($k['capacity'] ?? 4) ?> guests • <?= htmlspecialchars($k['description'] ?? '') ?>
                        </p>
                        
                        <div style="background: #ffffff; border: 1px solid #fecaca; border-radius: 8px; padding: 0.7rem 0.9rem; margin-bottom: 0.9rem; font-size: 0.85rem; color: #991b1b; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-lock" style="color: #dc2626; font-size: 1rem;"></i>
                            <span>This Kubo is occupied from <strong><?= date('M d, Y', strtotime($check_in)) ?></strong> to <strong><?= date('M d, Y', strtotime($check_out)) ?></strong>.</span>
                        </div>

                        <button disabled class="btn" style="width: 100%; background: #fee2e2; color: #991b1b; cursor: not-allowed; border: 1.5px solid #fca5a5; font-weight: 800; padding: 0.75rem;">
                            <i class="fa-solid fa-ban"></i> Not Available (<?= ($units_total <= 0 ? '0 Units Available' : 'Already Booked') ?>)
                        </button>
                        <?php if ($units_total > 0 && !$user_has_active_booking): ?>
                        <a href="book_house.php?id=<?= $k['kubo_id'] ?>" style="display: block; text-align: center; margin-top: 0.6rem; color: var(--primary-purple); font-size: 0.85rem; font-weight: 700; text-decoration: underline;">
                            <i class="fa-regular fa-calendar-days"></i> View this Kubo's Calendar for other open dates
                        </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="card" style="border-left: 5px solid #9ca3af; background: #f9fafb;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                            <span class="badge badge-danger"><i class="fa-solid fa-ban"></i> Not Available (<?= ucfirst($st) ?>)</span>
                            <span style="font-weight: 700; color: var(--text-muted);">₱<?= number_format($k['price']) ?> / night</span>
                        </div>
                        <h3 style="font-family: 'Outfit', sans-serif; margin: 0.5rem 0;"><?= htmlspecialchars($k['name']) ?></h3>
                        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1rem;">
                            Max <?= htmlspecialchars($k['capacity'] ?? 4) ?> guests
                        </p>
                        <button disabled class="btn" style="width: 100%; background: #d1d5db; color: #6b7280; cursor: not-allowed; border: none;">
                            <i class="fa-solid fa-ban"></i> Not Available For Selected Dates
                        </button>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <?php include 'footer.php'; ?>
    <?php
    $calendar_script_file = __DIR__ . '/assets/monthly_calendar_picker.js';
    if (file_exists($calendar_script_file)) {
        echo "<script>\n" . file_get_contents($calendar_script_file) . "\n</script>\n";
    } else {
        echo '<script src="assets/monthly_calendar_picker.js"></script>' . "\n";
    }
    ?>
    <script>
    console.log('🗓️ Initializing availability calendar');
    try {
        initMonthlyCalendarPicker({
            containerId: 'availabilityCalendarPicker',
            checkInInputId: 'availCheckIn',
            checkOutInputId: 'availCheckOut',
            initialCheckIn: '<?= $check_in ?>',
            initialCheckOut: '<?= $check_out ?>',
            isAdmin: false,
            onRangeSelected: function(range) {
                console.log('✓ Date range selected:', range);
                // Auto-submit the form to update availability
                const checkInInput = document.getElementById('availCheckIn');
                const checkOutInput = document.getElementById('availCheckOut');
                if (checkInInput && checkOutInput && checkInInput.value && checkOutInput.value) {
                    console.log('📅 Submitting form with dates:', {
                        checkIn: checkInInput.value,
                        checkOut: checkOutInput.value
                    });
                    // Optional: auto-submit after a short delay to let form update
                    setTimeout(() => {
                        document.getElementById('availFilterForm').submit();
                    }, 300);
                }
            }
        });
        console.log('✓ Availability calendar initialized successfully');
    } catch (err) {
        console.error('❌ Error initializing availability calendar:', err);
    }
    </script>
</body>
</html>
