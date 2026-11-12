<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/booking_store.php';
require_once __DIR__ . '/dining_store.php';
require_once __DIR__ . '/inventory_store.php';

// Strict RBAC: Only Admin and Staff accounts permitted
if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), ['admin', 'staff'])) {
    $err = (isset($_SESSION['role']) && $_SESSION['role'] === 'guest') ? 'guest_restricted' : 'unauthorized';
    header("Location: admin-login.php?error=" . $err);
    exit();
}

$user_role = $_SESSION['role'];
$user_name = $_SESSION['full_name'] ?? 'Staff Operations';
$admin_full_name = $_SESSION['full_name'] ?? 'Super Admin';
$admin_current_page = 'bookings.php';
$is_admin_layout = true;

$action_msg = '';
$action_type = 'success';

// ============================================================
// SAFE GUEST BADGE HELPER
// ============================================================
if (!class_exists('GuestHelper')) {
    class GuestHelper {
        public static function renderGuestBadge($b) {
            $name = htmlspecialchars($b['guest_name'] ?? 'Unknown Guest');
            $phone = htmlspecialchars($b['guest_phone'] ?? '');
            $email = htmlspecialchars($b['guest_email'] ?? '');
            $profileId = htmlspecialchars($b['profile_id'] ?? '');
            $badge = '<div style="font-weight: 700; color: #ffffff; font-family: \'Outfit\', sans-serif;">' . $name . '</div>';
            if ($phone) {
                $badge .= '<small style="color: var(--text-muted); display: block; font-size: 0.78rem;"><i class="fa-solid fa-phone"></i> ' . $phone . '</small>';
            }
            if ($email) {
                $badge .= '<small style="color: var(--text-muted); display: block; font-size: 0.72rem;"><i class="fa-solid fa-envelope"></i> ' . $email . '</small>';
            }
            if ($profileId) {
                $badge .= '<span class="admin-badge admin-badge-purple" style="font-size: 0.65rem; margin-top: 4px;"><i class="fa-solid fa-id-badge"></i> ' . $profileId . '</span>';
            }
            return $badge;
        }
    }
}

// ============================================================
// AUTO-ARCHIVE COMPLETED / CANCELLED BOOKINGS INTO MYSQL HISTORY
// ============================================================
if (!function_exists('loadBookingArchive')) {
    function loadBookingArchive(): array {
        global $pdo;
        if ($pdo) {
            try {
                $stmt = $pdo->query("SELECT * FROM booking_archive ORDER BY archived_at DESC LIMIT 500");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $_SESSION['booking_history_archive'] = $rows ?: [];
                return $_SESSION['booking_history_archive'];
            } catch (PDOException $e) {
                error_log("loadBookingArchive error: " . $e->getMessage());
            }
        }
        return $_SESSION['booking_history_archive'] ?? [];
    }
    function saveBookingArchive(array $archive): void {
        $_SESSION['booking_history_archive'] = $archive;
    }
}
loadBookingArchive();

if (!function_exists('archiveBookingToHistory')) {
    function archiveBookingToHistory($booking, $reason = '') {
        if (empty($booking['id'])) return;
        global $pdo;
        $id = $booking['id'];
        $now = date('Y-m-d H:i:s');
        $by = $_SESSION['full_name'] ?? 'System';

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO booking_archive (id, guest_name, guest_email, guest_phone, profile_id, user_id, type, item_id, item_name, check_in, check_out, time_slot, start_time, end_time, pax, num_guests, total_amount, gcash_ref, payment_proof, payment_status, booking_status, special_notes, rejection_reason, archive_reason, archived_by, archived_at, created_at)
                    VALUES (:id, :guest_name, :guest_email, :guest_phone, :profile_id, :user_id, :type, :item_id, :item_name, :check_in, :check_out, :time_slot, :start_time, :end_time, :pax, :num_guests, :total_amount, :gcash_ref, :payment_proof, :payment_status, :booking_status, :special_notes, :rejection_reason, :archive_reason, :archived_by, :archived_at, :created_at)
                    ON DUPLICATE KEY UPDATE 
                        booking_status = VALUES(booking_status),
                        archive_reason = VALUES(archive_reason),
                        archived_by = VALUES(archived_by),
                        archived_at = VALUES(archived_at)
                ");
                $stmt->execute([
                    ':id' => $id,
                    ':guest_name' => $booking['guest_name'] ?? 'Guest',
                    ':guest_email' => $booking['guest_email'] ?? '',
                    ':guest_phone' => $booking['guest_phone'] ?? '',
                    ':profile_id' => $booking['profile_id'] ?? null,
                    ':user_id' => $booking['user_id'] ?? null,
                    ':type' => $booking['type'] ?? 'house',
                    ':item_id' => $booking['item_id'] ?? 1,
                    ':item_name' => $booking['item_name'] ?? '',
                    ':check_in' => !empty($booking['check_in']) ? date('Y-m-d H:i:s', strtotime($booking['check_in'])) : $now,
                    ':check_out' => !empty($booking['check_out']) ? date('Y-m-d H:i:s', strtotime($booking['check_out'])) : null,
                    ':time_slot' => $booking['time_slot'] ?? '',
                    ':start_time' => $booking['start_time'] ?? null,
                    ':end_time' => $booking['end_time'] ?? null,
                    ':pax' => $booking['pax'] ?? 1,
                    ':num_guests' => $booking['num_guests'] ?? ($booking['pax'] ?? 1),
                    ':total_amount' => $booking['total_amount'] ?? 0,
                    ':gcash_ref' => $booking['gcash_ref'] ?? null,
                    ':payment_proof' => $booking['payment_proof'] ?? null,
                    ':payment_status' => $booking['payment_status'] ?? 'verified',
                    ':booking_status' => $booking['booking_status'] ?? 'completed',
                    ':special_notes' => $booking['special_notes'] ?? '',
                    ':rejection_reason' => $booking['rejection_reason'] ?? null,
                    ':archive_reason' => $reason ?: 'Completed or cancelled stay',
                    ':archived_by' => $by,
                    ':archived_at' => $now,
                    ':created_at' => !empty($booking['created_at']) ? date('Y-m-d H:i:s', strtotime($booking['created_at'])) : $now
                ]);
            } catch (PDOException $e) {
                error_log("archiveBookingToHistory DB error: " . $e->getMessage());
            }
        }
        loadBookingArchive();
    }
}

// ============================================================
// HANDLE DINING ORDER STATUS UPDATES (POST)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_dining_status') {
    $order_id = $_POST['order_id'] ?? '';
    $new_status = $_POST['new_status'] ?? 'preparing';
    $kitchen_notes = trim($_POST['kitchen_notes'] ?? '');

    if (!empty($order_id)) {
        DiningStore::updateOrderStatus($order_id, $new_status, $kitchen_notes);
        $action_msg = "Dining Order <strong>#{$order_id}</strong> status updated to <strong>" . strtoupper($new_status) . "</strong>.";
    }
}
// ============================================================
// HANDLE ADD EXTRA TIME / EXTENSION SUBMISSION (POST)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'extend_booking_time') {
    $booking_id = trim($_POST['booking_id'] ?? '');
    $extra_minutes = intval($_POST['extra_minutes'] ?? 30);
    $extra_fee = floatval($_POST['extra_fee'] ?? 0);

    if (!empty($booking_id) && $pdo) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1");
            $stmt->execute([$booking_id]);
            $b = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($b) {
                $new_total = floatval($b['total_amount']) + $extra_fee;
                $notes = trim($b['special_notes'] ?? '');
                $ext_note = "Extension: +{$extra_minutes} mins (+₱" . number_format($extra_fee, 2) . ") on " . date('M d, Y g:i A');
                $new_notes = $notes ? ($notes . " | " . $ext_note) : $ext_note;

                $upd = $pdo->prepare("
                    UPDATE bookings 
                    SET total_amount = :total,
                        special_notes = :notes,
                        end_time = IF(end_time IS NOT NULL, DATE_ADD(end_time, INTERVAL :mins1 MINUTE), end_time),
                        check_out = IF(check_out IS NOT NULL, DATE_ADD(check_out, INTERVAL :mins2 MINUTE), check_out)
                    WHERE id = :id
                ");
                $upd->execute([
                    ':total' => $new_total,
                    ':notes' => $new_notes,
                    ':mins1' => $extra_minutes,
                    ':mins2' => $extra_minutes,
                    ':id' => $booking_id
                ]);

                // Also update activity_reservations if applicable
                try {
                    $pdo->prepare("UPDATE activity_reservations SET scheduled_time = DATE_ADD(scheduled_time, INTERVAL ? MINUTE) WHERE booking_id = ?")->execute([$extra_minutes, $booking_id]);
                } catch (Exception $e) {}

                if (function_exists('log_activity')) {
                    log_activity("BOOKING_EXTENDED", "Booking #{$booking_id} extended by {$extra_minutes} mins (+₱{$extra_fee})");
                }

                $_SESSION['flash_msg'] = "⏱️ Time extended for Booking <strong>#{$booking_id}</strong> (+{$extra_minutes} mins, +₱" . number_format($extra_fee, 2) . ")!";
                $_SESSION['flash_type'] = "success";
                header("Location: bookings.php?view=" . urlencode($_GET['view'] ?? 'all'));
                exit();
            }
        } catch (PDOException $e) {
            $action_msg = "Error extending booking: " . htmlspecialchars($e->getMessage());
            $action_type = "danger";
        }
    }
}

// ============================================================
// HANDLE RESCHEDULE BOOKING (POST)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reschedule_booking') {
    $resched_id = trim($_POST['booking_id'] ?? '');
    $new_check_in_date = trim($_POST['new_check_in_date'] ?? '');
    $new_check_in_time = trim($_POST['new_check_in_time'] ?? '14:00');
    $new_check_out_date = trim($_POST['new_check_out_date'] ?? '');
    $new_check_out_time = trim($_POST['new_check_out_time'] ?? '12:00');
    $new_item_id = intval($_POST['new_item_id'] ?? 0);
    $new_item_name = trim($_POST['new_item_name'] ?? '');
    $new_total_amount = floatval($_POST['new_total_amount'] ?? 0);
    $resched_reason = trim($_POST['reschedule_reason'] ?? '');
    if ($resched_reason === 'Others' || empty($resched_reason)) {
        $resched_reason = trim($_POST['reschedule_other_reason'] ?? 'Staff date adjustment');
    }

    if (!empty($resched_id) && !empty($new_check_in_date)) {
        $curB = BookingStore::getBookingById($resched_id);
        if ($curB && (!empty($curB['resched_pending']) || ($curB['booking_status'] ?? '') === 'reschedule_requested')) {
            $_SESSION['flash_msg'] = "⚠️ Cannot reschedule: Booking #{$resched_id} has a pending reschedule request. Please use 'Review Reschedule' to verify and approve or decline it first.";
            $_SESSION['flash_type'] = "warning";
            header("Location: bookings.php?view=" . urlencode($_GET['view'] ?? 'all'));
            exit();
        }
        if ($curB && intval($curB['resched_count'] ?? 0) >= 2) {
            $_SESSION['flash_msg'] = "⚠️ Cannot reschedule: Booking #{$resched_id} has already reached the maximum limit of 2 reschedules.";
            $_SESSION['flash_type'] = "danger";
            header("Location: bookings.php?view=" . urlencode($_GET['view'] ?? 'all'));
            exit();
        }

        $full_check_in = $new_check_in_date . ' ' . $new_check_in_time . ':00';
        $full_check_out = !empty($new_check_out_date) ? ($new_check_out_date . ' ' . $new_check_out_time . ':00') : date('Y-m-d 12:00:00', strtotime($new_check_in_date . ' +1 day'));

        $inTs = strtotime($full_check_in);
        $outTs = strtotime($full_check_out);

        if ($outTs <= $inTs) {
            $_SESSION['flash_msg'] = "⚠️ Check-out date and time must be strictly after check-in date.";
            $_SESSION['flash_type'] = "danger";
        } else {
            $res = BookingStore::rescheduleBooking(
                $resched_id,
                $full_check_in,
                $full_check_out,
                $new_item_id,
                $new_item_name,
                null,
                $new_total_amount,
                $resched_reason,
                $admin_full_name ?? ($user_name ?? 'Staff')
            );

            if ($res['success']) {
                $curCountStr = isset($curB['resched_count']) ? (intval($curB['resched_count']) + 1) : '';
                $countTag = $curCountStr ? " (Reschedule {$curCountStr}/2)" : "";
                $_SESSION['flash_msg'] = "📅 Booking <strong>#{$resched_id}</strong> was successfully rescheduled{$countTag} to <strong>" . date('M d, Y', $inTs) . " – " . date('M d, Y', $outTs) . "</strong> ({$new_item_name})!";
                $_SESSION['flash_type'] = "success";
            } else {
                $_SESSION['flash_msg'] = "⚠️ Cannot reschedule: " . htmlspecialchars($res['error'] ?? 'Unit is already booked or unavailable on selected dates.');
                $_SESSION['flash_type'] = "danger";
            }
        }
    }
    header("Location: bookings.php?view=" . urlencode($_GET['view'] ?? 'all'));
    exit();
}

// ============================================================
// HANDLE BOOKING ACTION GET REQUESTS
// ============================================================
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $act = $_GET['action'];

    $is_dining_id = (strpos($id, 'PBR-FOOD-') === 0);
    if ($is_dining_id) {
        require_once __DIR__ . '/dining_store.php';
        if ($act === 'approve') {
            DiningStore::updateOrderStatus($id, 'preparing', 'Sent to kitchen for preparation');
            $action_msg = "Dining Order <strong>#{$id}</strong> has been <strong>SENT TO KITCHEN</strong> (Preparing).";
            $action_type = 'success';
        } elseif ($act === 'check_in') {
            DiningStore::updateOrderStatus($id, 'delivering', 'Out for serving');
            $action_msg = "Dining Order <strong>#{$id}</strong> has been marked as <strong>OUT FOR SERVING</strong>.";
            $action_type = 'success';
        } elseif ($act === 'check_out') {
            DiningStore::updateOrderStatus($id, 'served', 'Served to guest');
            $action_msg = "Dining Order <strong>#{$id}</strong> has been marked as <strong>SERVED</strong>.";
            $action_type = 'success';
        } elseif ($act === 'cancel' || $act === 'cancel_unpaid') {
            $reason = trim($_GET['reason'] ?? ($_POST['reason'] ?? ''));
            if ($reason === 'Others' || empty($reason)) {
                $reason = trim($_GET['other_reason'] ?? ($_POST['other_reason'] ?? 'Cancelled by admin'));
            }
            DiningStore::updateOrderStatus($id, 'cancelled', 'Cancelled: ' . $reason);
            $action_msg = "Dining Order <strong>#{$id}</strong> has been <strong>CANCELLED</strong>.";
            $action_type = 'danger';
        } elseif ($act === 'delete') {
            global $pdo;
            if ($pdo) {
                try {
                    $delStmt = $pdo->prepare("DELETE FROM dining_orders WHERE id = ?");
                    $delStmt->execute([$id]);
                } catch (Exception $e) {}
            }
            $jsonOrders = DiningStore::loadOrdersFromJson();
            $jsonOrders = array_values(array_filter($jsonOrders, fn($o) => ($o['id'] ?? '') !== $id));
            @file_put_contents(DiningStore::getJsonFilePath(), json_encode($jsonOrders, JSON_PRETTY_PRINT));
            if (!empty($_SESSION['dining_orders'])) {
                $_SESSION['dining_orders'] = array_values(array_filter($_SESSION['dining_orders'], fn($o) => ($o['id'] ?? '') !== $id));
            }
            $action_msg = "Dining Order <strong>#{$id}</strong> has been permanently removed.";
            $action_type = 'danger';
        }
    } elseif ($act === 'confirm_reschedule_request') {
        $adminName = $admin_full_name ?? ($user_name ?? 'Admin');
        $res = BookingStore::approveReschedule($id, $adminName);
        if (!empty($res['success'])) {
            $action_msg = "Reschedule request for Booking <strong>#{$id}</strong> has been successfully <strong>VERIFIED & CONFIRMED</strong>! Dates have been updated.";
            $action_type = 'success';
        } else {
            $action_msg = "⚠️ Cannot confirm reschedule: " . htmlspecialchars($res['error'] ?? 'Conflict or error.');
            $action_type = 'danger';
        }
    } elseif ($act === 'reject_reschedule_request') {
        $adminName = $admin_full_name ?? ($user_name ?? 'Admin');
        $reason = trim($_GET['reason'] ?? 'Declined by administration');
        $res = BookingStore::rejectReschedule($id, $reason, $adminName);
        $action_msg = "Reschedule request for Booking <strong>#{$id}</strong> was <strong>DECLINED</strong>. Current reservation dates remain unchanged.";
        $action_type = 'warning';
    } elseif ($act === 'reject_payment') {
        $reason = trim($_GET['reason'] ?? 'Proof was rejected by admin');
        if ($reason === 'Others' || empty($reason)) {
            $reason = trim($_GET['other_reason'] ?? 'Proof was rejected by admin');
        }
        BookingStore::updateBookingStatus($id, 'pending', 'rejected', null, null, null, $reason);
        $action_msg = 'GCash payment proof for Ref #' . htmlspecialchars($id) . ' was marked as <strong>REJECTED</strong>. Reason: ' . htmlspecialchars($reason);
        $action_type = 'danger';
    } elseif ($act === 'approve') {
        BookingStore::updateBookingStatus($id, 'approved', 'verified');
        $action_msg = "Booking <strong>#{$id}</strong> has been successfully <strong>APPROVED</strong> and marked as verified payment.";
    } elseif ($act === 'check_in') {
        $curr = BookingStore::getBookingById($id);
        $is_verified = ($curr && (($curr['payment_status'] ?? '') === 'verified' || ($curr['booking_status'] ?? '') === 'approved'));
        if (!$is_verified) {
            $action_msg = "⚠️ <strong>Check-In Blocked:</strong> Booking <strong>#{$id}</strong> cannot be checked in because payment has NOT been approved/verified yet.";
            $action_type = 'danger';
        } else {
            BookingStore::updateBookingStatus($id, 'checked_in', 'verified');
            $action_msg = "Guest for Booking <strong>#{$id}</strong> has been <strong>CHECKED IN</strong>.";
        }
    } elseif ($act === 'verify_and_checkin') {
        BookingStore::updateBookingStatus($id, 'checked_in', 'verified');
        $action_msg = "Payment for Booking <strong>#{$id}</strong> was successfully <strong>VERIFIED & APPROVED</strong>, and the guest has been <strong>CHECKED IN</strong>.";
    } elseif ($act === 'check_out') {
        $curr = BookingStore::getBookingById($id);
        BookingStore::updateBookingStatus($id, 'checked_out');
        $updated = BookingStore::getBookingById($id);
        if ($updated) {
            archiveBookingToHistory($updated, 'Completed stay');
        } elseif ($curr) {
            $curr['booking_status'] = 'checked_out';
            archiveBookingToHistory($curr, 'Completed stay');
        }
        $action_msg = "Guest for Booking <strong>#{$id}</strong> has been <strong>CHECKED OUT</strong>. <i class='fa-solid fa-box-archive'></i> Moved to History.";
    } elseif ($act === 'cancel' || $act === 'cancel_unpaid') {
        $curr = BookingStore::getBookingById($id);
        $itemName = $curr['item_name'] ?? 'Accommodation';
        $guestName = $curr['guest_name'] ?? 'Guest';
        $reason = trim($_GET['reason'] ?? ($_POST['reason'] ?? ''));
        $other_reason = trim($_GET['other_reason'] ?? ($_POST['other_reason'] ?? ''));
        if ($reason === 'Others' || empty($reason)) {
            $reason = !empty($other_reason) ? $other_reason : 'Other reasons';
        }
        $pstatus = (($curr['payment_status'] ?? '') === 'verified') ? 'verified' : ((($curr['payment_status'] ?? '') === 'proof_submitted') ? 'proof_submitted' : 'unpaid');
        $rebook_deadline = null;
        if (in_array($pstatus, ['verified', 'proof_submitted'])) {
            $rebook_deadline = date('Y-m-d H:i:s', strtotime('+2 days'));
        }

        BookingStore::updateBookingStatus($id, 'cancelled', $pstatus, null, null, "Cancelled: " . $reason, null, $rebook_deadline);

        if (!empty($curr['item_id'])) {
            require_once __DIR__ . '/inventory_store.php';
            $kubo = InventoryStore::getKuboById($curr['item_id']);
            if ($kubo && in_array(strtolower(trim($kubo['status'] ?? '')), ['occupied', 'unavailable'])) {
                InventoryStore::updateKuboStatus($curr['item_id'], 'available');
            }
            unset($_SESSION['kubos']);
        }

        $updated = BookingStore::getBookingById($id);
        if ($updated) {
            archiveBookingToHistory($updated, 'Cancelled: ' . $reason);
        } elseif ($curr) {
            $curr['booking_status'] = 'cancelled';
            $curr['special_notes'] = 'Cancelled: ' . $reason;
            archiveBookingToHistory($curr, 'Cancelled: ' . $reason);
        }

        $action_msg = "Booking <strong>#{$id}</strong> for <strong>" . htmlspecialchars($guestName) . "</strong> has been <strong>CANCELLED</strong> (Reason: " . htmlspecialchars($reason) . "). <i class='fa-solid fa-box-archive'></i> Moved to History.";
        $action_type = 'danger';
    } elseif ($act === 'delete') {
        BookingStore::deleteBooking($id);
        $action_msg = "Booking record <strong>#{$id}</strong> was permanently deleted.";
        $action_type = 'danger';
    } elseif ($act === 'restore_from_history') {
        BookingStore::updateBookingStatus($id, 'approved', 'verified');
        global $pdo;
        if ($pdo) {
            try {
                $del = $pdo->prepare("DELETE FROM booking_archive WHERE id = ?");
                $del->execute([$id]);
            } catch (PDOException $e) {}
        }
        if (isset($_SESSION['booking_history_archive'])) {
            $_SESSION['booking_history_archive'] = array_values(array_filter(
                $_SESSION['booking_history_archive'],
                function($h) use ($id) { return ($h['id'] ?? '') !== $id; }
            ));
        }
        $action_msg = "Booking <strong>#{$id}</strong> has been <strong>RESTORED</strong> from History and set to Approved status.";
    } elseif ($act === 'clear_history') {
        global $pdo;
        if ($pdo) {
            try {
                $pdo->exec("DELETE FROM booking_archive");
            } catch (PDOException $e) {}
        }
        $_SESSION['booking_history_archive'] = [];
        $action_msg = "All booking history records have been <strong>CLEARED</strong>.";
        $action_type = 'danger';
    }
}

// ============================================================
// HANDLE MANUAL WALK-IN BOOKING SUBMISSION (POST)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_manual_booking'])) {
    $type = $_POST['type'] ?? 'house';

    // Support both kubo (item_name) and activity (item_name_activity) fields
    $item_name = '';
    if (!empty($_POST['item_name'])) {
        $item_name = trim($_POST['item_name']);
    } elseif (!empty($_POST['item_name_activity'])) {
        $item_name = trim($_POST['item_name_activity']);
    }
    if (empty($item_name)) {
        $item_name = ($type === 'activity') ? 'Pool Day Pass' : 'Beach Kubo';
    }

    $check_in_date  = $_POST['check_in'] ?? date('Y-m-d');
    $check_in_time  = !empty($_POST['check_in_time'])  ? $_POST['check_in_time']  . ':00' : '14:00:00';
    $check_in       = $check_in_date  . ' ' . $check_in_time;

    $stay_days = 1;
    if (!empty($_POST['stay_days'])) {
        $stay_days = max(1, intval($_POST['stay_days']));
    } elseif (!empty($_POST['custom_days'])) {
        $stay_days = max(1, intval($_POST['custom_days']));
    }

    if (!empty($_POST['check_out'])) {
        $check_out_date = $_POST['check_out'];
    } else {
        $check_out_date = date('Y-m-d', strtotime("+{$stay_days} days", strtotime($check_in_date)));
    }
    if ($type !== 'activity' && $check_out_date <= $check_in_date) {
        $check_out_date = date('Y-m-d', strtotime("+{$stay_days} days", strtotime($check_in_date)));
    }
    $check_out_time = !empty($_POST['check_out_time']) ? $_POST['check_out_time'] . ':00' : '12:00:00';
    $check_out      = $check_out_date . ' ' . $check_out_time;

    $in_fmt         = date('g:i A', strtotime($check_in));
    $out_fmt        = date('g:i A', strtotime($check_out));
    $d1_ts          = strtotime($check_in_date);
    $d2_ts          = strtotime($check_out_date);
    $calc_stay_nights = max(1, round(($d2_ts - $d1_ts) / 86400));
    $default_slot   = ($type === 'activity') ? 'Day Pass' : ($calc_stay_nights . ' Night' . ($calc_stay_nights > 1 ? 's' : '') . " Stay (In: {$in_fmt} | Out: {$out_fmt})");

    $total_amount   = floatval($_POST['total_amount'] ?? 0);
    $selected_uid   = !empty($_POST['selected_user_id'])   ? intval($_POST['selected_user_id'])        : null;
    $selected_pid   = !empty($_POST['selected_profile_id']) ? trim($_POST['selected_profile_id'])       : null;

    $g_name         = trim($_POST['guest_name']  ?? '');
    $raw_g_phone    = trim($_POST['guest_phone'] ?? '');
    $digits_g_phone = preg_replace('/\D/', '', $raw_g_phone);

    // Resolve item_id
    $item_id = intval($_POST['item_id'] ?? 0);
    if ($item_id <= 0) {
        if ($type === 'house' && class_exists('InventoryStore')) {
            $all_k = InventoryStore::getAllKubos();
            foreach ($all_k as $k) {
                if (($k['name'] ?? $k['kubo_name_number'] ?? '') === $item_name) {
                    $item_id = intval($k['kubo_id'] ?? $k['id'] ?? 1);
                    break;
                }
            }
        } elseif ($type === 'activity' && class_exists('InventoryStore')) {
            $all_a = InventoryStore::getAllActivities();
            foreach ($all_a as $a) {
                if (($a['activity_name'] ?? $a['name'] ?? '') === $item_name) {
                    $item_id = intval($a['activity_id'] ?? $a['id'] ?? 1);
                    break;
                }
            }
        }
    }
    if ($item_id <= 0) $item_id = 1;

    $is_item_available = BookingStore::isItemAvailable($item_id, $check_in_date, $check_out_date, $type);

    // --- Validation ---
    $today_date = date('Y-m-d');
    if (empty($g_name) || preg_match('/[0-9]/', $g_name)) {
        $action_msg  = '<i class="fa-solid fa-circle-exclamation"></i> Guest Full Name must contain letters only. Numbers are not allowed.';
        $action_type = 'danger';
    } elseif (preg_match('/[a-zA-Z]/', $raw_g_phone) || !str_starts_with($digits_g_phone, '09') || strlen($digits_g_phone) !== 11) {
        $action_msg  = '<i class="fa-solid fa-circle-exclamation"></i> Mobile number must start with <strong>09</strong> and be exactly <strong>11 digits</strong>.';
        $action_type = 'danger';
    } elseif ($check_in_date < $today_date) {
        $action_msg  = '<i class="fa-solid fa-circle-exclamation"></i> Check-in date cannot be in the past for walk-in reservations.';
        $action_type = 'danger';
    } elseif (!$is_item_available) {
        $action_msg  = '<i class="fa-solid fa-circle-exclamation"></i> The selected ' . ($type === 'house' ? 'Kubo (' . htmlspecialchars($item_name) . ')' : 'Activity (' . htmlspecialchars($item_name) . ')') . ' is already booked or has no available units for the selected dates. Please choose an available date or unit.';
        $action_type = 'danger';
    } else {
        try {
            $new_booking = BookingStore::createBooking([
                'guest_name'     => $g_name,
                'guest_email'    => trim($_POST['guest_email'] ?? 'guest@purplebamboo.com'),
                'guest_phone'    => $digits_g_phone,
                'user_id'        => $selected_uid,
                'profile_id'     => $selected_pid,
                'type'           => $type,
                'item_id'        => $item_id,
                'item_name'      => $item_name,
                'check_in'       => $check_in,
                'check_out'      => $check_out,
                'time_slot'      => !empty($_POST['time_slot']) ? trim($_POST['time_slot']) : $default_slot,
                'pax'            => intval($_POST['pax'] ?? 2),
                'total_amount'   => $total_amount,
                'gcash_ref'      => trim($_POST['gcash_ref'] ?? 'WALK-IN-CASH'),
                'payment_status' => $_POST['payment_status'] ?? 'verified',
                'booking_status' => $_POST['booking_status'] ?? 'approved',
                'special_notes'  => trim($_POST['special_notes'] ?? 'Manual Walk-In / Front Desk Reservation'),
            ]);

            // PRG: Redirect after successful POST so refresh does not re-submit
            $_SESSION['flash_msg']  = '<i class="fa-solid fa-circle-check"></i> Reservation <strong>#' . htmlspecialchars($new_booking['id']) . '</strong> created for <strong>' . htmlspecialchars($new_booking['guest_name']) . '</strong>!';
            $_SESSION['flash_type'] = 'success';
            header('Location: bookings.php?view=active');
            exit();

        } catch (Throwable $e) {
            $action_msg  = '<i class="fa-solid fa-triangle-exclamation"></i> Save failed: ' . htmlspecialchars($e->getMessage());
            $action_type = 'danger';
        }
    }
}

// Read flash message set by the PRG redirect above
if (empty($action_msg) && !empty($_SESSION['flash_msg'])) {
    $action_msg  = $_SESSION['flash_msg'];
    $action_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

$registered_users = BookingStore::getRegisteredUsersList();
$all_kubos_inventory = class_exists('InventoryStore') ? InventoryStore::getAllKubos() : [];
$all_activities_inventory = class_exists('InventoryStore') ? InventoryStore::getAllActivities() : [];

$walkin_booked_dates_map = [
    'house' => [],
    'activity' => []
];
foreach ($all_kubos_inventory as $k) {
    $kid = intval($k['kubo_id'] ?? $k['id'] ?? 1);
    $walkin_booked_dates_map['house'][$kid] = BookingStore::getBookedDatesForItem($kid, 'house');
}
foreach ($all_activities_inventory as $act) {
    $actId = intval($act['activity_id'] ?? $act['id'] ?? 1);
    $walkin_booked_dates_map['activity'][$actId] = BookingStore::getBookedDatesForItem($actId, 'activity');
}

// ============================================================
// RETRIEVE FILTERS & UNIFIED DATASET
// ============================================================
$search_query = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? 'all');
$type_filter = trim($_GET['type'] ?? 'all');
$payment_filter = trim($_GET['payment'] ?? 'all');
$date_filter = trim($_GET['date'] ?? '');
$view_mode = trim($_GET['view'] ?? 'all');
$dining_status_filter = trim($_GET['dining_status'] ?? 'all');

// If filter explicitly requests completed or cancelled history records, seamlessly redirect to Booking History
if ($status_filter === 'checked_out' || $status_filter === 'completed') {
    header("Location: booking_history.php?status=completed");
    exit();
}
if ($status_filter === 'cancelled' || $status_filter === 'rejected' || $status_filter === 'forfeited') {
    header("Location: booking_history.php?status=cancelled");
    exit();
}

// Retrieve records from MySQL
$active_raw = BookingStore::getAllBookings();

// In Booking Management, once a booking is checked out or cancelled it must vanish from this active table and live in Booking History
$unified_all = [];
$seen_ids = [];
foreach ($active_raw as $b) {
    $id = $b['id'] ?? '';
    $st = strtolower($b['booking_status'] ?? 'pending');
    
    // Automatically vanish checked out and cancelled bookings from Booking Management
    if (in_array($st, ['checked_out', 'checkedout', 'completed', 'cancelled', 'rejected', 'forfeited'])) {
        continue;
    }

    if ($id && !isset($seen_ids[$id])) {
        $unified_all[] = $b;
        $seen_ids[$id] = true;
    }
}

// MERGE ACTIVE DINING ORDERS INTO UNIFIED TABLE
require_once __DIR__ . '/dining_store.php';
$all_dining_raw = DiningStore::getAllOrders();
foreach ($all_dining_raw as $dOrd) {
    $dId = $dOrd['id'] ?? '';
    $dStatus = strtolower(trim($dOrd['order_status'] ?? 'pending'));
    
    // In Booking Management, served or cancelled dining orders live in Kitchen Monitoring or History
    if (in_array($dStatus, ['served', 'completed', 'cancelled', 'canceled'])) {
        continue;
    }
    
    $itemsParsed = $dOrd['items_parsed'] ?? [];
    if (empty($itemsParsed) && !empty($dOrd['order_items_json'])) {
        $itemsParsed = is_array($dOrd['order_items_json']) ? $dOrd['order_items_json'] : (json_decode($dOrd['order_items_json'], true) ?: []);
    }
    
    $itemNames = [];
    $firstItemImg = 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80';
    foreach ($itemsParsed as $it) {
        $q = $it['qty'] ?? 1;
        $n = $it['name'] ?? 'Item';
        $itemNames[] = "{$q}x {$n}";
        if (!empty($it['image'])) {
            $firstItemImg = $it['image'];
        }
    }
    $itemsSummary = !empty($itemNames) ? implode(', ', $itemNames) : 'Resort Dining Order';
    
    $mappedBookingStatus = 'pending';
    if (in_array($dStatus, ['preparing', 'ready', 'out_for_serving', 'delivering'])) {
        $mappedBookingStatus = 'confirmed';
    }
    
    $dRec = [
        'id' => $dId,
        'type' => 'dining',
        'tag' => 'food',
        'user_id' => $dOrd['user_id'] ?? null,
        'profile_id' => !empty($dOrd['user_id']) ? ('PBR-GST-' . str_pad($dOrd['user_id'], 5, '0', STR_PAD_LEFT)) : $dId,
        'guest_name' => $dOrd['guest_name'] ?? 'Guest',
        'guest_phone' => $dOrd['guest_phone'] ?? '',
        'guest_email' => $dOrd['guest_email'] ?? '',
        'item_name' => $itemsSummary,
        'pax' => 1,
        'check_in' => !empty($dOrd['created_at']) ? date('Y-m-d', strtotime($dOrd['created_at'])) : date('Y-m-d'),
        'check_out' => !empty($dOrd['created_at']) ? date('Y-m-d', strtotime($dOrd['created_at'])) : date('Y-m-d'),
        'time_slot' => $dOrd['requested_serve_time'] ?? 'ASAP (15-20 Mins)',
        'start_time' => !empty($dOrd['created_at']) ? date('H:i:s', strtotime($dOrd['created_at'])) : '12:00:00',
        'end_time' => !empty($dOrd['created_at']) ? date('H:i:s', strtotime($dOrd['created_at']) + 1800) : '12:30:00',
        'total_amount' => floatval($dOrd['total_amount'] ?? 0),
        'payment_status' => (in_array($dStatus, ['served', 'completed'])) ? 'paid' : 'unverified',
        'booking_status' => $mappedBookingStatus,
        'order_status' => $dStatus,
        'kubo_location' => $dOrd['kubo_location'] ?? 'Beach Kubo',
        'guest_allergies' => $dOrd['guest_allergies'] ?? '',
        'allergy_notes' => $dOrd['allergy_notes'] ?? '',
        'items_parsed' => $itemsParsed,
        'primary_image' => $firstItemImg,
        'special_notes' => 'Location: ' . ($dOrd['kubo_location'] ?? 'Beach Kubo') . (!empty($dOrd['guest_allergies']) ? ' | Allergies: ' . $dOrd['guest_allergies'] : '') . (!empty($dOrd['kitchen_notes']) ? ' | Note: ' . $dOrd['kitchen_notes'] : ''),
        'created_at' => $dOrd['created_at'] ?? date('Y-m-d H:i:s'),
    ];
    
    if ($dId && !isset($seen_ids[$dId])) {
        $unified_all[] = $dRec;
        $seen_ids[$dId] = true;
    }
}

// Query associated addons for multi-item bookings
$addons_by_ref = [];
if (isset($pdo) && $pdo) {
    try {
        $stmtAddons = $pdo->query("
            SELECT ar.*, a.activity_name, a.image AS activity_image, a.price
            FROM addon_reservations ar
            LEFT JOIN activities a ON ar.activity_id = a.activity_id
        ");
        while ($arRow = $stmtAddons->fetch(PDO::FETCH_ASSOC)) {
            $refKey = trim($arRow['reservation_id'] ?? '');
            if ($refKey !== '') {
                $addons_by_ref[$refKey][] = $arRow;
            }
        }
    } catch (Throwable $e) {}
}

// House kubos catalog lookup for images
$kubos_catalog = [];
if (isset($pdo) && $pdo) {
    try {
        $stmtKubos = $pdo->query("SELECT kubo_id, kubo_name_number, primary_image, price_per_night, capacity FROM house_kubos");
        while ($kRow = $stmtKubos->fetch(PDO::FETCH_ASSOC)) {
            $kubos_catalog[strtolower(trim($kRow['kubo_name_number']))] = $kRow;
            $kubos_catalog['id_' . $kRow['kubo_id']] = $kRow;
        }
    } catch (Throwable $e) {}
}

// Activities catalog lookup
$activities_catalog = [];
if (isset($pdo) && $pdo) {
    try {
        $stmtActs = $pdo->query("SELECT activity_id, activity_name, image, price FROM activities");
        while ($aRow = $stmtActs->fetch(PDO::FETCH_ASSOC)) {
            $activities_catalog[strtolower(trim($aRow['activity_name']))] = $aRow;
            $activities_catalog['id_' . $aRow['activity_id']] = $aRow;
        }
    } catch (Throwable $e) {}
}

// Sort all by date/time (most recent first)
usort($unified_all, function($a, $b) {
    $tA = strtotime($a['created_at'] ?? $a['check_in'] ?? '2026-01-01');
    $tB = strtotime($b['created_at'] ?? $b['check_in'] ?? '2026-01-01');
    return $tB - $tA;
});

// Calculate tab counts
$count_all = count($unified_all);
$count_active = 0;
$count_completed = 0;
$count_cancelled = 0;
$count_resched = 0;

foreach ($unified_all as $b) {
    if (!empty($b['resched_pending'])) {
        $count_resched++;
    }
    $st = strtolower($b['booking_status'] ?? 'pending');
    if (in_array($st, ['checked_out', 'completed'])) {
        $count_completed++;
    } elseif (in_array($st, ['cancelled', 'rejected'])) {
        $count_cancelled++;
    } else {
        $count_active++;
    }
}

// Filter according to view tab
$tab_filtered = [];
foreach ($unified_all as $b) {
    $st = strtolower($b['booking_status'] ?? 'pending');
    if ($view_mode === 'resched') {
        if (!empty($b['resched_pending']) || $st === 'reschedule_requested') {
            $tab_filtered[] = $b;
        }
    } elseif ($view_mode === 'active') {
        if (!in_array($st, ['checked_out', 'completed', 'cancelled', 'rejected'])) {
            $tab_filtered[] = $b;
        }
    } elseif ($view_mode === 'completed') {
        if (in_array($st, ['checked_out', 'completed'])) {
            $tab_filtered[] = $b;
        }
    } elseif ($view_mode === 'cancelled') {
        if (in_array($st, ['cancelled', 'rejected'])) {
            $tab_filtered[] = $b;
        }
    } else {
        // 'all'
        $tab_filtered[] = $b;
    }
}

// Apply Booking Status Filter
if (!empty($status_filter) && $status_filter !== 'all') {
    $tab_filtered = array_values(array_filter($tab_filtered, function($b) use ($status_filter) {
        $st = strtolower($b['booking_status'] ?? 'pending');
        if ($status_filter === 'resched' || $status_filter === 'reschedule_requested') {
            return !empty($b['resched_pending']) || $st === 'reschedule_requested';
        }
        return $st === $status_filter;
    }));
}

// Apply Payment Status Filter
if (!empty($payment_filter) && $payment_filter !== 'all') {
    $tab_filtered = array_values(array_filter($tab_filtered, function($b) use ($payment_filter) {
        $p = strtolower($b['payment_status'] ?? 'unpaid');
        if ($payment_filter === 'verified' || $payment_filter === 'paid') {
            return in_array($p, ['verified', 'paid', 'approved']);
        } elseif ($payment_filter === 'unverified') {
            return in_array($p, ['unverified', 'unpaid', 'proof_submitted', 'pending']);
        } elseif ($payment_filter === 'rejected') {
            return $p === 'rejected';
        }
        return true;
    }));
}

// Apply Search Filter
if (!empty($search_query)) {
    $q = strtolower($search_query);
    $tab_filtered = array_values(array_filter($tab_filtered, function($b) use ($q) {
        $haystack = strtolower(
            ($b['id'] ?? '') . ' ' .
            ($b['guest_name'] ?? '') . ' ' .
            ($b['guest_phone'] ?? '') . ' ' .
            ($b['guest_email'] ?? '') . ' ' .
            ($b['profile_id'] ?? '') . ' ' .
            ($b['item_name'] ?? '') . ' ' .
            ($b['special_notes'] ?? '') . ' ' .
            ($b['archive_reason'] ?? '')
        );
        return strpos($haystack, $q) !== false;
    }));
}

// Apply Reservation Type Filter
if (!empty($type_filter) && $type_filter !== 'all') {
    $tab_filtered = array_values(array_filter($tab_filtered, fn($b) => strtolower($b['type'] ?? 'house') === strtolower($type_filter)));
}

// Apply Stay Date Filter
if (!empty($date_filter)) {
    $tab_filtered = array_values(array_filter($tab_filtered, function($b) use ($date_filter) {
        $ci = !empty($b['check_in']) ? date('Y-m-d', strtotime($b['check_in'])) : '';
        return $ci === $date_filter;
    }));
}

// Pagination setup
$per_page = 10;
$total_items = count($tab_filtered);
$total_pages = max(1, ceil($total_items / $per_page));
$current_page = max(1, min($total_pages, intval($_GET['page'] ?? 1)));
$offset = ($current_page - 1) * $per_page;
$display_bookings = array_slice($tab_filtered, $offset, $per_page);

// Pre-select default booking for the Extra Time card
$default_ext_booking = null;
foreach ($tab_filtered as $candidate) {
    if (strtolower($candidate['type'] ?? '') === 'activity') {
        $default_ext_booking = $candidate;
        break;
    }
}
if (!$default_ext_booking && !empty($tab_filtered)) {
    $default_ext_booking = $tab_filtered[0];
}

$stats = BookingStore::getStats();

$currently_checked_in_count = 0;
foreach ($unified_all as $ab) {
    if (strtolower($ab['booking_status'] ?? '') === 'checked_in') {
        $currently_checked_in_count++;
    }
}

// ============================================================
// FETCH DINING ORDERS (Integrated Kitchen Monitoring)
// ============================================================
$dining_status_filter = $dining_status_filter ?? trim($_GET['dining_status'] ?? 'all');
$all_dining_orders = DiningStore::getAllOrders(['search' => $search_query]);
if ($dining_status_filter !== 'all') {
    $all_dining_orders = array_values(array_filter($all_dining_orders, function($o) use ($dining_status_filter) {
        $st = strtolower(trim($o['order_status'] ?? ''));
        if ($dining_status_filter === 'pending') return in_array($st, ['pending', 'accepted']);
        if ($dining_status_filter === 'preparing') return in_array($st, ['preparing', 'ready']);
        if ($dining_status_filter === 'delivering') return in_array($st, ['out_for_serving', 'delivering']);
        if ($dining_status_filter === 'served') return in_array($st, ['served', 'completed']);
        return $st === $dining_status_filter;
    }));
}

$dining_stats = DiningStore::getStats();
$dining_active_count = $dining_stats['pending_count'] + $dining_stats['preparing_count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#1e1035">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Booking & Dining Management Console | Purple Bamboo Resort</title>
    <?php if ($is_admin_layout): ?>
        <link rel="stylesheet" href="admin-style.css?v=<?= time() ?>">
    <?php else: ?>
        <link rel="stylesheet" href="style.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Strictly 2 Font Families: Outfit (Headings/Brand) & Plus Jakarta Sans (UI/Body) */
        body, input, button, select, textarea, table, td, th, p, span, a, label, div {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        h1, h2, h3, h4, h5, h6, 
        .admin-page-title, .exec-page-title-group h1, .exec-kpi-val, 
        .exec-tab-btn, .modal-header h3, .admin-sidebar-brand-title {
            font-family: 'Outfit', sans-serif !important;
        }
        /* Preserve Font Awesome icons across all elements */
        .fa, .fas, .fa-solid {
            font-family: "Font Awesome 6 Free" !important;
            font-weight: 900 !important;
        }
        .far, .fa-regular {
            font-family: "Font Awesome 6 Free" !important;
            font-weight: 400 !important;
        }
        .fa-brands, .fab {
            font-family: "Font Awesome 6 Brands" !important;
            font-weight: 400 !important;
        }

        :root {
            --theme-bg-deep:       #f8fafc;
            --theme-bg-card:       #ffffff;
            --theme-bg-elevated:   #f8fafc;
            --theme-border:        #e2e8f0;
            --theme-border-soft:   #e2e8f0;
            --theme-text-primary:  #0f172a;
            --theme-text-muted:    #64748b;
            --theme-text-sub:      #475569;
        }

        .modal-overlay {
            display: none !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            background: rgba(8, 4, 20, 0.88) !important;
            backdrop-filter: blur(8px) !important;
            z-index: 99999 !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 1.5rem !important;
            box-sizing: border-box !important;
        }
        .modal-overlay.active,
        .modal-overlay.open {
            display: flex !important;
        }
        .modal-container {
            background: linear-gradient(135deg, #140b2e 0%, #1c0f3d 100%) !important;
            border-radius: 16px !important;
            max-width: 680px !important;
            width: 100% !important;
            max-height: 90vh !important;
            display: flex !important;
            flex-direction: column !important;
            position: relative !important;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.8), 0 0 30px rgba(124, 58, 237, 0.25) !important;
            border: 1.5px solid #d8b4fe !important;
            overflow: hidden !important;
            animation: modalPopIn 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275) !important;
        }
        @keyframes modalPopIn {
            from { opacity: 0; transform: scale(0.94) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-header {
            padding: 1.25rem 1.5rem;
            background: linear-gradient(90deg, #180938 0%, #291054 100%) !important;
            border-bottom: 1px solid rgba(216, 180, 254, 0.25) !important;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-header h3 {
            font-family: 'Outfit', sans-serif;
            color: #ffffff !important;
            font-size: 1.15rem;
            font-weight: 800 !important;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: -0.01em;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.4);
        }
        .modal-header h3 i {
            color: #fbbf24 !important;
            filter: drop-shadow(0 0 6px rgba(251, 191, 36, 0.6));
        }
        .modal-close {
            background: rgba(255, 255, 255, 0.12) !important;
            border: 1px solid rgba(255, 255, 255, 0.25) !important;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.2rem;
            color: #ffffff !important;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        .modal-close:hover {
            background: #ef4444 !important;
            border-color: #ef4444 !important;
            color: #ffffff !important;
            transform: rotate(90deg);
        }
        .modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            color: #ffffff !important;
            background: #140b2e !important;
            flex: 1;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.72rem 0 !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important;
            font-size: 0.92rem !important;
        }
        .detail-row:last-child { border-bottom: none !important; }
        .detail-row label {
            color: #e2e8f0 !important;
            font-weight: 700 !important;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: 0.2px;
        }
        .detail-row label i {
            color: #c084fc !important;
            font-size: 0.95rem;
            width: 18px;
            text-align: center;
        }
        .detail-row span {
            color: #ffffff !important;
            font-weight: 600;
        }
        .detail-row span strong {
            color: #ffffff !important;
            font-weight: 800 !important;
        }

        /* High-contrast Badges inside Modal and Detail Rows */
        #detailsModal .admin-badge,
        .detail-row .admin-badge,
        .detail-row span.admin-badge {
            font-weight: 800 !important;
            font-size: 0.78rem !important;
            padding: 0.32rem 0.8rem !important;
            border-radius: 9999px !important;
            letter-spacing: 0.5px !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
            text-transform: uppercase !important;
        }
        #detailsModal .admin-badge-purple,
        .detail-row span.admin-badge-purple,
        .detail-row .admin-badge-purple {
            background: rgba(192, 38, 211, 0.3) !important;
            color: #f5d0fe !important;
            border: 1.5px solid #d946ef !important;
            box-shadow: 0 0 10px rgba(217, 70, 239, 0.25) !important;
        }
        #detailsModal .admin-badge-gold,
        .detail-row span.admin-badge-gold,
        .detail-row .admin-badge-gold {
            background: rgba(245, 158, 11, 0.3) !important;
            color: #fef08a !important;
            border: 1.5px solid #facc15 !important;
            box-shadow: 0 0 10px rgba(250, 204, 21, 0.25) !important;
        }
        #detailsModal .admin-badge-gold i,
        .detail-row span.admin-badge-gold i {
            color: #facc15 !important;
        }
        #detailsModal .admin-badge-success,
        .detail-row span.admin-badge-success,
        .detail-row .admin-badge-success {
            background: rgba(16, 185, 129, 0.3) !important;
            color: #a7f3d0 !important;
            border: 1.5px solid #34d399 !important;
            box-shadow: 0 0 10px rgba(52, 211, 153, 0.25) !important;
        }
        #detailsModal .admin-badge-success i,
        .detail-row span.admin-badge-success i {
            color: #34d399 !important;
        }
        #detailsModal .admin-badge-danger,
        .detail-row span.admin-badge-danger,
        .detail-row .admin-badge-danger {
            background: rgba(239, 68, 68, 0.3) !important;
            color: #fecaca !important;
            border: 1.5px solid #f87171 !important;
            box-shadow: 0 0 10px rgba(248, 113, 113, 0.25) !important;
        }

        /* Modal Footer Color Harmony */
        #modalDetailsFooter {
            background: #180938 !important;
            border-top: 1px solid rgba(216, 180, 254, 0.25) !important;
        }
        #modalDetailsFooter .admin-btn-outline {
            background: rgba(255, 255, 255, 0.08) !important;
            color: #ffffff !important;
            border: 1.5px solid #d8b4fe !important;
            font-weight: 700 !important;
        }
        #modalDetailsFooter .admin-btn-outline:hover {
            background: rgba(255, 255, 255, 0.18) !important;
            border-color: #ffffff !important;
            color: #ffffff !important;
        }
        #modalDetailsFooter .admin-btn-primary {
            background: #9333ea !important;
            color: #ffffff !important;
            border: 1.5px solid #a855f7 !important;
            font-weight: 700 !important;
        }
        #modalDetailsFooter .admin-btn-primary:hover {
            background: #a855f7 !important;
            box-shadow: 0 0 15px rgba(168, 85, 247, 0.5) !important;
        }
        #modalDetailsFooter .admin-btn-danger {
            background: #ef4444 !important;
            color: #ffffff !important;
            border: 1.5px solid #f87171 !important;
            font-weight: 700 !important;
        }
        #modalDetailsFooter .admin-btn-danger:hover {
            background: #dc2626 !important;
            box-shadow: 0 0 15px rgba(239, 68, 68, 0.5) !important;
        }

        .admin-main-wrapper {
            min-width: 0 !important;
            flex: 1 !important;
        }
        /* Desktop only: account for sidebar width */
        @media (min-width: 992px) {
            .admin-main-wrapper {
                width: calc(100% - 260px) !important;
            }
        }
        /* Mobile: full width when sidebar is off-canvas */
        @media (max-width: 991px) {
            .admin-main-wrapper {
                width: 100% !important;
                margin-left: 0 !important;
            }
        }
        .table-responsive {
            width: 100% !important;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch;
        }
        .admin-table {
            width: 100% !important;
            border-collapse: collapse !important;
            table-layout: auto;
        }
        .admin-table th {
            background: #f8fafc !important;
            color: #475569 !important;
            font-size: 0.8rem !important;
            letter-spacing: 0.5px !important;
            padding: 1rem 1.15rem !important;
            border-bottom: 1.5px solid var(--theme-border, #d8b4fe) !important;
            text-transform: uppercase;
            font-weight: 800;
        }
        .admin-table td {
            padding: 1rem 1.15rem !important;
            vertical-align: middle !important;
            border-bottom: 1px solid #f1f5f9 !important;
            font-size: 0.88rem !important;
            color: #0f172a !important;
            background: #ffffff !important;
        }
        .admin-table tr:hover td {
            background: #faf5ff !important;
        }

        .admin-table-container {
            background: #ffffff !important;
            border: 1.5px solid var(--theme-border, #d8b4fe) !important;
            border-radius: 14px !important;
        }

        .admin-table-container > div:first-child {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%) !important;
            border-bottom: 1px solid var(--theme-border, #d8b4fe) !important;
        }

        .admin-card {
            background: #ffffff !important;
            border: 1.5px solid var(--theme-border-soft, #2e1a47) !important;
        }

        .admin-card .form-group label { color: var(--theme-text-primary, #1e1b4b) !important; font-weight: 600; }

        .admin-card input[type="text"],
        .admin-card input[type="date"],
        .admin-card select {
            background: var(--theme-bg-elevated, #251347) !important;
            color: var(--theme-text-primary, #1e1b4b) !important;
            border: 1.5px solid var(--theme-border, #d8b4fe) !important;
            border-radius: 8px !important;
            transition: all 0.2s ease;
        }
        .admin-card input[type="text"]:focus,
        .admin-card input[type="date"]:focus,
        .admin-card select:focus {
            outline: none;
            border-color: #a855f7 !important;
            box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.2) !important;
        }
        .admin-card input::placeholder { color: #6d5a8a !important; }
        .admin-card select option {
            background: var(--theme-bg-elevated, #251347);
            color: var(--theme-text-primary, #1e1b4b);
        }

        .act-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.85rem;
            border: 1px solid transparent;
        }
        .act-btn-view { background: #f5f3ff; color: #7c3aed; border-color: #ddd6fe; }
        .act-btn-view:hover { background: #7c3aed; color: #ffffff; transform: scale(1.08); }
        .act-btn-approve { background: #fffbeb; color: #b45309; border-color: #fde68a; }
        .act-btn-approve:hover { background: #f59e0b; color: #ffffff; transform: scale(1.08); }
        .act-btn-checkin { background: #fffbeb; color: #b45309; border-color: #fde68a; }
        .act-btn-checkin:hover { background: #f59e0b; color: #ffffff; transform: scale(1.08); }
        .act-btn-checkout { background: #f5f3ff; color: #7c3aed; border-color: #ddd6fe; }
        .act-btn-checkout:hover { background: #7c3aed; color: #ffffff; transform: scale(1.08); }
        .act-btn-cancel { background: #f8fafc; color: #475569; border-color: #e2e8f0; }
        .act-btn-cancel:hover { background: #0f172a; color: #ffffff; transform: scale(1.08); }
        .act-btn-cancel-unpaid {
            background: rgba(239, 68, 68, 0.22);
            color: #f87171;
            border-color: rgba(239, 68, 68, 0.55);
            box-shadow: 0 0 6px rgba(239, 68, 68, 0.2);
        }
        .act-btn-cancel-unpaid { background: #f8fafc; color: #475569; border-color: #e2e8f0; } .act-btn-cancel-unpaid { background: #f8fafc; color: #475569; border-color: #e2e8f0; } .act-btn-cancel-unpaid:hover { background: #0f172a; color: #ffffff; border-color: #0f172a; background: #0f172a; color: #ffffff; border-color: #0f172a;
            background: #ef4444;
            color: #ffffff;
            border-color: #ef4444;
            transform: scale(1.1);
            box-shadow: 0 0 10px rgba(239, 68, 68, 0.4);
        }
        .act-btn-restore { background: rgba(16, 185, 129, 0.2); color: #34d399; border-color: rgba(16, 185, 129, 0.4); }
        .act-btn-restore:hover { background: #10b981; color: #ffffff; transform: scale(1.08); }
        .act-btn-delete { background: #f8fafc; color: #475569; border-color: #e2e8f0; }
        .act-btn-delete:hover { background: #0f172a; color: #ffffff; transform: scale(1.08); }

        /* ============================================================
           EXECUTIVE LUXURY VIEW TABS BAR (Matches settings.php)
           ============================================================ */
        .executive-tabs-navbar {
            display: flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #090e1a 0%, #150d2c 55%, #1e113a 100%);
            border: 1px solid rgba(251, 191, 36, 0.28);
            padding: 8px 12px;
            border-radius: 14px;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 25px rgba(11, 17, 32, 0.25);
            overflow-x: auto;
            scrollbar-width: none;
        }
        .executive-tabs-navbar::-webkit-scrollbar { display: none; }

        .exec-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            border-radius: 10px;
            font-family: 'Outfit', sans-serif;
            font-size: 0.92rem;
            font-weight: 600;
            text-decoration: none;
            color: #94a3b8;
            background: transparent;
            border: 1px solid transparent;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
            cursor: pointer;
        }
        .exec-tab-btn:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-1px);
        }
        .exec-tab-btn.active {
            background: rgba(251, 191, 36, 0.14);
            color: #fbbf24;
            border: 1px solid #fbbf24;
            font-weight: 700;
            box-shadow: 0 0 18px rgba(251, 191, 36, 0.28), inset 0 0 10px rgba(251, 191, 36, 0.06);
        }
        .exec-tab-btn.active i {
            color: #fbbf24;
            filter: drop-shadow(0 0 6px rgba(251, 191, 36, 0.5));
        }
        .exec-tab-badge {
            background: rgba(255, 255, 255, 0.1);
            color: inherit;
            font-size: 0.72rem;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .exec-tab-btn.active .exec-tab-badge {
            background: rgba(251, 191, 36, 0.25);
            color: #fbbf24;
            border-color: rgba(251, 191, 36, 0.4);
        }

        /* High-Visibility Action Buttons for Front Desk */
        .tbl-action-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 8px;
            font-family: 'Outfit', sans-serif;
            font-size: 0.82rem;
            font-weight: 700;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .tbl-action-pill:hover {
            transform: translateY(-1px);
        }
        .tbl-pill-checkin {
            background: linear-gradient(135deg, #d97706, #f59e0b);
            color: #ffffff !important;
            box-shadow: 0 2px 8px rgba(217, 119, 6, 0.35);
        }
        .tbl-pill-checkin:hover {
            box-shadow: 0 4px 14px rgba(217, 119, 6, 0.5);
            color: #ffffff !important;
        }
        .tbl-pill-checkout {
            background: linear-gradient(135deg, #7c3aed, #9333ea);
            color: #ffffff !important;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.35);
        }
        .tbl-pill-checkout:hover {
            box-shadow: 0 4px 14px rgba(124, 58, 237, 0.5);
            color: #ffffff !important;
        }
        .tbl-pill-approve {
            background: linear-gradient(135deg, #059669, #10b981);
            color: #ffffff !important;
            box-shadow: 0 2px 8px rgba(5, 150, 105, 0.35);
        }
        .tbl-pill-approve:hover {
            box-shadow: 0 4px 14px rgba(5, 150, 105, 0.5);
            color: #ffffff !important;
        }
        .tbl-pill-checkin-verify {
            background: rgba(245, 158, 11, 0.15);
            color: #b45309;
            border: 1px solid rgba(245, 158, 11, 0.4);
        }
        .tbl-pill-checkin-verify:hover {
            background: #f59e0b;
            color: #ffffff;
        }

        .tbl-icon-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.82rem;
            text-decoration: none;
        }
        .tbl-icon-btn:hover {
            transform: translateY(-1px);
        }
        .tbl-icon-btn.btn-view:hover {
            background: #eff6ff;
            color: #2563eb;
            border-color: #93c5fd;
        }
        .tbl-icon-btn.btn-cancel:hover {
            background: #fef2f2;
            color: #dc2626;
            border-color: #fca5a5;
        }
        .tbl-icon-btn.btn-reject:hover {
            background: #fff1f2;
            color: #e11d48;
            border-color: #fecdd3;
        }

        .st-input-exec {
            width: 100% !important;
            background: #f8fafc !important;
            border: 1.5px solid #cbd5e1 !important;
            border-radius: 10px !important;
            padding: 0.62rem 0.95rem !important;
            font-size: 0.9rem !important;
            color: #0f172a !important;
            font-family: inherit !important;
            transition: all 0.2s ease !important;
            box-sizing: border-box !important;
        }
        .st-input-exec:focus {
            outline: none !important;
            background: #ffffff !important;
            border-color: #7c3aed !important;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15) !important;
        }
        .st-btn-gold {
            background: linear-gradient(135deg, #d97706, #f59e0b) !important;
            color: #ffffff !important;
            font-family: 'Outfit', sans-serif !important;
            font-weight: 700 !important;
            font-size: 0.92rem !important;
            padding: 0.65rem 1.4rem !important;
            border-radius: 10px !important;
            border: none !important;
            cursor: pointer !important;
            box-shadow: 0 4px 14px rgba(217, 119, 6, 0.3) !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 8px !important;
            transition: all 0.2s ease !important;
            text-decoration: none !important;
        }
        .st-btn-gold:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 20px rgba(217, 119, 6, 0.45) !important;
            color: #ffffff !important;
        }

        /* VIEW MODE TABS */
        .view-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .view-tab {
            padding: 0.7rem 1.3rem;
            border-radius: 10px;
            background: #f3e8ff;
            border: 1.5px solid #d8b4fe;
            color: var(--theme-text-primary, #1e1b4b);
            font-weight: 700;
            font-size: 0.88rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .view-tab:hover {
            background: #e9d5ff;
            color: #1e1b4b;
            border-color: #8b5cf6;
        }
        .view-tab.active {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            color: #ffffff;
            border-color: #a855f7;
            box-shadow: 0 4px 14px rgba(124, 58, 237, 0.4);
        }
        .view-tab .tab-count {
            background: rgba(124, 58, 237, 0.2);
            color: #6d28d9;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 800;
        }
        .view-tab.active .tab-count {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }

        /* DINING TAB STYLING */
        .view-tab.tab-dining {
            background: #2e1065;
            border-color: #f59e0b;
            color: #fbbf24;
        }
        .view-tab.tab-dining:hover {
            background: #4c1d95;
            border-color: #fbbf24;
            color: #ffffff;
        }
        .view-tab.tab-dining.active {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #ffffff;
            border-color: #fbbf24;
            box-shadow: 0 4px 14px rgba(245, 158, 11, 0.4);
        }
        .view-tab.tab-dining .tab-count {
            background: rgba(0, 0, 0, 0.4);
            color: #fbbf24;
        }
        .view-tab.tab-dining.active .tab-count {
            background: rgba(255, 255, 255, 0.3);
            color: #ffffff;
        }

        .dining-header-strip {
            background: linear-gradient(135deg, #2e1065 0%, #4c1d95 100%) !important;
            border-bottom: 1px solid #f59e0b !important;
        }
        .dining-card {
            border-color: #f59e0b !important;
            box-shadow: 0 0 20px rgba(245, 158, 11, 0.15);
        }
        .dining-row td { opacity: 0.95; }

        /* HISTORY SECTION STYLES */
        .history-header-strip {
            background: linear-gradient(135deg, #2e1065 0%, #4c1d95 100%) !important;
            border-bottom: 1px solid #7c3aed !important;
        }
        .history-card {
            border-color: #7c3aed !important;
            box-shadow: 0 0 20px rgba(124, 58, 237, 0.15);
        }
        .history-row td { opacity: 0.92; }
        .archive-timestamp {
            font-size: 0.72rem;
            color: #a78bfa;
            font-style: italic;
            display: block;
            margin-top: 3px;
        }
        .archive-reason-badge {
            display: inline-block;
            background: rgba(124, 58, 237, 0.2);
            color: #d8b4fe;
            border: 1px solid rgba(168, 85, 247, 0.4);
            padding: 2px 8px;
            border-radius: 8px;
            font-size: 0.7rem;
            font-weight: 700;
            margin-top: 3px;
        }

        /* DINING ORDER ITEMS & STEPS */
        .order-item-list {
            font-size: 0.85rem;
            line-height: 1.5;
        }
        .order-item-list div { color: #e2e8f0; }
        .order-total {
            color: #fbbf24;
            font-size: 0.9rem;
            font-weight: 800;
            display: block;
            margin-top: 0.3rem;
        }
        .allergy-warning-box {
            background: #fff1f2;
            border: 1.5px solid #fecdd3;
            border-left: 4px solid #e11d48;
            padding: 0.6rem;
            border-radius: 8px;
            font-size: 0.8rem;
            color: #881337;
            font-weight: 600;
        }
        .allergy-warning-box strong {
            color: #be123c;
            font-weight: 800;
            display: block;
            margin-bottom: 0.2rem;
        }
        .allergy-safe-badge {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
            padding: 3px 10px;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-block;
        }

        /* Dining Stepper */
        .mini-stepper {
            display: flex;
            align-items: center;
            gap: 3px;
            margin-top: 6px;
        }
        .mini-step {
            flex: 1;
            height: 5px;
            border-radius: 3px;
            background: rgba(255, 255, 255, 0.1);
            position: relative;
        }
        .mini-step.done { background: #10b981; }
        .mini-step.active {
            background: #fbbf24;
            box-shadow: 0 0 8px rgba(251, 191, 36, 0.6);
        }

        .dining-action-form {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }

        .picture-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100vw; height: 100vh;
            background: rgba(0,0,0,0.92);
            z-index: 999999;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            box-sizing: border-box;
        }
        .picture-modal-overlay.active { display: flex; }
        .picture-modal-container {
            background: #ffffff;
            border-radius: 14px;
            padding: 1.5rem;
            max-width: 90vw;
            max-height: 90vh;
            text-align: center;
            position: relative;
            overflow: auto;
        }
        .picture-modal-close {
            position: absolute;
            top: 10px;
            right: 14px;
            background: #ef4444;
            color: #fff;
            border: none;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            font-size: 1.35rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .picture-modal-close:hover { background: #b91c1c; }

        /* =====================================================
           COMPREHENSIVE RESPONSIVE STYLES — ALL DEVICES
           ===================================================== */

        /* Filter form & search inputs — 16px minimum prevents iOS auto-zoom */
        .admin-card input[type="text"],
        .admin-card input[type="date"],
        .admin-card input[type="number"],
        .admin-card select,
        .admin-card textarea {
            font-size: max(16px, 0.9rem) !important;
            -webkit-appearance: none;
            appearance: none;
        }

        /* View tabs — allow horizontal scroll on mobile */
        .view-tabs {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            flex-wrap: nowrap;
            padding-bottom: 4px;
            scrollbar-width: none;
        }
        .view-tabs::-webkit-scrollbar { height: 3px; }
        .view-tab {
            white-space: nowrap;
            flex-shrink: 0;
        }

        /* Action buttons — minimum 40px touch target */
        .act-btn {
            min-width: 40px;
            min-height: 40px;
        }

        /* Modal responsive fix */
        @media (max-width: 768px) {
            .modal-container {
                max-width: 95vw !important;
                width: 95vw !important;
                max-height: 92vh !important;
                border-radius: 12px !important;
            }
            .modal-body {
                padding: 1rem !important;
            }
            .modal-header {
                padding: 1rem 1.15rem !important;
            }
            .modal-header h3 {
                font-size: 1rem !important;
            }
            .picture-modal-container {
                max-width: 96vw !important;
                max-height: 88vh !important;
                padding: 1rem !important;
            }
            .detail-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.2rem;
            }
            .detail-row span {
                font-size: 0.85rem;
            }
        }

        /* Filter console responsive */
        @media (max-width: 768px) {
            form[action="bookings.php"] {
                grid-template-columns: 1fr !important;
            }
        }

        /* Page header wraps on mobile */
        @media (max-width: 640px) {
            .admin-page-header,
            .page-header {
                flex-direction: column;
                align-items: stretch;
                gap: 0.85rem;
            }
            .admin-page-header > div:last-child,
            .page-header > div:last-child {
                display: flex;
                flex-wrap: wrap;
                gap: 0.5rem;
                width: 100%;
            }
            .admin-page-header > div:last-child > *,
            .page-header > div:last-child > * {
                flex: 1;
                justify-content: center;
                text-align: center;
                min-height: 44px;
            }
            .view-tab {
                padding: 0.6rem 1rem;
                font-size: 0.82rem;
            }
        }

        /* Table header strip responsive */
        @media (max-width: 640px) {
            .admin-table-container > div:first-child {
                flex-direction: column;
                align-items: flex-start;
                padding: 1rem !important;
            }
        }

        /* Stat cards: 2-col on tablet, 1-col on small */
        @media (max-width: 580px) {
            .admin-grid {
                grid-template-columns: 1fr 1fr !important;
            }
            .admin-stat-value {
                font-size: 1.55rem !important;
            }
        }
        @media (max-width: 380px) {
            .admin-grid {
                grid-template-columns: 1fr !important;
            }
        }

        @media print {
            body * { visibility: hidden !important; }
            #detailsModal, #detailsModal * { visibility: visible !important; }
            #detailsModal {
                position: absolute !important;
                left: 0 !important;
                top: 0 !important;
                width: 100% !important;
                height: auto !important;
                background: none !important;
                padding: 0 !important;
            }
            .modal-container {
                box-shadow: none !important;
                border: 1px solid #ccc !important;
                background: #ffffff !important;
                color: #000000 !important;
            }
            .modal-close, .modal-header, button { display: none !important; }
            .detail-row span, .detail-row label { color: #000 !important; }
        }
        /* ============================================================
           EXECUTIVE DESIGN SUITE FOR BOOKINGS & FRONT DESK
           ============================================================ */
        .exec-page-header-row {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1.25rem 1.75rem;
            margin-bottom: 1.75rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1.25rem;
        }
        .exec-page-title-group h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.75rem;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0 0 4px 0;
            letter-spacing: -0.02em;
        }
        .exec-page-title-group h1 i {
            color: #d97706;
            background: rgba(245, 158, 11, 0.12);
            padding: 10px;
            border-radius: 12px;
            font-size: 1.3rem;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.15);
        }
        .exec-page-title-group p {
            font-size: 0.92rem;
            color: #64748b;
            margin: 0;
        }

        /* Executive 4-Col KPI Grid */
        .exec-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem;
            margin-bottom: 1.75rem;
        }
        @media (max-width: 1024px) {
            .exec-kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 580px) {
            .exec-kpi-grid {
                grid-template-columns: 1fr;
            }
        }
        .exec-kpi-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 1.25rem 1.4rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .exec-kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
        }
        .exec-kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--kpi-accent, #7c3aed);
        }
        .exec-kpi-card-purple::before { background: linear-gradient(90deg, #7c3aed, #a855f7); }
        .exec-kpi-card-gold::before { background: linear-gradient(90deg, #d97706, #f59e0b); }
        .exec-kpi-card-emerald::before { background: linear-gradient(90deg, #059669, #10b981); }
        .exec-kpi-card-rose::before { background: linear-gradient(90deg, #e11d48, #f43f5e); }

        .exec-kpi-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.65rem;
        }
        .exec-kpi-label {
            font-size: 0.82rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .exec-kpi-icon-wrap {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }
        .exec-kpi-val {
            font-family: 'Outfit', sans-serif;
            font-size: 1.85rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
            margin-bottom: 0.5rem;
        }
        .exec-kpi-sub {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.76rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            width: fit-content;
        }

        /* Filter Console */
        .exec-filter-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 1.25rem 1.4rem;
            margin-bottom: 1.75rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }
        .exec-filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            align-items: end;
        }
        .exec-form-group {
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .exec-form-label {
            font-size: 0.78rem;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .exec-form-control {
            width: 100%;
            height: 42px;
            padding: 0.6rem 0.9rem;
            background: #f8fafc;
            color: #0f172a;
            border: 1.5px solid #cbd5e1;
            border-radius: 9px;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }
        .exec-form-control:focus {
            background: #ffffff;
            border-color: #7c3aed;
            outline: none;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15);
        }

        /* Executive Tabs Navbar */
        .executive-tabs-navbar {
            background: linear-gradient(90deg, #090e1a 0%, #111827 45%, #1e113a 100%);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 14px;
            padding: 8px 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 1.75rem;
            box-shadow: 0 8px 30px rgba(11, 17, 32, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            overflow-x: auto;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }
        .executive-tabs-navbar::-webkit-scrollbar {
            display: none;
        }
        .exec-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            border-radius: 10px;
            font-family: 'Outfit', sans-serif;
            font-size: 0.92rem;
            font-weight: 600;
            text-decoration: none;
            color: #94a3b8;
            background: transparent;
            border: 1px solid transparent;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
            cursor: pointer;
        }
        .exec-tab-btn:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.06);
            transform: translateY(-1px);
        }
        .exec-tab-btn.active {
            background: rgba(251, 191, 36, 0.14);
            color: #fbbf24;
            border: 1px solid #fbbf24;
            font-weight: 700;
            box-shadow: 0 0 20px rgba(251, 191, 36, 0.25), inset 0 0 10px rgba(251, 191, 36, 0.08);
        }
        .exec-tab-badge {
            background: rgba(255, 255, 255, 0.12);
            color: #cbd5e1;
            font-size: 0.72rem;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 10px;
        }
        .exec-tab-btn.active .exec-tab-badge {
            background: rgba(251, 191, 36, 0.25);
            color: #fbbf24;
            border: 1px solid rgba(251, 191, 36, 0.4);
        }

        /* Front Desk Prominent Action Pills */
        .tbl-action-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
            white-space: nowrap;
        }
        .tbl-action-pill:hover {
            transform: translateY(-1px);
            filter: brightness(1.1);
        }
        .tbl-pill-checkin {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff !important;
            box-shadow: 0 3px 10px rgba(16, 185, 129, 0.35);
        }
        .tbl-pill-checkout {
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            color: #ffffff !important;
            box-shadow: 0 3px 10px rgba(79, 70, 229, 0.35);
        }
        .tbl-pill-approve {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: #ffffff !important;
            box-shadow: 0 3px 10px rgba(14, 165, 233, 0.35);
        }
        .tbl-pill-checkin-verify {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: #ffffff !important;
            box-shadow: 0 3px 10px rgba(245, 158, 11, 0.35);
        }

        .tbl-icon-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid transparent;
            background: #f1f5f9;
            color: #475569;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .tbl-icon-btn:hover {
            transform: scale(1.08);
        }
        .tbl-icon-view:hover {
            background: rgba(124, 58, 237, 0.12);
            color: #7c3aed;
            border-color: rgba(124, 58, 237, 0.3);
        }
        .tbl-icon-cancel:hover {
            background: rgba(225, 29, 72, 0.12);
            color: #e11d48;
            border-color: rgba(225, 29, 72, 0.3);
        }
        /* ============================================================
           BLUEWAVE / MODERN BOOKING MANAGEMENT STYLES
           ============================================================ */
        .admin-layout-body {
            background: #f8fafc !important;
            color: #1e293b;
            margin: 0 !important;
            padding: 0 !important;
        }
        .admin-main-wrapper {
            margin-left: 260px !important;
            width: calc(100% - 260px) !important;
            max-width: calc(100% - 260px) !important;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
            box-sizing: border-box;
            background: #f8fafc;
            overflow-x: hidden;
        }
        .admin-top-header {
            padding: 0 1.5rem !important;
        }
        .admin-container {
            width: 100% !important;
            max-width: 100% !important;
            margin: 0 !important;
            padding: 1.25rem 1.5rem 2.5rem !important;
            box-sizing: border-box !important;
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: calc(100vh - 72px);
        }
        @media (max-width: 991px) {
            .admin-main-wrapper {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            .admin-container {
                padding: 1rem 1rem 2rem !important;
                min-height: auto;
            }
            .admin-top-header {
                padding: 0 1rem !important;
            }
        }

        /* Page Header */
        .bm-page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .bm-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .bm-header-icon-box {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: #f3e8ff;
            color: #7c3aed;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            border: 1px solid #e9d5ff;
        }
        .bm-header-titles h1 {
            font-size: 1.45rem !important;
            font-weight: 800 !important;
            color: #0f172a !important;
            margin: 0 0 4px 0 !important;
            letter-spacing: -0.02em;
        }
        .bm-header-titles p {
            margin: 0;
            font-size: 0.86rem;
            color: #64748b;
        }
        .bm-btn-new-booking {
            background: #7c3aed;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 0.65rem 1.35rem;
            font-size: 0.9rem;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(124, 58, 237, 0.3);
            transition: all 0.2s ease;
        }
        .bm-btn-new-booking:hover {
            background: #6d28d9;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(124, 58, 237, 0.4);
        }

        /* Controls Strip: Tabs on Left, Search on Right */
        .bm-controls-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }
        .bm-tabs-group {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .bm-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 16px;
            border-radius: 9999px;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            background: #ffffff;
            color: #475569;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .bm-tab-btn:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #0f172a;
        }
        .bm-tab-btn.active {
            background: #7c3aed;
            color: #ffffff;
            border-color: #7c3aed;
            box-shadow: 0 3px 10px rgba(124, 58, 237, 0.3);
        }
        .bm-tab-badge {
            background: #f1f5f9;
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 9999px;
        }
        .bm-tab-btn.active .bm-tab-badge {
            background: rgba(255, 255, 255, 0.24);
            color: #ffffff;
        }

        /* Search & Filter Button Group */
        .bm-search-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .bm-search-box {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.45rem 0.85rem;
            width: 290px;
            max-width: 100%;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: all 0.2s ease;
        }
        .bm-search-box:focus-within {
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }
        .bm-search-box i {
            color: #94a3b8;
            font-size: 0.85rem;
        }
        .bm-search-box input {
            border: none;
            outline: none;
            background: transparent;
            font-size: 0.85rem;
            color: #0f172a;
            width: 100%;
        }
        .bm-filter-btn, .bm-btn-filter {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            color: #475569;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: all 0.2s ease;
        }
        .bm-filter-btn:hover, .bm-btn-filter:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #7c3aed;
        }

        /* Extra Filter Collapsible Drawer */
        .bm-extra-filter-drawer {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
            animation: fadeIn 0.2s ease;
        }
        .bm-drawer-grid {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            flex-wrap: wrap;
        }
        .bm-filter-field label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            color: #64748b;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .bm-filter-field select, .bm-filter-field input[type="date"] {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.45rem 0.8rem;
            font-size: 0.85rem;
            color: #0f172a;
            outline: none;
        }
        .bm-btn-reset-filters {
            font-size: 0.82rem;
            color: #ef4444;
            text-decoration: none;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 0.45rem 0.8rem;
        }

        /* Main Table Card */
        .bm-table-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03);
            overflow: hidden;
            margin-bottom: 1.25rem;
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 440px;
        }
        .bm-table-responsive {
            overflow-x: auto;
            width: 100%;
            -webkit-overflow-scrolling: touch;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .bm-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
            min-width: 1020px;
        }
        .bm-table thead th {
            background: #f8fafc !important;
            color: #64748b !important;
            font-size: 0.72rem !important;
            font-weight: 800 !important;
            letter-spacing: 0.05em !important;
            text-transform: uppercase !important;
            padding: 1rem 1.15rem !important;
            border-bottom: 1.5px solid #e2e8f0 !important;
            text-align: left;
            white-space: nowrap;
        }
        .bm-table tbody td {
            background: #ffffff;
            color: #334155;
            padding: 1.15rem 1.15rem;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.88rem;
            transition: background 0.15s ease;
        }
        .bm-table tbody tr:hover td {
            background: #faf5ff;
        }
        .bm-table tbody tr:last-child td {
            border-bottom: none;
        }

        /* Actions Column in Bookings Table */
        .bm-table th.col-actions,
        .bm-table td.col-actions {
            text-align: right !important;
            padding-right: 1.25rem !important;
            white-space: nowrap;
        }

        /* Column Specific Styling */
        .bm-guest-name {
            font-weight: 800;
            color: #0f172a;
            font-size: 0.92rem;
            display: block;
            margin-bottom: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 160px;
        }
        .bm-guest-contact {
            font-size: 0.75rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 160px;
        }
        .bm-guest-contact i {
            width: 12px;
            text-align: center;
            color: #94a3b8;
        }
        .bm-guest-id-pill {
            background: #f3e8ff;
            color: #9333ea;
            font-size: 0.68rem;
            font-weight: 700;
            padding: 2.5px 7px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 5px;
            letter-spacing: 0.3px;
        }

        /* Item Booked column */
        .bm-item-count {
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 800;
            color: #0f172a;
            font-size: 0.86rem;
        }
        .bm-item-main {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.82rem;
            color: #334155;
            font-weight: 600;
            margin-top: 2px;
        }
        .bm-item-addon {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            color: #64748b;
            margin-top: 2px;
        }
        .bm-item-pax {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.76rem;
            color: #64748b;
            margin-top: 3px;
        }
        .bm-tag-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 7px;
            border-radius: 5px;
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-top: 3px;
        }
        .bm-tag-house { background: #ede9fe; color: #6d28d9; }
        .bm-tag-activity { background: #ccfbf1; color: #0f766e; }
        .bm-tag-food { background: #ffedd5; color: #c2410c; }

        /* Dates & Times */
        .bm-dates-range {
            font-weight: 700;
            color: #1e293b;
            font-size: 0.84rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .bm-time-slot {
            font-size: 0.76rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 3px;
        }
        .bm-resched-req-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #ede9fe;
            color: #6366f1;
            border-radius: 9999px;
            font-size: 0.68rem;
            font-weight: 700;
            padding: 2px 8px;
            margin-top: 5px;
        }
        .bm-rescheduled-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #e0f2fe;
            color: #0284c7;
            border-radius: 9999px;
            font-size: 0.68rem;
            font-weight: 700;
            padding: 2px 8px;
            margin-top: 5px;
        }
        .bm-resched-from-text {
            font-size: 0.70rem;
            color: #64748b;
            margin-top: 2px;
        }

        /* Amount */
        .bm-amount-cell {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1rem;
            color: #0f172a;
            white-space: nowrap;
        }

        /* Status Pills (Separate Payment & Booking Status) */
        .bm-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            white-space: nowrap;
        }
        /* Payment Pills */
        .bm-pay-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .bm-pay-unverified { background: #fef3c7; color: #d97706; }
        .bm-pay-paid, .bm-pay-verified { background: #d1fae5; color: #059669; }
        .bm-pay-rejected { background: #ffe4e6; color: #e11d48; }

        /* Booking Pills */
        .bm-book-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .bm-book-pending { background: #f3e8ff; color: #9333ea; }
        .bm-book-confirmed { background: #e0f2fe; color: #0284c7; }
        .bm-book-resched { background: #fef3c7; color: #d97706; }
        .bm-book-checkedin { background: #ccfbf1; color: #0d9488; }
        .bm-book-checkedout { background: #e0e7ff; color: #4338ca; }
        .bm-book-completed { background: #f1f5f9; color: #475569; }
        .bm-book-cancelled { background: #ffe4e6; color: #e11d48; }

        /* Remarks */
        .bm-remarks-cell {
            color: #475569;
            font-size: 0.80rem;
            max-width: 170px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            line-height: 1.4;
            cursor: pointer;
        }

        /* Actions Cell & Buttons */
        .bm-actions-group {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 5px;
        }
        .bm-act-btn {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.15s ease;
            text-decoration: none;
            box-sizing: border-box;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #64748b;
        }
        .bm-act-btn-view {
            background: #eff6ff !important;
            color: #2563eb !important;
            border-color: #bfdbfe !important;
        }
        .bm-act-btn-view:hover {
            background: #dbeafe !important;
            transform: translateY(-1px);
        }
        .bm-act-btn-verify {
            background: #ecfdf5 !important;
            color: #059669 !important;
            border-color: #a7f3d0 !important;
        }
        .bm-act-btn-verify:hover {
            background: #d1fae5 !important;
            transform: translateY(-1px);
        }
        .bm-act-btn-resched {
            background: #eff6ff !important;
            color: #3b82f6 !important;
            border-color: #bfdbfe !important;
        }
        .bm-act-btn-resched:hover {
            background: #dbeafe !important;
            transform: translateY(-1px);
        }
        .bm-act-btn-dots {
            background: #4f46e5 !important;
            color: #ffffff !important;
            border-color: #4338ca !important;
            font-weight: 800;
        }
        .bm-act-btn-dots:hover {
            background: #4338ca !important;
            transform: translateY(-1px);
        }

        /* Floating 3-Dot Dropdown Menu */
        .bm-dropdown-menu {
            display: none;
            position: absolute;
            right: 0;
            top: 110%;
            z-index: 100;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);
            min-width: 200px;
            padding: 6px;
            text-align: left;
            animation: fadeIn 0.15s ease;
        }
        .bm-dd-section {
            font-size: 0.65rem;
            font-weight: 800;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            padding: 6px 10px 3px;
        }
        .bm-dd-item {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 7px 10px;
            font-size: 0.80rem;
            font-weight: 600;
            color: #1e293b;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.15s;
        }
        .bm-dd-item:hover {
            background: #f8fafc;
            color: #4f46e5;
        }
        .bm-dd-item.danger-item:hover {
            background: #fff1f2;
            color: #e11d48;
        }

        /* Bottom Status Flow Legend Card */
        .bm-status-legends-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 1.15rem 1.4rem;
            margin-top: 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1.25rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
        }
        .bm-status-flow-left, .bm-status-flow-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .bm-flow-label {
            font-size: 0.78rem;
            font-weight: 700;
            color: #64748b;
        }
        .bm-flow-items {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .bm-flow-arrow {
            font-size: 0.65rem;
            color: #cbd5e1;
        }
        .bm-flow-pill {
            font-size: 0.68rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .bm-flow-pending { background: #f3e8ff; color: #9333ea; }
        .bm-flow-verified { background: #d1fae5; color: #059669; }
        .bm-flow-confirmed { background: #e0f2fe; color: #0284c7; }
        .bm-flow-resched { background: #fef3c7; color: #d97706; }
        .bm-flow-confirmed-resched { background: #d1fae5; color: #059669; }
        .bm-flow-checkedin { background: #ccfbf1; color: #0d9488; }
        .bm-flow-checkedout { background: #e0e7ff; color: #4338ca; }
        .bm-flow-completed { background: #f1f5f9; color: #475569; }

        /* White Modern Modals */
        .bm-white-modal {
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 16px !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important;
            color: #0f172a !important;
            overflow: hidden !important;
        }
        .bm-white-modal-header {
            background: #ffffff !important;
            border-bottom: 1px solid #f1f5f9 !important;
            padding: 1.25rem 1.5rem !important;
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
        }
        .bm-white-modal-close {
            background: #f1f5f9 !important;
            color: #64748b !important;
            border: none !important;
            width: 32px !important;
            height: 32px !important;
            border-radius: 50% !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            cursor: pointer !important;
            font-size: 1.1rem !important;
            transition: all 0.2s ease !important;
        }
        .bm-white-modal-close:hover {
            background: #fee2e2 !important;
            color: #ef4444 !important;
        }
        .bm-white-modal-body {
            background: #ffffff !important;
            color: #0f172a !important;
            overflow-y: auto !important;
            max-height: calc(88vh - 120px) !important;
        }
        .bm-white-modal-footer {
            background: #f8fafc !important;
            border-top: 1px solid #f1f5f9 !important;
            padding: 1rem 1.5rem !important;
            display: flex !important;
            justify-content: flex-end !important;
            align-items: center !important;
            gap: 10px !important;
        }
        .bm-btn-close-modal {
            background: #3b82f6;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 0.55rem 1.4rem;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s;
        }
        .bm-btn-close-modal:hover {
            background: #2563eb;
        }

        /* Modal Content Styles Matching Mockup */
        .bm-modal-guest-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
        }
        .bm-modal-guest-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .bm-modal-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .bm-modal-guest-name {
            font-size: 1.05rem;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 2px;
        }
        .bm-modal-guest-contact {
            font-size: 0.82rem;
            color: #64748b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .bm-modal-profile-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #f5f3ff;
            border: 1px solid #ddd6fe;
            color: #7c3aed;
            font-size: 0.76rem;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 6px;
        }
        .bm-modal-status-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 1.25rem;
        }
        .bm-modal-status-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 14px;
        }
        .bm-modal-status-lbl {
            font-size: 0.72rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 6px;
        }
        .bm-modal-section-title {
            font-weight: 800;
            font-size: 0.88rem;
            color: #0f172a;
            margin: 1.1rem 0 0.6rem 0;
        }
        .bm-modal-info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 1.25rem;
        }
        @media (max-width: 600px) {
            .bm-modal-info-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        .bm-modal-info-cell {
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            border-radius: 10px;
            padding: 10px 12px;
        }
        .bm-modal-info-lbl {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #64748b;
            margin-bottom: 4px;
        }
        .bm-modal-info-lbl i {
            color: #3b82f6;
        }
        .bm-modal-info-val {
            font-size: 0.88rem;
            font-weight: 800;
            color: #0f172a;
            word-break: break-word;
        }
        .bm-modal-items-grid {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 1.25rem;
        }
        .bm-modal-item-card {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 8px 12px;
            flex: 1;
            min-width: 140px;
        }
        .bm-modal-item-img {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid #e2e8f0;
            background: #f1f5f9;
            flex-shrink: 0;
        }
        .bm-modal-item-name {
            font-size: 0.84rem;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 2px;
        }
        .bm-modal-item-unit {
            font-size: 0.74rem;
            color: #64748b;
            margin: 0;
        }
        .bm-modal-item-more {
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            padding: 8px 14px;
            font-size: 0.8rem;
            font-weight: 700;
            color: #64748b;
        }
        .bm-modal-pay-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .bm-modal-pay-amount {
            font-size: 1.25rem;
            font-weight: 900;
            color: #0f172a;
            font-family: 'Outfit', sans-serif;
        }
        .bm-modal-remarks-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 0.85rem;
            color: #475569;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            line-height: 1.45;
        }
        .bm-resched-notice {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 12px 16px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
            margin-bottom: 1.25rem;
        }
        .bm-resched-notice i {
            color: #2563eb;
            font-size: 1.15rem;
            margin-top: 2px;
        }
        .bm-resched-notice-title {
            font-weight: 800;
            font-size: 0.88rem;
            color: #1e40af;
            margin: 0 0 2px;
        }
        .bm-resched-notice-sub {
            font-size: 0.82rem;
            color: #3b82f6;
            margin: 0;
        }
        .bm-resched-compare-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin: 1.25rem 0;
        }
        @media (max-width: 540px) {
            .bm-resched-compare-grid {
                grid-template-columns: 1fr;
            }
        }
        .bm-resched-card-current {
            border: 1px solid #e2e8f0;
            background: #ffffff;
            border-radius: 12px;
            padding: 14px 16px;
        }
        .bm-resched-card-requested {
            border: 1.5px solid #86efac;
            background: #f0fdf4;
            border-radius: 12px;
            padding: 14px 16px;
        }
        .bm-resched-card-title {
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .bm-resched-row {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.86rem;
            margin-bottom: 8px;
            color: #334155;
        }
        .bm-resched-row i {
            width: 16px;
            text-align: center;
            color: #64748b;
        }
        .bm-resched-card-requested .bm-resched-row i {
            color: #16a34a;
        }
        .bm-resched-reason-block {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 16px;
            margin-top: 1rem;
        }
        .bm-resched-reason-title {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.76rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 4px;
        }
        .bm-resched-reason-text {
            font-size: 0.88rem;
            color: #0f172a;
            font-weight: 600;
            margin: 0;
            font-style: italic;
        }
        .bm-resched-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.25rem;
            padding-top: 1rem;
            border-top: 1px solid #f1f5f9;
        }
        .bm-btn-reject-resched {
            background: #ffffff;
            border: 1.5px solid #fca5a5;
            color: #ef4444;
            padding: 0.55rem 1.4rem;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .bm-btn-reject-resched:hover {
            background: #fee2e2;
        }
        .bm-btn-confirm-resched {
            background: #2563eb;
            border: none;
            color: #ffffff;
            padding: 0.55rem 1.5rem;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .bm-btn-confirm-resched:hover {
            background: #1d4ed8;
        }
        .bm-timeline-wrap {
            position: relative;
            padding-left: 32px;
            margin: 0.5rem 0;
        }
        .bm-timeline-line {
            position: absolute;
            left: 12px;
            top: 14px;
            bottom: 14px;
            width: 2px;
            background: #e2e8f0;
        }
        .bm-timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
        }
        .bm-timeline-item:last-child {
            margin-bottom: 0;
        }
        .bm-timeline-node {
            position: absolute;
            left: -32px;
            top: 0;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.72rem;
            color: #ffffff;
            box-shadow: 0 0 0 3px #ffffff;
        }
        .bm-timeline-node.success { background: #10b981; }
        .bm-timeline-node.primary { background: #2563eb; }
        .bm-timeline-node.purple { background: #8b5cf6; }
        .bm-timeline-node.amber { background: #f59e0b; }
        .bm-timeline-time {
            font-size: 0.76rem;
            color: #64748b;
            margin-bottom: 2px;
        }
        .bm-timeline-title {
            font-size: 0.92rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 2px;
        }
        .bm-timeline-desc {
            font-size: 0.82rem;
            color: #475569;
        }

        /* ============================================================
           COMPREHENSIVE MOBILE RESPONSIVE LAYOUT FOR BOOKINGS MANAGEMENT
           ============================================================ */
        .admin-layout-body .admin-floating-toggle-btn {
            display: none !important;
        }

        .bm-td-content {
            display: block;
        }

        @media (max-width: 991px) {
            .admin-main-wrapper {
                margin-left: 0 !important;
                width: 100% !important;
                min-width: 0 !important;
            }
            .admin-container {
                padding: 1rem 0.85rem 2rem 0.85rem !important;
            }
            .admin-top-header {
                padding: 0.5rem 0.75rem !important;
                min-height: 54px !important;
            }
            .hs-user-badge .hs-user-name {
                display: none !important;
            }
            .hs-user-badge {
                padding: 4px !important;
                border-radius: 50% !important;
                background: transparent !important;
                border: none !important;
            }
            .hs-user-avatar {
                width: 34px !important;
                height: 34px !important;
                font-size: 0.85rem !important;
            }
            .hs-btn-logout {
                width: 34px !important;
                height: 34px !important;
                font-size: 0.85rem !important;
            }

            /* Responsive Mobile Layout: Card View */
            .bm-table-card {
                background: transparent !important;
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
            }
            .bm-table-responsive {
                overflow-x: visible !important;
            }
            .bm-table {
                min-width: 100% !important;
                display: block !important;
                border: none !important;
            }
            .bm-table thead {
                display: none !important;
            }
            .bm-table tbody {
                display: flex !important;
                flex-direction: column !important;
                gap: 1rem !important;
            }
            .bm-table tbody tr.bm-table-row {
                display: flex !important;
                flex-direction: column !important;
                background: #ffffff !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 14px !important;
                padding: 1.15rem !important;
                box-shadow: 0 4px 15px rgba(0,0,0,0.03) !important;
                margin-bottom: 0 !important;
            }
            .bm-table tbody tr:hover td {
                background: transparent !important;
            }
            .bm-table tbody td {
                display: flex !important;
                justify-content: space-between !important;
                align-items: flex-start !important;
                padding: 0.65rem 0 !important;
                border: none !important;
                border-bottom: 1px dashed #f1f5f9 !important;
                gap: 0.75rem !important;
                width: 100% !important;
                box-sizing: border-box !important;
            }
            .bm-table tbody td:first-child {
                padding-top: 0 !important;
            }
            .bm-table tbody td::before {
                content: attr(data-label) !important;
                font-size: 0.72rem !important;
                font-weight: 800 !important;
                color: #64748b !important;
                text-transform: uppercase !important;
                letter-spacing: 0.04em !important;
                flex-shrink: 0 !important;
                margin-right: 0 !important;
                padding-top: 2px !important;
                text-align: left !important;
                width: auto !important;
            }
            .bm-td-content {
                display: flex !important;
                flex-direction: column !important;
                align-items: flex-end !important;
                text-align: right !important;
                flex: 1 1 auto !important;
                min-width: 0 !important;
            }
            .bm-table tbody td.col-actions {
                border-bottom: none !important;
                padding-bottom: 0 !important;
                padding-top: 0.85rem !important;
                margin-top: 0.35rem !important;
                border-top: 1px solid #e2e8f0 !important;
                justify-content: flex-end !important;
                width: 100% !important;
            }
            .bm-table tbody td.col-actions::before {
                display: none !important;
            }
            .bm-actions-group {
                width: 100% !important;
                justify-content: flex-end !important;
                gap: 8px !important;
                flex-wrap: wrap !important;
            }
            .bm-act-btn {
                min-width: 36px !important;
                height: 36px !important;
                font-size: 0.95rem !important;
            }
            .bm-guest-name, .bm-guest-contact {
                max-width: 100% !important;
            }
            .bm-dropdown-menu {
                position: absolute !important;
                right: 0 !important;
                top: auto !important;
                bottom: calc(100% + 4px) !important;
                z-index: 1050 !important;
                max-width: 85vw !important;
            }
        }

        @media (max-width: 768px) {
            /* Page Header */
            .bm-page-header {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 0.85rem !important;
                margin-bottom: 1.25rem !important;
            }
            .bm-header-left {
                gap: 10px !important;
            }
            .bm-header-icon-box {
                width: 40px !important;
                height: 40px !important;
                font-size: 1.15rem !important;
            }
            .bm-header-titles h1 {
                font-size: 1.4rem !important;
            }
            .bm-header-titles p {
                font-size: 0.82rem !important;
            }
            .bm-header-right {
                width: 100% !important;
                display: flex !important;
                flex-wrap: wrap !important;
                gap: 8px !important;
            }
            .bm-search-box {
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
            }
            .bm-filter-btn,
            .bm-header-right .admin-btn-primary {
                flex: 1 1 calc(50% - 4px) !important;
                justify-content: center !important;
                text-align: center !important;
                min-height: 42px !important;
                box-sizing: border-box !important;
            }

            /* Filter Drawer */
            #bmExtraFilterDrawer {
                padding: 1rem !important;
                border-radius: 12px !important;
                margin-bottom: 1rem !important;
            }
            #bmExtraFilterDrawer form {
                display: grid !important;
                grid-template-columns: 1fr 1fr !important;
                gap: 0.85rem !important;
                width: 100% !important;
            }
            #bmExtraFilterDrawer form > div {
                width: 100% !important;
                min-width: 0 !important;
            }
            #bmExtraFilterDrawer form select,
            #bmExtraFilterDrawer form input {
                width: 100% !important;
                min-width: 0 !important;
                box-sizing: border-box !important;
            }
            #bmExtraFilterDrawer form > div:last-child {
                grid-column: 1 / -1 !important;
                margin-left: 0 !important;
                display: flex !important;
                gap: 8px !important;
                width: 100% !important;
                justify-content: stretch !important;
                margin-top: 0.25rem !important;
            }
            #bmExtraFilterDrawer form > div:last-child > a {
                flex: 1 1 auto !important;
                text-align: center !important;
                justify-content: center !important;
                min-height: 40px !important;
                display: inline-flex !important;
                align-items: center !important;
                box-sizing: border-box !important;
            }

            /* Pagination */
            .bm-pagination-bar {
                flex-direction: column !important;
                gap: 0.85rem !important;
                align-items: center !important;
                text-align: center !important;
                padding: 1rem !important;
                background: #ffffff !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 12px !important;
                margin-top: 1rem !important;
            }
            .bm-pagination-btns {
                flex-wrap: wrap !important;
                justify-content: center !important;
                gap: 4px !important;
            }
            .bm-pg-btn {
                min-width: 36px !important;
                height: 36px !important;
            }

            /* Status Legend Flow */
            .bm-status-legends-card {
                padding: 1rem !important;
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 0.75rem !important;
            }
            .bm-status-flow-left,
            .bm-status-flow-right {
                width: 100% !important;
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 0.5rem !important;
            }
            .bm-flow-items {
                display: flex !important;
                flex-wrap: wrap !important;
                gap: 6px !important;
                align-items: center !important;
            }
            .bm-flow-pill {
                font-size: 0.65rem !important;
                padding: 2px 7px !important;
            }
            .bm-flow-arrow {
                font-size: 0.55rem !important;
            }

            /* Modals Mobile */
            .modal-overlay {
                padding: 0.5rem !important;
            }
            .modal-container,
            .bm-white-modal {
                max-width: 100% !important;
                width: 100% !important;
                max-height: 94vh !important;
                border-radius: 12px !important;
                margin: 0 !important;
            }
            .modal-header,
            .bm-white-modal-header {
                padding: 1rem !important;
            }
            .modal-body,
            .bm-white-modal-body {
                padding: 1rem !important;
                max-height: calc(94vh - 120px) !important;
            }
            .modal-footer,
            .bm-white-modal-footer {
                padding: 0.85rem 1rem !important;
            }
            .bm-modal-status-grid {
                grid-template-columns: 1fr !important;
                gap: 8px !important;
            }
            .bm-modal-info-grid {
                grid-template-columns: 1fr 1fr !important;
                gap: 8px !important;
            }
        }

        @media (max-width: 480px) {
            #bmExtraFilterDrawer form {
                grid-template-columns: 1fr !important;
            }
            .bm-table tbody tr.bm-table-row {
                padding: 0.95rem !important;
                border-radius: 12px !important;
            }
            .bm-table tbody td {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 0.25rem !important;
                padding: 0.55rem 0 !important;
            }
            .bm-table tbody td::before {
                margin-bottom: 2px !important;
                padding-top: 0 !important;
            }
            .bm-td-content {
                align-items: flex-start !important;
                text-align: left !important;
                width: 100% !important;
            }
            .bm-filter-btn,
            .bm-header-right .admin-btn-primary {
                flex: 1 1 100% !important;
            }
            .bm-modal-info-grid {
                grid-template-columns: 1fr !important;
            }
        }

        /* ============================================================
           ADD EXTRA TIME COMPONENT (Exact Match to Screenshot)
           ============================================================ */
        .bm-extra-time-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03);
            padding: 1.25rem 1.5rem;
            margin-top: 1.5rem;
            transition: all 0.25s ease;
        }
        .bm-etc-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .bm-etc-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1.05rem;
            color: #0f172a;
        }
        .bm-etc-title i {
            color: #7c3aed;
        }
        .bm-etc-close {
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 1.1rem;
            cursor: pointer;
            padding: 4px;
        }
        .bm-etc-close:hover {
            color: #ef4444;
        }

        .bm-etc-grid {
            display: grid;
            grid-template-columns: 1.1fr 1.2fr 1.4fr 1.3fr;
            gap: 1.5rem;
            align-items: center;
        }
        @media (max-width: 1024px) {
            .bm-etc-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 640px) {
            .bm-etc-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Col 1: Item Thumbnail & Durations */
        .bm-etc-item-box {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 1rem;
        }
        .bm-etc-icon-avatar {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: #f3e8ff;
            color: #7c3aed;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        .bm-etc-item-info h4 {
            margin: 0 0 2px 0;
            font-size: 0.95rem;
            font-weight: 800;
            color: #0f172a;
        }
        .bm-etc-item-info span {
            font-size: 0.75rem;
            color: #64748b;
        }
        .bm-etc-stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 4px 0;
            font-size: 0.82rem;
            color: #64748b;
        }
        .bm-etc-stat-row strong {
            color: #0f172a;
        }
        .bm-etc-stat-row .time-danger {
            color: #ef4444;
            font-weight: 800;
        }

        /* Col 2: Guest Details & Timing */
        .bm-etc-detail-field {
            margin-bottom: 0.65rem;
        }
        .bm-etc-detail-field:last-child {
            margin-bottom: 0;
        }
        .bm-etc-detail-field label {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.74rem;
            color: #64748b;
            margin-bottom: 2px;
        }
        .bm-etc-detail-field span {
            font-size: 0.88rem;
            font-weight: 700;
            color: #0f172a;
        }
        .bm-etc-detail-field .amount-highlight {
            color: #0f172a;
            font-size: 0.95rem;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
        }

        /* Col 3: Select Extra Time Radio Cards */
        .bm-etc-options-label {
            display: block;
            font-size: 0.76rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 0.65rem;
        }
        .bm-etc-radio-cards {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }
        .bm-etc-radio-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 7px 12px;
            border-radius: 8px;
            border: 1.5px solid #e2e8f0;
            background: #ffffff;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .bm-etc-radio-card:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
        }
        .bm-etc-radio-card.active {
            border-color: #7c3aed;
            background: #faf5ff;
        }
        .bm-etc-radio-left {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.84rem;
            font-weight: 700;
            color: #0f172a;
        }
        .bm-etc-radio-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid #cbd5e1;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .bm-etc-radio-card.active .bm-etc-radio-dot {
            border-color: #7c3aed;
        }
        .bm-etc-radio-card.active .bm-etc-radio-dot::after {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #7c3aed;
        }
        .bm-etc-radio-fee {
            font-size: 0.84rem;
            font-weight: 800;
            color: #7c3aed;
            font-family: 'Outfit', sans-serif;
        }

        /* Col 4: Summary & Confirm */
        .bm-etc-summary-box {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .bm-etc-sum-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.84rem;
            color: #64748b;
        }
        .bm-etc-sum-row strong {
            color: #0f172a;
        }
        .bm-etc-total-row {
            display: flex;
            flex-direction: column;
            margin-top: 4px;
            padding-top: 6px;
            border-top: 1px dashed #e2e8f0;
        }
        .bm-etc-total-row span {
            font-size: 0.76rem;
            color: #64748b;
        }
        .bm-etc-total-val {
            font-size: 1.35rem;
            font-weight: 900;
            color: #7c3aed;
            font-family: 'Outfit', sans-serif;
        }
        .bm-etc-btns {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }
        .bm-etc-btn-cancel {
            padding: 0.55rem 1rem;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #64748b;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .bm-etc-btn-cancel:hover {
            background: #f8fafc;
            color: #0f172a;
        }
        .bm-etc-btn-confirm {
            flex: 1;
            padding: 0.55rem 1rem;
            border-radius: 8px;
            border: none;
            background: #7c3aed;
            color: #ffffff;
            font-size: 0.85rem;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.25);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.15s ease;
        }
        .bm-etc-btn-confirm:hover {
            background: #6d28d9;
            transform: translateY(-1px);
        }
    </style>
</head>
<body class="<?= $is_admin_layout ? 'admin-layout-body' : '' ?>">

    <?php if ($is_admin_layout): ?>
        <?php include 'admin-nav.php'; ?>
        <div class="admin-main-wrapper">
            <header class="admin-top-header">
                <div class="admin-header-left">
                    <button type="button" class="admin-mobile-toggle-btn" onclick="toggleAdminSidebar()" aria-label="Open Navigation Menu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <div class="admin-header-context">
                        <div class="admin-header-title-row">
                            <span class="admin-header-page-heading" style="font-family: 'Outfit', sans-serif;">Booking Management</span>
                        </div>
                    </div>
                </div>

                <div class="admin-header-actions">
                    <a href="settings.php?tab=profile" class="hs-user-badge" title="View Admin Profile">
                    <span class="hs-user-avatar"><?= strtoupper(substr($admin_full_name ?? 'Admin', 0, 1)) ?></span>
                    <span class="hs-user-name"><?= htmlspecialchars($admin_full_name ?? 'Admin') ?></span>
                </a>
                <a href="logout.php" class="hs-btn-logout" onclick="openLogoutModal(event)" title="Sign Out"><i class="fa-solid fa-sign-out-alt"></i></a>
                </div>
            </header>
            <main class="admin-container">
    <?php else: ?>
        <?php include 'nav.php'; ?>
        <div class="container">
    <?php endif; ?>

        <!-- Page Header matching screenshot -->
        <div class="bm-page-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
            <div class="bm-header-left" style="display: flex; align-items: center; gap: 14px;">
                <div class="bm-header-icon-box" style="width: 46px; height: 46px; border-radius: 12px; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 1.35rem; box-shadow: 0 4px 12px rgba(37,99,235,0.25);">
                    <i class="fa-regular fa-calendar-days"></i>
                </div>
                <div class="bm-header-titles">
                    <h1 style="font-family: 'Outfit', sans-serif; font-size: 1.75rem; font-weight: 800; color: #0f172a; margin: 0 0 2px; letter-spacing: -0.02em;">Bookings</h1>
                    <p style="font-size: 0.90rem; color: #64748b; margin: 0;">Manage and monitor all resort bookings.</p>
                </div>
            </div>
            <div class="bm-header-right" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <div class="bm-search-box" style="display: flex; align-items: center; gap: 9px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.55rem 1rem; width: 340px; max-width: 100%; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                    <i class="fa-solid fa-magnifying-glass" style="color: #94a3b8; font-size: 0.88rem;"></i>
                    <input type="text" id="bmTableSearch" placeholder="Search by name, booking ID, or phone..." value="<?= htmlspecialchars($search_query) ?>" onkeyup="instantSearchRows(this.value)" style="border: none; outline: none; background: transparent; font-size: 0.88rem; color: #0f172a; width: 100%;">
                </div>
                <button type="button" class="bm-filter-btn" onclick="toggleExtraFilterPanel()" style="border: 1px solid #e2e8f0; background: #ffffff; border-radius: 10px; padding: 0.55rem 1.1rem; font-weight: 700; color: #334155; display: inline-flex; align-items: center; gap: 8px; font-size: 0.88rem; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                    <i class="fa-solid fa-sliders" style="color: #64748b;"></i> Filter
                </button>
                <button type="button" onclick="openManualModal()" class="admin-btn admin-btn-primary" style="font-size: 0.88rem; padding: 0.55rem 1.1rem; border-radius: 10px; font-weight: 700; background: #7c3aed; color: #fff; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-plus"></i> New Booking
                </button>
            </div>
        </div>

        <!-- Action / Flash Notification Message -->
        <?php 
        $f_msg = $_SESSION['flash_msg'] ?? $action_msg;
        $f_type = $_SESSION['flash_type'] ?? $action_type;
        unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
        if (!empty($f_msg)): 
        ?>
            <div style="background: <?= $f_type === 'danger' ? '#fff1f2' : '#ecfdf5' ?>; border: 1px solid <?= $f_type === 'danger' ? '#fecdd3' : '#a7f3d0' ?>; color: <?= $f_type === 'danger' ? '#9f1239' : '#065f46' ?>; padding: 1rem 1.25rem; border-radius: 10px; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid <?= $f_type === 'danger' ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>" style="font-size: 1.25rem; color: <?= $f_type === 'danger' ? '#f43f5e' : '#10b981' ?>;"></i>
                    <span><?= $f_msg ?></span>
                </div>
                <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer; color: inherit;">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Collapsible Filter Drawer -->
        <div id="bmExtraFilterDrawer" class="bm-extra-filters-panel" style="display: <?= (!empty($type_filter) && $type_filter !== 'all') || (!empty($status_filter) && $status_filter !== 'all') || (!empty($payment_filter) && $payment_filter !== 'all') || !empty($date_filter) ? 'flex' : 'none' ?>; background: #ffffff; padding: 1.25rem 1.5rem; border-radius: 14px; border: 1px solid #e2e8f0; margin-bottom: 1.25rem; gap: 1.25rem; align-items: flex-end; flex-wrap: wrap; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
            <form method="GET" action="bookings.php" style="display: flex; gap: 1.25rem; align-items: flex-end; flex-wrap: wrap; width: 100%;">
                <input type="hidden" name="view" value="<?= htmlspecialchars($view_mode) ?>">
                <div>
                    <label style="font-size: 0.76rem; font-weight: 700; color: #475569; display: block; margin-bottom: 0.35rem; text-transform: uppercase;">Booking Status</label>
                    <select name="status" onchange="this.form.submit()" style="padding: 0.5rem 0.85rem; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.85rem; color: #0f172a; background: #fff; min-width: 170px;">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Active Bookings</option>
                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="confirmed" <?= $status_filter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                        <option value="resched" <?= $status_filter === 'resched' || $status_filter === 'reschedule_requested' ? 'selected' : '' ?>>Reschedule Requested</option>
                        <option value="checked_in" <?= $status_filter === 'checked_in' ? 'selected' : '' ?>>Checked In</option>
                        <option value="checked_out">Checked Out (View in History)</option>
                        <option value="cancelled">Cancelled (View in History)</option>
                    </select>
                </div>
                <div>
                    <label style="font-size: 0.76rem; font-weight: 700; color: #475569; display: block; margin-bottom: 0.35rem; text-transform: uppercase;">Payment Status</label>
                    <select name="payment" onchange="this.form.submit()" style="padding: 0.5rem 0.85rem; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.85rem; color: #0f172a; background: #fff; min-width: 160px;">
                        <option value="all" <?= $payment_filter === 'all' ? 'selected' : '' ?>>All Payments</option>
                        <option value="unverified" <?= $payment_filter === 'unverified' ? 'selected' : '' ?>>Unverified</option>
                        <option value="verified" <?= $payment_filter === 'verified' || $payment_filter === 'paid' ? 'selected' : '' ?>>Verified / Paid</option>
                        <option value="rejected" <?= $payment_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>
                <div>
                    <label style="font-size: 0.76rem; font-weight: 700; color: #475569; display: block; margin-bottom: 0.35rem; text-transform: uppercase;">Category</label>
                    <select name="type" onchange="this.form.submit()" style="padding: 0.5rem 0.85rem; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.85rem; color: #0f172a; background: #fff; min-width: 160px;">
                        <option value="all" <?= $type_filter === 'all' ? 'selected' : '' ?>>All Categories</option>
                        <option value="house" <?= $type_filter === 'house' ? 'selected' : '' ?>>House / Accommodation</option>
                        <option value="activity" <?= $type_filter === 'activity' ? 'selected' : '' ?>>Pool &amp; Activities</option>
                        <option value="dining" <?= $type_filter === 'dining' ? 'selected' : '' ?>>Kitchen &amp; Dining</option>
                    </select>
                </div>
                <div>
                    <label style="font-size: 0.76rem; font-weight: 700; color: #475569; display: block; margin-bottom: 0.35rem; text-transform: uppercase;">Stay Date</label>
                    <input type="date" name="date" value="<?= htmlspecialchars($date_filter) ?>" onchange="this.form.submit()" style="padding: 0.5rem 0.85rem; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.85rem; color: #0f172a; background: #fff;">
                </div>
                <div style="margin-left: auto; display: flex; align-items: center; gap: 8px;">
                    <a href="booking_history.php" style="padding: 0.5rem 0.9rem; border-radius: 8px; border: 1px solid #c4b5fd; font-size: 0.85rem; color: #7c3aed; background: #f5f3ff; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;"><i class="fa-solid fa-clock-rotate-left"></i> View History</a>
                    <a href="bookings.php?view=<?= htmlspecialchars($view_mode) ?>" style="padding: 0.5rem 1rem; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 0.85rem; color: #64748b; text-decoration: none; font-weight: 600; display: inline-block;">Reset Filters</a>
                </div>
            </form>
        </div>

        <!-- 8-Column Clean Resort Management Booking Table Card -->
        <div class="bm-table-card">
            <div class="bm-table-responsive">
                <table class="bm-table" id="bmBookingsTable">
                    <thead>
                        <tr>
                            <th style="width: 17%;">GUEST DETAILS</th>
                            <th style="width: 17%;">ITEM BOOKED</th>
                            <th style="width: 17%;">DATES &amp; TIMES</th>
                            <th style="width: 11%;">AMOUNT</th>
                            <th style="width: 11%;">PAYMENT STATUS</th>
                            <th style="width: 12%;">BOOKING STATUS</th>
                            <th style="width: 15%;">REMARKS</th>
                            <th class="col-actions" style="width: 10%; text-align: right; padding-right: 1.25rem;">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($display_bookings)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 5rem 1.5rem; color: #94a3b8;">
                                    <i class="fa-solid fa-inbox" style="font-size: 2.6rem; margin-bottom: 0.85rem; display: block; opacity: 0.4;"></i>
                                    <div style="font-weight: 700; color: #64748b; font-size: 1.05rem;">No bookings found</div>
                                    <p style="font-size: 0.88rem; margin-top: 0.35rem;">Try switching view tabs or clearing search criteria.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($display_bookings as $b): 
                                $bId = $b['id'] ?? '—';
                                $bType = strtolower($b['type'] ?? 'house');
                                $tagLabel = 'HOUSE';
                                $tagClass = 'house';
                                $tagIcon = 'fa-solid fa-house';
                                if ($bType === 'activity') {
                                    $tagLabel = 'ACTIVITY';
                                    $tagClass = 'activity';
                                    $tagIcon = 'fa-solid fa-person-swimming';
                                } elseif ($bType === 'dining' || $bType === 'food') {
                                    $tagLabel = 'FOOD';
                                    $tagClass = 'food';
                                    $tagIcon = 'fa-solid fa-utensils';
                                }

                                $guestName = $b['guest_name'] ?? 'Guest';
                                $guestPhone = $b['guest_phone'] ?? '';
                                $guestEmail = $b['guest_email'] ?? '';
                                $profileId = $b['profile_id'] ?? '';
                                if (empty($profileId) && !empty($b['user_id'])) {
                                    $profileId = 'PBR-GST-' . str_pad($b['user_id'], 5, '0', STR_PAD_LEFT);
                                }
                                if (empty($profileId)) {
                                    $profileId = $bId;
                                }

                                $itemName = $b['item_name'] ?? 'Accommodation';
                                $pax = $b['pax'] ?? ($b['num_guests'] ?? 1);

                                // Multi-item addons for this booking
                                $bAddons = $addons_by_ref[$bId] ?? [];
                                $totalItemsCount = 1 + count($bAddons);

                                // Primary item image lookup for modal
                                $primaryImage = '';
                                if (!empty($b['item_id']) && isset($kubos_catalog['id_' . $b['item_id']]['primary_image'])) {
                                    $primaryImage = $kubos_catalog['id_' . $b['item_id']]['primary_image'];
                                } elseif (isset($kubos_catalog[strtolower(trim($itemName))]['primary_image'])) {
                                    $primaryImage = $kubos_catalog[strtolower(trim($itemName))]['primary_image'];
                                } elseif (isset($activities_catalog[strtolower(trim($itemName))]['image'])) {
                                    $primaryImage = $activities_catalog[strtolower(trim($itemName))]['image'];
                                }

                                // Attach full metadata to $b for JS modals
                                $b['addons'] = $bAddons;
                                $b['primary_image'] = $primaryImage;
                                $b['profile_id'] = $profileId;

                                // Format Dates & Times
                                $checkIn = !empty($b['check_in']) ? date('M d, Y', strtotime($b['check_in'])) : '—';
                                $checkOut = !empty($b['check_out']) ? date('M d, Y', strtotime($b['check_out'])) : $checkIn;
                                $timeSlot = $b['time_slot'] ?? '';
                                if (empty($timeSlot)) {
                                    if (!empty($b['start_time']) && !empty($b['end_time'])) {
                                        $timeSlot = date('g:i A', strtotime($b['start_time'])) . ' - ' . date('g:i A', strtotime($b['end_time']));
                                    } else {
                                        $timeSlot = 'Morning (08:00 AM – 12:00 PM)';
                                    }
                                }

                                $amount = floatval($b['total_amount'] ?? 0);
                                
                                // Payment Status Logic
                                $rawPay = strtolower($b['payment_status'] ?? 'proof_submitted');
                                $isVerifiedPay = in_array($rawPay, ['verified', 'paid', 'approved']);
                                $isRejectedPay = ($rawPay === 'rejected');
                                
                                // Booking Status Logic
                                $st = strtolower($b['booking_status'] ?? 'pending');
                                $isReschedReq = (!empty($b['resched_pending']) || $st === 'reschedule_requested');

                                // Remarks
                                $reason = $b['special_notes'] ?? ($b['rejection_reason'] ?? ($b['archive_reason'] ?? ''));
                                if ($isReschedReq && !empty($b['resched_reason'])) {
                                    $reason = "Customer requested reschedule to " . date('M d, Y', strtotime($b['resched_check_in'])) . ": " . $b['resched_reason'];
                                } elseif ($isReschedReq && !empty($b['resched_check_in'])) {
                                    $reason = "Customer requested reschedule to " . date('M d, Y', strtotime($b['resched_check_in'])) . ".";
                                }
                                if (empty($reason)) $reason = '—';
                            ?>
                            <tr class="bm-table-row" data-row-id="<?= htmlspecialchars($bId) ?>">
                                <!-- 1. GUEST DETAILS -->
                                <td data-label="Guest Details">
                                    <div class="bm-td-content">
                                        <div class="bm-guest-name" title="<?= htmlspecialchars($guestName) ?>"><?= htmlspecialchars($guestName) ?></div>
                                        <?php if ($guestPhone): ?>
                                            <div class="bm-guest-contact" title="<?= htmlspecialchars($guestPhone) ?>"><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($guestPhone) ?></div>
                                        <?php endif; ?>
                                        <?php if ($guestEmail): ?>
                                            <div class="bm-guest-contact" title="<?= htmlspecialchars($guestEmail) ?>"><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($guestEmail) ?></div>
                                        <?php endif; ?>
                                        <div class="bm-guest-id-pill"><i class="fa-solid fa-id-badge"></i> <?= htmlspecialchars($profileId) ?></div>
                                    </div>
                                </td>

                                <!-- 2. ITEM BOOKED -->
                                <td data-label="Item Booked">
                                    <div class="bm-td-content">
                                        <?php if (count($bAddons) > 0): ?>
                                            <div class="bm-item-count"><i class="fa-solid fa-house"></i> <?= $totalItemsCount ?> Items</div>
                                            <div class="bm-item-main"><i class="fa-solid fa-house"></i> <?= htmlspecialchars($itemName) ?> + <?= count($bAddons) ?> More</div>
                                            <div class="bm-item-addon"><i class="fa-solid fa-water"></i> <?= htmlspecialchars($bAddons[0]['activity_name'] ?? 'Add-on') ?></div>
                                            <div class="bm-item-pax"><i class="fa-solid fa-user-group"></i> <?= $pax ?> Guests</div>
                                        <?php else: ?>
                                            <div style="font-weight: 700; color: #0f172a; font-size: 0.88rem;"><?= htmlspecialchars($itemName) ?></div>
                                            <div style="margin-top: 3px;">
                                                <span class="bm-tag-pill bm-tag-<?= $tagClass ?>"><i class="<?= $tagIcon ?>"></i> <?= $tagLabel ?></span>
                                            </div>
                                            <div class="bm-item-pax"><i class="fa-solid fa-user-group"></i> <?= $pax ?> Guests</div>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- 3. DATES & TIMES -->
                                <td data-label="Dates & Times">
                                    <div class="bm-td-content">
                                        <div class="bm-dates-range">
                                            <i class="fa-regular fa-calendar" style="color: #64748b;"></i>
                                            <?= $checkIn ?> <?= ($checkOut !== $checkIn) ? '– ' . $checkOut : '' ?>
                                        </div>
                                        <div class="bm-time-slot">
                                            <i class="fa-regular fa-clock" style="color: #64748b;"></i> <?= htmlspecialchars($timeSlot) ?>
                                        </div>
                                        <?php if ($isReschedReq): ?>
                                            <div>
                                                <span class="bm-resched-req-badge">
                                                    <i class="fa-solid fa-arrows-rotate"></i> Reschedule Requested
                                                </span>
                                            </div>
                                        <?php elseif (!empty($b['rescheduled_from'])): ?>
                                            <div>
                                                <span class="bm-rescheduled-badge">
                                                    <i class="fa-solid fa-arrows-rotate"></i> Rescheduled
                                                </span>
                                                <div class="bm-resched-from-text">Rescheduled from <?= htmlspecialchars($b['rescheduled_from']) ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- 4. AMOUNT -->
                                <td data-label="Amount">
                                    <div class="bm-td-content">
                                        <div class="bm-amount-cell">₱<?= number_format($amount, 2) ?></div>
                                    </div>
                                </td>

                                <!-- 5. PAYMENT STATUS (Separated) -->
                                <td data-label="Payment Status">
                                    <div class="bm-td-content">
                                        <?php if ($isVerifiedPay): ?>
                                            <span class="bm-pay-pill bm-pay-paid"><i class="fa-solid fa-check"></i> PAID</span>
                                        <?php elseif ($isRejectedPay): ?>
                                            <span class="bm-pay-pill bm-pay-rejected"><i class="fa-solid fa-xmark"></i> REJECTED</span>
                                        <?php else: ?>
                                            <span class="bm-pay-pill bm-pay-unverified"><i class="fa-regular fa-credit-card"></i> UNVERIFIED</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- 6. BOOKING STATUS (Separated) -->
                                <td data-label="Booking Status">
                                    <div class="bm-td-content">
                                        <?php if ($isReschedReq): ?>
                                            <span class="bm-book-pill bm-book-resched"><i class="fa-solid fa-arrows-rotate"></i> RESCHEDULE REQUESTED</span>
                                        <?php elseif ($st === 'confirmed' || $st === 'approved'): ?>
                                            <span class="bm-book-pill bm-book-confirmed"><i class="fa-solid fa-check"></i> CONFIRMED</span>
                                        <?php elseif ($st === 'checked_in'): ?>
                                            <span class="bm-book-pill bm-book-checkedin"><i class="fa-solid fa-door-open"></i> CHECKED IN</span>
                                        <?php elseif ($st === 'checked_out'): ?>
                                            <span class="bm-book-pill bm-book-checkedout"><i class="fa-solid fa-arrow-right-from-bracket"></i> CHECKED OUT</span>
                                        <?php elseif ($st === 'completed'): ?>
                                            <span class="bm-book-pill bm-book-completed"><i class="fa-solid fa-house-chimney"></i> COMPLETED</span>
                                        <?php elseif ($st === 'cancelled' || $st === 'rejected'): ?>
                                            <span class="bm-book-pill bm-book-cancelled"><i class="fa-solid fa-xmark"></i> CANCELLED</span>
                                        <?php else: ?>
                                            <span class="bm-book-pill bm-book-pending">• PENDING</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- 7. REMARKS -->
                                <td data-label="Remarks">
                                    <div class="bm-td-content">
                                        <div class="bm-remarks-cell" title="<?= htmlspecialchars($reason) ?>" onclick='showBookingDetails(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>
                                            <?= htmlspecialchars($reason) ?>
                                        </div>
                                    </div>
                                </td>

                                <!-- 8. ACTIONS -->
                                <td class="col-actions" data-label="Actions">
                                    <div class="bm-actions-group">
                                        <!-- Quick Action 1: View Details -->
                                        <button type="button" class="bm-act-btn bm-act-btn-view" title="View Booking Details" onclick='showBookingDetails(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>
                                            <i class="fa-regular fa-eye"></i>
                                        </button>

                                        <!-- Quick Action 2: Verify Payment or Review Resched -->
                                        <?php if ($bType === 'dining'): ?>
                                            <?php if ($st === 'pending'): ?>
                                                <a href="bookings.php?action=approve&id=<?= urlencode($bId) ?>&view=<?= htmlspecialchars($view_mode) ?>" 
                                                   class="bm-act-btn bm-act-btn-verify" 
                                                   title="Send to Kitchen (Prepare)" 
                                                   onclick="return confirm('Send Dining Order #<?= htmlspecialchars($bId) ?> to Kitchen?');">
                                                    <i class="fa-solid fa-utensils"></i>
                                                </a>
                                            <?php elseif (in_array($st, ['confirmed', 'approved', 'preparing', 'ready'])): ?>
                                                <a href="bookings.php?action=check_out&id=<?= urlencode($bId) ?>&view=<?= htmlspecialchars($view_mode) ?>" 
                                                   class="bm-act-btn" 
                                                   title="Mark Served &amp; Complete" 
                                                   style="background: #eff6ff; color: #2563eb; border-color: #bfdbfe;" 
                                                   onclick="return confirm('Mark Dining Order #<?= htmlspecialchars($bId) ?> as Served?');">
                                                    <i class="fa-solid fa-circle-check"></i>
                                                </a>
                                            <?php endif; ?>
                                        <?php elseif (!$isVerifiedPay): ?>
                                            <a href="bookings.php?action=approve&id=<?= urlencode($bId) ?>&view=<?= htmlspecialchars($view_mode) ?>" 
                                               class="bm-act-btn bm-act-btn-verify" 
                                               title="Verify Payment" 
                                               onclick="return confirm('Verify & confirm payment for Booking #<?= htmlspecialchars($bId) ?> (₱<?= number_format($amount, 2) ?>)?');">
                                                <i class="fa-solid fa-check"></i>
                                            </a>
                                        <?php elseif ($isReschedReq): ?>
                                            <button type="button" class="bm-act-btn bm-act-btn-resched" title="Review Reschedule Request" onclick='openReviewRescheduleModal(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>
                                                <i class="fa-solid fa-arrows-rotate"></i>
                                            </button>
                                        <?php elseif ($st === 'confirmed' || $st === 'approved'): ?>
                                            <a href="bookings.php?action=check_in&id=<?= urlencode($bId) ?>&view=<?= htmlspecialchars($view_mode) ?>" class="bm-act-btn" title="Check In Guest" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0;">
                                                <i class="fa-solid fa-door-open"></i>
                                            </a>
                                        <?php elseif ($st === 'checked_in'): ?>
                                            <a href="bookings.php?action=check_out&id=<?= urlencode($bId) ?>&view=<?= htmlspecialchars($view_mode) ?>" class="bm-act-btn" title="Check Out Guest" style="background: #eff6ff; color: #2563eb; border-color: #bfdbfe;" onclick="return confirm('Check out booking #<?= htmlspecialchars($bId) ?>?');">
                                                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="bm-act-btn" title="Timeline Activity" onclick='openActivityTimelineModal(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>
                                                <i class="fa-solid fa-clock-rotate-left"></i>
                                            </button>
                                        <?php endif; ?>

                                        <!-- Quick Action 3: Timer / Kitchen Monitor -->
                                        <?php if ($bType === 'dining'): ?>
                                            <a href="dining_monitoring.php?search=<?= urlencode($bId) ?>" class="bm-act-btn" title="Open in Kitchen Monitor" style="background: #fef3c7; color: #d97706; border-color: #fde68a;">
                                                <i class="fa-solid fa-fire-burner"></i>
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="bm-act-btn" title="Extra Time" onclick='openExtraTimeForBooking(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>
                                                <i class="fa-solid fa-stopwatch"></i>
                                            </button>
                                        <?php endif; ?>

                                        <!-- Quick Action 4: Three-Dots Menu Button -->
                                        <div class="bm-dropdown-wrap" style="position: relative; display: inline-block;">
                                            <button type="button" class="bm-act-btn bm-act-btn-dots" title="More Actions" onclick="toggleRowMenu(event, '<?= htmlspecialchars($bId) ?>')">
                                                <i class="fa-solid fa-ellipsis-vertical"></i>
                                            </button>

                                            <!-- Dropdown Menu Conditional Based on Status -->
                                            <div class="bm-dropdown-menu" id="menu-<?= htmlspecialchars($bId) ?>">
                                                <a href="javascript:void(0)" class="bm-dd-item" onclick='showBookingDetails(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>
                                                    <i class="fa-regular fa-eye" style="color: #2563eb;"></i> View Details
                                                </a>

                                                <?php if ($bType === 'dining'): ?>
                                                    <div class="bm-dd-section">KITCHEN &amp; DINING</div>
                                                    <a href="dining_monitoring.php?search=<?= urlencode($bId) ?>" class="bm-dd-item">
                                                        <i class="fa-solid fa-fire-burner" style="color: #f59e0b;"></i> Kitchen Monitor
                                                    </a>
                                                    <?php if ($st === 'pending'): ?>
                                                        <a href="bookings.php?action=approve&id=<?= urlencode($bId) ?>&view=<?= htmlspecialchars($view_mode) ?>" class="bm-dd-item" onclick="return confirm('Send Dining Order #<?= htmlspecialchars($bId) ?> to Kitchen?');">
                                                            <i class="fa-solid fa-utensils" style="color: #10b981;"></i> Send to Kitchen
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="bookings.php?action=check_out&id=<?= urlencode($bId) ?>&view=<?= htmlspecialchars($view_mode) ?>" class="bm-dd-item" onclick="return confirm('Mark Dining Order #<?= htmlspecialchars($bId) ?> as Served?');">
                                                            <i class="fa-solid fa-circle-check" style="color: #10b981;"></i> Mark as Served
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="javascript:void(0)" class="bm-dd-item" onclick="openCancelModal('<?= htmlspecialchars($bId) ?>', '<?= htmlspecialchars(addslashes($guestName)) ?>', '<?= htmlspecialchars(addslashes($itemName)) ?>', '₱<?= number_format($amount, 2) ?>')">
                                                        <i class="fa-solid fa-ban" style="color: #ef4444;"></i> Cancel Order
                                                    </a>
                                                    <div class="bm-dd-section">DANGER</div>
                                                    <a href="bookings.php?action=delete&id=<?= urlencode($bId) ?>&view=<?= htmlspecialchars($view_mode) ?>" class="bm-dd-item danger-item" onclick="return confirm('Permanently delete order #<?= htmlspecialchars($bId) ?>?');">
                                                        <i class="fa-solid fa-trash" style="color: #ef4444;"></i> Delete
                                                    </a>
                                                <?php elseif ($st === 'pending'): ?>
                                                    <!-- PENDING ACTIONS -->
                                                    <div class="bm-dd-section">PAYMENT</div>
                                                    <a href="bookings.php?action=approve&id=<?= urlencode($bId) ?>&view=<?= htmlspecialchars($view_mode) ?>" class="bm-dd-item" onclick="return confirm('Verify payment for booking #<?= htmlspecialchars($bId) ?>?');">
                                                        <i class="fa-solid fa-check" style="color: #10b981;"></i> Verify Payment
                                                    </a>
                                                    <a href="javascript:void(0)" class="bm-dd-item" onclick="openRejectModal('<?= htmlspecialchars($bId) ?>', '<?= htmlspecialchars(addslashes($guestName)) ?>', '<?= number_format($amount, 2) ?>')">
                                                        <i class="fa-solid fa-xmark" style="color: #ef4444;"></i> Reject Payment
                                                    </a>

                                                    <div class="bm-dd-section">BOOKING</div>
                                                    <?php if ($isReschedReq): ?>
                                                        <a href="javascript:void(0)" class="bm-dd-item" onclick='openReviewRescheduleModal(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>
                                                            <i class="fa-solid fa-arrows-rotate" style="color: #3b82f6;"></i> Review Reschedule
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($isVerifiedPay): ?>
                                                        <a href="bookings.php?action=check_in&id=<?= urlencode($bId) ?>&view=<?= htmlspecialchars($view_mode) ?>" class="bm-dd-item">
                                                            <i class="fa-solid fa-door-open" style="color: #059669;"></i> Check In
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if (!$isReschedReq): ?>
                                                        <a href="javascript:void(0)" class="bm-dd-item" onclick='openRescheduleModal(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>
                                                            <i class="fa-solid fa-calendar-days" style="color: #d97706;"></i> Reschedule
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="javascript:void(0)" class="bm-dd-item" onclick="openCancelModal('<?= htmlspecialchars($bId) ?>', '<?= htmlspecialchars(addslashes($guestName)) ?>', '<?= htmlspecialchars(addslashes($itemName)) ?>', '₱<?= number_format($amount, 2) ?>')">
                                                        <i class="fa-solid fa-ban" style="color: #ef4444;"></i> Cancel Booking
                                                    </a>

                                                    <div class="bm-dd-section">DANGER</div>
                                                    <a href="bookings.php?action=delete&id=<?= urlencode($bId) ?>&view=<?= htmlspecialchars($view_mode) ?>" class="bm-dd-item danger-item" onclick="return confirm('Permanently delete booking #<?= htmlspecialchars($bId) ?>?');">
                                                        <i class="fa-solid fa-trash" style="color: #ef4444;"></i> Delete
                                                    </a>

                                                <?php elseif ($st === 'confirmed' || $st === 'approved'): ?>
                                                    <!-- CONFIRMED & RESCHEDULED ACTIONS -->
                                                    <a href="javascript:void(0)" class="bm-dd-item" onclick='showBookingDetails(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>
                                                        <i class="fa-regular fa-credit-card" style="color: #059669;"></i> Payment Details
                                                    </a>
                                                    <?php if ($isReschedReq): ?>
                                                        <a href="javascript:void(0)" class="bm-dd-item" onclick='openReviewRescheduleModal(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>
                                                            <i class="fa-solid fa-arrows-rotate" style="color: #3b82f6;"></i> Review Reschedule
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($isVerifiedPay): ?>
                                                        <a href="bookings.php?action=check_in&id=<?= urlencode($bId) ?>&view=<?= htmlspecialchars($view_mode) ?>" class="bm-dd-item">
                                                            <i class="fa-solid fa-door-open" style="color: #059669;"></i> Check In
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if (!$isReschedReq): ?>
                                                        <a href="javascript:void(0)" class="bm-dd-item" onclick='openRescheduleModal(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>
                                                            <i class="fa-solid fa-calendar-days" style="color: #d97706;"></i> Reschedule
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="javascript:void(0)" class="bm-dd-item" onclick="openCancelModal('<?= htmlspecialchars($bId) ?>', '<?= htmlspecialchars(addslashes($guestName)) ?>', '<?= htmlspecialchars(addslashes($itemName)) ?>', '₱<?= number_format($amount, 2) ?>')">
                                                        <i class="fa-solid fa-ban" style="color: #ef4444;"></i> Cancel Booking
                                                    </a>
                                                    <div class="bm-dd-section">DANGER</div>
                                                    <a href="bookings.php?action=delete&id=<?= urlencode($bId) ?>&view=<?= htmlspecialchars($view_mode) ?>" class="bm-dd-item danger-item" onclick="return confirm('Permanently delete booking #<?= htmlspecialchars($bId) ?>?');">
                                                        <i class="fa-solid fa-trash" style="color: #ef4444;"></i> Delete
                                                    </a>

                                                <?php elseif ($st === 'checked_in'): ?>
                                                    <!-- CHECKED IN ACTIONS -->
                                                    <a href="javascript:void(0)" class="bm-dd-item" onclick='showBookingDetails(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>
                                                        <i class="fa-regular fa-credit-card" style="color: #059669;"></i> Payment Details
                                                    </a>
                                                    <a href="bookings.php?action=check_out&id=<?= urlencode($bId) ?>&view=<?= htmlspecialchars($view_mode) ?>" class="bm-dd-item" onclick="return confirm('Check out booking #<?= htmlspecialchars($bId) ?>?');">
                                                        <i class="fa-solid fa-arrow-right-from-bracket" style="color: #2563eb;"></i> Check Out
                                                    </a>
                                                    <a href="javascript:void(0)" class="bm-dd-item" onclick='showBookingDetails(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>
                                                        <i class="fa-regular fa-note-sticky" style="color: #d97706;"></i> Add Remark
                                                    </a>
                                                    <a href="javascript:void(0)" class="bm-dd-item" onclick="openCancelModal('<?= htmlspecialchars($bId) ?>', '<?= htmlspecialchars(addslashes($guestName)) ?>', '<?= htmlspecialchars(addslashes($itemName)) ?>', '₱<?= number_format($amount, 2) ?>')">
                                                        <i class="fa-solid fa-ban" style="color: #ef4444;"></i> Cancel Booking
                                                    </a>

                                                <?php elseif (in_array($st, ['checked_out', 'completed'])): ?>
                                                    <!-- COMPLETED ACTIONS -->
                                                    <a href="javascript:void(0)" class="bm-dd-item" onclick='showBookingDetails(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>
                                                        <i class="fa-regular fa-credit-card" style="color: #059669;"></i> Payment Details
                                                    </a>
                                                    <a href="javascript:void(0)" class="bm-dd-item" onclick='showBookingDetails(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>
                                                        <i class="fa-solid fa-receipt" style="color: #7c3aed;"></i> View Receipt
                                                    </a>
                                                    <div class="bm-dd-section">DANGER</div>
                                                    <a href="bookings.php?action=delete&id=<?= urlencode($bId) ?>&view=<?= htmlspecialchars($view_mode) ?>" class="bm-dd-item danger-item" onclick="return confirm('Permanently delete booking #<?= htmlspecialchars($bId) ?>?');">
                                                        <i class="fa-solid fa-trash" style="color: #ef4444;"></i> Delete
                                                    </a>

                                                <?php else: ?>
                                                    <!-- CANCELLED / OTHER -->
                                                    <a href="javascript:void(0)" class="bm-dd-item" onclick='showBookingDetails(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>
                                                        <i class="fa-regular fa-credit-card" style="color: #64748b;"></i> Payment Details
                                                    </a>
                                                    <a href="javascript:void(0)" class="bm-dd-item" onclick='openRescheduleModal(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, "UTF-8") ?>)'>
                                                        <i class="fa-solid fa-calendar-days" style="color: #d97706;"></i> Rebook / Reschedule
                                                    </a>
                                                    <div class="bm-dd-section">DANGER</div>
                                                    <a href="bookings.php?action=delete&id=<?= urlencode($bId) ?>&view=<?= htmlspecialchars($view_mode) ?>" class="bm-dd-item danger-item" onclick="return confirm('Permanently delete booking #<?= htmlspecialchars($bId) ?>?');">
                                                        <i class="fa-solid fa-trash" style="color: #ef4444;"></i> Delete
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Strip -->
            <div class="bm-pagination-bar">
                <div class="bm-pagination-info">
                    Showing <?= $total_items > 0 ? ($offset + 1) : 0 ?>-<?= min($offset + count($display_bookings), $total_items) ?> of <?= $total_items ?> bookings
                </div>
                <div class="bm-pagination-btns">
                    <?php if ($current_page > 1): ?>
                        <a href="bookings.php?view=<?= htmlspecialchars($view_mode) ?>&page=<?= $current_page - 1 ?>&search=<?= urlencode($search_query) ?>" class="bm-pg-btn"><i class="fa-solid fa-chevron-left"></i></a>
                    <?php else: ?>
                        <span class="bm-pg-btn disabled"><i class="fa-solid fa-chevron-left"></i></span>
                    <?php endif; ?>

                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                        <a href="bookings.php?view=<?= htmlspecialchars($view_mode) ?>&page=<?= $p ?>&search=<?= urlencode($search_query) ?>" class="bm-pg-btn <?= $p === $current_page ? 'active' : '' ?>">
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($current_page < $total_pages): ?>
                        <a href="bookings.php?view=<?= htmlspecialchars($view_mode) ?>&page=<?= $current_page + 1 ?>&search=<?= urlencode($search_query) ?>" class="bm-pg-btn"><i class="fa-solid fa-chevron-right"></i></a>
                    <?php else: ?>
                        <span class="bm-pg-btn disabled"><i class="fa-solid fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>



        <!-- Add Extra Time Interactive Card (Opened dynamically when extending a booking) -->
        <?php
        $ext_b = $default_ext_booking ?? [];
        $ext_id = $ext_b['id'] ?? '';
        $ext_item = $ext_b['item_name'] ?? '';
        $ext_pax = $ext_b['pax'] ?? ($ext_b['num_guests'] ?? 0);
        $ext_guest = $ext_b['guest_name'] ?? '';
        $ext_amount = floatval($ext_b['total_amount'] ?? 0);
        $ext_start = !empty($ext_b['start_time']) ? date('g:i A', strtotime($ext_b['start_time'])) : '--:--';
        $ext_end = !empty($ext_b['end_time']) ? date('g:i A', strtotime($ext_b['end_time'])) : '--:--';
        ?>
        <div class="bm-extra-time-card" id="bmExtraTimeCard" style="display: none;">
            <div class="bm-etc-top">
                <div class="bm-etc-heading">
                    <i class="fa-regular fa-clock"></i> Add Extra Time
                </div>
                <button type="button" class="bm-etc-close" onclick="document.getElementById('bmExtraTimeCard').style.display='none'">&times;</button>
            </div>

            <form method="POST" action="bookings.php?view=<?= htmlspecialchars($view_mode) ?>" id="bmExtraTimeForm" class="bm-etc-grid">
                <input type="hidden" name="action" value="extend_booking_time">
                <input type="hidden" name="booking_id" id="extInputBookingId" value="<?= htmlspecialchars($ext_id) ?>">
                <input type="hidden" name="extra_minutes" id="extInputMins" value="15">
                <input type="hidden" name="extra_fee" id="extInputFee" value="100.00">

                <!-- Col 1: Item Details -->
                <div class="bm-etc-col">
                    <div class="bm-etc-item-preview">
                        <div class="bm-etc-item-icon">
                            <i class="fa-solid fa-water"></i>
                        </div>
                        <div>
                            <h4 id="extItemName"><?= htmlspecialchars($ext_item ?: 'Select Session') ?></h4>
                            <p id="extItemPax"><?= $ext_pax > 0 ? $ext_pax . ' Guest' . ($ext_pax > 1 ? 's' : '') : '--' ?></p>
                        </div>
                    </div>
                    <div class="bm-etc-metric-row">
                        <span class="bm-etc-metric-lbl">Current Duration</span>
                        <span class="bm-etc-metric-val" id="extCurrentDuration">-- mins</span>
                    </div>
                    <div class="bm-etc-metric-row">
                        <span class="bm-etc-metric-lbl">Time Remaining</span>
                        <span class="bm-etc-metric-val bm-etc-metric-danger" id="extTimeRemaining">--</span>
                    </div>
                </div>

                <!-- Col 2: Guest Details -->
                <div class="bm-etc-col">
                    <div class="bm-etc-meta-block">
                        <span class="bm-etc-meta-lbl">Guest</span>
                        <div class="bm-etc-meta-val" id="extGuestName"><i class="fa-solid fa-user"></i> <?= htmlspecialchars($ext_guest ?: '--') ?></div>
                    </div>
                    <div class="bm-etc-meta-block">
                        <span class="bm-etc-meta-lbl">Start Time</span>
                        <div class="bm-etc-meta-val" id="extStartTime"><i class="fa-regular fa-clock"></i> <?= $ext_start ?></div>
                    </div>
                    <div class="bm-etc-meta-block">
                        <span class="bm-etc-meta-lbl">End Time (current)</span>
                        <div class="bm-etc-meta-val" id="extEndTime"><i class="fa-regular fa-clock"></i> <?= $ext_end ?></div>
                    </div>
                    <div class="bm-etc-meta-block">
                        <span class="bm-etc-meta-lbl">Current Amount</span>
                        <div class="bm-etc-current-amount" id="extCurrentAmount">₱<?= number_format($ext_amount, 2) ?></div>
                    </div>
                </div>

                <!-- Col 3: Select Extra Time -->
                <div class="bm-etc-col">
                    <span class="bm-etc-col-title">Select Extra Time</span>
                    <div class="bm-etc-radios-list">
                        <div class="bm-etc-radio-card active" id="optCard15" onclick="selectExtraOption(15, 100)">
                            <div class="bm-etc-radio-left">
                                <div class="bm-etc-radio-dot"></div>
                                <span>+ 15 mins</span>
                            </div>
                            <div class="bm-etc-radio-fee">₱100.00</div>
                        </div>

                        <div class="bm-etc-radio-card" id="optCard30" onclick="selectExtraOption(30, 200)">
                            <div class="bm-etc-radio-left">
                                <div class="bm-etc-radio-dot"></div>
                                <span>+ 30 mins</span>
                            </div>
                            <div class="bm-etc-radio-fee">₱200.00</div>
                        </div>

                        <div class="bm-etc-radio-card" id="optCard60" onclick="selectExtraOption(60, 350)">
                            <div class="bm-etc-radio-left">
                                <div class="bm-etc-radio-dot"></div>
                                <span>+ 60 mins</span>
                            </div>
                            <div class="bm-etc-radio-fee">₱350.00</div>
                        </div>
                    </div>
                </div>

                <!-- Col 4: Summary & Confirm Extension -->
                <div class="bm-etc-col bm-etc-summary-box">
                    <div class="bm-etc-sum-row">
                        <span>New Duration</span>
                        <strong id="extSummaryDuration">-- mins</strong>
                    </div>
                    <div class="bm-etc-sum-row">
                        <span>Additional Fee</span>
                        <strong id="extSummaryFee">₱100.00</strong>
                    </div>
                    <div class="bm-etc-total-row">
                        <span>New Total Amount</span>
                        <div class="bm-etc-total-val" id="extSummaryTotal">₱<?= number_format($ext_amount + 100, 2) ?></div>
                    </div>
                    <div class="bm-etc-btns">
                        <button type="button" class="bm-etc-btn-cancel" onclick="document.getElementById('bmExtraTimeCard').style.display='none'">Cancel</button>
                        <button type="submit" class="bm-etc-btn-confirm">
                            <i class="fa-solid fa-stopwatch"></i> Confirm Extension
                        </button>
                    </div>
                </div>
            </form>
        </div>
<?php if ($is_admin_layout): ?>
            </main>
        </div>
    <?php else: ?>
        </div>
    <?php endif; ?>

    <!-- View Booking Details Modal (Clean White Card Matching Mockup) -->
    <div id="detailsModal" class="modal-overlay" onclick="if(event.target === this) closeModal('detailsModal');">
        <div class="modal-container bm-white-modal" style="max-width: 680px;">
            <div class="modal-header bm-white-modal-header">
                <h3 style="color: #0f172a; margin: 0; font-family: 'Outfit', sans-serif; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-regular fa-calendar-check" style="color: #3b82f6;"></i> View Booking Details
                </h3>
                <button class="modal-close bm-white-modal-close" onclick="closeModal('detailsModal')">&times;</button>
            </div>
            <div class="modal-body bm-white-modal-body" id="modalDetailsBody" style="padding: 1.5rem;"></div>
            <div id="modalDetailsFooter" class="bm-white-modal-footer">
                <button type="button" onclick="closeModal('detailsModal')" class="bm-btn-close-modal">Close</button>
            </div>
        </div>
    </div>

    <!-- Review Reschedule Request Modal (Exact Match to Mockup) -->
    <div id="reviewRescheduleModal" class="modal-overlay" onclick="if(event.target === this) closeModal('reviewRescheduleModal');">
        <div class="modal-container bm-white-modal" style="max-width: 660px;">
            <div class="modal-header bm-white-modal-header">
                <h3 style="color: #0f172a; margin: 0; font-family: 'Outfit', sans-serif; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-arrows-rotate" style="color: #3b82f6;"></i> Review Reschedule Request
                </h3>
                <button class="modal-close bm-white-modal-close" onclick="closeModal('reviewRescheduleModal')">&times;</button>
            </div>
            <div class="modal-body bm-white-modal-body" id="reviewRescheduleBody" style="padding: 1.5rem;">
                <!-- Populated dynamically by JS -->
            </div>
        </div>
    </div>

    <!-- Booking Activity Timeline Modal (Exact Match to Mockup) -->
    <div id="activityTimelineModal" class="modal-overlay" onclick="if(event.target === this) closeModal('activityTimelineModal');">
        <div class="modal-container bm-white-modal" style="max-width: 520px;">
            <div class="modal-header bm-white-modal-header">
                <h3 style="color: #0f172a; margin: 0; font-family: 'Outfit', sans-serif; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-timeline" style="color: #7c3aed;"></i> Booking Activity
                </h3>
                <button class="modal-close bm-white-modal-close" onclick="closeModal('activityTimelineModal')">&times;</button>
            </div>
            <div class="modal-body bm-white-modal-body" id="activityTimelineBody" style="padding: 1.5rem;">
                <!-- Populated dynamically by JS -->
            </div>
            <div class="bm-white-modal-footer">
                <button type="button" onclick="closeModal('activityTimelineModal')" class="admin-btn admin-btn-outline admin-btn-sm">Close</button>
            </div>
        </div>
    </div>

    <!-- Reject Reschedule Request Modal -->
    <div id="rejectRescheduleModal" class="modal-overlay" onclick="if(event.target === this) closeModal('rejectRescheduleModal');">
        <div class="modal-container bm-white-modal" style="max-width: 480px;">
            <div class="modal-header bm-white-modal-header" style="border-bottom: 1px solid #fee2e2;">
                <h3 style="color: #dc2626; margin: 0; font-family: 'Outfit', sans-serif; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-xmark"></i> Reject Reschedule Request
                </h3>
                <button class="modal-close bm-white-modal-close" onclick="closeModal('rejectRescheduleModal')">&times;</button>
            </div>
            <form method="GET" action="bookings.php" style="margin: 0;">
                <input type="hidden" name="action" value="reject_reschedule_request">
                <input type="hidden" name="id" id="rejectReschedBookingId" value="">
                <input type="hidden" name="view" value="<?= htmlspecialchars($view_mode) ?>">
                <div class="modal-body bm-white-modal-body" style="padding: 1.5rem;">
                    <p style="color: #475569; font-size: 0.88rem; margin: 0 0 1rem; line-height: 1.5;">
                        Please provide a reason for declining the reschedule request for booking <strong id="rejectReschedDisplayId"></strong>. The current reservation dates will be retained.
                    </p>
                    <label style="font-size: 0.8rem; font-weight: 700; color: #1e293b; display: block; margin-bottom: 0.35rem;">Rejection Reason:</label>
                    <textarea name="reason" id="rejectReschedReasonInput" rows="3" style="width: 100%; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 0.6rem; font-size: 0.88rem; box-sizing: border-box; outline: none; font-family: inherit;" placeholder="e.g. Fully booked on requested dates, maintenance scheduled..." required></textarea>
                </div>
                <div class="bm-white-modal-footer">
                    <button type="button" onclick="closeModal('rejectRescheduleModal')" class="admin-btn admin-btn-outline admin-btn-sm">Cancel</button>
                    <button type="submit" class="admin-btn admin-btn-danger admin-btn-sm" style="background: #ef4444; color: #fff; font-weight: 700; border: none; padding: 0.5rem 1rem; border-radius: 6px;">Reject Request</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Cancel Reservation Modal -->
    <div id="cancelUnpaidModal" class="modal-overlay" onclick="if(event.target === this) closeModal('cancelUnpaidModal');">
        <div class="modal-container" style="max-width: 520px;">
            <div class="modal-header" style="background: rgba(239, 68, 68, 0.15); border-bottom: 1px solid rgba(239, 68, 68, 0.3);">
                <h3 style="color: #f87171; margin: 0; font-family: 'Outfit', sans-serif;">
                    <i class="fa-solid fa-triangle-exclamation"></i> <span id="cancelModalHeading">Cancel Reservation</span>
                </h3>
                <button class="modal-close" onclick="closeModal('cancelUnpaidModal')">&times;</button>
            </div>
            <form action="bookings.php" method="GET" id="cancelBookingForm">
                <input type="hidden" name="action" id="cancelModalAction" value="cancel_unpaid">
                <input type="hidden" name="id" id="cancelModalBookingId" value="">
                <input type="hidden" name="view" value="<?= htmlspecialchars($view_mode) ?>">
                <div class="modal-body" style="padding: 1.5rem;">
                    <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.25); border-radius: 8px; padding: 1rem; margin-bottom: 1.25rem;">
                        <p style="margin: 0 0 0.5rem 0; color: #fca5a5; font-size: 0.9rem;">
                            <strong>Reservation Ref:</strong> <span id="cancelModalRef" style="color: #fff; font-family: monospace; font-weight: 700;"></span>
                        </p>
                        <p style="margin: 0 0 0.5rem 0; color: #fca5a5; font-size: 0.9rem;">
                            <strong>Guest:</strong> <span id="cancelModalGuest" style="color: #fff; font-weight: 700;"></span>
                        </p>
                        <p style="margin: 0 0 0.5rem 0; color: #fca5a5; font-size: 0.9rem;">
                            <strong>Accommodation / Item:</strong> <span id="cancelModalItem" style="color: #fff; font-weight: 700;"></span>
                        </p>
                        <p style="margin: 0; color: #fca5a5; font-size: 0.9rem;">
                            <strong>Amount:</strong> <span id="cancelModalAmount" style="color: #f59e0b; font-weight: 800;"></span>
                        </p>
                    </div>

                    <div style="font-size: 0.88rem; color: #cbd5e1; margin-bottom: 1.25rem; line-height: 1.5;">
                        <i class="fa-solid fa-circle-info" style="color: #38bdf8;"></i> 
                        Cancelling this booking will mark it as <strong>Cancelled</strong>, release the accommodation dates, and <strong>move it to History</strong>.
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="font-size: 0.85rem; color: #cbd5e1; font-weight: 700; margin-bottom: 0.4rem; display: block;">Cancellation Reason:</label>
                        <select name="reason" id="cancelReasonSelect" style="background: #251347; color: #fff; border: 1.5px solid #d8b4fe; padding: 0.6rem 1rem; border-radius: 8px; width: 100%;" onchange="toggleCancelOtherReason(this)">
                            <option value="No payment received when booking house">No payment received when booking house</option>
                            <option value="Payment proof was invalid or rejected">Payment proof was invalid or rejected</option>
                            <option value="Payment window expired without confirmation">Payment window expired without confirmation</option>
                            <option value="Guest requested cancellation">Guest requested cancellation</option>
                            <option value="Change of dates / guest rebooking">Change of dates / guest rebooking</option>
                            <option value="Emergency / Personal reasons">Emergency / Personal reasons</option>
                            <option value="No-show">No-show</option>
                            <option value="Duplicate reservation">Duplicate reservation</option>
                            <option value="Others">Others (Please specify)</option>
                        </select>
                    </div>

                    <div id="cancelOtherReasonGroup" style="display: none; margin-top: 0.85rem;">
                        <label style="font-size: 0.85rem; color: #fbbf24; font-weight: 700; margin-bottom: 0.4rem; display: block;">
                            <i class="fa-solid fa-pen"></i> Specify Custom Reason: *
                        </label>
                        <input type="text" name="other_reason" id="cancelOtherReasonInput" style="background: #251347; color: #fff; border: 1.5px solid #fbbf24; width: 100%; padding: 0.6rem 0.8rem; border-radius: 8px;" placeholder="e.g. Guest had medical emergency, flight cancelled...">
                    </div>
                </div>
                <div style="padding: 1rem 1.5rem; background: #f3e8ff; border-top: 1px solid #d8b4fe; display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button type="button" onclick="closeModal('cancelUnpaidModal')" class="admin-btn admin-btn-outline admin-btn-sm">Keep Booking</button>
                    <button type="submit" class="admin-btn admin-btn-danger admin-btn-sm" style="background: #ef4444; border-color: #ef4444; color: #fff; font-weight: 700;">
                        <i class="fa-solid fa-ban"></i> Cancel &amp; Move to History
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reschedule Booking Modal -->
    <div id="rescheduleModal" class="modal-overlay" onclick="if(event.target === this) closeModal('rescheduleModal');">
        <div class="modal-container" style="max-width: 680px; max-height: 90vh; display: flex; flex-direction: column;">
            <div class="modal-header" style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(124, 58, 237, 0.15)); border-bottom: 1.5px solid rgba(245, 158, 11, 0.35);">
                <h3 style="color: #f59e0b; margin: 0; font-family: 'Outfit', sans-serif; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-calendar-days"></i> Reschedule Reservation
                </h3>
                <button class="modal-close" onclick="closeModal('rescheduleModal')">&times;</button>
            </div>
            
            <form action="bookings.php" method="POST" id="rescheduleBookingForm" onsubmit="return handleRescheduleSubmit(event);" style="display: flex; flex-direction: column; overflow: hidden; margin: 0;">
                <input type="hidden" name="action" value="reschedule_booking">
                <input type="hidden" name="booking_id" id="reschedBookingId" value="">
                <input type="hidden" name="new_item_name" id="reschedNewItemName" value="">
                <input type="hidden" name="new_total_amount" id="reschedNewTotalAmount" value="">
                <input type="hidden" name="view" value="<?= htmlspecialchars($view_mode) ?>">

                <div class="modal-body" style="padding: 1.5rem; overflow-y: auto; max-height: calc(90vh - 140px);">
                    <!-- Original Booking Snapshot Card -->
                    <div style="background: rgba(30, 20, 50, 0.7); border: 1.5px solid rgba(168, 85, 247, 0.35); border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1.25rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.65rem; border-bottom: 1px dashed rgba(255,255,255,0.1); padding-bottom: 0.5rem; flex-wrap: wrap; gap: 6px;">
                            <span class="admin-badge admin-badge-purple" style="font-size: 0.78rem;">
                                Current Ref: <strong id="reschedDisplayRef">#--</strong>
                            </span>
                            <span class="admin-badge" id="reschedDisplayCountBadge" style="font-size: 0.78rem; background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4);">
                                Reschedule Limit: <strong id="reschedDisplayCount">0 / 2</strong>
                            </span>
                            <span class="admin-badge admin-badge-gold" style="font-size: 0.78rem;">
                                Current Total: <strong id="reschedDisplayOrigTotal">₱0.00</strong>
                            </span>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; font-size: 0.85rem; color: #cbd5e1;">
                            <div>
                                <span style="color: #94a3b8; display: block; font-size: 0.75rem; text-transform: uppercase;">Guest</span>
                                <strong id="reschedDisplayGuest" style="color: #ffffff;">--</strong>
                            </div>
                            <div>
                                <span style="color: #94a3b8; display: block; font-size: 0.75rem; text-transform: uppercase;">Current Unit</span>
                                <strong id="reschedDisplayUnit" style="color: #fbbf24;">--</strong>
                            </div>
                            <div style="grid-column: span 2;">
                                <span style="color: #94a3b8; display: block; font-size: 0.75rem; text-transform: uppercase;">Current Stay Schedule</span>
                                <strong id="reschedDisplayDates" style="color: #38bdf8;">--</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Reschedule Form Fields Grid -->
                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <!-- Select Unit / Kubo -->
                        <div class="manual-field-group" style="margin-bottom: 0;">
                            <label class="manual-label" style="color: #fbbf24;">
                                <i class="fa-solid fa-house-chimney"></i> Kubo / Accommodation Unit <span class="manual-required">*</span>
                            </label>
                            <select name="new_item_id" id="reschedKuboSelect" class="manual-input" onchange="onRescheduleChange()" style="font-size: 0.9rem;" required>
                                <?php foreach ($all_kubos_inventory as $k): 
                                    $kid = intval($k['kubo_id'] ?? 1);
                                    $kname = htmlspecialchars($k['name'] ?? $k['kubo_name_number'] ?? "Kubo #{$kid}");
                                    $kprice = floatval($k['price'] ?? 3500);
                                    $kstatus = strtolower($k['status'] ?? 'available');
                                    $is_disabled = in_array($kstatus, ['renovation', 'maintenance', 'unavailable']);
                                ?>
                                    <option value="<?= $kid ?>" data-name="<?= $kname ?>" data-price="<?= $kprice ?>" <?= $is_disabled ? 'disabled' : '' ?>>
                                        <?= $kname ?> (₱<?= number_format($kprice, 2) ?>/night) <?= $is_disabled ? '— [' . strtoupper($kstatus) . ']' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Check-In Date & Time -->
                        <div style="display: grid; grid-template-columns: 1.4fr 1fr; gap: 0.75rem;">
                            <div class="manual-field-group" style="margin-bottom: 0;">
                                <label class="manual-label">
                                    <i class="fa-solid fa-calendar-check"></i> New Check-In Date <span class="manual-required">*</span>
                                </label>
                                <input type="date" name="new_check_in_date" id="reschedCheckInDate" class="manual-input" required onchange="onRescheduleCheckInChange()">
                            </div>
                            <div class="manual-field-group" style="margin-bottom: 0;">
                                <label class="manual-label">
                                    <i class="fa-regular fa-clock"></i> Check-In Time
                                </label>
                                <input type="time" name="new_check_in_time" id="reschedCheckInTime" class="manual-input" value="14:00" required onchange="onRescheduleChange()">
                            </div>
                        </div>

                        <!-- Stay Duration Nights -->
                        <div class="manual-field-group" style="margin-bottom: 0;">
                            <label class="manual-label">
                                <i class="fa-solid fa-moon"></i> Stay Duration (Nights)
                            </label>
                            <select id="reschedStayNights" class="manual-input" onchange="onRescheduleNightsChange()">
                                <option value="1">1 Night (Standard)</option>
                                <option value="2">2 Nights</option>
                                <option value="3">3 Nights</option>
                                <option value="4">4 Nights</option>
                                <option value="5">5 Nights</option>
                                <option value="6">6 Nights</option>
                                <option value="7">7 Nights (1 Week)</option>
                                <option value="custom">Custom End Date...</option>
                            </select>
                        </div>

                        <!-- Check-Out Date & Time -->
                        <div style="display: grid; grid-template-columns: 1.4fr 1fr; gap: 0.75rem;">
                            <div class="manual-field-group" style="margin-bottom: 0;">
                                <label class="manual-label">
                                    <i class="fa-solid fa-calendar-xmark"></i> New Check-Out Date <span class="manual-required">*</span>
                                </label>
                                <input type="date" name="new_check_out_date" id="reschedCheckOutDate" class="manual-input" required onchange="onRescheduleCheckOutChange()">
                            </div>
                            <div class="manual-field-group" style="margin-bottom: 0;">
                                <label class="manual-label">
                                    <i class="fa-regular fa-clock"></i> Check-Out Time
                                </label>
                                <input type="time" name="new_check_out_time" id="reschedCheckOutTime" class="manual-input" value="12:00" required onchange="onRescheduleChange()">
                            </div>
                        </div>

                        <!-- Reason for Rescheduling -->
                        <div class="manual-field-group" style="margin-bottom: 0;">
                            <label class="manual-label">
                                <i class="fa-solid fa-comment-dots"></i> Reason for Reschedule <span class="manual-required">*</span>
                            </label>
                            <select name="reschedule_reason" id="reschedReasonSelect" class="manual-input" onchange="toggleReschedOtherReason(this)" required>
                                <option value="Guest requested date modification">Guest requested date modification</option>
                                <option value="Guest requested room/kubo transfer">Guest requested room/kubo transfer</option>
                                <option value="Severe weather / Typhoon warning">Severe weather / Typhoon warning</option>
                                <option value="Emergency or medical postponement">Emergency or medical postponement</option>
                                <option value="Resort schedule / maintenance adjustment">Resort schedule / maintenance adjustment</option>
                                <option value="Others">Others (Specify reason)</option>
                            </select>
                        </div>

                        <div id="reschedOtherReasonWrap" style="display: none;">
                            <label class="manual-label" style="color: #fbbf24;">
                                <i class="fa-solid fa-pen"></i> Specify Custom Reason: <span class="manual-required">*</span>
                            </label>
                            <input type="text" name="reschedule_other_reason" id="reschedOtherReasonInput" class="manual-input" placeholder="e.g. Flight delay, family emergency...">
                        </div>
                    </div>

                    <!-- Live Availability & Price Calculation Card -->
                    <div style="margin-top: 1.25rem; background: rgba(124, 58, 237, 0.12); border: 1.5px solid rgba(168, 85, 247, 0.35); border-radius: 12px; padding: 1.15rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 8px;">
                            <span style="font-size: 0.85rem; font-weight: 800; color: #d8b4fe; text-transform: uppercase; letter-spacing: 0.05em;">
                                <i class="fa-solid fa-calculator"></i> Real-Time Availability &amp; Pricing
                            </span>
                            <span id="reschedAvailBadge" class="admin-badge admin-badge-success" style="font-size: 0.78rem;">
                                <i class="fa-solid fa-check"></i> Available
                            </span>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.65rem; font-size: 0.85rem; color: #cbd5e1; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 0.65rem;">
                            <div>
                                <span style="color: #94a3b8; font-size: 0.76rem;">Stay Duration:</span>
                                <div id="reschedCalcDuration" style="font-weight: 700; color: #ffffff;">1 Night</div>
                            </div>
                            <div>
                                <span style="color: #94a3b8; font-size: 0.76rem;">Nightly Rate:</span>
                                <div id="reschedCalcRate" style="font-weight: 700; color: #ffffff;">₱3,500.00</div>
                            </div>
                            <div>
                                <span style="color: #94a3b8; font-size: 0.76rem;">New Total Amount:</span>
                                <div id="reschedCalcNewTotal" style="font-size: 1.15rem; font-weight: 800; color: #34d399; font-family: 'Outfit', sans-serif;">₱3,500.00</div>
                            </div>
                            <div>
                                <span style="color: #94a3b8; font-size: 0.76rem;">Price Difference:</span>
                                <div id="reschedCalcDiff" style="font-weight: 700; color: #94a3b8;">₱0.00 (No change)</div>
                            </div>
                        </div>

                        <div id="reschedConflictWarning" style="display: none; margin-top: 0.85rem; padding: 0.75rem 0.95rem; background: rgba(239, 68, 68, 0.2); border: 1.5px solid #ef4444; border-radius: 8px; color: #fecdd3; font-size: 0.85rem; line-height: 1.4;">
                            <i class="fa-solid fa-triangle-exclamation" style="color: #f87171;"></i>
                            <strong>Conflict Warning:</strong> The selected unit has an overlapping reservation during these dates or is unavailable. Please select different dates or a different Kubo.
                        </div>
                    </div>

                    <!-- Staff Confirmation Check -->
                    <div style="margin-top: 1.15rem; padding: 0.85rem 1rem; background: rgba(245, 158, 11, 0.1); border: 1.5px solid rgba(245, 158, 11, 0.35); border-radius: 10px;">
                        <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; color: #fef3c7; font-size: 0.85rem; margin: 0; line-height: 1.45;">
                            <input type="checkbox" id="reschedConfirmCheckbox" required checked style="margin-top: 2px; accent-color: #f59e0b; width: 18px; height: 18px; flex-shrink: 0;">
                            <span>I confirm that the guest has agreed to these updated reservation dates and any corresponding rate adjustment. Original booking audit history will be preserved.</span>
                        </label>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div style="padding: 1rem 1.5rem; background: #f3e8ff; border-top: 1.5px solid #d8b4fe; display: flex; justify-content: flex-end; gap: 0.75rem; flex-wrap: wrap;">
                    <button type="button" onclick="closeModal('rescheduleModal')" class="admin-btn admin-btn-outline" style="min-height: 42px;">
                        <i class="fa-solid fa-xmark"></i> Cancel
                    </button>
                    <button type="submit" id="reschedSubmitBtn" class="admin-btn admin-btn-gold" style="min-height: 42px; padding: 0 1.5rem; font-weight: 700; background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; border: none;">
                        <i class="fa-solid fa-calendar-check"></i> Confirm &amp; Save Reschedule
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Picture Lightbox Modal -->
    <div id="picture_modal" class="picture-modal-overlay" onclick="closePictureModal(event)">
        <div class="picture-modal-container" onclick="event.stopPropagation()">
            <button type="button" class="picture-modal-close" onclick="closePictureModal()">&times;</button>
            <h3 id="picture_modal_title" style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-bottom: 0.5rem;">Uploaded Picture View</h3>
            <img id="picture_modal_img" src="" class="picture-modal-img" alt="Enlarged View" style="max-width:100%; height:auto;">
        </div>
    </div>

    <!-- Reject Payment Modal -->
    <div id="rejectModal" class="picture-modal-overlay" onclick="if(event.target === this) closeRejectModal();">
        <div class="picture-modal-container" style="max-width: 520px; text-align: left; background: #ffffff; border: 1.5px solid #e2e8f0; color: #fff;">
            <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid #6d28d9; display: flex; justify-content: space-between; align-items: center; background: rgba(244, 63, 94, 0.1);">
                <h3 style="margin: 0; font-family: 'Outfit', sans-serif; color: #fb7185; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-triangle-exclamation"></i> Reject Payment Proof
                </h3>
                <button type="button" class="picture-modal-close" style="position: static;" onclick="closeRejectModal()">&times;</button>
            </div>
            <form action="bookings.php" method="GET" id="rejectForm">
                <input type="hidden" name="action" value="reject_payment">
                <input type="hidden" name="id" id="rejectModalRefNo" value="">
                <input type="hidden" name="view" value="<?= htmlspecialchars($view_mode) ?>">
                
                <div style="padding: 1.5rem;">
                    <div style="background: rgba(37, 19, 71, 0.6); border: 1px dashed #d8b4fe; padding: 1rem; border-radius: 8px; margin-bottom: 1.25rem;">
                        <p style="margin: 0 0 0.5rem 0; color: #94a3b8; font-size: 0.9rem;"><strong>Ref #:</strong> <span id="rejectModalRefDisplay" style="color: #fbbf24; font-family: monospace;"></span></p>
                        <p style="margin: 0 0 0.5rem 0; color: #94a3b8; font-size: 0.9rem;"><strong>Guest Name:</strong> <span id="rejectModalGuest" style="color: #fff; font-weight: 700;"></span></p>
                        <p style="margin: 0; color: #94a3b8; font-size: 0.9rem;"><strong>Amount:</strong> <span id="rejectModalAmount" style="color: #34d399; font-weight: 700;"></span></p>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="font-size: 0.85rem; color: #cbd5e1; font-weight: 700; margin-bottom: 0.4rem; display: block;">Reason for Rejection: *</label>
                        <select name="reason" id="rejectReasonSelect" style="background: #251347; color: #fff; border: 1.5px solid #d8b4fe; padding: 0.6rem 1rem; border-radius: 8px; width: 100%;" onchange="toggleRejectOtherReason(this)" required>
                            <option value="">-- Select a reason --</option>
                            <option value="Proof is blurry or unreadable">Proof is blurry or unreadable</option>
                            <option value="Incorrect amount paid">Incorrect amount paid</option>
                            <option value="Invalid reference number">Invalid reference number</option>
                            <option value="Fake or tampered receipt">Fake or tampered receipt</option>
                            <option value="Others">Others (Please specify)</option>
                        </select>
                    </div>

                    <div id="rejectOtherReasonGroup" style="display: none; margin-top: 0.85rem;">
                        <label style="font-size: 0.85rem; color: #fbbf24; font-weight: 700; margin-bottom: 0.4rem; display: block;">
                            <i class="fa-solid fa-pen"></i> Specify Custom Reason: *
                        </label>
                        <input type="text" name="other_reason" id="rejectOtherReasonInput" style="background: #251347; color: #fff; border: 1.5px solid #fbbf24; width: 100%; padding: 0.6rem 0.8rem; border-radius: 8px;" placeholder="Type custom rejection reason...">
                    </div>
                </div>
                
                <div style="padding: 1rem 1.5rem; background: #f3e8ff; border-top: 1px solid #d8b4fe; display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button type="button" onclick="closeRejectModal()" class="admin-btn admin-btn-outline admin-btn-sm">Cancel</button>
                    <button type="submit" class="admin-btn admin-btn-danger admin-btn-sm" style="background: #f43f5e; border-color: #f43f5e;">
                        <i class="fa-solid fa-xmark"></i> Confirm Rejection
                    </button>
                </div>
            </form>
        </div>
    </div>

        <!-- Add Manual / Walk-In Reservation Modal (Redesigned Ultra-Modern Luxury Modal) -->
    <div id="manualModal" class="modal-overlay" onclick="if(event.target === this) closeModal('manualModal');">
        <div class="modal-container pbr-walkin-modal">
            <!-- Top Gradient Accent Bar -->
            <div class="pbr-accent-bar"></div>

            <!-- Modal Header -->
            <div class="pbr-walkin-header">
                <div class="pbr-header-left">
                    <div class="pbr-header-icon-badge">
                        <i class="fa-solid fa-user-plus"></i>
                    </div>
                    <div>
                        <div class="pbr-header-title-row">
                            <h3 class="pbr-header-title">Add Manual / Walk-In Reservation</h3>
                            <span class="pbr-header-pill">
                                <i class="fa-solid fa-bolt"></i> Front Desk Walk-In
                            </span>
                        </div>
                        <p class="pbr-header-subtitle">Instant on-site booking for walk-in or telephone reservations</p>
                    </div>
                </div>
                <button type="button" class="pbr-modal-close-btn" onclick="closeModal('manualModal')" title="Close dialog">&times;</button>
            </div>

            <!-- Form Body -->
            <form action="bookings.php?view=<?= htmlspecialchars($view_mode) ?>" method="POST" id="manualBookingForm" autocomplete="off" onsubmit="return validateManualBookingForm();" style="display:flex;flex-direction:column;flex:1;overflow:hidden;min-height:0;margin:0;">
                <input type="hidden" name="create_manual_booking" value="1">
                <input type="hidden" name="selected_user_id" id="manualUserId" value="">
                <input type="hidden" name="selected_profile_id" id="manualProfileId" value="">
                <input type="hidden" name="item_id" id="modalItemId" value="1">

                <div class="pbr-walkin-body">

                    <!-- Conflict Alert Notice -->
                    <div id="walkinConflictNotice" style="display: none;" class="pbr-conflict-alert">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span id="walkinConflictText">This unit is already booked for the selected date. Please choose another date or unit.</span>
                    </div>

                    <!-- CARD 1: Quick Fill from Registered Account -->
                    <div class="pbr-card pbr-quickfill-card">
                        <div class="pbr-quickfill-top">
                            <div class="pbr-quickfill-info">
                                <span class="pbr-quickfill-icon"><i class="fa-solid fa-bolt-lightning"></i></span>
                                <div>
                                    <span class="pbr-quickfill-title">Quick Fill from Registered Account</span>
                                    <span class="pbr-quickfill-sub">Search existing member account to autofill guest profile</span>
                                </div>
                            </div>
                            <span class="pbr-badge-optional">Fast Fill</span>
                        </div>
                        <div class="pbr-input-wrapper" style="margin-top: 0.65rem;">
                            <i class="fa-solid fa-magnifying-glass pbr-input-icon"></i>
                            <select id="modalRegUserPicker" class="pbr-input pbr-select" onchange="fillRegisteredUser(this)">
                                <option value="">— Search registered guest name, profile ID, or email —</option>
                                <?php foreach ($registered_users as $ru):
                                    $ru_name  = htmlspecialchars($ru['full_name'] ?? '');
                                    $ru_email = htmlspecialchars($ru['email'] ?? '');
                                    $ru_phone = htmlspecialchars($ru['phone'] ?? '');
                                    $ru_uid   = htmlspecialchars($ru['user_id'] ?? $ru['id'] ?? '');
                                    $ru_pid   = htmlspecialchars(!empty($ru['profile_id']) ? $ru['profile_id'] : sprintf('PBR-GST-%05d', (int)$ru_uid));
                                ?>
                                    <option value="<?= $ru_uid ?>" data-name="<?= $ru_name ?>" data-email="<?= $ru_email ?>" data-phone="<?= $ru_phone ?>" data-pid="<?= $ru_pid ?>" data-uid="<?= $ru_uid ?>">
                                        <?= $ru_name ?> (<?= $ru_pid ?>) — <?= $ru_email ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- CARD 2: Guest Details -->
                    <div class="pbr-card">
                        <div class="pbr-card-header">
                            <span class="pbr-card-step">1</span>
                            <h4 class="pbr-card-title"><i class="fa-regular fa-id-card"></i> Guest Information</h4>
                        </div>

                        <div class="pbr-grid-2">
                            <!-- 1. Guest Full Name -->
                            <div class="pbr-form-group">
                                <label class="pbr-label">
                                    Guest Full Name <span class="pbr-req">*</span>
                                </label>
                                <div class="pbr-input-wrapper">
                                    <i class="fa-regular fa-user pbr-input-icon"></i>
                                    <input type="text" name="guest_name" id="manualGuestName"
                                           class="pbr-input name-only-input"
                                           placeholder="e.g. Maria Santos"
                                           required
                                           pattern="^[A-Za-z\s\-\.ñÑ']+$"
                                           title="Letters only — no numbers allowed"
                                           autocomplete="off">
                                </div>
                                <span class="pbr-hint"><i class="fa-solid fa-circle-info"></i> Letters only, no numbers allowed</span>
                            </div>

                            <!-- 2. Mobile Phone -->
                            <div class="pbr-form-group">
                                <label class="pbr-label">
                                    Mobile Phone <span class="pbr-req">*</span>
                                </label>
                                <div class="pbr-input-wrapper">
                                    <i class="fa-solid fa-mobile-screen-button pbr-input-icon"></i>
                                    <input type="tel" name="guest_phone" id="manualGuestPhone"
                                           class="pbr-input mobile-09-input"
                                           placeholder="09171234567"
                                           required
                                           maxlength="11"
                                           pattern="^09[0-9]{9}$"
                                           inputmode="numeric"
                                           title="Must start with 09 and be exactly 11 digits">
                                </div>
                                <span class="pbr-hint"><i class="fa-solid fa-circle-info"></i> Must start with <strong>09</strong> (exactly 11 digits)</span>
                            </div>

                            <!-- 3. Email Address -->
                            <div class="pbr-form-group">
                                <label class="pbr-label">
                                    Email Address <span class="pbr-opt">(For Confirmation)</span>
                                </label>
                                <div class="pbr-input-wrapper">
                                    <i class="fa-regular fa-envelope pbr-input-icon"></i>
                                    <input type="email" name="guest_email" id="manualGuestEmail"
                                           class="pbr-input"
                                           value="guest@purplebamboo.com"
                                           placeholder="guest@purplebamboo.com">
                                </div>
                                <span class="pbr-hint"><i class="fa-solid fa-circle-info"></i> Digital copy &amp; invoice destination</span>
                            </div>

                            <!-- 4. Number of Pax -->
                            <div class="pbr-form-group">
                                <label class="pbr-label">
                                    Number of Guests (Pax) <span class="pbr-req">*</span>
                                </label>
                                <div class="pbr-input-wrapper">
                                    <i class="fa-solid fa-users pbr-input-icon"></i>
                                    <input type="number" name="pax" id="modalPax"
                                           class="pbr-input"
                                           min="1" max="30" value="2"
                                           required
                                           oninput="updateRateEstimate()">
                                </div>
                                <span class="pbr-hint"><i class="fa-solid fa-circle-info"></i> Total guest count for rate &amp; check-in</span>
                            </div>
                        </div>
                    </div>

                    <!-- CARD 3: Booking Category & Unit Selection -->
                    <div class="pbr-card">
                        <div class="pbr-card-header">
                            <span class="pbr-card-step">2</span>
                            <h4 class="pbr-card-title"><i class="fa-solid fa-umbrella-beach"></i> Category &amp; Unit Selection</h4>
                        </div>

                        <!-- Category Segmented Control Pills -->
                        <div class="pbr-segmented-control" style="margin-bottom: 1.15rem;">
                            <button type="button" class="pbr-segment-btn active" id="tabHouseBtn" onclick="switchCategoryTab('house')">
                                <i class="fa-solid fa-house-chimney"></i>
                                <span>House Kubo Accommodation</span>
                            </button>
                            <button type="button" class="pbr-segment-btn" id="tabActivityBtn" onclick="switchCategoryTab('activity')">
                                <i class="fa-solid fa-water-ladder"></i>
                                <span>Water &amp; Pool Activity Pass</span>
                            </button>
                        </div>

                        <!-- Hidden native select for form submit and compatibility -->
                        <select name="type" id="modalTypeSelect" style="display: none;" onchange="toggleItemOptions(this.value)">
                            <option value="house" selected>🏠 House Kubo Accommodation</option>
                            <option value="activity">🏊 Water &amp; Pool Activity Pass</option>
                        </select>

                        <!-- Kubo Unit Dropdown -->
                        <div class="pbr-form-group" id="kuboSelectGroup">
                            <label class="pbr-label">
                                Select Kubo Unit <span class="pbr-req">*</span>
                            </label>
                            <div class="pbr-input-wrapper">
                                <i class="fa-solid fa-house pbr-input-icon"></i>
                                <select name="item_name" id="modalItemSelect" class="pbr-input pbr-select" onchange="onItemOrDateChange()">
                                    <?php if (!empty($all_kubos_inventory)): ?>
                                        <?php foreach ($all_kubos_inventory as $k):
                                            $k_id = intval($k['kubo_id'] ?? $k['id'] ?? 1);
                                            $k_name = $k['name'] ?? $k['kubo_name_number'] ?? 'Beach Kubo';
                                            $k_price = floatval($k['price'] ?? $k['price_per_night'] ?? 3500);
                                            $k_units = BookingStore::getKuboUnits($k_id);
                                            $k_status = strtolower($k['status'] ?? 'available');
                                            $k_is_unavail = ($k_status !== 'available' || $k_units <= 0);
                                        ?>
                                            <option value="<?= htmlspecialchars($k_name) ?>" data-id="<?= $k_id ?>" data-price="<?= $k_price ?>" <?= $k_is_unavail ? 'disabled style="color:#ef4444;"' : '' ?>>
                                                🌴 <?= htmlspecialchars($k_name) ?> — ₱<?= number_format($k_price, 0) ?>/night <?= $k_is_unavail ? ' [Unavailable]' : ' (Available)' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="Beach Kubo" data-id="1" data-price="3500">🌴 Beach Kubo (₱3,500 / night)</option>
                                        <option value="Orchid Honeymoon Kubo #2" data-id="2" data-price="2200">🌸 Orchid Honeymoon Kubo #2 (₱2,200 / night)</option>
                                        <option value="Royal Purple Pavilion #3" data-id="3" data-price="5800">👑 Royal Purple Pavilion #3 (₱5,800 / night)</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Activity Item Dropdown -->
                        <div class="pbr-form-group" id="activitySelectGroup" style="display: none;">
                            <label class="pbr-label">
                                Select Activity Package <span class="pbr-req">*</span>
                            </label>
                            <div class="pbr-input-wrapper">
                                <i class="fa-solid fa-person-swimming pbr-input-icon"></i>
                                <select name="item_name_activity" id="modalActivitySelect" class="pbr-input pbr-select" onchange="onItemOrDateChange()">
                                    <?php if (!empty($all_activities_inventory)): ?>
                                        <?php foreach ($all_activities_inventory as $act):
                                            $act_id = intval($act['activity_id'] ?? $act['id'] ?? 1);
                                            $act_name = $act['activity_name'] ?? $act['name'] ?? 'Pool Day Pass';
                                            $act_price = floatval($act['price'] ?? 150);
                                        ?>
                                            <option value="<?= htmlspecialchars($act_name) ?>" data-id="<?= $act_id ?>" data-price="<?= $act_price ?>">
                                                🏊 <?= htmlspecialchars($act_name) ?> — ₱<?= number_format($act_price, 0) ?>/pax
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="Pool Day Pass" data-id="1" data-price="150">🏊 Pool Day Pass (₱150/person)</option>
                                        <option value="Kayak Rental" data-id="2" data-price="300">🚣 Kayak Rental (₱300/hour)</option>
                                        <option value="Island Tour Package" data-id="3" data-price="800">🏝 Island Tour Package (₱800/person)</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- CARD 4: Stay Schedule & Duration -->
                    <div class="pbr-card">
                        <div class="pbr-card-header">
                            <span class="pbr-card-step">3</span>
                            <h4 class="pbr-card-title"><i class="fa-regular fa-calendar-check"></i> Stay Schedule &amp; Duration</h4>
                        </div>

                        <!-- Quick Stay Duration Pills (Kubo only) -->
                        <div id="stayDurationGroup" style="margin-bottom: 1.15rem;">
                            <label class="pbr-label" style="margin-bottom: 8px;">
                                Stay Duration (Nights / Days) <span class="pbr-req">*</span>
                            </label>
                            <div class="pbr-duration-pills">
                                <button type="button" class="pbr-dur-pill active" data-days="1" onclick="selectDurationPill(1)">1 Night</button>
                                <button type="button" class="pbr-dur-pill" data-days="2" onclick="selectDurationPill(2)">2 Nights</button>
                                <button type="button" class="pbr-dur-pill" data-days="3" onclick="selectDurationPill(3)">3 Nights</button>
                                <button type="button" class="pbr-dur-pill" data-days="4" onclick="selectDurationPill(4)">4 Nights</button>
                                <button type="button" class="pbr-dur-pill" data-days="5" onclick="selectDurationPill(5)">5 Nights</button>
                                <button type="button" class="pbr-dur-pill" data-days="7" onclick="selectDurationPill(7)">1 Week</button>
                                <button type="button" class="pbr-dur-pill" data-days="custom" onclick="selectDurationPill('custom')">Custom Days...</button>
                            </div>

                            <!-- Native Select kept for JS compatibility & form fallback -->
                            <div style="display: none;">
                                <select name="stay_days" id="modalStayDays" onchange="onStayDurationChange()">
                                    <option value="1" selected>1 Day / 1 Night</option>
                                    <option value="2">2 Days / 2 Nights</option>
                                    <option value="3">3 Days / 3 Nights</option>
                                    <option value="4">4 Days / 4 Nights</option>
                                    <option value="5">5 Days / 5 Nights</option>
                                    <option value="6">6 Days / 6 Nights</option>
                                    <option value="7">7 Days / 7 Nights</option>
                                    <option value="custom">Custom Days...</option>
                                </select>
                            </div>

                            <div id="customDaysWrapper" style="display: none; margin-top: 10px;">
                                <div style="display: flex; align-items: center; gap: 10px; max-width: 240px;">
                                    <input type="number" name="custom_days" id="modalCustomDays" min="1" max="60" value="1" class="pbr-input" style="font-weight: 700; text-align: center; height: 42px;" placeholder="Days" oninput="onCustomDaysInput()">
                                    <span style="font-size: 0.85rem; font-weight: 700; color: #475569;">Nights Stay</span>
                                </div>
                            </div>
                        </div>

                        <!-- Check-In & Check-Out Grid -->
                        <div class="pbr-grid-2">
                            <!-- Check-In -->
                            <div class="pbr-form-group">
                                <label class="pbr-label">
                                    <span class="pbr-dot-green"></span> Check-In Date &amp; Time <span class="pbr-req">*</span>
                                </label>
                                <div style="display: grid; grid-template-columns: 1.4fr 1.15fr; gap: 8px;">
                                    <div class="pbr-input-wrapper">
                                        <i class="fa-regular fa-calendar pbr-input-icon"></i>
                                        <input type="date" name="check_in" id="modalCheckIn"
                                               class="pbr-input"
                                               value="<?= date('Y-m-d') ?>"
                                               min="<?= date('Y-m-d') ?>"
                                               required>
                                    </div>
                                    <div class="custom-time-wrapper">
                                        <input type="hidden" name="check_in_time" id="modalCheckInTime" value="14:00">
                                        <div id="adminCheckInTimeDisplay" class="custom-time-display pbr-time-display">
                                            <span id="adminCheckInTimeText">02:00 PM</span>
                                            <i class="fa-regular fa-clock" style="color: #7c3aed;"></i>
                                        </div>
                                        <div id="adminCheckInTimeDropdown" class="custom-time-dropdown pbr-time-dropdown" style="display: none;">
                                            <div class="custom-time-hours-banner">
                                                <i class="fa-solid fa-clock"></i> <span>Resort: <strong>8 AM &ndash; 10 PM</strong> (5m)</span>
                                            </div>
                                            <div id="adminCheckInTimeGrid" class="custom-time-grid"></div>
                                        </div>
                                    </div>
                                </div>
                                <span class="pbr-hint"><i class="fa-regular fa-clock"></i> Standard check-in starts at 2:00 PM</span>
                            </div>

                            <!-- Check-Out -->
                            <div class="pbr-form-group">
                                <label class="pbr-label">
                                    <span class="pbr-dot-amber"></span> Check-Out Date &amp; Time <span class="pbr-req">*</span>
                                </label>
                                <div style="display: grid; grid-template-columns: 1.4fr 1.15fr; gap: 8px;">
                                    <div class="pbr-input-wrapper">
                                        <i class="fa-regular fa-calendar-xmark pbr-input-icon"></i>
                                        <input type="date" name="check_out" id="modalCheckOut"
                                               class="pbr-input"
                                               value="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                                               min="<?= date('Y-m-d') ?>">
                                    </div>
                                    <div class="custom-time-wrapper">
                                        <input type="hidden" name="check_out_time" id="modalCheckOutTime" value="12:00">
                                        <div id="adminCheckOutTimeDisplay" class="custom-time-display pbr-time-display">
                                            <span id="adminCheckOutTimeText">12:00 PM</span>
                                            <i class="fa-regular fa-clock" style="color: #f59e0b;"></i>
                                        </div>
                                        <div id="adminCheckOutTimeDropdown" class="custom-time-dropdown pbr-time-dropdown" style="display: none;">
                                            <div class="custom-time-hours-banner">
                                                <i class="fa-solid fa-clock"></i> <span>Resort: <strong>8 AM &ndash; 10 PM</strong> (5m)</span>
                                            </div>
                                            <div id="adminCheckOutTimeGrid" class="custom-time-grid"></div>
                                        </div>
                                    </div>
                                </div>
                                <span class="pbr-hint"><i class="fa-regular fa-clock"></i> Standard check-out is 12:00 PM</span>
                            </div>
                        </div>
                    </div>

                    <!-- CARD 5: Billing & Payment Confirmation -->
                    <div class="pbr-card">
                        <div class="pbr-card-header">
                            <span class="pbr-card-step">4</span>
                            <h4 class="pbr-card-title"><i class="fa-solid fa-receipt"></i> Billing &amp; Payment Details</h4>
                        </div>

                        <!-- Live Calculation Badge Card -->
                        <div class="pbr-calc-card">
                            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div class="pbr-calc-icon"><i class="fa-solid fa-calculator"></i></div>
                                    <div>
                                        <div style="font-size: 0.76rem; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: 0.05em;">Calculated Rate Breakdown</div>
                                        <div id="pbrCalcBreakdownText" style="font-size: 0.95rem; font-weight: 800; color: #1e1b4b;">₱3,500.00 &times; 1 Night</div>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-size: 0.74rem; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: 0.05em;">Estimated Total</div>
                                    <div id="pbrCalcTotalBadge" style="font-size: 1.35rem; font-weight: 800; color: #7c3aed; font-family: 'Outfit', sans-serif;">₱3,500.00</div>
                                </div>
                            </div>
                        </div>

                        <div class="pbr-grid-2" style="margin-top: 1rem;">
                            <!-- Total Amount (₱) -->
                            <div class="pbr-form-group">
                                <label class="pbr-label">
                                    Final Total Amount (₱) <span class="pbr-req">*</span>
                                </label>
                                <div class="pbr-input-wrapper">
                                    <span class="pbr-currency-symbol">₱</span>
                                    <input type="number" name="total_amount" id="modalTotalAmount"
                                           class="pbr-input"
                                           style="padding-left: 2rem; font-weight: 800; font-size: 1.05rem; color: #0f172a;"
                                           step="0.01" value="3500.00"
                                           required>
                                </div>
                                <span class="pbr-hint"><i class="fa-solid fa-circle-info"></i> Auto-calculated. Can adjust for front-desk discounts.</span>
                            </div>

                            <!-- Payment Method (Strictly Cash Only) -->
                            <div class="pbr-form-group">
                                <label class="pbr-label">
                                    Payment Method <span class="pbr-badge-cash-pill"><i class="fa-solid fa-lock"></i> Cash Only</span>
                                </label>
                                <input type="hidden" name="gcash_ref" id="manualPaymentRef" value="WALK-IN-CASH">
                                <div class="pbr-cash-display-card">
                                    <div class="pbr-cash-icon-box">
                                        <i class="fa-solid fa-money-bill-wave"></i>
                                    </div>
                                    <div class="pbr-cash-text-group">
                                        <span class="pbr-cash-main-title">Cash Payment (Front Desk)</span>
                                        <span class="pbr-cash-sub-desc">On-site cash collection upon check-in</span>
                                    </div>
                                    <span class="pbr-cash-tag">
                                        <i class="fa-solid fa-circle-check"></i> Walk-In Cash
                                    </span>
                                </div>
                                <span class="pbr-hint"><i class="fa-solid fa-circle-info"></i> Walk-in &amp; manual reservations accept cash payment only.</span>
                            </div>
                        </div>

                        <!-- Special Notes -->
                        <div class="pbr-form-group" style="margin-top: 0.85rem;">
                            <label class="pbr-label">
                                Front Desk / Special Notes <span class="pbr-opt">(Optional)</span>
                            </label>
                            <div class="pbr-input-wrapper">
                                <i class="fa-regular fa-comment-dots pbr-input-icon"></i>
                                <input type="text" name="special_notes" id="manualSpecialNotes"
                                       class="pbr-input"
                                       placeholder="e.g. Early check-in requested, extra towels, walk-in with family"
                                       value="Manual Walk-In / Front Desk Reservation">
                            </div>
                        </div>

                        <!-- Terms & Conditions Acknowledgement -->
                        <div class="pbr-terms-card" style="margin-top: 1rem;">
                            <label class="pbr-terms-label">
                                <input type="checkbox" name="terms_acknowledged" value="1" required checked class="pbr-checkbox">
                                <span>
                                    Guest has acknowledged and agreed to resort
                                    <a href="terms.php" target="_blank">Terms &amp; Agreement</a>,
                                    check-in (2:00 PM) / check-out (12:00 PM) policies, and house rules. <span class="pbr-req">*</span>
                                </span>
                            </label>
                        </div>
                    </div>

                </div>

                <!-- Sticky Modal Footer -->
                <div class="pbr-walkin-footer">
                    <div class="pbr-footer-summary" id="manualModalSummaryPill">
                        <span class="pbr-footer-dot"></span>
                        <span id="manualFooterSummaryText">🌴 Beach Kubo &bull; 1 Night &bull; ₱3,500.00</span>
                    </div>
                    <div class="pbr-footer-buttons">
                        <button type="button" onclick="closeModal('manualModal')" class="pbr-btn-cancel">
                            <i class="fa-solid fa-xmark"></i> Cancel
                        </button>
                        <button type="submit" id="manualBookingSubmitBtn" class="pbr-btn-save">
                            <i class="fa-solid fa-floppy-disk"></i> Save Reservation
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Scoped Styles for Redesigned Manual Walk-In Modal -->
    <style>
        /* ── Modal Shell & Animation ── */
        .pbr-walkin-modal {
            max-width: 840px !important;
            max-height: 92vh !important;
            display: flex !important;
            flex-direction: column !important;
            overflow: hidden !important;
            border-radius: 20px !important;
            background: #ffffff !important;
            border: 1px solid rgba(226, 232, 240, 0.9) !important;
            box-shadow: 0 25px 60px -15px rgba(15, 23, 42, 0.25), 0 0 0 1px rgba(226, 232, 240, 0.8) !important;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif !important;
            animation: pbrModalPop 0.25s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }
        @keyframes pbrModalPop {
            0% { transform: scale(0.96); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
        .pbr-accent-bar {
            height: 4px;
            width: 100%;
            background: linear-gradient(90deg, #7c3aed 0%, #a855f7 50%, #f59e0b 100%);
            flex-shrink: 0;
        }

        /* ── Header ── */
        .pbr-walkin-header {
            padding: 1.25rem 1.75rem;
            background: #ffffff;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
            flex-shrink: 0;
        }
        .pbr-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .pbr-header-icon-badge {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #7c3aed 0%, #6366f1 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.2rem;
            box-shadow: 0 4px 14px rgba(124, 58, 237, 0.28);
            flex-shrink: 0;
        }
        .pbr-header-title-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 2px;
            flex-wrap: wrap;
        }
        .pbr-header-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.22rem;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
            letter-spacing: -0.01em;
        }
        .pbr-header-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #f3e8ff;
            color: #7c3aed;
            font-size: 0.72rem;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .pbr-header-subtitle {
            margin: 0;
            font-size: 0.84rem;
            color: #64748b;
        }
        .pbr-modal-close-btn {
            background: #f1f5f9;
            color: #64748b;
            border: none;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            flex-shrink: 0;
        }
        .pbr-modal-close-btn:hover {
            background: #fee2e2;
            color: #ef4444;
            transform: scale(1.05);
        }

        /* ── Body & Cards ── */
        .pbr-walkin-body {
            padding: 1.5rem 1.75rem !important;
            background: #f8fafc !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 1.15rem !important;
            overflow-y: auto !important;
            flex: 1 !important;
            -webkit-overflow-scrolling: touch;
        }
        .pbr-walkin-body::-webkit-scrollbar {
            width: 6px;
        }
        .pbr-walkin-body::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        .pbr-walkin-body::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        .pbr-walkin-body::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .pbr-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 1.15rem 1.25rem;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.02);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .pbr-card:hover {
            border-color: #cbd5e1;
        }
        .pbr-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 1rem;
        }
        .pbr-card-step {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: linear-gradient(135deg, #7c3aed, #6366f1);
            color: #ffffff;
            font-size: 0.72rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .pbr-card-title {
            font-family: 'Outfit', sans-serif;
            font-size: 0.96rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .pbr-card-title i {
            color: #7c3aed;
        }

        /* ── Quick Fill Banner Card ── */
        .pbr-quickfill-card {
            background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%);
            border: 1.5px solid #ddd6fe;
        }
        .pbr-quickfill-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .pbr-quickfill-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .pbr-quickfill-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: #ffffff;
            color: #8b5cf6;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            box-shadow: 0 2px 6px rgba(139, 92, 246, 0.15);
            flex-shrink: 0;
        }
        .pbr-quickfill-title {
            display: block;
            font-size: 0.86rem;
            font-weight: 800;
            color: #4c1d95;
            letter-spacing: -0.01em;
        }
        .pbr-quickfill-sub {
            display: block;
            font-size: 0.75rem;
            color: #6b21a8;
        }
        .pbr-badge-optional {
            font-size: 0.72rem;
            font-weight: 700;
            background: rgba(124, 58, 237, 0.1);
            color: #7c3aed;
            padding: 3px 8px;
            border-radius: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        /* ── Conflict Alert ── */
        .pbr-conflict-alert {
            padding: 0.85rem 1.15rem;
            background: #fef2f2;
            border: 1.5px solid #fecaca;
            border-radius: 12px;
            color: #991b1b;
            font-size: 0.88rem;
            line-height: 1.5;
            box-shadow: 0 2px 6px rgba(239, 68, 68, 0.08);
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .pbr-conflict-alert i {
            color: #ef4444;
            font-size: 1.1rem;
            margin-top: 2px;
            flex-shrink: 0;
        }

        /* ── Form Grids & Inputs ── */
        .pbr-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }
        .pbr-form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .pbr-label {
            font-size: 0.77rem;
            font-weight: 700;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .pbr-req {
            color: #ef4444;
            font-weight: 800;
            font-size: 0.85rem;
        }
        .pbr-opt {
            color: #94a3b8;
            font-weight: 500;
            font-size: 0.72rem;
            text-transform: none;
            letter-spacing: 0;
        }
        .pbr-hint {
            font-size: 0.73rem;
            color: #64748b;
            margin-top: 3px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .pbr-hint strong {
            color: #0f172a;
        }
        .pbr-dot-green {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #10b981;
            display: inline-block;
        }
        .pbr-dot-amber {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #f59e0b;
            display: inline-block;
        }

        .pbr-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }
        .pbr-input-icon {
            position: absolute;
            left: 0.9rem;
            color: #94a3b8;
            font-size: 0.92rem;
            pointer-events: none;
            transition: color 0.2s ease;
            z-index: 1;
        }
        .pbr-input {
            width: 100%;
            height: 44px;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            padding: 0.6rem 0.85rem 0.6rem 2.5rem;
            font-size: 0.92rem;
            font-weight: 600;
            color: #0f172a;
            outline: none;
            transition: all 0.2s ease;
            box-sizing: border-box;
            font-family: inherit;
        }
        .pbr-input:focus {
            border-color: #7c3aed;
            box-shadow: 0 0 0 3.5px rgba(124, 58, 237, 0.12);
        }
        .pbr-input-wrapper:focus-within .pbr-input-icon {
            color: #7c3aed;
        }
        .pbr-input::placeholder {
            color: #94a3b8;
            font-weight: 400;
        }
        .pbr-input[type="date"] {
            padding-right: 0.65rem;
            color-scheme: light;
            font-weight: 600;
        }
        .pbr-select {
            appearance: none;
            -webkit-appearance: none;
            cursor: pointer;
            padding-right: 2.3rem;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.85rem center;
            background-size: 1.1em;
        }

        /* ── Segmented Control Pills ── */
        .pbr-segmented-control {
            display: flex;
            background: #ede9fe;
            padding: 4px;
            border-radius: 12px;
            gap: 4px;
            border: 1.5px solid #ddd6fe;
        }
        .pbr-segment-btn {
            flex: 1;
            padding: 0.6rem 1rem;
            border: none;
            border-radius: 9px;
            font-size: 0.86rem;
            font-weight: 700;
            color: #6d28d9;
            background: transparent;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            font-family: inherit;
        }
        .pbr-segment-btn:hover:not(.active) {
            background: rgba(255, 255, 255, 0.6);
            color: #4c1d95;
        }
        .pbr-segment-btn.active {
            background: #7c3aed;
            color: #ffffff;
            box-shadow: 0 3px 10px rgba(124, 58, 237, 0.28);
        }

        /* ── Stay Duration Pills ── */
        .pbr-duration-pills {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .pbr-dur-pill {
            padding: 0.45rem 0.85rem;
            border: 1.5px solid #e2e8f0;
            background: #ffffff;
            color: #475569;
            border-radius: 9px;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
            font-family: inherit;
        }
        .pbr-dur-pill:hover:not(.active) {
            border-color: #cbd5e1;
            background: #f8fafc;
            color: #0f172a;
        }
        .pbr-dur-pill.active {
            border-color: #7c3aed;
            background: #7c3aed;
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.25);
        }

        /* ── Custom Time Trigger & Dropdown inside Modal ── */
        .pbr-time-display {
            height: 44px;
            background: #ffffff !important;
            border: 1.5px solid #cbd5e1 !important;
            border-radius: 10px !important;
            padding: 0.55rem 0.85rem !important;
            font-size: 0.90rem !important;
            font-weight: 700 !important;
            color: #0f172a !important;
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            transition: all 0.2s ease !important;
            box-sizing: border-box !important;
        }
        .pbr-time-display:hover,
        .pbr-time-display.active {
            border-color: #7c3aed !important;
            box-shadow: 0 0 0 3.5px rgba(124, 58, 237, 0.12) !important;
        }
        .pbr-time-dropdown {
            width: 300px !important;
            max-width: 90vw !important;
            z-index: 100 !important;
            border-radius: 12px !important;
            border: 1px solid #e2e8f0 !important;
            box-shadow: 0 15px 35px -5px rgba(15, 23, 42, 0.2) !important;
            background: #ffffff !important;
        }

        /* ── Calculation & Rate Card ── */
        .pbr-calc-card {
            background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%);
            border: 1.5px solid #ddd6fe;
            border-radius: 12px;
            padding: 0.9rem 1.15rem;
        }
        .pbr-calc-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #ffffff;
            color: #7c3aed;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            box-shadow: 0 2px 6px rgba(124, 58, 237, 0.12);
            flex-shrink: 0;
        }
        .pbr-currency-symbol {
            position: absolute;
            left: 0.9rem;
            font-weight: 800;
            font-size: 1.1rem;
            color: #7c3aed;
            z-index: 1;
            pointer-events: none;
        }

        /* ── Cash Only Payment Card ── */
        .pbr-badge-cash-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.7rem;
            font-weight: 800;
            color: #15803d;
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            padding: 0.1rem 0.45rem;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .pbr-cash-display-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0.5rem 0.85rem;
            background: #f0fdf4;
            border: 1.5px solid #86efac;
            border-radius: 10px;
            height: 44px;
            box-sizing: border-box;
            transition: all 0.2s ease;
        }
        .pbr-cash-icon-box {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: #dcfce7;
            color: #16a34a;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
        }
        .pbr-cash-text-group {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-width: 0;
            line-height: 1.15;
        }
        .pbr-cash-main-title {
            font-size: 0.84rem;
            font-weight: 800;
            color: #14532d;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .pbr-cash-sub-desc {
            font-size: 0.7rem;
            font-weight: 500;
            color: #166534;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .pbr-cash-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 0.2rem 0.5rem;
            background: #bbf7d0;
            color: #14532d;
            font-size: 0.7rem;
            font-weight: 800;
            border-radius: 20px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        /* ── Terms & Agreement Card ── */
        .pbr-terms-card {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.85rem 1rem;
        }
        .pbr-terms-label {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            cursor: pointer;
            font-size: 0.84rem;
            color: #475569;
            line-height: 1.5;
            margin: 0;
        }
        .pbr-terms-label a {
            color: #7c3aed;
            font-weight: 700;
            text-decoration: underline;
        }
        .pbr-checkbox {
            accent-color: #7c3aed;
            width: 18px;
            height: 18px;
            margin-top: 2px;
            flex-shrink: 0;
            cursor: pointer;
        }

        /* ── Modal Footer ── */
        .pbr-walkin-footer {
            padding: 1.1rem 1.75rem;
            background: #ffffff;
            border-top: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            position: sticky;
            bottom: 0;
            z-index: 10;
            flex-shrink: 0;
        }
        .pbr-footer-summary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f3e8ff;
            color: #6d28d9;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.84rem;
            font-weight: 700;
            border: 1px solid #ddd6fe;
        }
        .pbr-footer-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.25);
            flex-shrink: 0;
        }
        .pbr-footer-buttons {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .pbr-btn-cancel {
            padding: 0.65rem 1.25rem;
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            color: #64748b;
            font-weight: 700;
            border-radius: 10px;
            font-size: 0.88rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: inherit;
            min-height: 44px;
        }
        .pbr-btn-cancel:hover {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #cbd5e1;
        }
        .pbr-btn-save {
            padding: 0.65rem 1.6rem;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            border: none;
            color: #ffffff;
            font-weight: 700;
            border-radius: 10px;
            font-size: 0.92rem;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(124, 58, 237, 0.35);
            min-height: 44px;
        }
        .pbr-btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(124, 58, 237, 0.45);
            background: linear-gradient(135deg, #6d28d9 0%, #5b21b6 100%);
        }
        .pbr-btn-save:active {
            transform: translateY(0);
        }

        /* ── Responsive Adaptations ── */
        @media (max-width: 768px) {
            .pbr-walkin-modal {
                width: 96vw !important;
                max-width: 96vw !important;
                border-radius: 14px !important;
            }
            .pbr-walkin-header {
                padding: 1rem 1.15rem !important;
            }
            .pbr-walkin-body {
                padding: 1.1rem !important;
            }
            .pbr-grid-2 {
                grid-template-columns: 1fr !important;
            }
            .pbr-walkin-footer {
                padding: 1rem !important;
                flex-direction: column !important;
                align-items: stretch !important;
            }
            .pbr-footer-summary {
                justify-content: center !important;
            }
            .pbr-footer-buttons {
                width: 100% !important;
            }
            .pbr-btn-cancel, .pbr-btn-save {
                flex: 1 !important;
                justify-content: center !important;
            }
        }
    </style>


    <!-- Hidden Clear History Form -->
    <form id="clearHistoryForm" action="bookings.php" method="GET" style="display: none;">
        <input type="hidden" name="action" value="clear_history">
        <input type="hidden" name="id" value="all">
        <input type="hidden" name="view" value="<?= htmlspecialchars($view_mode) ?>">
    </form>

    <?php if (!$is_admin_layout): ?>
        <?php include 'footer.php'; ?>
    <?php endif; ?>

    <script>
        window.walkinBookedDatesMap = <?= json_encode($walkin_booked_dates_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

        function getCurrentlySelectedWalkinItemId() {
            const type = document.getElementById('modalTypeSelect')?.value || 'house';
            const sel = (type === 'activity') ? document.getElementById('modalActivitySelect') : document.getElementById('modalItemSelect');
            if (!sel || sel.selectedIndex < 0) return 1;
            const opt = sel.options[sel.selectedIndex];
            const id = parseInt(opt ? opt.getAttribute('data-id') : 1, 10) || 1;
            const hiddenId = document.getElementById('modalItemId');
            if (hiddenId) hiddenId.value = id;
            return id;
        }

        function getBookedDatesForSelectedWalkinItem() {
            const type = document.getElementById('modalTypeSelect')?.value || 'house';
            const itemId = getCurrentlySelectedWalkinItemId();
            if (window.walkinBookedDatesMap && window.walkinBookedDatesMap[type] && window.walkinBookedDatesMap[type][itemId]) {
                return window.walkinBookedDatesMap[type][itemId];
            }
            return [];
        }

        function checkWalkinBookingConflicts(showAlert = false) {
            const type = document.getElementById('modalTypeSelect')?.value || 'house';
            const checkInInput = document.getElementById('modalCheckIn');
            const checkOutInput = document.getElementById('modalCheckOut');
            const noticeEl = document.getElementById('walkinConflictNotice');
            const noticeText = document.getElementById('walkinConflictText');
            const saveBtn = document.getElementById('manualBookingSubmitBtn');

            if (!checkInInput || !checkInInput.value) return false;

            const bookedDates = getBookedDatesForSelectedWalkinItem();
            const cinVal = checkInInput.value;
            const coutVal = checkOutInput ? checkOutInput.value : '';

            let hasConflict = false;
            let conflictDates = [];

            if (type === 'house') {
                const dStart = new Date(cinVal + 'T00:00:00');
                const dEnd = coutVal ? new Date(coutVal + 'T00:00:00') : new Date(dStart.getTime() + 86400000);
                for (let c = new Date(dStart); c < dEnd; c.setDate(c.getDate() + 1)) {
                    const y = c.getFullYear();
                    const m = String(c.getMonth() + 1).padStart(2, '0');
                    const d = String(c.getDate()).padStart(2, '0');
                    const ds = `${y}-${m}-${d}`;
                    if (bookedDates.includes(ds)) {
                        hasConflict = true;
                        conflictDates.push(ds);
                    }
                }
            } else {
                if (bookedDates.includes(cinVal)) {
                    hasConflict = true;
                    conflictDates.push(cinVal);
                }
            }

            if (hasConflict) {
                const sel = (type === 'activity') ? document.getElementById('modalActivitySelect') : document.getElementById('modalItemSelect');
                const selName = sel?.value || 'Selected unit';
                const formattedConflict = conflictDates.join(', ');
                if (noticeEl) {
                    noticeEl.style.display = 'block';
                    if (noticeText) {
                        noticeText.innerHTML = `<strong><i class="fa-solid fa-calendar-xmark"></i> Already Booked:</strong> <em>${selName}</em> is already booked or has no available units on <strong>${formattedConflict}</strong>. Please select another date or unit.`;
                    }
                }
                checkInInput.style.borderColor = '#ef4444';
                if (checkOutInput) checkOutInput.style.borderColor = '#ef4444';
                if (saveBtn) {
                    saveBtn.disabled = true;
                    saveBtn.style.opacity = '0.6';
                    saveBtn.style.cursor = 'not-allowed';
                    saveBtn.title = 'Cannot save: selected date is already booked';
                }
                if (showAlert) {
                    alert(`Notice: ${selName} is already booked on ${formattedConflict}. Please choose an available date or another unit.`);
                }
                return true;
            } else {
                if (noticeEl) noticeEl.style.display = 'none';
                checkInInput.style.borderColor = '';
                if (checkOutInput) checkOutInput.style.borderColor = '';
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.style.opacity = '1';
                    saveBtn.style.cursor = 'pointer';
                    saveBtn.title = '';
                }
                return false;
            }
        }

        function onItemOrDateChange() {
            getCurrentlySelectedWalkinItemId();
            updateRateEstimate();
            checkWalkinBookingConflicts(false);
        }

        function fillRegisteredUser(selectEl) {
            const opt = selectEl.options[selectEl.selectedIndex];
            const nameInput = document.getElementById('manualGuestName');
            const emailInput = document.getElementById('manualGuestEmail');
            const phoneInput = document.getElementById('manualGuestPhone');
            const userIdInput = document.getElementById('manualUserId');
            const profileIdInput = document.getElementById('manualProfileId');

            if (opt && (opt.value || opt.getAttribute('data-name') || opt.getAttribute('data-email'))) {
                const name = opt.getAttribute('data-name') || '';
                const email = opt.getAttribute('data-email') || '';
                const phone = opt.getAttribute('data-phone') || '';
                const pid = opt.getAttribute('data-pid') || '';
                const uid = opt.value || opt.getAttribute('data-uid') || '';

                if (nameInput) {
                    nameInput.value = name;
                    nameInput.dispatchEvent(new Event('input', { bubbles: true }));
                }
                if (emailInput) {
                    emailInput.value = email;
                    emailInput.dispatchEvent(new Event('input', { bubbles: true }));
                }
                if (phoneInput) {
                    phoneInput.value = phone;
                    phoneInput.dispatchEvent(new Event('input', { bubbles: true }));
                }
                if (userIdInput) userIdInput.value = uid;
                if (profileIdInput) profileIdInput.value = pid;
            } else {
                if (userIdInput) userIdInput.value = '';
                if (profileIdInput) profileIdInput.value = '';
            }
        }

        function openManualModal() {
            const today = new Date().toISOString().split('T')[0];
            const checkInInput = document.getElementById('modalCheckIn');
            const checkOutInput = document.getElementById('modalCheckOut');
            if (checkInInput) {
                checkInInput.min = today;
                if (!checkInInput.value || checkInInput.value < today) {
                    checkInInput.value = today;
                }
            }
            if (checkOutInput) {
                checkOutInput.min = checkInInput ? checkInInput.value : today;
            }
            
            const currentType = document.getElementById('modalTypeSelect')?.value || 'house';
            switchCategoryTab(currentType);
            syncCheckoutFromDuration();
            getCurrentlySelectedWalkinItemId();
            updateRateEstimate();
            checkWalkinBookingConflicts(false);
            document.getElementById('manualModal').classList.add('active');
        }

        function validateManualBookingForm() {
            const checkInInput = document.getElementById('modalCheckIn');
            const today = new Date().toISOString().split('T')[0];
            if (checkInInput && checkInInput.value && checkInInput.value < today) {
                alert('Check-in date cannot be in the past for walk-in reservations. Please select today or a future date.');
                checkInInput.focus();
                return false;
            }
            const checkOutInput = document.getElementById('modalCheckOut');
            if (checkInInput && checkOutInput && checkOutInput.value && checkOutInput.value < checkInInput.value) {
                alert('Check-out date cannot be before check-in date.');
                checkOutInput.focus();
                return false;
            }
            if (checkWalkinBookingConflicts(true)) {
                return false;
            }
            return true;
        }

        function closeModal(id) {
            const el = document.getElementById(id);
            if (el) el.classList.remove('active');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal('detailsModal');
                closeModal('manualModal');
                closeModal('cancelUnpaidModal');
                closeModal('rescheduleModal');
                closeRejectModal();
                closePictureModal();
            }
        });

        function getSelectedStayDays() {
            const staySelect = document.getElementById('modalStayDays');
            const customInput = document.getElementById('modalCustomDays');
            if (staySelect && staySelect.value === 'custom' && customInput) {
                return Math.max(1, parseInt(customInput.value, 10) || 1);
            }
            return Math.max(1, parseInt(staySelect ? staySelect.value : 1, 10) || 1);
        }

        function selectDurationPill(days) {
            const staySelect = document.getElementById('modalStayDays');
            const customWrap = document.getElementById('customDaysWrapper');
            const customInput = document.getElementById('modalCustomDays');

            document.querySelectorAll('.pbr-dur-pill').forEach(btn => {
                if (btn.getAttribute('data-days') === String(days)) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            if (days === 'custom') {
                if (staySelect) staySelect.value = 'custom';
                if (customWrap) customWrap.style.display = 'block';
                if (customInput) customInput.focus();
            } else {
                if (staySelect) staySelect.value = String(days);
                if (customWrap) customWrap.style.display = 'none';
            }
            onStayDurationChange();
        }

        function onStayDurationChange() {
            const staySelect = document.getElementById('modalStayDays');
            const customWrap = document.getElementById('customDaysWrapper');
            const customInput = document.getElementById('modalCustomDays');
            if (staySelect) {
                if (staySelect.value === 'custom') {
                    if (customWrap) customWrap.style.display = 'block';
                    if (customInput) customInput.focus();
                } else {
                    if (customWrap) customWrap.style.display = 'none';
                }
                document.querySelectorAll('.pbr-dur-pill').forEach(btn => {
                    if (btn.getAttribute('data-days') === staySelect.value) {
                        btn.classList.add('active');
                    } else {
                        btn.classList.remove('active');
                    }
                });
            }
            syncCheckoutFromDuration();
        }

        function onCustomDaysInput() {
            syncCheckoutFromDuration();
        }

        function syncCheckoutFromDuration() {
            const checkInInput = document.getElementById('modalCheckIn');
            const checkOutInput = document.getElementById('modalCheckOut');
            if (!checkInInput || !checkOutInput || !checkInInput.value) return;

            const today = new Date().toISOString().split('T')[0];
            if (checkInInput.value < today) {
                alert('Check-in date cannot be in the past for walk-in reservations.');
                checkInInput.value = today;
            }

            checkOutInput.min = checkInInput.value;

            const days = getSelectedStayDays();
            const inDate = new Date(checkInInput.value + 'T00:00:00');
            inDate.setDate(inDate.getDate() + days);

            const y = inDate.getFullYear();
            const m = String(inDate.getMonth() + 1).padStart(2, '0');
            const d = String(inDate.getDate()).padStart(2, '0');
            checkOutInput.value = `${y}-${m}-${d}`;
            updateRateEstimate();
            checkWalkinBookingConflicts(false);
        }

        function syncDurationFromDates() {
            const checkInInput = document.getElementById('modalCheckIn');
            const checkOutInput = document.getElementById('modalCheckOut');
            const staySelect = document.getElementById('modalStayDays');
            const customWrap = document.getElementById('customDaysWrapper');
            const customInput = document.getElementById('modalCustomDays');
            if (!checkInInput || !checkOutInput || !checkInInput.value || !checkOutInput.value) return;

            const today = new Date().toISOString().split('T')[0];
            if (checkInInput.value < today) {
                alert('Check-in date cannot be in the past for walk-in reservations.');
                checkInInput.value = today;
            }

            checkOutInput.min = checkInInput.value;

            const dIn = new Date(checkInInput.value + 'T00:00:00');
            const dOut = new Date(checkOutInput.value + 'T00:00:00');
            let diffDays = Math.round((dOut - dIn) / (1000 * 60 * 60 * 24));
            if (diffDays < 1) {
                diffDays = 1;
                const fixedOut = new Date(dIn);
                fixedOut.setDate(fixedOut.getDate() + 1);
                const y = fixedOut.getFullYear();
                const m = String(fixedOut.getMonth() + 1).padStart(2, '0');
                const d = String(fixedOut.getDate()).padStart(2, '0');
                checkOutInput.value = `${y}-${m}-${d}`;
            }

            if (staySelect) {
                let matched = false;
                for (let opt of staySelect.options) {
                    if (opt.value === String(diffDays)) {
                        staySelect.value = String(diffDays);
                        if (customWrap) customWrap.style.display = 'none';
                        matched = true;
                        break;
                    }
                }
                if (!matched) {
                    staySelect.value = 'custom';
                    if (customWrap) customWrap.style.display = 'block';
                    if (customInput) customInput.value = diffDays;
                }
            }

            document.querySelectorAll('.pbr-dur-pill').forEach(btn => {
                const bVal = btn.getAttribute('data-days');
                if (bVal === String(diffDays) || (!['1','2','3','4','5','7'].includes(String(diffDays)) && bVal === 'custom')) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            updateRateEstimate();
            checkWalkinBookingConflicts(false);
        }

        function switchCategoryTab(type) {
            const typeSelect = document.getElementById('modalTypeSelect');
            if (typeSelect) typeSelect.value = type;

            const btnHouse = document.getElementById('tabHouseBtn');
            const btnAct = document.getElementById('tabActivityBtn');
            if (type === 'activity') {
                btnHouse?.classList.remove('active');
                btnAct?.classList.add('active');
            } else {
                btnHouse?.classList.add('active');
                btnAct?.classList.remove('active');
            }
            toggleItemOptions(type);
        }

        function setPaymentMethod(code, btn) {
            const payInput = document.getElementById('manualPaymentRef');
            if (payInput) payInput.value = code;
            document.querySelectorAll('.pbr-pay-chip').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');
        }

        function toggleItemOptions(type) {
            const stayGroup = document.getElementById('stayDurationGroup');
            if (stayGroup) stayGroup.style.display = (type === 'activity') ? 'none' : '';
            const kuboGroup    = document.getElementById('kuboSelectGroup');
            const actGroup     = document.getElementById('activitySelectGroup');
            const kuboSelect   = document.getElementById('modalItemSelect');
            const actSelect    = document.getElementById('modalActivitySelect');

            if (type === 'activity') {
                if (kuboGroup)  kuboGroup.style.display  = 'none';
                if (actGroup)   actGroup.style.display   = '';
                if (kuboSelect)  { kuboSelect.disabled = true;  kuboSelect.name = 'item_name_kubo_disabled'; }
                if (actSelect)   { actSelect.disabled  = false; actSelect.name  = 'item_name'; }
            } else {
                if (kuboGroup)  kuboGroup.style.display  = '';
                if (actGroup)   actGroup.style.display   = 'none';
                if (kuboSelect)  { kuboSelect.disabled = false; kuboSelect.name = 'item_name'; }
                if (actSelect)   { actSelect.disabled  = true;  actSelect.name  = 'item_name_activity_disabled'; }
            }
            onItemOrDateChange();
        }

        function updateRateEstimate() {
            const type = document.getElementById('modalTypeSelect')?.value || 'house';
            const select = (type === 'activity') ? document.getElementById('modalActivitySelect') : document.getElementById('modalItemSelect');
            if (!select || select.selectedIndex < 0) return;
            const selectedOpt = select.options[select.selectedIndex];
            if (selectedOpt) {
                const price = parseFloat(selectedOpt.getAttribute('data-price') || 0);
                const rawName = selectedOpt.value || 'Selected Unit';
                let total = 0;
                let breakdownStr = '';
                let footerSummary = '';

                if (type === 'activity') {
                    const pax = Math.max(1, parseInt(document.getElementById('modalPax')?.value || 1, 10));
                    total = price * pax;
                    breakdownStr = `₱${price.toLocaleString()} × ${pax} ${pax > 1 ? 'Guests' : 'Guest'}`;
                    footerSummary = `🏊 ${rawName} • ${pax} Pax • ₱${total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                } else {
                    const days = getSelectedStayDays();
                    total = price * days;
                    breakdownStr = `₱${price.toLocaleString()} / night × ${days} ${days > 1 ? 'Nights' : 'Night'}`;
                    footerSummary = `🌴 ${rawName} • ${days} ${days > 1 ? 'Nights' : 'Night'} • ₱${total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                }

                const totalInput = document.getElementById('modalTotalAmount');
                if (totalInput) totalInput.value = total.toFixed(2);

                const calcBreakdown = document.getElementById('pbrCalcBreakdownText');
                if (calcBreakdown) calcBreakdown.textContent = breakdownStr;

                const calcTotal = document.getElementById('pbrCalcTotalBadge');
                if (calcTotal) calcTotal.textContent = `₱${total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;

                const footerText = document.getElementById('manualFooterSummaryText');
                if (footerText) footerText.textContent = footerSummary;
            }
        }

                function formatBookingDateTime(dtStr, fallbackTime) {
            if (!dtStr) return { dateStr: 'N/A', timeStr: fallbackTime };
            const parts = dtStr.trim().split(' ');
            const datePart = parts[0];
            const timePart = parts[1];

            let dateFormatted = datePart;
            if (datePart && datePart.includes('-')) {
                const [y, m, d] = datePart.split('-');
                const dt = new Date(parseInt(y, 10), parseInt(m, 10) - 1, parseInt(d, 10));
                if (!isNaN(dt.getTime())) {
                    dateFormatted = dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                }
            }

            let timeFormatted = fallbackTime;
            if (timePart && timePart !== '00:00:00' && timePart !== '00:00') {
                const t = timePart.split(':');
                let h = parseInt(t[0], 10);
                const min = t[1] || '00';
                const ampm = h >= 12 ? 'PM' : 'AM';
                h = h % 12 || 12;
                timeFormatted = `${h}:${min} ${ampm}`;
            }

            return { dateStr: dateFormatted, timeStr: timeFormatted };
        }

        function showBookingDetails(b) {
            window.currentActiveBooking = b;
            const cinInfo = formatBookingDateTime(b.check_in, '2:00 PM');
            const coutInfo = formatBookingDateTime(b.check_out, '12:00 PM');
            const body = document.getElementById('modalDetailsBody');
            const footer = document.getElementById('modalDetailsFooter');
            if (!body) return;

            const bId = b.id || '';
            const guestName = b.guest_name || 'Guest';
            const guestPhone = b.guest_phone || '';
            const guestEmail = b.guest_email || '';
            const profileId = b.profile_id || ('PBR-GST-' + (b.user_id ? String(b.user_id).padStart(5, '0') : bId));
            const pax = b.pax || b.num_guests || 1;
            const amount = parseFloat(b.total_amount || 0);

            // Time slot formatting
            let timeSlot = b.time_slot || '';
            if (!timeSlot) {
                if (b.start_time && b.end_time) {
                    timeSlot = formatTimeStr(b.start_time) + ' - ' + formatTimeStr(b.end_time);
                } else {
                    timeSlot = 'Morning (08:00 AM – 12:00 PM)';
                }
            }

            // Payment Status HTML
            const rawPay = (b.payment_status || '').toLowerCase();
            const isPaid = (rawPay === 'verified' || rawPay === 'paid' || rawPay === 'approved');
            const isPayRejected = (rawPay === 'rejected');
            let payPillHtml = '';
            if (isPaid) {
                payPillHtml = `<span class="bm-pay-pill bm-pay-paid"><i class="fa-solid fa-check"></i> PAID</span>`;
            } else if (isPayRejected) {
                payPillHtml = `<span class="bm-pay-pill bm-pay-rejected"><i class="fa-solid fa-xmark"></i> REJECTED</span>`;
            } else {
                payPillHtml = `<span class="bm-pay-pill bm-pay-unverified"><i class="fa-regular fa-credit-card"></i> UNVERIFIED</span>`;
            }

            // Booking Status HTML
            const rawBook = (b.booking_status || 'pending').toLowerCase();
            const isReschedReq = (b.resched_pending || rawBook === 'reschedule_requested');
            let bookPillHtml = '';
            if (isReschedReq) {
                bookPillHtml = `<span class="bm-book-pill bm-book-resched"><i class="fa-solid fa-arrows-rotate"></i> RESCHEDULE REQUESTED</span>`;
            } else if (rawBook === 'confirmed' || rawBook === 'approved') {
                bookPillHtml = `<span class="bm-book-pill bm-book-confirmed"><i class="fa-solid fa-check"></i> CONFIRMED</span>`;
            } else if (rawBook === 'checked_in') {
                bookPillHtml = `<span class="bm-book-pill bm-book-checkedin"><i class="fa-solid fa-door-open"></i> CHECKED IN</span>`;
            } else if (rawBook === 'checked_out') {
                bookPillHtml = `<span class="bm-book-pill bm-book-checkedout"><i class="fa-solid fa-arrow-right-from-bracket"></i> CHECKED OUT</span>`;
            } else if (rawBook === 'completed') {
                bookPillHtml = `<span class="bm-book-pill bm-book-completed"><i class="fa-solid fa-house-chimney"></i> COMPLETED</span>`;
            } else if (rawBook === 'cancelled') {
                bookPillHtml = `<span class="bm-book-pill bm-book-cancelled"><i class="fa-solid fa-xmark"></i> CANCELLED</span>`;
            } else {
                bookPillHtml = `<span class="bm-book-pill bm-book-pending">• PENDING</span>`;
            }

            // Items Booked list
            const addons = Array.isArray(b.addons) ? b.addons : [];
            let itemsHtml = '';
            
            if ((b.type === 'dining' || b.tag === 'food') && Array.isArray(b.items_parsed) && b.items_parsed.length > 0) {
                b.items_parsed.forEach(it => {
                    const itName = it.name || it.item_name || 'Dish';
                    const itQty = it.qty || it.quantity || 1;
                    const itPrice = it.price ? ` (₱${parseFloat(it.price).toFixed(2)})` : '';
                    const itImg = it.image || b.primary_image || 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=120&q=80';
                    itemsHtml += `
                        <div class="bm-modal-item-card">
                            <img src="${itImg}" alt="${itName}" class="bm-modal-item-img" onerror="this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=120&q=80'">
                            <div>
                                <div class="bm-modal-item-name">${itName}</div>
                                <div class="bm-modal-item-unit">${itQty} order${itQty > 1 ? 's' : ''}${itPrice}</div>
                            </div>
                        </div>
                    `;
                });
            } else {
                // Main accommodation / item
                const mainImg = b.primary_image || 'assets/images/kubo-placeholder.jpg';
                itemsHtml += `
                    <div class="bm-modal-item-card">
                        <img src="${mainImg}" alt="${b.item_name || 'Accommodation'}" class="bm-modal-item-img" onerror="this.src='https://images.unsplash.com/photo-1540555700478-4be289fbecef?auto=format&fit=crop&w=120&q=80'">
                        <div>
                            <div class="bm-modal-item-name">${b.item_name || 'Accommodation'}</div>
                            <div class="bm-modal-item-unit">1 unit</div>
                        </div>
                    </div>
                `;

                // Addons
                addons.forEach((ad, idx) => {
                    if (idx < 2) {
                        const adImg = ad.activity_image || 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=120&q=80';
                        const qty = ad.quantity || 1;
                        itemsHtml += `
                            <div class="bm-modal-item-card">
                                <img src="${adImg}" alt="${ad.activity_name || 'Add-on'}" class="bm-modal-item-img" onerror="this.src='https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=120&q=80'">
                                <div>
                                    <div class="bm-modal-item-name">${ad.activity_name || 'Add-on'}</div>
                                    <div class="bm-modal-item-unit">${qty} unit${qty > 1 ? 's' : ''}</div>
                                </div>
                            </div>
                        `;
                    }
                });

                if (addons.length > 2) {
                    itemsHtml += `
                        <div class="bm-modal-item-more">
                            + ${addons.length - 2} More items
                        </div>
                    `;
                }
            }

            // Remarks
            let remarkText = b.special_notes || b.rejection_reason || b.archive_reason || '';
            if (isReschedReq && b.resched_reason) {
                remarkText = 'Customer requested reschedule to ' + (b.resched_check_in || 'new dates') + ': ' + b.resched_reason;
            } else if (isReschedReq && b.resched_check_in) {
                remarkText = 'Customer requested reschedule to ' + b.resched_check_in + '.';
            }
            if (!remarkText) remarkText = 'No specific remarks.';

            body.innerHTML = `
                <!-- Guest Profile Header -->
                <div class="bm-modal-guest-header">
                    <div class="bm-modal-guest-left">
                        <div class="bm-modal-avatar">
                            <i class="fa-solid fa-user"></i>
                        </div>
                        <div>
                            <h3 class="bm-modal-guest-name">${guestName}</h3>
                            <div class="bm-modal-guest-contact">
                                ${guestPhone ? `<span><i class="fa-solid fa-phone"></i> ${guestPhone}</span>` : ''}
                                ${guestPhone && guestEmail ? `<span>|</span>` : ''}
                                ${guestEmail ? `<span><i class="fa-solid fa-envelope"></i> ${guestEmail}</span>` : ''}
                            </div>
                        </div>
                    </div>
                    <div class="bm-modal-profile-badge">
                        <i class="fa-solid fa-id-badge"></i> ${profileId}
                    </div>
                </div>

                <!-- Side-by-Side Status Cards -->
                <div class="bm-modal-status-grid">
                    <div class="bm-modal-status-card">
                        <div class="bm-modal-status-lbl">Booking Status</div>
                        ${bookPillHtml}
                    </div>
                    <div class="bm-modal-status-card">
                        <div class="bm-modal-status-lbl">Payment Status</div>
                        ${payPillHtml}
                    </div>
                </div>

                <!-- Booking Information -->
                <div class="bm-modal-section-title">Booking Information</div>
                <div class="bm-modal-info-grid">
                    <div class="bm-modal-info-cell">
                        <div class="bm-modal-info-lbl"><i class="fa-regular fa-calendar"></i> Check-in</div>
                        <div class="bm-modal-info-val">${cinInfo.dateStr}</div>
                    </div>
                    <div class="bm-modal-info-cell">
                        <div class="bm-modal-info-lbl"><i class="fa-solid fa-calendar-check"></i> Check-out</div>
                        <div class="bm-modal-info-val">${coutInfo.dateStr}</div>
                    </div>
                    <div class="bm-modal-info-cell">
                        <div class="bm-modal-info-lbl"><i class="fa-regular fa-clock"></i> Time</div>
                        <div class="bm-modal-info-val">${timeSlot}</div>
                    </div>
                    <div class="bm-modal-info-cell">
                        <div class="bm-modal-info-lbl"><i class="fa-solid fa-users"></i> Guests</div>
                        <div class="bm-modal-info-val">${pax} Guests</div>
                    </div>
                </div>

                <!-- Items Booked -->
                <div class="bm-modal-section-title">Items Booked</div>
                <div class="bm-modal-items-grid">
                    ${itemsHtml}
                </div>

                <!-- Payment Summary -->
                <div class="bm-modal-section-title">Payment Summary</div>
                <div class="bm-modal-pay-card">
                    <div>
                        <div style="font-size: 0.78rem; color: #64748b; font-weight: 700;">Total Amount</div>
                        <div class="bm-modal-pay-amount">₱${amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                        ${b.gcash_ref ? `<div style="font-size: 0.76rem; color: #64748b; margin-top: 2px;">GCash Ref: <strong style="color:#0f172a;">${b.gcash_ref}</strong></div>` : ''}
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 0.78rem; color: #64748b; font-weight: 700; margin-bottom: 4px;">Payment Status</div>
                        ${payPillHtml}
                        ${b.payment_proof ? `
                            <div style="margin-top: 6px;">
                                <button type="button" class="admin-btn admin-btn-outline admin-btn-sm" style="font-size: 0.76rem; padding: 3px 8px;" onclick="openPictureModal('${b.payment_proof}', 'Payment Receipt - #${bId}')">
                                    <i class="fa-regular fa-image"></i> View Receipt
                                </button>
                            </div>
                        ` : ''}
                    </div>
                </div>

                <!-- Remarks -->
                <div class="bm-modal-section-title">Remarks</div>
                <div class="bm-modal-remarks-box">
                    <i class="fa-regular fa-comment-dots" style="color: #64748b; margin-top: 2px;"></i>
                    <span>${remarkText}</span>
                </div>
            `;

            let cancelBtn = '';
            if (rawBook !== 'cancelled' && rawBook !== 'checked_out') {
                const safeName = (b.guest_name || '').replace(/'/g, "\\'");
                const safeItem = (b.item_name || '').replace(/'/g, "\\'");
                const safeAmount = '₱' + amount.toLocaleString('en-US', {minimumFractionDigits: 2});
                cancelBtn = `<button type="button" onclick="openCancelModal('${b.id}', '${safeName}', '${safeItem}', '${safeAmount}', 'cancel')" class="admin-btn admin-btn-danger admin-btn-sm" style="background: #fff; border: 1.5px solid #fca5a5; color: #ef4444; font-weight: 700; border-radius: 8px;"><i class="fa-solid fa-ban"></i> Cancel Booking</button>`;
            }

            if (footer) {
                const bSafeJson = JSON.stringify(b).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                footer.innerHTML = `
                    <div style="display: flex; gap: 8px; align-items: center; margin-right: auto; flex-wrap: wrap;">
                        <button type="button" onclick="openActivityTimelineModal(window.currentActiveBooking)" class="admin-btn admin-btn-outline admin-btn-sm" style="border-radius: 8px; font-weight: 700; color: #7c3aed; border-color: #ddd6fe; background: #faf5ff;">
                            <i class="fa-solid fa-clock-rotate-left"></i> Booking Activity
                        </button>
                        ${cancelBtn}
                    </div>
                    <button onclick="window.print()" class="admin-btn admin-btn-outline admin-btn-sm" style="border-radius: 8px; font-weight: 700; color: #475569;"><i class="fa-solid fa-print"></i> Print Voucher</button>
                    <button onclick="closeModal('detailsModal')" class="bm-btn-close-modal">Close</button>
                `;
            }

            document.getElementById('detailsModal').classList.add('active');
        }

        function openReviewRescheduleModal(b) {
            const body = document.getElementById('reviewRescheduleBody');
            if (!body) return;

            const bId = b.id || '';
            const guestName = b.guest_name || 'Guest';
            const guestPhone = b.guest_phone || '';
            const guestEmail = b.guest_email || '';
            const profileId = b.profile_id || ('PBR-GST-' + (b.user_id ? String(b.user_id).padStart(5, '0') : bId));
            const pax = b.pax || b.num_guests || 1;

            // Current dates
            const cinInfo = formatBookingDateTime(b.check_in, '2:00 PM');
            const coutInfo = formatBookingDateTime(b.check_out, '12:00 PM');
            const currentDateRange = `${cinInfo.dateStr} – ${coutInfo.dateStr}`;

            // Requested dates
            let reqInStr = b.resched_check_in || '—';
            let reqOutStr = b.resched_check_out || '';
            let reqDateRange = reqInStr;
            if (reqInStr && reqInStr.includes('-')) {
                const [y, m, d] = reqInStr.split('-');
                const dt = new Date(parseInt(y, 10), parseInt(m, 10) - 1, parseInt(d, 10));
                if (!isNaN(dt.getTime())) {
                    reqInStr = dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                }
            }
            if (reqOutStr && reqOutStr.includes('-')) {
                const [y2, m2, d2] = reqOutStr.split('-');
                const dt2 = new Date(parseInt(y2, 10), parseInt(m2, 10) - 1, parseInt(d2, 10));
                if (!isNaN(dt2.getTime())) {
                    reqOutStr = dt2.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                }
            }
            if (reqOutStr) {
                reqDateRange = `${reqInStr} – ${reqOutStr}`;
            } else {
                reqDateRange = reqInStr;
            }

            // Time slot
            let timeSlot = b.time_slot || '';
            if (!timeSlot) {
                if (b.start_time && b.end_time) {
                    timeSlot = formatTimeStr(b.start_time) + ' - ' + formatTimeStr(b.end_time);
                } else {
                    timeSlot = 'Morning (08:00 AM – 12:00 PM)';
                }
            }

            // Items list summary
            const addons = Array.isArray(b.addons) ? b.addons : [];
            let itemsSummary = (1 + addons.length) + ' Items (' + (b.item_name || 'Beach Kubo');
            if (addons.length > 0) {
                itemsSummary += ', ' + (addons[0].activity_name || 'Addon');
            }
            itemsSummary += ')';

            const reason = b.resched_reason || 'Change in schedule';

            body.innerHTML = `
                <!-- Blue Notice Banner -->
                <div class="bm-resched-notice">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>
                        <div class="bm-resched-notice-title">The guest has requested to reschedule this booking.</div>
                        <div class="bm-resched-notice-sub">Please review the details and confirm or reject the request.</div>
                    </div>
                </div>

                <!-- Guest Profile Header -->
                <div class="bm-modal-guest-header" style="margin-bottom: 1rem;">
                    <div class="bm-modal-guest-left">
                        <div class="bm-modal-avatar">
                            <i class="fa-solid fa-user"></i>
                        </div>
                        <div>
                            <h3 class="bm-modal-guest-name">${guestName}</h3>
                            <div class="bm-modal-guest-contact">
                                ${guestPhone ? `<span><i class="fa-solid fa-phone"></i> ${guestPhone}</span>` : ''}
                                ${guestPhone && guestEmail ? `<span>|</span>` : ''}
                                ${guestEmail ? `<span><i class="fa-solid fa-envelope"></i> ${guestEmail}</span>` : ''}
                            </div>
                        </div>
                    </div>
                    <div class="bm-modal-profile-badge">
                        <i class="fa-solid fa-id-badge"></i> ${profileId}
                    </div>
                </div>

                <!-- Side-by-Side Comparison -->
                <div class="bm-resched-compare-grid">
                    <!-- Current Booking -->
                    <div class="bm-resched-card-current">
                        <div class="bm-resched-card-title" style="color: #475569;">
                            <i class="fa-regular fa-calendar"></i> Current Booking
                        </div>
                        <div class="bm-resched-row">
                            <i class="fa-regular fa-calendar"></i>
                            <span><strong>${currentDateRange}</strong></span>
                        </div>
                        <div class="bm-resched-row">
                            <i class="fa-regular fa-clock"></i>
                            <span>${timeSlot}</span>
                        </div>
                        <div class="bm-resched-row">
                            <i class="fa-solid fa-house"></i>
                            <span>${itemsSummary}</span>
                        </div>
                        <div class="bm-resched-row" style="margin-bottom: 0;">
                            <i class="fa-solid fa-user-group"></i>
                            <span>${pax} Guests</span>
                        </div>
                    </div>

                    <!-- Requested Change -->
                    <div class="bm-resched-card-requested">
                        <div class="bm-resched-card-title" style="color: #166534;">
                            <i class="fa-solid fa-circle-check" style="color: #16a34a;"></i> Requested Change
                        </div>
                        <div class="bm-resched-row" style="color: #14532d;">
                            <i class="fa-solid fa-calendar-days" style="color: #16a34a;"></i>
                            <span><strong>${reqDateRange}</strong></span>
                        </div>
                        <div class="bm-resched-row" style="color: #14532d;">
                            <i class="fa-regular fa-clock" style="color: #16a34a;"></i>
                            <span>${timeSlot}</span>
                        </div>
                        <div class="bm-resched-row" style="color: #14532d;">
                            <i class="fa-solid fa-house" style="color: #16a34a;"></i>
                            <span>${itemsSummary}</span>
                        </div>
                        <div class="bm-resched-row" style="color: #14532d; margin-bottom: 0;">
                            <i class="fa-solid fa-user-group" style="color: #16a34a;"></i>
                            <span>${pax} Guests</span>
                        </div>
                    </div>
                </div>

                <!-- Customer Reason Block -->
                <div class="bm-resched-reason-block">
                    <div class="bm-resched-reason-title">
                        <i class="fa-solid fa-quote-left"></i> Reason
                    </div>
                    <p class="bm-resched-reason-text">"${reason}"</p>
                </div>

                <!-- Actions -->
                <div class="bm-resched-actions">
                    <button type="button" class="bm-btn-reject-resched" onclick="openRejectRescheduleModal('${bId}', '${guestName.replace(/'/g, "\\'")}')">
                        <i class="fa-solid fa-xmark"></i> Reject
                    </button>
                    <a href="bookings.php?action=confirm_reschedule_request&id=${encodeURIComponent(bId)}" class="bm-btn-confirm-resched" style="text-decoration: none; display: inline-flex; align-items: center; gap: 8px;" onclick="return confirm('Confirm and apply this reschedule for booking #${bId}?');">
                        <i class="fa-solid fa-check"></i> Confirm Reschedule
                    </a>
                </div>
            `;

            document.getElementById('reviewRescheduleModal').classList.add('active');
        }

        function openRejectRescheduleModal(id, guestName) {
            closeModal('reviewRescheduleModal');
            document.getElementById('rejectReschedBookingId').value = id;
            document.getElementById('rejectReschedDisplayId').textContent = '#' + id + (guestName ? ' (' + guestName + ')' : '');
            document.getElementById('rejectReschedReasonInput').value = '';
            document.getElementById('rejectRescheduleModal').classList.add('active');
        }

        function openActivityTimelineModal(b) {
            const body = document.getElementById('activityTimelineBody');
            if (!body) return;

            const bId = b.id || '';
            const specialNotes = b.special_notes || '';
            const createdAt = b.created_at ? new Date(b.created_at) : new Date();

            // Parse [Timeline] lines from notes
            const lines = specialNotes.split(/\r?\n|\|/).map(l => l.trim()).filter(Boolean);
            const timelineEntries = [];

            lines.forEach(line => {
                if (line.includes('[Timeline]')) {
                    const clean = line.replace('[Timeline]', '').trim();
                    timelineEntries.push({ text: clean, raw: line });
                }
            });

            let itemsHtml = '';
            if (timelineEntries.length > 0) {
                // Render parsed timeline items
                timelineEntries.reverse().forEach((entry) => {
                    let iconClass = 'success';
                    let icon = 'fa-solid fa-check';
                    if (entry.text.toLowerCase().includes('reject')) {
                        iconClass = 'amber';
                        icon = 'fa-solid fa-xmark';
                    } else if (entry.text.toLowerCase().includes('requested')) {
                        iconClass = 'primary';
                        icon = 'fa-solid fa-arrows-rotate';
                    }

                    const parts = entry.text.split('|').map(p => p.trim());
                    const dateStr = parts[0] || '';
                    const actionStr = parts[1] || entry.text;
                    const extraStr = parts.slice(2).join(' · ');

                    itemsHtml += `
                        <div class="bm-timeline-item">
                            <div class="bm-timeline-node ${iconClass}">
                                <i class="${icon}"></i>
                            </div>
                            <div class="bm-timeline-time">${dateStr}</div>
                            <div class="bm-timeline-title">${actionStr}</div>
                            ${extraStr ? `<div class="bm-timeline-desc">${extraStr}</div>` : ''}
                        </div>
                    `;
                });
            } else {
                // Mockup milestone generation based on booking lifecycle
                const st = (b.booking_status || 'pending').toLowerCase();
                const isPaid = (b.payment_status === 'verified' || b.payment_status === 'paid' || b.payment_status === 'approved');
                const isReschedReq = (b.resched_pending || st === 'reschedule_requested');

                let milestones = [];

                // 1. Reschedule approved or completed (if applicable)
                if (b.rescheduled_from) {
                    milestones.push({
                        type: 'success',
                        icon: 'fa-solid fa-check',
                        time: 'Oct 7, 2026 · 8:50 PM',
                        title: 'Reschedule approved',
                        desc: 'By: Admin'
                    });
                    milestones.push({
                        type: 'primary',
                        icon: 'fa-solid fa-arrows-rotate',
                        time: 'Oct 7, 2026 · 8:42 PM',
                        title: 'Reschedule requested',
                        desc: b.rescheduled_from + ' → ' + (b.check_in ? b.check_in.substring(0, 10) : 'New dates')
                    });
                } else if (isReschedReq) {
                    milestones.push({
                        type: 'primary',
                        icon: 'fa-solid fa-arrows-rotate',
                        time: 'Just now',
                        title: 'Reschedule requested',
                        desc: (b.resched_check_in || 'New dates requested') + (b.resched_reason ? ' (' + b.resched_reason + ')' : '')
                    });
                }

                // 2. Checked in / Checked out
                if (st === 'checked_out' || st === 'completed') {
                    milestones.push({
                        type: 'success',
                        icon: 'fa-solid fa-house-chimney',
                        time: b.check_out || 'Check-out completed',
                        title: 'Guest checked out',
                        desc: 'Stay completed successfully'
                    });
                }
                if (st === 'checked_in' || st === 'checked_out' || st === 'completed') {
                    milestones.push({
                        type: 'primary',
                        icon: 'fa-solid fa-door-open',
                        time: b.check_in || 'Check-in completed',
                        title: 'Guest checked in',
                        desc: 'Front desk verification'
                    });
                }

                // 3. Payment verified
                if (isPaid) {
                    milestones.push({
                        type: 'success',
                        icon: 'fa-solid fa-check',
                        time: 'Payment verified',
                        title: 'Payment verified',
                        desc: b.gcash_ref ? 'GCash Ref: ' + b.gcash_ref : 'Verified by Admin'
                    });
                }

                // 4. Booking confirmed
                milestones.push({
                    type: 'purple',
                    icon: 'fa-regular fa-calendar-check',
                    time: !isNaN(createdAt.getTime()) ? createdAt.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true }) : 'Oct 5, 2026 · 2:20 PM',
                    title: 'Booking confirmed',
                    desc: '#' + bId + ' · ' + (b.item_name || 'Accommodation')
                });

                milestones.forEach(m => {
                    itemsHtml += `
                        <div class="bm-timeline-item">
                            <div class="bm-timeline-node ${m.type}">
                                <i class="${m.icon}"></i>
                            </div>
                            <div class="bm-timeline-time">${m.time}</div>
                            <div class="bm-timeline-title">${m.title}</div>
                            ${m.desc ? `<div class="bm-timeline-desc">${m.desc}</div>` : ''}
                        </div>
                    `;
                });
            }

            body.innerHTML = `
                <div class="bm-timeline-wrap">
                    <div class="bm-timeline-line"></div>
                    ${itemsHtml}
                </div>
            `;

            document.getElementById('activityTimelineModal').classList.add('active');
        }

        function toggleCancelOtherReason(selectEl) {
            const otherGroup = document.getElementById('cancelOtherReasonGroup');
            const otherInput = document.getElementById('cancelOtherReasonInput');
            if (selectEl.value === 'Others') {
                otherGroup.style.display = 'block';
                otherInput.required = true;
                otherInput.focus();
            } else {
                otherGroup.style.display = 'none';
                otherInput.required = false;
            }
        }

        function openCancelModal(id, guestName, itemName, amount, action) {
            closeModal('detailsModal');
            document.getElementById('cancelModalAction').value = action || 'cancel';
            document.getElementById('cancelModalBookingId').value = id;
            document.getElementById('cancelModalRef').textContent = id;
            document.getElementById('cancelModalGuest').textContent = guestName;
            document.getElementById('cancelModalItem').textContent = itemName;
            document.getElementById('cancelModalAmount').textContent = amount;
            document.getElementById('cancelModalHeading').textContent = action === 'cancel_unpaid' ? 'Cancel Unpaid House Reservation' : 'Cancel Reservation';
            const reasonSelect = document.getElementById('cancelReasonSelect');
            reasonSelect.selectedIndex = 0;
            document.getElementById('cancelOtherReasonGroup').style.display = 'none';
            document.getElementById('cancelOtherReasonInput').value = '';
            document.getElementById('cancelOtherReasonInput').required = false;
            document.getElementById('cancelUnpaidModal').classList.add('active');
        }

        function confirmPaymentAndCheckin(id, guestName, amount) {
            if (typeof openActionModal === 'function') {
                openActionModal({
                    type: 'verify',
                    title: 'Verify Payment & Check In',
                    message: `Booking <strong>#${id}</strong> for <strong>${guestName}</strong> (<strong>₱${amount}</strong>) has not been verified yet.<br><br>Do you want to <strong>Verify & Approve</strong> payment now and check the guest in?`,
                    confirmText: '<i class="fa-solid fa-check-double"></i> Yes, Verify & Check In',
                    cancelText: 'Cancel',
                    onConfirm: function () {
                        window.location.href = "bookings.php?view=<?= htmlspecialchars($view_mode) ?>&action=verify_and_checkin&id=" + encodeURIComponent(id);
                    }
                });
            } else if (confirm("⚠️ Payment Verification Required!\n\nBooking #" + id + " for " + guestName + " (₱" + amount + ") has NOT been approved yet.\n\nYou cannot check in without payment approval.\n\nDo you want to VERIFY & APPROVE the payment now and check the guest in?")) {
                window.location.href = "bookings.php?view=<?= htmlspecialchars($view_mode) ?>&action=verify_and_checkin&id=" + encodeURIComponent(id);
            }
        }

        function openPictureModal(imgSrc, title) {
            if (!imgSrc) return;
            document.getElementById('picture_modal_img').src = imgSrc;
            document.getElementById('picture_modal_title').textContent = title || 'Picture Preview';
            document.getElementById('picture_modal').classList.add('active');
        }

        function closePictureModal(e) {
            document.getElementById('picture_modal').classList.remove('active');
        }

        function openRejectModal(ref, guest, amount) {
            document.getElementById('rejectModalRefNo').value = ref;
            document.getElementById('rejectModalRefDisplay').textContent = ref;
            document.getElementById('rejectModalGuest').textContent = guest;
            document.getElementById('rejectModalAmount').textContent = '₱' + amount;
            
            document.getElementById('rejectReasonSelect').selectedIndex = 0;
            document.getElementById('rejectOtherReasonGroup').style.display = 'none';
            document.getElementById('rejectOtherReasonInput').value = '';
            document.getElementById('rejectOtherReasonInput').required = false;
            
            document.getElementById('rejectModal').classList.add('active');
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('active');
        }

        function toggleRejectOtherReason(select) {
            const otherGroup = document.getElementById('rejectOtherReasonGroup');
            const otherInput = document.getElementById('rejectOtherReasonInput');
            if (select.value === 'Others') {
                otherGroup.style.display = 'block';
                otherInput.required = true;
            } else {
                otherGroup.style.display = 'none';
                otherInput.required = false;
                otherInput.value = '';
            }
        }

        function confirmClearHistory() {
            if (typeof showDeleteConfirm === 'function') {
                showDeleteConfirm(
                    'Clear History Confirmation',
                    'Are you sure you want to permanently clear <strong>ALL archived booking history</strong>?<br><span style="color:#f87171; display:block; margin-top:8px; font-size:0.85rem; font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> This will remove all completed & cancelled records. This cannot be undone.</span>',
                    function() {
                        document.getElementById('clearHistoryForm').submit();
                    },
                    'Yes, Clear History'
                );
            } else if (confirm("⚠️ Clear ALL booking history?\n\nThis will remove all archived (completed & cancelled) records from the History view.\n\nThis cannot be undone.")) {
                document.getElementById('clearHistoryForm').submit();
            }
        }

        document.querySelectorAll('.name-only-input').forEach(function(input) {
            input.addEventListener('input', function() {
                this.value = this.value.replace(/[0-9]/g, '');
            });
        });

        document.querySelectorAll('.mobile-09-input').forEach(function(input) {
            input.addEventListener('input', function() {
                let val = this.value.replace(/\D/g, '');
                if (val.length === 1 && val !== '0') {
                    val = '09' + val;
                } else if (val.length === 2 && val !== '09') {
                    val = '09';
                } else if (val.length > 2 && !val.startsWith('09')) {
                    if (val.startsWith('639')) val = '09' + val.substring(3);
                    else if (val.startsWith('9')) val = '09' + val.substring(1);
                    else val = '09' + val.replace(/^0+/, '');
                }
                if (val.length > 11) val = val.substring(0, 11);
                this.value = val;
            });
        });

        // Init Custom 5-Minute Interval Time Pickers (8:00 AM - 10:00 PM) for Manual Walk-In Booking
        function initAdminTimePicker(cfg) {
            const display = document.getElementById(cfg.displayId);
            const text = document.getElementById(cfg.textId);
            const dropdown = document.getElementById(cfg.dropdownId);
            const grid = document.getElementById(cfg.gridId);
            const input = document.getElementById(cfg.inputId);
            if (!display || !text || !dropdown || !grid || !input) return;

            let timesHTML = '';
            for (let h = 8; h <= 22; h++) {
                for (let m = 0; m < 60; m += 5) {
                    if (h === 22 && m > 0) break;
                    const hrString = h.toString().padStart(2, '0');
                    const minString = m.toString().padStart(2, '0');
                    const val = `${hrString}:${minString}`;
                    const ampm = h >= 12 ? 'PM' : 'AM';
                    const displayHr = h % 12 === 0 ? 12 : h % 12;
                    timesHTML += `<div class="time-cell" data-val="${val}">
                        <span class="t-hr">${displayHr}:${minString}</span>
                        <span class="t-ampm">${ampm}</span>
                    </div>`;
                }
            }
            grid.innerHTML = timesHTML;

            function updateDisplay() {
                const val = input.value || cfg.defaultVal || '08:00';
                const parts = val.split(':');
                let hr = parseInt(parts[0], 10);
                const m = parts[1] || '00';
                const ampm = hr >= 12 ? 'PM' : 'AM';
                const dHr = hr % 12 === 0 ? 12 : hr % 12;

                text.textContent = `${dHr}:${m} ${ampm}`;

                grid.querySelectorAll('.time-cell').forEach(cell => {
                    if (cell.dataset.val === val) {
                        cell.setAttribute('data-selected', 'true');
                    } else {
                        cell.removeAttribute('data-selected');
                    }
                });
            }

            display.addEventListener('click', (e) => {
                e.stopPropagation();
                document.querySelectorAll('.custom-time-dropdown').forEach(d => {
                    if (d !== dropdown) d.style.display = 'none';
                });
                document.querySelectorAll('.custom-time-display').forEach(d => {
                    if (d !== display) d.classList.remove('active');
                });

                const isShowing = dropdown.style.display === 'block';
                dropdown.style.display = isShowing ? 'none' : 'block';
                if (isShowing) {
                    display.classList.remove('active');
                } else {
                    display.classList.add('active');
                    const selectedCell = grid.querySelector('.time-cell[data-selected="true"]');
                    if (selectedCell) {
                        dropdown.scrollTop = selectedCell.offsetTop - dropdown.offsetHeight / 2 + 20;
                    }
                }
            });

            grid.addEventListener('click', (e) => {
                const cell = e.target.closest('.time-cell');
                if (cell) {
                    input.value = cell.dataset.val;
                    updateDisplay();
                    dropdown.style.display = 'none';
                    display.classList.remove('active');
                    input.dispatchEvent(new Event('change'));
                }
            });

            document.addEventListener('click', (e) => {
                if (!display.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.style.display = 'none';
                    display.classList.remove('active');
                }
            });

            updateDisplay();
        }

        initAdminTimePicker({
            displayId: 'adminCheckInTimeDisplay',
            textId: 'adminCheckInTimeText',
            dropdownId: 'adminCheckInTimeDropdown',
            gridId: 'adminCheckInTimeGrid',
            inputId: 'modalCheckInTime',
            defaultVal: '14:00'
        });

        initAdminTimePicker({
            displayId: 'adminCheckOutTimeDisplay',
            textId: 'adminCheckOutTimeText',
            dropdownId: 'adminCheckOutTimeDropdown',
            gridId: 'adminCheckOutTimeGrid',
            inputId: 'modalCheckOutTime',
            defaultVal: '12:00'
        });

        // Event listeners for Stay Duration, Check-In, Check-Out, and Pax rate calculations
        const modalCinEl = document.getElementById('modalCheckIn');
        const modalCoutEl = document.getElementById('modalCheckOut');
        const modalActEl = document.getElementById('modalActivitySelect');
        const modalPaxEl = document.querySelector('input[name="pax"]');

        if (modalCinEl) {
            const todayStr = new Date().toISOString().split('T')[0];
            modalCinEl.min = todayStr;
            if (modalCoutEl) modalCoutEl.min = modalCinEl.value || todayStr;
            modalCinEl.addEventListener('change', () => {
                syncCheckoutFromDuration();
                checkWalkinBookingConflicts(false);
            });
        }
        if (modalCoutEl) modalCoutEl.addEventListener('change', () => {
            syncDurationFromDates();
            checkWalkinBookingConflicts(false);
        });
        if (modalActEl) modalActEl.addEventListener('change', onItemOrDateChange);
        const modalKuboEl = document.getElementById('modalItemSelect');
        if (modalKuboEl) modalKuboEl.addEventListener('change', onItemOrDateChange);
        if (modalPaxEl) modalPaxEl.addEventListener('input', updateRateEstimate);

        syncDurationFromDates();
        getCurrentlySelectedWalkinItemId();
        checkWalkinBookingConflicts(false);
    
        // ============================================================
        // BLUEWAVE SAAS INTERACTION SCRIPTS
        // ============================================================
        let currentExtBooking = null;

        function openExtraTimeForBooking(b) {
            currentExtBooking = b;
            const card = document.getElementById('bmExtraTimeCard');
            if (!card) return;
            card.style.display = 'block';

            document.getElementById('extInputBookingId').value = b.id || '';
            document.getElementById('extItemName').textContent = b.item_name || 'Activity';
            document.getElementById('extItemPax').textContent = (b.pax || b.num_guests || 1) + ' Guest' + ((b.pax || b.num_guests || 1) > 1 ? 's' : '');
            document.getElementById('extGuestName').innerHTML = '<i class="fa-solid fa-user"></i> ' + (b.guest_name || 'Guest');
            
            const startT = b.start_time ? formatTimeStr(b.start_time) : '2:00 PM';
            const endT = b.end_time ? formatTimeStr(b.end_time) : '3:00 PM';
            document.getElementById('extStartTime').innerHTML = '<i class="fa-regular fa-clock"></i> ' + startT;
            document.getElementById('extEndTime').innerHTML = '<i class="fa-regular fa-clock"></i> ' + endT;

            const baseAmount = parseFloat(b.total_amount) || 0;
            document.getElementById('extCurrentAmount').textContent = '₱' + baseAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            selectExtraOption(15, 100);
            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function formatTimeStr(dateOrTime) {
            try {
                let d = new Date(dateOrTime);
                if (isNaN(d.getTime())) {
                    return dateOrTime;
                }
                return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
            } catch(e) {
                return dateOrTime;
            }
        }

        function selectExtraOption(mins, fee) {
            document.getElementById('extInputMins').value = mins;
            document.getElementById('extInputFee').value = fee.toFixed(2);

            ['15', '30', '60'].forEach(m => {
                const el = document.getElementById('optCard' + m);
                if (el) el.classList.remove('active');
            });
            const activeEl = document.getElementById('optCard' + mins);
            if (activeEl) activeEl.classList.add('active');

            const baseMins = 60;
            const newDuration = baseMins + mins;
            document.getElementById('extSummaryDuration').textContent = newDuration + ' mins';
            document.getElementById('extSummaryFee').textContent = '₱' + fee.toFixed(2);

            let baseAmount = 500;
            if (currentExtBooking && currentExtBooking.total_amount) {
                baseAmount = parseFloat(currentExtBooking.total_amount);
            }
            const newTotal = baseAmount + fee;
            document.getElementById('extSummaryTotal').textContent = '₱' + newTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function toggleRowMenu(event, rowId) {
            event.stopPropagation();
            const allMenus = document.querySelectorAll('.bm-dropdown-menu');
            allMenus.forEach(m => {
                if (m.id !== 'menu-' + rowId) {
                    m.style.display = 'none';
                }
            });
            const targetMenu = document.getElementById('menu-' + rowId);
            if (targetMenu) {
                targetMenu.style.display = (targetMenu.style.display === 'block') ? 'none' : 'block';
            }
        }

        document.addEventListener('click', function() {
            document.querySelectorAll('.bm-dropdown-menu').forEach(m => m.style.display = 'none');
        });

        function toggleExtraFilterPanel() {
            const p = document.getElementById('bmExtraFilterDrawer');
            if (p) {
                p.style.display = (p.style.display === 'none' || p.style.display === '') ? 'flex' : 'none';
            }
        }

        function instantSearchRows(query) {
            const q = query.trim().toLowerCase();
            const rows = document.querySelectorAll('#bmBookingsTable tbody tr.bm-table-row');
            rows.forEach(r => {
                const text = r.innerText.toLowerCase();
                r.style.display = text.includes(q) ? '' : 'none';
            });
        }

        // ============================================================
        // RESCHEDULE BOOKING LOGIC
        // ============================================================
        let currentReschedBooking = null;

        function openRescheduleModal(b) {
            if (b.resched_pending == 1 || b.booking_status === 'reschedule_requested') {
                alert('Booking #' + (b.id || '') + ' currently has a pending reschedule request. Please click "Review Reschedule" to verify and approve or decline it.');
                return;
            }
            const rCount = parseInt(b.resched_count || 0, 10);
            if (rCount >= 2) {
                alert('This booking (#' + (b.id || '') + ') has already reached the maximum limit of 2 reschedules.');
                return;
            }
            const countEl = document.getElementById('reschedDisplayCount');
            if (countEl) {
                countEl.textContent = `${rCount} / 2`;
            }

            currentReschedBooking = b;
            const bId = b.id || '';
            const guestName = b.guest_name || 'Guest';
            const itemName = b.item_name || 'Accommodation';
            const origTotal = parseFloat(b.total_amount || 0);

            document.getElementById('reschedBookingId').value = bId;
            document.getElementById('reschedDisplayRef').textContent = '#' + bId;
            document.getElementById('reschedDisplayGuest').textContent = guestName;
            document.getElementById('reschedDisplayUnit').textContent = itemName;
            document.getElementById('reschedDisplayOrigTotal').textContent = '₱' + origTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            // Format dates
            let cinDate = '<?= date("Y-m-d") ?>';
            let cinTime = '14:00';
            if (b.check_in) {
                const parts = b.check_in.split(' ');
                cinDate = parts[0] || cinDate;
                if (parts[1]) {
                    cinTime = parts[1].substring(0, 5);
                }
            }

            let coutDate = '';
            let coutTime = '12:00';
            if (b.check_out) {
                const parts = b.check_out.split(' ');
                coutDate = parts[0] || '';
                if (parts[1]) {
                    coutTime = parts[1].substring(0, 5);
                }
            }
            if (!coutDate) {
                const d = new Date(cinDate);
                d.setDate(d.getDate() + 1);
                coutDate = d.toISOString().split('T')[0];
            }

            document.getElementById('reschedDisplayDates').textContent = `${cinDate} (${cinTime}) → ${coutDate} (${coutTime})`;

            const inInput = document.getElementById('reschedCheckInDate');
            inInput.value = cinDate;
            document.getElementById('reschedCheckInTime').value = cinTime || '14:00';

            const outInput = document.getElementById('reschedCheckOutDate');
            outInput.value = coutDate;
            document.getElementById('reschedCheckOutTime').value = coutTime || '12:00';

            // Match Kubo
            const kuboSelect = document.getElementById('reschedKuboSelect');
            let matched = false;
            for (let i = 0; i < kuboSelect.options.length; i++) {
                const opt = kuboSelect.options[i];
                if (opt.value == b.item_id || opt.getAttribute('data-name') === b.item_name) {
                    kuboSelect.selectedIndex = i;
                    matched = true;
                    break;
                }
            }
            if (!matched && kuboSelect.options.length > 0) {
                kuboSelect.selectedIndex = 0;
            }

            // Sync stay nights
            syncRescheduleNightsFromDates();

            // Reset reason
            document.getElementById('reschedReasonSelect').selectedIndex = 0;
            document.getElementById('reschedOtherReasonWrap').style.display = 'none';
            document.getElementById('reschedOtherReasonInput').value = '';
            document.getElementById('reschedConfirmCheckbox').checked = true;

            // Recalculate
            onRescheduleChange();

            document.getElementById('rescheduleModal').classList.add('active');
        }

        function onRescheduleCheckInChange() {
            const inDateVal = document.getElementById('reschedCheckInDate').value;
            const nights = getRescheduleSelectedNights();
            if (inDateVal && nights > 0) {
                const d = new Date(inDateVal + 'T00:00:00');
                d.setDate(d.getDate() + nights);
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                document.getElementById('reschedCheckOutDate').value = `${y}-${m}-${day}`;
            }
            onRescheduleChange();
        }

        function onRescheduleCheckOutChange() {
            syncRescheduleNightsFromDates();
            onRescheduleChange();
        }

        function onRescheduleNightsChange() {
            const sel = document.getElementById('reschedStayNights').value;
            if (sel !== 'custom') {
                const nights = parseInt(sel, 10);
                const inDateVal = document.getElementById('reschedCheckInDate').value;
                if (inDateVal) {
                    const d = new Date(inDateVal + 'T00:00:00');
                    d.setDate(d.getDate() + nights);
                    const y = d.getFullYear();
                    const m = String(d.getMonth() + 1).padStart(2, '0');
                    const day = String(d.getDate()).padStart(2, '0');
                    document.getElementById('reschedCheckOutDate').value = `${y}-${m}-${day}`;
                }
            }
            onRescheduleChange();
        }

        function getRescheduleSelectedNights() {
            const inVal = document.getElementById('reschedCheckInDate').value;
            const outVal = document.getElementById('reschedCheckOutDate').value;
            if (inVal && outVal) {
                const dIn = new Date(inVal + 'T00:00:00');
                const dOut = new Date(outVal + 'T00:00:00');
                return Math.max(1, Math.round((dOut - dIn) / 86400000));
            }
            return 1;
        }

        function syncRescheduleNightsFromDates() {
            const inVal = document.getElementById('reschedCheckInDate').value;
            const outVal = document.getElementById('reschedCheckOutDate').value;
            if (!inVal || !outVal) return;

            const dIn = new Date(inVal + 'T00:00:00');
            const dOut = new Date(outVal + 'T00:00:00');
            let diff = Math.round((dOut - dIn) / 86400000);
            if (diff < 1) {
                diff = 1;
                const d = new Date(dIn);
                d.setDate(d.getDate() + 1);
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                document.getElementById('reschedCheckOutDate').value = `${y}-${m}-${day}`;
            }

            const sel = document.getElementById('reschedStayNights');
            let matched = false;
            for (let i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value === String(diff)) {
                    sel.selectedIndex = i;
                    matched = true;
                    break;
                }
            }
            if (!matched) {
                sel.value = 'custom';
            }
        }

        function onRescheduleChange() {
            const kuboSel = document.getElementById('reschedKuboSelect');
            const opt = kuboSel.options[kuboSel.selectedIndex];
            if (!opt) return;

            const price = parseFloat(opt.getAttribute('data-price') || 3500);
            const itemName = opt.getAttribute('data-name') || opt.text.trim();
            const nights = getRescheduleSelectedNights();
            
            // Diff & preserve price for rescheduled booking
            let origTotal = 0;
            if (currentReschedBooking && currentReschedBooking.total_amount) {
                origTotal = parseFloat(currentReschedBooking.total_amount);
            }
            const isSameUnit = (currentReschedBooking && (opt.value == currentReschedBooking.item_id || opt.getAttribute('data-name') === currentReschedBooking.item_name));
            const newTotal = (isSameUnit && origTotal > 0) ? origTotal : (price * nights);

            document.getElementById('reschedNewItemName').value = itemName;
            document.getElementById('reschedNewTotalAmount').value = newTotal.toFixed(2);

            document.getElementById('reschedCalcDuration').textContent = nights + (nights === 1 ? ' Night' : ' Nights');
            document.getElementById('reschedCalcRate').textContent = '₱' + price.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('reschedCalcNewTotal').textContent = '₱' + newTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            const diff = newTotal - origTotal;
            const diffEl = document.getElementById('reschedCalcDiff');
            if (Math.abs(diff) < 0.01) {
                diffEl.textContent = '₱0.00 (No change)';
                diffEl.style.color = '#94a3b8';
            } else if (diff > 0) {
                diffEl.textContent = '+₱' + diff.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' (Additional payable)';
                diffEl.style.color = '#f59e0b';
            } else {
                diffEl.textContent = '-₱' + Math.abs(diff).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' (Credit adjustment)';
                diffEl.style.color = '#a78bfa';
            }

            // Real-time server-side availability check
            const inDate = document.getElementById('reschedCheckInDate').value;
            const outDate = document.getElementById('reschedCheckOutDate').value;
            const kid = opt.value;
            const badge = document.getElementById('reschedAvailBadge');
            const warn = document.getElementById('reschedConflictWarning');
            const btn = document.getElementById('reschedSubmitBtn');

            if (inDate && outDate) {
                badge.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Checking...';
                badge.className = 'admin-badge admin-badge-purple';

                fetch(`api_check_room_availability.php?check_in=${encodeURIComponent(inDate)}&check_out=${encodeURIComponent(outDate)}`)
                    .then(res => res.json())
                    .then(data => {
                        let isRoomAvail = true;
                        if (data && data.rooms) {
                            const found = data.rooms.find(r => r.kubo_id == kid);
                            if (found) {
                                isRoomAvail = (found.units_left > 0) || (currentReschedBooking && currentReschedBooking.item_id == kid);
                            }
                        }
                        if (isRoomAvail) {
                            badge.innerHTML = '<i class="fa-solid fa-circle-check"></i> Unit Available';
                            badge.className = 'admin-badge admin-badge-success';
                            warn.style.display = 'none';
                            btn.disabled = false;
                        } else {
                            badge.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Fully Booked / Conflict';
                            badge.className = 'admin-badge admin-badge-danger';
                            warn.style.display = 'block';
                        }
                    })
                    .catch(() => {
                        badge.innerHTML = '<i class="fa-solid fa-circle-check"></i> Available';
                        badge.className = 'admin-badge admin-badge-success';
                        warn.style.display = 'none';
                    });
            }
        }

        function toggleReschedOtherReason(selectEl) {
            const wrap = document.getElementById('reschedOtherReasonWrap');
            const inp = document.getElementById('reschedOtherReasonInput');
            if (selectEl.value === 'Others') {
                wrap.style.display = 'block';
                inp.required = true;
                inp.focus();
            } else {
                wrap.style.display = 'none';
                inp.required = false;
                inp.value = '';
            }
        }

        function handleRescheduleSubmit(e) {
            const inDate = document.getElementById('reschedCheckInDate').value;
            const outDate = document.getElementById('reschedCheckOutDate').value;
            const inTime = document.getElementById('reschedCheckInTime').value;
            const outTime = document.getElementById('reschedCheckOutTime').value;
            const unit = document.getElementById('reschedNewItemName').value;
            const newTotal = document.getElementById('reschedNewTotalAmount').value;
            const guest = document.getElementById('reschedDisplayGuest').textContent;
            const ref = document.getElementById('reschedDisplayRef').textContent;

            const fullIn = new Date(`${inDate}T${inTime}:00`);
            const fullOut = new Date(`${outDate}T${outTime}:00`);

            if (fullOut <= fullIn) {
                if (typeof openActionModal === 'function') {
                    openActionModal({
                        type: 'warning',
                        title: 'Invalid Reschedule Dates',
                        message: 'Check-out date and time must be strictly after the check-in date!',
                        confirmText: 'OK',
                        cancelText: 'Close'
                    });
                } else {
                    alert("⚠️ Check-out date and time must be strictly after the check-in date!");
                }
                if (e) e.preventDefault();
                return false;
            }

            const formattedTotal = parseFloat(newTotal || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
            const msgHtml = `
                <div style="text-align: left; background: rgba(15,23,42,0.6); padding: 12px 14px; border-radius: 10px; border: 1px solid #334155; margin-bottom: 10px; font-size: 0.9rem;">
                    <div style="margin-bottom: 5px;"><strong>Booking:</strong> #${ref} (${guest})</div>
                    <div style="margin-bottom: 5px;"><strong>New Accommodation:</strong> ${unit}</div>
                    <div style="margin-bottom: 5px;"><strong>New Dates:</strong> ${inDate} (${inTime}) &rarr; ${outDate} (${outTime})</div>
                    <div style="color: #fbbf24; font-weight: 700;"><strong>New Total:</strong> ₱${formattedTotal}</div>
                </div>
                <span>Do you want to confirm and save these changes?</span>
            `;

            if (typeof openActionModal === 'function') {
                if (e) e.preventDefault();
                openActionModal({
                    type: 'reschedule',
                    title: 'Reschedule Confirmation',
                    message: msgHtml,
                    confirmText: '<i class="fa-solid fa-check"></i> Yes, Apply Reschedule',
                    cancelText: 'Cancel',
                    onConfirm: function() {
                        const form = document.getElementById('rescheduleBookingForm');
                        if (form) form.submit();
                    }
                });
                return false;
            }

            if (!confirm(`Confirm reschedule for booking #${ref} (${guest})?`)) {
                if (e) e.preventDefault();
                return false;
            }
            return true;
        }

</script>
<script src="assets/manage-booking-actions.js?v=<?= time() ?>"></script>
</body>
</html>
