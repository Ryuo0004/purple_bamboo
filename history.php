<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/booking_store.php';
require_once __DIR__ . '/dining_store.php';
require_once __DIR__ . '/inventory_store.php';
require_once __DIR__ . '/auth.php';

// Require logged-in guest session
if (empty($_SESSION['user_id'])) {
    header("Location: login.php?msg=" . urlencode("Please sign in first to access your bookings.") . "&redirect=history.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$email = $_SESSION['email'] ?? '';
$profile_id = $_SESSION['profile_id'] ?? '';
$first_name = $_SESSION['first_name'] ?? 'Guest';
$user_role = $_SESSION['role'] ?? 'guest';

// Verify ownership helper
function verifyBookingOwnership($booking, $user_id, $email, $profile_id) {
    if (!$booking) return false;
    $b_uid = $booking['user_id'] ?? null;
    $b_email = strtolower(trim($booking['guest_email'] ?? ''));
    $b_pid = $booking['profile_id'] ?? '';
    
    if (!empty($user_id) && $b_uid == $user_id) return true;
    if (!empty($profile_id) && $b_pid === $profile_id) return true;
    if (!empty($email) && $b_email === strtolower(trim($email))) return true;
    return false;
}

// -------------------------------------------------------------
// AJAX: Check Availability & Price Difference for Reschedule
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'check_reschedule_avail') {
    header('Content-Type: application/json');
    $ref = trim($_GET['ref'] ?? '');
    $new_check_in = trim($_GET['check_in'] ?? '');
    $new_check_out = trim($_GET['check_out'] ?? '');

    $booking = BookingStore::getBookingById($ref);
    if (!$booking || !verifyBookingOwnership($booking, $user_id, $email, $profile_id)) {
        echo json_encode(['success' => false, 'error' => 'Booking not found or unauthorized']);
        exit();
    }

    if (!empty($booking['resched_pending']) || ($booking['booking_status'] ?? '') === 'reschedule_requested') {
        echo json_encode(['success' => false, 'error' => 'A reschedule request is already pending verification. You cannot reschedule again until it has been approved by admin.']);
        exit();
    }

    if (intval($booking['resched_count'] ?? 0) >= 2) {
        echo json_encode(['success' => false, 'error' => 'This reservation has already reached the maximum limit of 2 reschedules.']);
        exit();
    }

    $created_ts = !empty($booking['created_at']) ? strtotime($booking['created_at']) : 0;
    if ($created_ts > 0 && (time() - $created_ts) > (3 * 86400)) {
        echo json_encode(['success' => false, 'error' => 'This reservation can no longer be rescheduled because more than 3 days have passed since it was booked. Rescheduling is only permitted within 3 days of booking.']);
        exit();
    }

    if (empty($new_check_in)) {
        echo json_encode(['success' => false, 'error' => 'Check-in date is required']);
        exit();
    }

    $today = date('Y-m-d');
    if ($new_check_in < $today) {
        echo json_encode(['success' => false, 'error' => 'New check-in date cannot be in the past']);
        exit();
    }

    $type = $booking['type'] ?? 'house';
    $item_id = intval($booking['item_id'] ?? 1);

    if ($type === 'house') {
        if (empty($new_check_out) || $new_check_out <= $new_check_in) {
            $old_in = !empty($booking['check_in']) ? date('Y-m-d', strtotime($booking['check_in'])) : $today;
            $old_out = !empty($booking['check_out']) ? date('Y-m-d', strtotime($booking['check_out'])) : date('Y-m-d', strtotime($old_in . ' +1 day'));
            $orig_nights = max(1, round((strtotime($old_out) - strtotime($old_in)) / 86400));
            $new_check_out = date('Y-m-d', strtotime($new_check_in . " +{$orig_nights} days"));
        }
        $isAvailable = BookingStore::isItemAvailable($item_id, $new_check_in, $new_check_out, 'house', $booking['id']);
    } else {
        if (empty($new_check_out)) {
            $new_check_out = $new_check_in;
        }
        $isAvailable = BookingStore::isItemAvailable($item_id, $new_check_in, $new_check_out, 'activity', $booking['id']);
    }

    // Rescheduling retains the exact original total price
    $current_total = floatval($booking['total_amount'] ?? 0);
    $new_total = $current_total;
    $diff = 0;
    $diff_text = '₱0.00 (No change)';

    echo json_encode([
        'success' => true,
        'available' => $isAvailable,
        'current_amount' => $current_total,
        'new_amount' => $new_total,
        'diff' => $diff,
        'diff_text' => $diff_text,
        'message' => $isAvailable ? 'Selected dates are available!' : 'Unit is fully booked for these dates. Please choose different dates.'
    ]);
    exit();
}

// -------------------------------------------------------------
// POST: Process Reschedule
// -------------------------------------------------------------
$flash_msg = '';
$flash_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reschedule_booking') {
    $ref = trim($_POST['ref'] ?? '');
    $new_check_in = trim($_POST['new_check_in'] ?? '');
    $new_check_out = trim($_POST['new_check_out'] ?? '');
    $new_time_slot = trim($_POST['new_time_slot'] ?? '');
    $reschedule_reason = trim($_POST['reschedule_reason'] ?? 'Customer requested reschedule via portal');

    $booking = BookingStore::getBookingById($ref);
    if (!$booking || !verifyBookingOwnership($booking, $user_id, $email, $profile_id)) {
        $flash_msg = 'Unable to reschedule: Booking not found or unauthorized.';
        $flash_type = 'danger';
    } else {
        $status_raw = strtolower(trim($booking['booking_status'] ?? 'pending'));
        $created_ts = !empty($booking['created_at']) ? strtotime($booking['created_at']) : 0;
        $is_resched_expired = ($created_ts > 0 && (time() - $created_ts) > (3 * 86400));

        if (!empty($booking['resched_pending']) || $status_raw === 'reschedule_requested') {
            $flash_msg = 'Cannot reschedule: A reschedule request for Booking #' . htmlspecialchars($booking['id']) . ' is already awaiting admin verification. You cannot reschedule again until it has been approved.';
            $flash_type = 'warning';
        } elseif (in_array($status_raw, ['completed', 'checked_out', 'cancelled', 'rejected', 'forfeited'])) {
            $flash_msg = 'This reservation cannot be rescheduled because it is already ' . htmlspecialchars($status_raw) . '.';
            $flash_type = 'danger';
        } elseif (intval($booking['resched_count'] ?? 0) >= 2) {
            $flash_msg = 'This reservation cannot be rescheduled because it has already reached the maximum limit of 2 reschedules.';
            $flash_type = 'danger';
        } elseif ($is_resched_expired) {
            $flash_msg = 'This reservation cannot be rescheduled because more than 3 days have passed since it was booked. Rescheduling is only permitted within 3 days of booking.';
            $flash_type = 'danger';
        } else {
            $type = $booking['type'] ?? 'house';
            $item_id = intval($booking['item_id'] ?? 1);
            if ($type === 'house') {
                if (empty($new_check_out) || $new_check_out <= $new_check_in) {
                    $old_in = !empty($booking['check_in']) ? date('Y-m-d', strtotime($booking['check_in'])) : date('Y-m-d');
                    $old_out = !empty($booking['check_out']) ? date('Y-m-d', strtotime($booking['check_out'])) : date('Y-m-d', strtotime($old_in . ' +1 day'));
                    $orig_nights = max(1, round((strtotime($old_out) - strtotime($old_in)) / 86400));
                    $new_check_out = date('Y-m-d', strtotime($new_check_in . " +{$orig_nights} days"));
                }
            } else {
                if (empty($new_check_out)) $new_check_out = $new_check_in;
            }

            // Availability check
            $isAvail = BookingStore::isItemAvailable($item_id, $new_check_in, $new_check_out, $type, $booking['id']);
            if (!$isAvail) {
                $flash_msg = 'The selected schedule is no longer available. Please select another date.';
                $flash_type = 'danger';
            } else {
                // Submit request for Admin verification (price remains identical)
                $res = BookingStore::requestReschedule(
                    $booking['id'],
                    $new_check_in,
                    $new_check_out,
                    $new_time_slot ?: ($booking['time_slot'] ?? ''),
                    $reschedule_reason
                );

                if (!empty($res['success'])) {
                    header("Location: history.php?msg=" . urlencode("Reschedule request for Booking #" . $booking['id'] . " was submitted! Our resort admin will verify and confirm it shortly."));
                    exit();
                } else {
                    $flash_msg = $res['error'] ?? 'Failed to submit reschedule request.';
                    $flash_type = 'danger';
                }
            }
        }
    }
}

// -------------------------------------------------------------
// POST: Process Cancellation (Disabled via Portal)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_booking') {
    $flash_msg = 'Online cancellation is disabled. Please contact the resort front desk for assistance or reschedule your reservation dates.';
    $flash_type = 'warning';
}

// -------------------------------------------------------------
// POST: Process Resubmit Payment Proof (When Payment Rejected)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reupload_payment_proof') {
    $ref = trim($_POST['ref'] ?? '');
    $gref = trim($_POST['gcash_ref'] ?? '');

    $booking = BookingStore::getBookingById($ref);
    if (!$booking || !verifyBookingOwnership($booking, $user_id, $email, $profile_id)) {
        $flash_msg = 'Unable to resubmit payment proof: Booking not found or unauthorized.';
        $flash_type = 'danger';
    } else {
        $clean_gref = preg_replace('/[^0-9]/', '', $gref);
        $clean_gref = substr($clean_gref, 0, 13);

        if (empty($clean_gref) || strlen($clean_gref) !== 13) {
            $flash_msg = 'Please enter a valid 13-digit GCash Reference Number (e.g. 1002938475612).';
            $flash_type = 'danger';
        } elseif (empty($_FILES['proof_image']) || $_FILES['proof_image']['error'] !== UPLOAD_ERR_OK) {
            $flash_msg = 'Please upload a clear screenshot or photo of your official GCash transaction receipt.';
            $flash_type = 'danger';
        } else {
            $upload_dir = __DIR__ . '/uploads/receipts/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $file_info = pathinfo($_FILES['proof_image']['name']);
            $ext = strtolower($file_info['extension'] ?? 'jpg');
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];

            if (!in_array($ext, $allowed)) {
                $flash_msg = 'Only image files (JPG, PNG, WEBP) are allowed as valid payment proof.';
                $flash_type = 'danger';
            } else {
                $safe_ref = preg_replace('/[^A-Za-z0-9_-]/', '', $ref);
                $new_filename = 'receipt_' . $safe_ref . '_' . time() . '.' . $ext;
                $target_file = $upload_dir . $new_filename;

                if (move_uploaded_file($_FILES['proof_image']['tmp_name'], $target_file)) {
                    $proof_path = 'uploads/receipts/' . $new_filename;
                    // Reset status to proof_submitted, record resubmission, and archive previous rejection reason
                    BookingStore::resubmitPaymentProof($ref, $clean_gref, $proof_path);
                    header("Location: history.php?msg=" . urlencode("New GCash payment proof for Booking #{$ref} was submitted successfully! Our front desk team is reviewing it for immediate confirmation."));
                    exit();
                } else {
                    $flash_msg = 'There was an issue uploading your receipt. Please try again.';
                    $flash_type = 'danger';
                }
            }
        }
    }
}

if (!empty($_GET['msg'])) {
    $flash_msg = $_GET['msg'];
    $flash_type = 'success';
}

// -------------------------------------------------------------
// Fetch & Categorize User Bookings
// -------------------------------------------------------------
$all_b = BookingStore::getAllBookings();
$user_bookings = array_values(array_filter($all_b, function($b) use ($user_id, $email, $profile_id) {
    return (($b['user_id'] ?? null) == $user_id) 
        || (!empty($b['profile_id']) && $b['profile_id'] === $profile_id)
        || (!empty($email) && strtolower(trim($b['guest_email'] ?? '')) === strtolower(trim($email)));
}));

// Pre-fetch add-ons for multi-item display
$addons_by_booking = [];
if (!empty($user_bookings)) {
    global $pdo;
    if ($pdo) {
        $b_ids = array_column($user_bookings, 'id');
        if (!empty($b_ids)) {
            $placeholders = implode(',', array_fill(0, count($b_ids), '?'));
            try {
                $stmt = $pdo->prepare("
                    SELECT ar.*, a.activity_name, a.price, a.duration 
                    FROM addon_reservations ar 
                    LEFT JOIN activities a ON ar.activity_id = a.activity_id 
                    WHERE ar.reservation_id IN ($placeholders)
                ");
                $stmt->execute($b_ids);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $addons_by_booking[$r['reservation_id']][] = $r;
                }
            } catch (Exception $e) {}
        }
    }
}

// Categorize into Upcoming & Active vs History
$upcoming_bookings = [];
$history_bookings = [];

foreach ($user_bookings as $b) {
    $raw_st = strtolower(trim($b['booking_status'] ?? 'pending'));
    $raw_pay = strtolower(trim($b['payment_status'] ?? ''));
    
    // Check for forfeiture of rebooking window
    if (!empty($b['rebook_deadline']) && empty($b['is_forfeited'])) {
        if (strtotime($b['rebook_deadline']) < time()) {
            $b['is_forfeited'] = 1;
            BookingStore::updateBookingStatus($b['id'], null, null, null, null, null, null, null, 1);
        }
    }

    // Grouping: If payment was rejected, keep in Upcoming/Active so guest can immediately resolve it
    if ($raw_pay === 'rejected' || in_array($raw_st, ['pending', 'confirmed', 'approved', 'active', 'checked_in', 'reschedule_requested']) || !empty($b['resched_pending'])) {
        $upcoming_bookings[] = $b;
    } else {
        $history_bookings[] = $b;
    }
}

// Sort Upcoming by check_in ASC (nearest first) or created_at DESC
usort($upcoming_bookings, function($a, $b) {
    $a_time = !empty($a['check_in']) ? strtotime($a['check_in']) : strtotime($a['created_at'] ?? 'now');
    $b_time = !empty($b['check_in']) ? strtotime($b['check_in']) : strtotime($b['created_at'] ?? 'now');
    return $a_time - $b_time;
});

// Sort History by check_in DESC (most recent first)
usort($history_bookings, function($a, $b) {
    $a_time = !empty($a['check_in']) ? strtotime($a['check_in']) : strtotime($a['created_at'] ?? 'now');
    $b_time = !empty($b['check_in']) ? strtotime($b['check_in']) : strtotime($b['created_at'] ?? 'now');
    return $b_time - $a_time;
});

// Fetch Dining Orders
$user_dining = DiningStore::getAllOrders(['user_email' => $email]);

// Helper for normalized booking status display
function formatBookingStatus($raw) {
    $s = strtolower(trim($raw ?? 'pending'));
    if ($s === 'reschedule_requested') {
        return ['key' => 'reschedule_requested', 'label' => 'RESCHEDULE PENDING', 'class' => 'status-resched'];
    }
    if (in_array($s, ['active', 'checked_in'])) {
        return ['key' => 'active', 'label' => 'ACTIVE', 'class' => 'status-active'];
    }
    if (in_array($s, ['confirmed', 'approved'])) {
        return ['key' => 'confirmed', 'label' => 'CONFIRMED', 'class' => 'status-confirmed'];
    }
    if (in_array($s, ['completed', 'checked_out'])) {
        return ['key' => 'completed', 'label' => 'COMPLETED', 'class' => 'status-completed'];
    }
    if (in_array($s, ['cancelled', 'rejected', 'forfeited'])) {
        return ['key' => 'cancelled', 'label' => 'CANCELLED', 'class' => 'status-cancelled'];
    }
    return ['key' => 'pending', 'label' => 'PENDING', 'class' => 'status-pending'];
}

// Helper for normalized payment status display
function formatPaymentStatus($raw, $b = null) {
    $s = strtolower(trim($raw ?? ''));
    if (in_array($s, ['verified', 'paid', 'approved'])) {
        return ['label' => 'VERIFIED', 'class' => 'payment-verified'];
    }
    if ($s === 'rejected') {
        return ['label' => 'REJECTED', 'class' => 'payment-rejected'];
    }
    if ($b && (!empty($b['resubmitted_at']) || !empty($b['prev_rejection_reason'])) && in_array($s, ['proof_submitted', 'pending'])) {
        return ['label' => 'RESUBMITTED (UNDER REVIEW)', 'class' => 'payment-pending'];
    }
    return ['label' => 'PENDING', 'class' => 'payment-pending'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings | Purple Bamboo Resort</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Modern, Clean Customer Bookings Layout */
        .bookings-page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.75rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--border-color, #e2e8f0);
        }

        .booking-tabs-nav {
            display: flex;
            gap: 0.6rem;
            margin-bottom: 1.75rem;
            flex-wrap: wrap;
        }

        .booking-tab-btn {
            background: #ffffff;
            color: var(--text-main, #334155);
            border: 1.5px solid var(--border-color, #e2e8f0);
            padding: 0.65rem 1.25rem;
            border-radius: 9999px;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .booking-tab-btn:hover {
            border-color: var(--primary-purple, #6b21a8);
            color: var(--primary-purple, #6b21a8);
            background: #faf5ff;
        }

        .booking-tab-btn.active {
            background: linear-gradient(135deg, var(--primary-purple, #6b21a8), #8b5cf6);
            color: #ffffff;
            border-color: transparent;
            box-shadow: 0 4px 12px rgba(107, 33, 168, 0.25);
        }

        .tab-badge {
            background: rgba(0, 0, 0, 0.08);
            color: inherit;
            font-size: 0.72rem;
            padding: 2px 7px;
            border-radius: 9999px;
            font-weight: 800;
        }

        .booking-tab-btn.active .tab-badge {
            background: rgba(255, 255, 255, 0.28);
            color: #ffffff;
        }

        /* Booking Cards Grid / Rows */
        .booking-cards-list {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .booking-card {
            background: #ffffff;
            border: 1.5px solid var(--border-color, #e2e8f0);
            border-radius: 14px;
            padding: 1.35rem 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
            position: relative;
        }

        .booking-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
        }

        .booking-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 0.85rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px dashed #f1f5f9;
        }

        .booking-ref-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--primary-purple, #581c87);
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        /* Status Badges */
        .status-badge {
            font-size: 0.75rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            padding: 0.3rem 0.75rem;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            text-transform: uppercase;
        }

        .status-confirmed {
            background: #f3e8ff;
            color: #6b21a8;
            border: 1px solid #d8b4fe;
        }

        .status-active {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #6ee7b7;
        }

        .status-resched {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
        }

        .status-pending {
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
        }

        .status-completed {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }

        .status-cancelled {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        /* Booking Card Body */
        .booking-item-name {
            font-family: 'Outfit', sans-serif;
            font-size: 1.25rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 0.5rem 0;
            line-height: 1.3;
        }

        .booking-meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem 1.75rem;
            color: #475569;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        .booking-meta-item {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
        }

        .booking-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            padding-top: 0.85rem;
            border-top: 1px solid #f8fafc;
        }

        .booking-pricing-box {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.25rem;
        }

        .booking-total-price {
            font-family: 'Outfit', sans-serif;
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--bamboo-gold, #ca8a04);
        }

        .payment-status-pill {
            font-size: 0.85rem;
            color: #64748b;
        }

        .payment-status-pill strong {
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 0.8rem;
        }

        .payment-verified {
            color: #15803d;
            background: #dcfce7;
        }

        .payment-pending {
            color: #b45309;
            background: #fef3c7;
        }

        .payment-rejected {
            color: #b91c1c;
            background: #fee2e2;
        }

        .booking-card-actions {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .btn-action {
            font-size: 0.82rem;
            font-weight: 700;
            padding: 0.45rem 0.9rem;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            cursor: pointer;
            transition: all 0.15s ease;
            text-decoration: none;
            border: 1px solid transparent;
        }

        .btn-action-primary {
            background: #faf5ff;
            color: var(--primary-purple, #6b21a8);
            border-color: #d8b4fe;
        }
        .btn-action-primary:hover {
            background: var(--primary-purple, #6b21a8);
            color: #ffffff;
        }

        .btn-action-reschedule {
            background: #f0fdfa;
            color: #0f766e;
            border-color: #99f6e4;
        }
        .btn-action-reschedule:hover {
            background: #0f766e;
            color: #ffffff;
        }

        .btn-action-cancel {
            background: #fff1f2;
            color: #be123c;
            border-color: #fecdd3;
        }
        .btn-action-cancel:hover {
            background: #be123c;
            color: #ffffff;
        }

        .booking-modal-dialog .ride-guide-container {
            margin-top: 0 !important;
            border: none !important;
            box-shadow: none !important;
            padding: 0 !important;
        }

        /* Empty States */
        .empty-booking-state {
            text-align: center;
            padding: 3.5rem 1.5rem;
            background: #ffffff;
            border: 1.5px dashed var(--border-color, #e2e8f0);
            border-radius: 14px;
            color: var(--text-muted, #64748b);
        }

        .empty-booking-state i {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 0.85rem;
            display: block;
        }

        .empty-booking-state h4 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.25rem;
            color: #334155;
            margin-bottom: 0.35rem;
        }

        .empty-booking-state p {
            font-size: 0.95rem;
            max-width: 420px;
            margin: 0 auto 1.25rem auto;
        }

        /* Modal Overlays & Dialogs */
        .booking-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 10000;
            padding: 1.25rem;
        }

        .booking-modal-dialog {
            background: #ffffff;
            border-radius: 16px;
            max-width: 620px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
            animation: modalFadeIn 0.2s ease-out;
            position: relative;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.97) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        .modal-header-custom {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header-custom h3 {
            font-family: 'Outfit', sans-serif;
            margin: 0;
            color: var(--primary-purple, #6b21a8);
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-close-btn {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: #94a3b8;
            cursor: pointer;
            line-height: 1;
            padding: 0.25rem;
            transition: color 0.15s;
        }
        .modal-close-btn:hover { color: #0f172a; }

        .modal-body-custom {
            padding: 1.5rem;
        }

        .modal-footer-custom {
            padding: 1rem 1.5rem;
            border-top: 1px solid #f1f5f9;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            background: #f8fafc;
            border-bottom-left-radius: 16px;
            border-bottom-right-radius: 16px;
        }

        .detail-section-title {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.75px;
            font-weight: 800;
            color: #64748b;
            margin-bottom: 0.65rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.85rem;
            margin-bottom: 1.25rem;
            background: #f8fafc;
            padding: 1rem;
            border-radius: 10px;
        }

        .detail-item {
            font-size: 0.88rem;
        }

        .detail-item-label {
            color: #64748b;
            font-size: 0.75rem;
            display: block;
            margin-bottom: 2px;
        }

        .detail-item-value {
            font-weight: 700;
            color: #1e293b;
        }

        /* Reschedule Specific */
        .reschedule-compare-box {
            background: #faf5ff;
            border: 1px solid #e9d5ff;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1.25rem;
        }

        .avail-status-box {
            padding: 0.75rem;
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 700;
            display: none;
            margin-bottom: 1rem;
            align-items: center;
            gap: 0.5rem;
        }

        .avail-status-box.avail-yes {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
            display: flex;
        }

        .avail-status-box.avail-no {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
            display: flex;
        }
    </style>
</head>
<body>
    <?php include 'nav.php'; ?>

    <div class="container" style="max-width: 1050px; margin-top: 2rem; margin-bottom: 4rem;">
        
        <?php if (!empty($flash_msg)): ?>
            <div style="background: <?= $flash_type === 'success' ? '#f0fdf4' : '#fef2f2' ?>; border: 1px solid <?= $flash_type === 'success' ? '#86efac' : '#fecaca' ?>; color: <?= $flash_type === 'success' ? '#166534' : '#991b1b' ?>; padding: 1rem 1.25rem; border-radius: var(--radius-sm, 8px); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.6rem;">
                <i class="fa-solid <?= $flash_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                <span><?= htmlspecialchars($flash_msg) ?></span>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="bookings-page-header">
            <div>
                <h2 style="font-family: 'Outfit', sans-serif; font-size: 1.85rem; color: var(--primary-purple, #581c87); margin: 0 0 0.35rem 0; display: flex; align-items: center; gap: 0.6rem;">
                    <i class="fa-solid fa-calendar-check" style="color: var(--primary-magenta, #e0129c);"></i> My Bookings
                </h2>
                <p style="color: var(--text-muted, #64748b); font-size: 0.95rem; margin: 0;">
                    View and manage your resort reservations and activities.
                </p>
            </div>
            <a href="browse_kubos.php" class="btn btn-purple btn-sm" style="font-weight: 700; display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.55rem 1.1rem; border-radius: 8px;">
                <i class="fa-solid fa-plus"></i> Book Another Stay
            </a>
        </div>

        <!-- Booking Tabs Navigation -->
        <div class="booking-tabs-nav">
            <button class="booking-tab-btn active" onclick="switchBookingTab('upcoming')">
                <i class="fa-solid fa-calendar-days"></i> Upcoming &amp; Active
                <span class="tab-badge"><?= count($upcoming_bookings) ?></span>
            </button>
            <button class="booking-tab-btn" onclick="switchBookingTab('history')">
                <i class="fa-solid fa-clock-rotate-left"></i> History
                <span class="tab-badge"><?= count($history_bookings) ?></span>
            </button>
            <button class="booking-tab-btn" onclick="switchBookingTab('dining')">
                <i class="fa-solid fa-utensils"></i> Dining Orders
                <span class="tab-badge"><?= count($user_dining) ?></span>
            </button>
        </div>

        <!-- ============================================================= -->
        <!-- TAB 1: UPCOMING & ACTIVE BOOKINGS -->
        <!-- ============================================================= -->
        <div id="tab-pane-upcoming" class="tab-pane-content">
            <?php
            $has_rejected_upcoming = false;
            foreach ($upcoming_bookings as $ub_check) {
                if (strtolower(trim($ub_check['payment_status'] ?? '')) === 'rejected') {
                    $has_rejected_upcoming = true;
                    break;
                }
            }
            ?>
            <?php if ($has_rejected_upcoming): ?>
                <div style="background: #fef2f2; border: 1.5px solid #fca5a5; border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 42px; height: 42px; background: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #dc2626; font-size: 1.25rem; flex-shrink: 0;">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </div>
                        <div>
                            <strong style="color: #991b1b; font-size: 0.95rem; display: block;">Payment Proof Requires Resubmission</strong>
                            <span style="color: #7f1d1d; font-size: 0.85rem;">One or more of your reservations had its GCash receipt rejected. Please click &ldquo;Resend Payment Proof&rdquo; below to submit a new receipt.</span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($upcoming_bookings)): ?>
                <div class="empty-booking-state">
                    <i class="fa-regular fa-calendar-xmark"></i>
                    <h4>No upcoming bookings</h4>
                    <p>Your upcoming reservations will appear here.</p>
                    <a href="browse_kubos.php" class="btn btn-purple btn-sm" style="font-weight: 700; padding: 0.55rem 1.25rem;">Explore Accommodations</a>
                </div>
            <?php else: ?>
                <div class="booking-cards-list">
                    <?php foreach ($upcoming_bookings as $b): ?>
                        <?php
                        $st_info = formatBookingStatus($b['booking_status'] ?? 'pending');
                        $pt_info = formatPaymentStatus($b['payment_status'] ?? '', $b);
                        
                        // Multi-item label
                        $addons = $addons_by_booking[$b['id']] ?? [];
                        $addons_count = count($addons);
                        $display_item_name = htmlspecialchars($b['item_name'] ?? 'Resort Stay');
                        if ($addons_count > 0) {
                            $display_item_name .= " <span style='font-size: 0.9rem; color: var(--primary-magenta, #e0129c); font-weight: 700;'>+ {$addons_count} More</span>";
                        }

                        // Dates
                        $check_in_fmt = !empty($b['check_in']) ? date('M d, Y', strtotime($b['check_in'])) : 'TBD';
                        $check_out_fmt = (!empty($b['check_out']) && $b['check_out'] !== $b['check_in']) ? date('M d, Y', strtotime($b['check_out'])) : '';
                        $date_str = $check_in_fmt . (!empty($check_out_fmt) ? " &rarr; {$check_out_fmt}" : "");

                        // Times
                        if (($b['type'] ?? 'house') === 'house') {
                            $in_ts = !empty($b['check_in']) ? strtotime($b['check_in']) : false;
                            $out_ts = !empty($b['check_out']) ? strtotime($b['check_out']) : false;
                            $in_t = ($in_ts && date('H:i', $in_ts) !== '00:00') ? date('g:i A', $in_ts) : '2:00 PM';
                            $out_t = ($out_ts && date('H:i', $out_ts) !== '00:00') ? date('g:i A', $out_ts) : '12:00 PM';
                            $time_str = "Check-in: {$in_t} &bull; Check-out: {$out_t}";
                        } else {
                            $time_str = !empty($b['time_slot']) ? "Slot: " . htmlspecialchars($b['time_slot']) : (!empty($b['start_time']) ? date('g:i A', strtotime($b['start_time'])) . (!empty($b['end_time']) ? ' - ' . date('g:i A', strtotime($b['end_time'])) : '') : 'Standard Activity Schedule');
                        }

                        // Permissions
                        $resched_count = intval($b['resched_count'] ?? 0);
                        $is_pending_resched = !empty($b['resched_pending']) || ($b['booking_status'] ?? '') === 'reschedule_requested';
                        $created_ts = !empty($b['created_at']) ? strtotime($b['created_at']) : 0;
                        $is_resched_expired = ($created_ts > 0 && (time() - $created_ts) > (3 * 86400));
                        $is_eligible_reschedule = in_array($st_info['key'], ['pending', 'confirmed', 'reschedule_requested']);
                        $is_eligible_cancel = false;
                        ?>
                        <div class="booking-card" id="card-<?= htmlspecialchars($b['id']) ?>">
                            <div class="booking-card-header">
                                <div class="booking-ref-title">
                                    <i class="fa-solid fa-bookmark" style="color: var(--bamboo-gold, #ca8a04); font-size: 0.95rem;"></i>
                                    #<?= htmlspecialchars($b['id']) ?>
                                </div>
                                <span class="status-badge <?= $st_info['class'] ?>">
                                    <i class="fa-solid <?= $st_info['key'] === 'active' ? 'fa-circle-dot fa-fade' : ($st_info['key'] === 'reschedule_requested' ? 'fa-arrows-rotate' : ($st_info['key'] === 'confirmed' ? 'fa-circle-check' : 'fa-clock')) ?>"></i>
                                    <?= $st_info['label'] ?>
                                </span>
                            </div>

                            <div class="booking-item-name">
                                <?= $display_item_name ?>
                            </div>

                            <div class="booking-meta-row">
                                <div class="booking-meta-item">
                                    <i class="fa-regular fa-calendar" style="color: var(--primary-magenta, #e0129c);"></i>
                                    <strong><?= $date_str ?></strong>
                                </div>
                                <div class="booking-meta-item">
                                    <i class="fa-regular fa-clock" style="color: #0ea5e9;"></i>
                                    <span><?= $time_str ?></span>
                                </div>
                                <?php if (!empty($b['pax'])): ?>
                                    <div class="booking-meta-item">
                                        <i class="fa-solid fa-user-group" style="color: #8b5cf6;"></i>
                                        <span><?= intval($b['pax']) ?> Guest<?= intval($b['pax']) > 1 ? 's' : '' ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($resched_count > 0): ?>
                                    <div class="booking-meta-item" style="color: <?= $resched_count >= 2 ? '#b91c1c' : '#d97706' ?>; font-weight: 600;" title="Reschedules used: <?= $resched_count ?> of 2 allowed">
                                        <i class="fa-solid fa-repeat"></i>
                                        <span>Rescheduled: <?= $resched_count ?>/2</span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($is_resched_expired && $resched_count < 2 && !$is_pending_resched): ?>
                                    <div class="booking-meta-item" style="color: #64748b; font-size: 0.82rem;" title="Rescheduling is only permitted within 3 days of booking.">
                                        <i class="fa-solid fa-clock-rotate-left" style="color: #94a3b8;"></i>
                                        <span>Reschedule window closed (3+ days)</span>
                                    </div>
                                <?php elseif (!$is_resched_expired && $resched_count < 2 && !$is_pending_resched && $created_ts > 0): ?>
                                    <?php 
                                        $seconds_left = max(0, (3 * 86400) - (time() - $created_ts));
                                        $hours_left = floor($seconds_left / 3600);
                                        $days_left = floor($seconds_left / 86400);
                                        $window_text = $days_left >= 1 ? "{$days_left}d " . ($hours_left % 24) . "h left to reschedule" : "{$hours_left}h left to reschedule";
                                    ?>
                                    <div class="booking-meta-item" style="color: #0284c7; font-size: 0.82rem;" title="Rescheduling permitted within 3 days of booking">
                                        <i class="fa-solid fa-hourglass-start" style="color: #0284c7;"></i>
                                        <span><?= $window_text ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($is_pending_resched): ?>
                                    <div style="background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 8px; padding: 0.65rem 0.85rem; margin-top: 0.75rem; font-size: 0.82rem; color: #1e40af; width: 100%;">
                                        <div style="display: flex; align-items: center; gap: 6px; font-weight: 700; color: #1d4ed8;">
                                            <i class="fa-solid fa-hourglass-half fa-spin-pulse"></i> Reschedule Request Pending Admin Approval
                                        </div>
                                        <div style="margin-top: 3px; color: #1e3a8a;">
                                            Requested: <strong><?= !empty($b['resched_check_in']) ? date('M d, Y', strtotime($b['resched_check_in'])) : 'Selected dates' ?></strong> <?= (!empty($b['resched_check_out']) && $b['resched_check_out'] !== $b['resched_check_in']) ? 'to <strong>' . date('M d, Y', strtotime($b['resched_check_out'])) . '</strong>' : '' ?>
                                        </div>
                                        <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 2px;">Your current schedule remains active until verified &amp; approved by admin. You cannot reschedule again until this request is approved.</small>
                                    </div>
                                <?php endif; ?>
                                <?php if (strtolower($b['payment_status'] ?? '') === 'rejected'): ?>
                                    <div style="background: #fef2f2; border: 1.5px solid #fecaca; border-radius: 9px; padding: 0.75rem 0.95rem; margin-top: 0.75rem; font-size: 0.84rem; color: #991b1b; width: 100%;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-weight: 700; color: #b91c1c;">
                                            <span style="display: flex; align-items: center; gap: 6px;">
                                                <i class="fa-solid fa-triangle-exclamation"></i> Payment Proof Rejected by Admin
                                            </span>
                                            <span style="font-size: 0.72rem; background: #fee2e2; color: #b91c1c; padding: 2px 7px; border-radius: 4px; border: 1px solid #fca5a5; font-weight: 800;">ACTION REQUIRED</span>
                                        </div>
                                        <div style="margin-top: 4px; color: #7f1d1d; line-height: 1.4;">
                                            Reason: <strong><?= htmlspecialchars(!empty($b['rejection_reason']) ? $b['rejection_reason'] : 'Proof could not be verified by resort staff') ?></strong>
                                        </div>
                                        <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 3px;">Please submit a clear, new GCash payment receipt to confirm your reservation.</small>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="booking-card-footer">
                                <div class="booking-pricing-box">
                                    <div class="booking-total-price">
                                        ₱<?= number_format(floatval($b['total_amount']), 2) ?>
                                    </div>
                                    <div class="payment-status-pill">
                                        Payment: <strong class="<?= $pt_info['class'] ?>"><?= $pt_info['label'] ?></strong>
                                    </div>
                                </div>

                                <div class="booking-card-actions">
                                    <button type="button" class="btn-action btn-action-primary" onclick="showBookingDetails(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($addons), ENT_QUOTES, 'UTF-8') ?>)">
                                        <i class="fa-solid fa-circle-info"></i> View Details
                                    </button>

                                    <?php if (in_array(strtolower($b['payment_status'] ?? ''), ['verified', 'proof_submitted'])): ?>
                                        <button type="button" class="btn-action" style="background: #f5f3ff; color: #6d28d9; border-color: #ddd6fe;" onclick="openRideGuideModal()">
                                            <i class="fa-solid fa-map-location-dot"></i> Ride Guide
                                        </button>
                                    <?php endif; ?>

                                    <?php if (strtolower($b['payment_status'] ?? '') === 'rejected'): ?>
                                        <button type="button" class="btn-action" style="background: #ef4444; color: #ffffff; border-color: #dc2626; font-weight: 700;" onclick="openReuploadPaymentModal(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8') ?>)">
                                            <i class="fa-solid fa-arrow-up-from-bracket"></i> Resend Payment Proof
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($is_eligible_reschedule): ?>
                                        <?php if ($is_pending_resched): ?>
                                            <button type="button" class="btn-action" style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; cursor: not-allowed; opacity: 0.9;" title="Reschedule request is currently awaiting admin verification and approval. You cannot reschedule again until this request has been approved." disabled>
                                                <i class="fa-solid fa-hourglass-half"></i> Reschedule Pending Approval
                                            </button>
                                        <?php elseif ($resched_count >= 2): ?>
                                            <button type="button" class="btn-action" style="background: #f1f5f9; color: #94a3b8; border: 1px solid #cbd5e1; cursor: not-allowed; opacity: 0.85;" title="Maximum limit of 2 reschedules reached" disabled>
                                                <i class="fa-solid fa-ban"></i> Reschedule Limit (2/2)
                                            </button>
                                        <?php elseif ($is_resched_expired): ?>
                                            <button type="button" class="btn-action" style="background: #f8fafc; color: #94a3b8; border: 1px solid #e2e8f0; cursor: not-allowed; opacity: 0.85;" title="Rescheduling is only permitted within 3 days of booking. More than 3 days have passed since this reservation was booked." disabled>
                                                <i class="fa-solid fa-clock-rotate-left"></i> Reschedule Expired (3+ Days)
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn-action btn-action-reschedule" onclick="openRescheduleModal(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8') ?>)">
                                                <i class="fa-solid fa-calendar-days"></i> Reschedule <?= $resched_count > 0 ? "({$resched_count}/2)" : "" ?>
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============================================================= -->
        <!-- TAB 2: HISTORY (COMPLETED & CANCELLED) -->
        <!-- ============================================================= -->
        <div id="tab-pane-history" class="tab-pane-content" style="display: none;">
            <?php if (empty($history_bookings)): ?>
                <div class="empty-booking-state">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <h4>No booking history</h4>
                    <p>Completed and cancelled bookings will appear here.</p>
                </div>
            <?php else: ?>
                <div class="booking-cards-list">
                    <?php foreach ($history_bookings as $b): ?>
                        <?php
                        $st_info = formatBookingStatus($b['booking_status'] ?? 'completed');
                        $pt_info = formatPaymentStatus($b['payment_status'] ?? '', $b);
                        
                        $addons = $addons_by_booking[$b['id']] ?? [];
                        $addons_count = count($addons);
                        $display_item_name = htmlspecialchars($b['item_name'] ?? 'Resort Stay');
                        if ($addons_count > 0) {
                            $display_item_name .= " <span style='font-size: 0.9rem; color: #94a3b8; font-weight: 700;'>+ {$addons_count} More</span>";
                        }

                        $check_in_fmt = !empty($b['check_in']) ? date('M d, Y', strtotime($b['check_in'])) : 'TBD';
                        $check_out_fmt = (!empty($b['check_out']) && $b['check_out'] !== $b['check_in']) ? date('M d, Y', strtotime($b['check_out'])) : '';
                        $date_str = $check_in_fmt . (!empty($check_out_fmt) ? " &rarr; {$check_out_fmt}" : "");

                        if (($b['type'] ?? 'house') === 'house') {
                            $in_ts = !empty($b['check_in']) ? strtotime($b['check_in']) : false;
                            $out_ts = !empty($b['check_out']) ? strtotime($b['check_out']) : false;
                            $in_t = ($in_ts && date('H:i', $in_ts) !== '00:00') ? date('g:i A', $in_ts) : '2:00 PM';
                            $out_t = ($out_ts && date('H:i', $out_ts) !== '00:00') ? date('g:i A', $out_ts) : '12:00 PM';
                            $time_str = "Check-in: {$in_t} &bull; Check-out: {$out_t}";
                        } else {
                            $time_str = !empty($b['time_slot']) ? "Slot: " . htmlspecialchars($b['time_slot']) : (!empty($b['start_time']) ? date('g:i A', strtotime($b['start_time'])) . (!empty($b['end_time']) ? ' - ' . date('g:i A', strtotime($b['end_time'])) : '') : 'Standard Schedule');
                        }

                        // Has valid rebook window?
                        $can_rebook = !empty($b['rebook_deadline']) && empty($b['is_forfeited']) && (strtotime($b['rebook_deadline']) >= time());
                        ?>
                        <div class="booking-card" style="opacity: 0.92;">
                            <div class="booking-card-header">
                                <div class="booking-ref-title" style="color: #475569;">
                                    <i class="fa-solid fa-bookmark" style="color: #94a3b8; font-size: 0.95rem;"></i>
                                    #<?= htmlspecialchars($b['id']) ?>
                                </div>
                                <span class="status-badge <?= $st_info['class'] ?>">
                                    <i class="fa-solid <?= $st_info['key'] === 'completed' ? 'fa-flag-checkered' : 'fa-ban' ?>"></i>
                                    <?= $st_info['label'] ?>
                                </span>
                            </div>

                            <div class="booking-item-name">
                                <?= $display_item_name ?>
                            </div>

                            <div class="booking-meta-row">
                                <div class="booking-meta-item">
                                    <i class="fa-regular fa-calendar" style="color: #64748b;"></i>
                                    <span><?= $date_str ?></span>
                                </div>
                                <div class="booking-meta-item">
                                    <i class="fa-regular fa-clock" style="color: #64748b;"></i>
                                    <span><?= $time_str ?></span>
                                </div>
                                <?php if (strtolower($b['payment_status'] ?? '') === 'rejected'): ?>
                                    <div style="background: #fef2f2; border: 1.5px solid #fecaca; border-radius: 9px; padding: 0.75rem 0.95rem; margin-top: 0.75rem; font-size: 0.84rem; color: #991b1b; width: 100%;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-weight: 700; color: #b91c1c;">
                                            <span style="display: flex; align-items: center; gap: 6px;">
                                                <i class="fa-solid fa-triangle-exclamation"></i> Payment Proof Rejected by Admin
                                            </span>
                                            <span style="font-size: 0.72rem; background: #fee2e2; color: #b91c1c; padding: 2px 7px; border-radius: 4px; border: 1px solid #fca5a5; font-weight: 800;">ACTION REQUIRED</span>
                                        </div>
                                        <div style="margin-top: 4px; color: #7f1d1d; line-height: 1.4;">
                                            Reason: <strong><?= htmlspecialchars(!empty($b['rejection_reason']) ? $b['rejection_reason'] : 'Proof could not be verified by resort staff') ?></strong>
                                        </div>
                                        <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 3px;">Please submit a clear, new GCash payment receipt to confirm this reservation.</small>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="booking-card-footer">
                                <div class="booking-pricing-box">
                                    <div class="booking-total-price" style="color: #475569;">
                                        ₱<?= number_format(floatval($b['total_amount']), 2) ?>
                                    </div>
                                    <div class="payment-status-pill">
                                        Payment: <strong class="<?= $pt_info['class'] ?>"><?= $pt_info['label'] ?></strong>
                                    </div>
                                </div>

                                <div class="booking-card-actions">
                                    <button type="button" class="btn-action btn-action-primary" onclick="showBookingDetails(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($addons), ENT_QUOTES, 'UTF-8') ?>)">
                                        <i class="fa-solid fa-circle-info"></i> View Details
                                    </button>

                                    <?php if (in_array(strtolower($b['payment_status'] ?? ''), ['verified', 'proof_submitted'])): ?>
                                        <button type="button" class="btn-action" style="background: #f5f3ff; color: #6d28d9; border-color: #ddd6fe;" onclick="openRideGuideModal()">
                                            <i class="fa-solid fa-map-location-dot"></i> Ride Guide
                                        </button>
                                    <?php endif; ?>

                                    <?php if (strtolower($b['payment_status'] ?? '') === 'rejected'): ?>
                                        <button type="button" class="btn-action" style="background: #ef4444; color: #ffffff; border-color: #dc2626; font-weight: 700;" onclick="openReuploadPaymentModal(<?= htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8') ?>)">
                                            <i class="fa-solid fa-arrow-up-from-bracket"></i> Resend Payment Proof
                                        </button>
                                    <?php endif; ?>

                                    <?php if (in_array(strtolower($b['booking_status'] ?? ''), ['completed', 'checked_out'])): ?>
                                        <a href="reviews.php?booking_id=<?= urlencode($b['id']) ?>#write-review" class="btn-action" style="background: #fdf4ff; color: #a21caf; border-color: #f0abfc; font-weight: 700;">
                                            <i class="fa-solid fa-star" style="color: #eab308;"></i> Leave Review
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($can_rebook): ?>
                                        <a href="rebook.php?ref=<?= urlencode($b['id']) ?>" class="btn-action" style="background: #fef3c7; color: #b45309; border-color: #fde68a;">
                                            <i class="fa-solid fa-rotate"></i> Rebook Now
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============================================================= -->
        <!-- TAB 3: DINING ORDERS -->
        <!-- ============================================================= -->
        <div id="tab-pane-dining" class="tab-pane-content" style="display: none;">
            <?php if (empty($user_dining)): ?>
                <div class="empty-booking-state">
                    <i class="fa-solid fa-utensils"></i>
                    <h4>No dining orders</h4>
                    <p>Your dining orders will appear here once you place an order from the resort menu.</p>
                    <a href="dining.php" class="btn btn-gold btn-sm" style="font-weight: 700; padding: 0.55rem 1.25rem;">Browse Dining Menu</a>
                </div>
            <?php else: ?>
                <div class="table-responsive-wrapper" style="background: #fff; border: 1.5px solid var(--border-color); border-radius: 14px; overflow: hidden; box-shadow: var(--shadow-sm);">
                    <table class="table" style="width: 100%; border-collapse: collapse; margin: 0;">
                        <thead>
                            <tr style="background: #f8fafc; border-bottom: 1.5px solid var(--border-color);">
                                <th style="padding: 1rem 1.25rem; font-size: 0.82rem; color: var(--primary-purple); font-weight: 800;">Order ID</th>
                                <th style="padding: 1rem 1.25rem; font-size: 0.82rem; color: var(--primary-purple); font-weight: 800;">Serving Location</th>
                                <th style="padding: 1rem 1.25rem; font-size: 0.82rem; color: var(--primary-purple); font-weight: 800;">Items Ordered</th>
                                <th style="padding: 1rem 1.25rem; font-size: 0.82rem; color: var(--primary-purple); font-weight: 800;">Amount</th>
                                <th style="padding: 1rem 1.25rem; font-size: 0.82rem; color: var(--primary-purple); font-weight: 800;">Date &amp; Time</th>
                                <th style="padding: 1rem 1.25rem; font-size: 0.82rem; color: var(--primary-purple); font-weight: 800;">Kitchen Status</th>
                                <th style="padding: 1rem 1.25rem; font-size: 0.82rem; color: var(--primary-purple); font-weight: 800;">Payment &amp; Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($user_dining as $ord): ?>
                                <?php
                                $d_st = strtolower(trim($ord['order_status'] ?? 'pending'));
                                $badge_bg = '#fef3c7';
                                $badge_c = '#b45309';
                                if ($d_st === 'served') { $badge_bg = '#dcfce7'; $badge_c = '#15803d'; }
                                elseif ($d_st === 'cancelled') { $badge_bg = '#fee2e2'; $badge_c = '#b91c1c'; }
                                elseif ($d_st === 'preparing' || $d_st === 'out_for_serving') { $badge_bg = '#e0f2fe'; $badge_c = '#0369a1'; }

                                $items = is_string($ord['order_items_json']) ? json_decode($ord['order_items_json'], true) : $ord['order_items_json'];
                                $items_summary = 'Dining Items';
                                if (is_array($items)) {
                                    $parts = array_map(function($i) { return ($i['qty'] ?? 1) . 'x ' . ($i['name'] ?? $i['item_name'] ?? 'Item'); }, $items);
                                    $items_summary = implode(', ', $parts);
                                }

                                $p_meth = strtolower(trim($ord['payment_method'] ?? 'cash'));
                                $p_stat = strtolower(trim($ord['payment_status'] ?? 'unpaid'));
                                ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 1rem 1.25rem; font-weight: 700; color: var(--primary-purple);">
                                        #<?= htmlspecialchars($ord['id']) ?>
                                    </td>
                                    <td style="padding: 1rem 1.25rem; font-weight: 600;">
                                        <i class="fa-solid fa-location-dot" style="color: var(--bamboo-gold); margin-right: 4px;"></i>
                                        <?= htmlspecialchars($ord['kubo_location'] ?? 'Beach Kubo') ?>
                                    </td>
                                    <td style="padding: 1rem 1.25rem; font-size: 0.88rem; max-width: 280px;">
                                        <?= htmlspecialchars($items_summary) ?>
                                    </td>
                                    <td style="padding: 1rem 1.25rem; font-weight: 800; color: var(--bamboo-gold);">
                                        ₱<?= number_format(floatval($ord['total_amount']), 2) ?>
                                    </td>
                                    <td style="padding: 1rem 1.25rem; font-size: 0.85rem; color: var(--text-muted);">
                                        <?= date('M d, Y h:i A', strtotime($ord['created_at'])) ?>
                                    </td>
                                    <td style="padding: 1rem 1.25rem;">
                                        <span style="background: <?= $badge_bg ?>; color: <?= $badge_c ?>; padding: 3px 8px; border-radius: 9999px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase;">
                                            <?= htmlspecialchars($d_st) ?>
                                        </span>
                                    </td>
                                    <td style="padding: 1rem 1.25rem; font-size: 0.82rem;">
                                        <?php if ($p_meth === 'gcash'): ?>
                                            <div style="font-weight: 700; color: #0284c7; margin-bottom: 2px;">
                                                <i class="fa-solid fa-mobile-screen"></i> GCash
                                            </div>
                                            <?php if ($p_stat === 'paid' || $p_stat === 'verified'): ?>
                                                <span style="background: #dcfce7; color: #15803d; padding: 2px 7px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; display: inline-block;">
                                                    <i class="fa-solid fa-check"></i> VERIFIED
                                                </span>
                                            <?php elseif ($p_stat === 'proof_submitted'): ?>
                                                <span style="background: #fef3c7; color: #b45309; padding: 2px 7px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; display: inline-block;">
                                                    <i class="fa-solid fa-hourglass-half"></i> UNDER REVIEW
                                                </span>
                                            <?php else: ?>
                                                <span style="background: #fee2e2; color: #dc2626; padding: 2px 7px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; display: inline-block;">
                                                    UNPAID
                                                </span>
                                                <div style="margin-top: 4px;">
                                                    <a href="payment_method.php?ref=<?= urlencode($ord['id']) ?>" class="btn btn-sm btn-purple" style="font-size: 0.72rem; padding: 0.22rem 0.55rem; border-radius: 6px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                                        <i class="fa-solid fa-credit-card"></i> Pay GCash
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif ($p_meth === 'charge_to_room'): ?>
                                            <div style="font-weight: 700; color: #7c3aed; margin-bottom: 2px;">
                                                <i class="fa-solid fa-key"></i> Room Folio
                                            </div>
                                            <span style="background: #ede9fe; color: #6d28d9; padding: 2px 7px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; display: inline-block;">
                                                CHARGE TO ROOM
                                            </span>
                                        <?php else: ?>
                                            <div style="font-weight: 700; color: #15803d; margin-bottom: 2px;">
                                                <i class="fa-solid fa-money-bill-wave"></i> Cash
                                            </div>
                                            <?php if ($p_stat === 'paid' || $p_stat === 'verified'): ?>
                                                <span style="background: #dcfce7; color: #15803d; padding: 2px 7px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; display: inline-block;">
                                                    <i class="fa-solid fa-check"></i> PAID
                                                </span>
                                            <?php else: ?>
                                                <span style="background: #f1f5f9; color: #475569; padding: 2px 7px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; display: inline-block;">
                                                    PAY ON SERVING
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- ============================================================= -->
    <!-- MODAL 1: VIEW DETAILS MODAL -->
    <!-- ============================================================= -->
    <div id="viewDetailsModal" class="booking-modal-overlay" onclick="if(event.target === this) closeDetailsModal();">
        <div class="booking-modal-dialog">
            <div class="modal-header-custom">
                <h3><i class="fa-solid fa-receipt" style="color: var(--bamboo-gold);"></i> Reservation Breakdown</h3>
                <button type="button" class="modal-close-btn" onclick="closeDetailsModal()">&times;</button>
            </div>
            <div class="modal-body-custom">
                
                <!-- Reference & Status Header -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; padding-bottom: 0.75rem; border-bottom: 1px solid #f1f5f9; flex-wrap: wrap; gap: 0.5rem;">
                    <div>
                        <span style="font-size: 0.78rem; color: #64748b; font-weight: 700;">BOOKING REFERENCE</span>
                        <div id="modalRefNo" style="font-family: 'Outfit', sans-serif; font-size: 1.3rem; font-weight: 800; color: var(--primary-purple);"></div>
                        <span id="modalCreatedAt" style="font-size: 0.78rem; color: #94a3b8;"></span>
                    </div>
                    <div id="modalStatusBadge"></div>
                </div>

                <!-- Pending Reschedule Notice Banner in Modal -->
                <div id="modalReschedBanner" style="display: none; background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1.25rem; font-size: 0.85rem; color: #1e40af;">
                    <div style="font-weight: 700; color: #1d4ed8; display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-hourglass-half fa-spin-pulse"></i> Reschedule Request Pending Admin Approval
                    </div>
                    <div id="modalReschedDatesText" style="margin-top: 4px; font-weight: 600; color: #1e3a8a;"></div>
                    <small style="color: #64748b; display: block; margin-top: 2px;">Your current schedule remains active until verified &amp; approved by admin. You cannot submit another reschedule request until this one is approved.</small>
                </div>

                <!-- Guest Information -->
                <div class="detail-section-title"><i class="fa-solid fa-user"></i> Guest Information</div>
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-item-label">Guest Name</span>
                        <span id="modalGuestName" class="detail-item-value"></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item-label">Number of Guests</span>
                        <span id="modalGuestPax" class="detail-item-value"></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item-label">Contact Phone</span>
                        <span id="modalGuestPhone" class="detail-item-value"></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item-label">Contact Email</span>
                        <span id="modalGuestEmail" class="detail-item-value"></span>
                    </div>
                </div>

                <!-- Reservation Information -->
                <div class="detail-section-title"><i class="fa-solid fa-hotel"></i> Reservation Details</div>
                <div class="detail-grid">
                    <div class="detail-item" style="grid-column: span 2;">
                        <span class="detail-item-label">Kubo / Activity Booked</span>
                        <span id="modalItemName" class="detail-item-value" style="font-size: 1rem; color: var(--primary-purple);"></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item-label">Schedule Date(s)</span>
                        <span id="modalDates" class="detail-item-value"></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item-label">Time / Schedule</span>
                        <span id="modalTimes" class="detail-item-value"></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item-label">Duration</span>
                        <span id="modalDuration" class="detail-item-value"></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item-label">Type</span>
                        <span id="modalType" class="detail-item-value" style="text-transform: capitalize;"></span>
                    </div>
                </div>

                <!-- Additional Items & Add-Ons -->
                <div id="modalAddonsContainer" style="display: none; margin-bottom: 1.25rem;">
                    <div class="detail-section-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Additional Activities &amp; Add-ons</div>
                    <div id="modalAddonsList" style="background: #f8fafc; border-radius: 10px; padding: 0.85rem;"></div>
                </div>

                <!-- Payment Summary -->
                <div class="detail-section-title"><i class="fa-solid fa-credit-card"></i> Payment Summary</div>
                <div class="detail-grid" style="background: #faf5ff; border: 1px solid #f3e8ff;">
                    <div class="detail-item">
                        <span class="detail-item-label">Total Amount</span>
                        <span id="modalTotalAmount" class="detail-item-value" style="font-size: 1.25rem; color: var(--bamboo-gold);"></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item-label">Payment Verification</span>
                        <span id="modalPaymentStatus" class="detail-item-value"></span>
                    </div>
                    <div id="modalGcashRefBox" class="detail-item" style="display: none;">
                        <span class="detail-item-label">GCash Reference No.</span>
                        <span id="modalGcashRef" class="detail-item-value" style="font-family: monospace;"></span>
                    </div>
                </div>

                <!-- Payment Proof Rejection Notice inside View Details Modal -->
                <div id="modalPaymentRejectedBanner" style="display: none; margin-top: 1rem; padding: 0.85rem 1rem; background: #fef2f2; border: 1.5px solid #fecaca; border-radius: 9px; font-size: 0.85rem; color: #991b1b;">
                    <div style="font-weight: 700; color: #b91c1c; display: flex; align-items: center; justify-content: space-between; gap: 6px;">
                        <span style="display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-triangle-exclamation"></i> GCash Payment Proof Rejected
                        </span>
                        <span style="font-size: 0.72rem; background: #fee2e2; color: #b91c1c; padding: 2px 7px; border-radius: 4px; border: 1px solid #fca5a5; font-weight: 800;">ACTION REQUIRED</span>
                    </div>
                    <div style="margin-top: 4px; color: #7f1d1d; line-height: 1.4;">
                        <strong>Reason:</strong> <span id="modalPaymentRejectReasonText"></span>
                    </div>
                    <div style="margin-top: 0.75rem;">
                        <button type="button" id="modalResubmitProofBtn" class="btn btn-sm" style="background: #ef4444; color: #fff; font-weight: 700; border: none; padding: 0.45rem 0.95rem; border-radius: 6px; cursor: pointer;">
                            <i class="fa-solid fa-arrow-up-from-bracket"></i> Resend Payment Proof Now
                        </button>
                    </div>
                </div>

                <!-- Special Notes -->
                <div id="modalNotesBox" style="display: none; margin-top: 1rem; padding: 0.75rem 1rem; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; font-size: 0.85rem; color: #92400e;">
                    <strong><i class="fa-regular fa-note-sticky"></i> Special Notes / Requests:</strong>
                    <div id="modalNotesText" style="margin-top: 4px; line-height: 1.4;"></div>
                </div>

                <!-- Cancellation / Rejection Details -->
                <div id="modalCancelBox" style="display: none; margin-top: 1rem; padding: 0.75rem 1rem; background: #fef2f2; border: 1px solid #fee2e2; border-radius: 8px; font-size: 0.85rem; color: #991b1b;">
                    <strong><i class="fa-solid fa-circle-exclamation"></i> Cancellation Information:</strong>
                    <div id="modalCancelText" style="margin-top: 4px; line-height: 1.4;"></div>
                </div>

            </div>
            <div class="modal-footer-custom" style="display: flex; justify-content: space-between; align-items: center;">
                <button type="button" id="modalRideGuideBtn" class="btn btn-sm" style="display: none; background: #f5f3ff; color: #6d28d9; border: 1.5px solid #ddd6fe; font-weight: 700; border-radius: 8px; padding: 0.45rem 0.95rem; cursor: pointer;" onclick="closeDetailsModal(); openRideGuideModal();">
                    <i class="fa-solid fa-map-location-dot"></i> Ride &amp; Arrival Guide
                </button>
                <button type="button" class="btn btn-outline btn-sm" onclick="closeDetailsModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- MODAL 2: RESCHEDULE MODAL -->
    <!-- ============================================================= -->
    <div id="rescheduleModal" class="booking-modal-overlay" onclick="if(event.target === this) closeRescheduleModal();">
        <div class="booking-modal-dialog">
            <div class="modal-header-custom">
                <h3><i class="fa-solid fa-calendar-days" style="color: #0ea5e9;"></i> Reschedule Reservation</h3>
                <button type="button" class="modal-close-btn" onclick="closeRescheduleModal()">&times;</button>
            </div>
            <form id="rescheduleForm" action="history.php" method="POST">
                <input type="hidden" name="action" value="reschedule_booking">
                <input type="hidden" name="ref" id="reschedRef">

                <div class="modal-body-custom">
                    
                    <!-- Current Booking Summary -->
                    <div class="reschedule-compare-box">
                        <div style="font-size: 0.78rem; font-weight: 800; color: #7c3aed; text-transform: uppercase; margin-bottom: 0.35rem;">Current Reservation</div>
                        <div id="reschedCurrentItem" style="font-weight: 800; font-size: 1.05rem; color: #1e1b4b;"></div>
                        <div id="reschedCurrentDates" style="font-size: 0.88rem; color: #475569; margin: 0.25rem 0;"></div>
                        <div style="font-size: 0.88rem;">Current Amount: <strong id="reschedCurrentAmount" style="color: var(--bamboo-gold);"></strong></div>
                    </div>

                    <!-- Reschedule Policy & Attempt Badge -->
                    <div style="background: #faf5ff; border: 1.5px solid #e9d5ff; border-radius: 9px; padding: 0.65rem 0.95rem; font-size: 0.83rem; margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px;">
                        <span style="font-weight: 700; color: #6b21a8; display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-repeat"></i> Policy: Max 2 Reschedules &bull; Within 3 Days of Booking
                        </span>
                        <span id="reschedAttemptBadge" style="font-weight: 800; font-size: 0.78rem; background: #9333ea; color: #ffffff; padding: 2px 10px; border-radius: 9999px;">
                            Attempt 1 of 2
                        </span>
                    </div>

                    <!-- Admin Verification Notice -->
                    <div style="background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 9px; padding: 0.75rem 0.95rem; font-size: 0.83rem; color: #1e40af; margin-bottom: 1rem; line-height: 1.45; display: flex; align-items: flex-start; gap: 8px;">
                        <i class="fa-solid fa-shield-halved" style="color: #3b82f6; margin-top: 2px; font-size: 1rem;"></i>
                        <div>
                            <strong>Admin Verification Required:</strong> Reschedule requests are submitted for admin verification. Your current reservation remains active until approved, and your total booking price will remain unchanged.
                        </div>
                    </div>

                    <!-- New Schedule Selection -->
                    <div style="margin-bottom: 1.25rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                            <h4 style="font-family: 'Outfit', sans-serif; font-size: 1rem; color: var(--primary-purple); margin: 0;">
                                Select New Schedule
                            </h4>
                            <span id="reschedDurationBadge" style="background: #ede9fe; color: #6d28d9; font-weight: 700; font-size: 0.76rem; padding: 2px 8px; border-radius: 6px; border: 1px solid #ddd6fe;"></span>
                        </div>

                        <div class="form-group" style="margin-bottom: 0.85rem;">
                            <label style="font-weight: 700; font-size: 0.88rem; color: #334155;">New Check-In / Activity Date <span style="color: #ef4444;">*</span></label>
                            <input type="date" id="reschedNewCheckIn" name="new_check_in" class="form-control" required min="<?= date('Y-m-d') ?>" oninput="onReschedCheckInChange()" onchange="onReschedCheckInChange()">
                        </div>

                        <div id="reschedCheckOutGroup" class="form-group" style="margin-bottom: 0.85rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                                <label style="font-weight: 700; font-size: 0.88rem; color: #334155; margin: 0;">New Check-Out Date</label>
                                <span style="font-size: 0.72rem; color: #6d28d9; font-weight: 700; background: #f5f3ff; border: 1px solid #ddd6fe; padding: 1px 6px; border-radius: 4px;">
                                    <i class="fa-solid fa-lock"></i> Auto-calculated
                                </span>
                            </div>
                            <input type="date" id="reschedNewCheckOut" name="new_check_out" class="form-control" readonly style="background: #f8fafc; cursor: not-allowed; border-color: #cbd5e1;" min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                            <small id="reschedCheckOutHint" style="color: #64748b; font-size: 0.75rem; display: flex; align-items: center; gap: 4px; margin-top: 4px;">
                                <i class="fa-solid fa-calculator" style="color: #7c3aed;"></i> Auto-calculated based on your booked stay duration.
                            </small>
                        </div>

                        <div id="reschedTimeSlotGroup" class="form-group" style="display: none; margin-bottom: 0.85rem;">
                            <label style="font-weight: 700; font-size: 0.88rem; color: #334155;">Preferred Time Slot</label>
                            <select id="reschedNewTimeSlot" name="new_time_slot" class="form-control">
                                <option value="Morning (08:00 AM - 12:00 PM)">Morning (08:00 AM - 12:00 PM)</option>
                                <option value="Afternoon (01:00 PM - 05:00 PM)">Afternoon (01:00 PM - 05:00 PM)</option>
                                <option value="Sunset / Evening (05:00 PM - 08:00 PM)">Sunset / Evening (05:00 PM - 08:00 PM)</option>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom: 0.85rem;">
                            <label style="font-weight: 700; font-size: 0.88rem; color: #334155;">Reason for Rescheduling (Optional)</label>
                            <input type="text" name="reschedule_reason" class="form-control" placeholder="e.g. Schedule adjustment, family emergency...">
                        </div>
                    </div>

                    <!-- Live Availability Box -->
                    <div id="reschedAvailBox" class="avail-status-box"></div>

                    <!-- Pricing Comparison Display -->
                    <div id="reschedPriceBox" style="display: none; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.85rem; font-size: 0.88rem;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.35rem;">
                            <span>Current Amount:</span>
                            <span id="priceCurrent" style="font-weight: 700;"></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.35rem;">
                            <span>New Amount:</span>
                            <span id="priceNew" style="font-weight: 700; color: var(--primary-purple);"></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; border-top: 1px dashed #cbd5e1; padding-top: 0.4rem; font-weight: 800;">
                            <span>Price Difference:</span>
                            <span id="priceDiff" style="color: #10b981;">₱0.00 (No change)</span>
                        </div>
                    </div>

                </div>
                <div class="modal-footer-custom">
                    <button type="button" class="btn btn-outline btn-sm" onclick="closeRescheduleModal()">Cancel</button>
                    <button type="submit" id="btnConfirmReschedule" class="btn btn-gold btn-sm" style="font-weight: 700;" disabled>
                        <i class="fa-solid fa-paper-plane"></i> Submit Reschedule Request
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- MODAL 3: CANCEL CONFIRMATION MODAL -->
    <!-- ============================================================= -->
    <div id="cancelConfirmModal" class="booking-modal-overlay" onclick="if(event.target === this) closeCancelModal();">
        <div class="booking-modal-dialog" style="max-width: 480px;">
            <div class="modal-header-custom" style="border-bottom-color: #fee2e2;">
                <h3 style="color: #b91c1c;"><i class="fa-solid fa-triangle-exclamation"></i> Cancel Reservation</h3>
                <button type="button" class="modal-close-btn" onclick="closeCancelModal()">&times;</button>
            </div>
            <form action="history.php" method="POST">
                <input type="hidden" name="action" value="cancel_booking">
                <input type="hidden" name="ref" id="cancelRef">

                <div class="modal-body-custom">
                    <p style="font-size: 0.95rem; color: #334155; margin-bottom: 1rem; line-height: 1.5;">
                        Are you sure you want to cancel booking <strong id="cancelRefDisplay" style="color: var(--primary-purple);"></strong> for <strong id="cancelItemDisplay"></strong>?
                    </p>
                    <div style="background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; padding: 0.75rem 1rem; font-size: 0.85rem; color: #9f1239; margin-bottom: 1.25rem;">
                        <i class="fa-solid fa-circle-info"></i> Your reserved slot will be released back to resort inventory in accordance with our reservation policies.
                    </div>

                    <div class="form-group" style="margin-bottom: 0.5rem;">
                        <label style="font-weight: 700; font-size: 0.85rem; color: #334155;">Reason for Cancellation (Optional)</label>
                        <select name="cancel_reason" class="form-control" style="font-size: 0.88rem;">
                            <option value="Change of plans">Change of plans</option>
                            <option value="Family emergency">Family emergency</option>
                            <option value="Schedule conflict">Schedule conflict</option>
                            <option value="Weather / travel disruption">Weather / travel disruption</option>
                            <option value="Other personal reason">Other personal reason</option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer-custom">
                    <button type="button" class="btn btn-outline btn-sm" onclick="closeCancelModal()">Keep Booking</button>
                    <button type="submit" class="btn btn-danger btn-sm" style="font-weight: 700; background: #dc2626; border-color: #dc2626;">
                        <i class="fa-solid fa-ban"></i> Yes, Cancel Booking
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- MODAL 4: RESUBMIT PAYMENT PROOF MODAL -->
    <!-- ============================================================= -->
    <div id="reuploadPaymentModal" class="booking-modal-overlay" onclick="if(event.target === this) closeReuploadPaymentModal();">
        <div class="booking-modal-dialog" style="max-width: 530px;">
            <div class="modal-header-custom" style="border-bottom-color: #fee2e2; background: #fff5f5;">
                <h3 style="color: #b91c1c; font-family: 'Outfit', sans-serif; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-arrow-up-from-bracket"></i> Resubmit GCash Payment Proof
                </h3>
                <button type="button" class="modal-close-btn" onclick="closeReuploadPaymentModal()">&times;</button>
            </div>
            <form id="reuploadPaymentForm" action="history.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="reupload_payment_proof">
                <input type="hidden" name="ref" id="reuploadRef">

                <div class="modal-body-custom">
                    
                    <!-- Target Booking Info -->
                    <div style="background: #faf5ff; border: 1.5px solid #e9d5ff; border-radius: 10px; padding: 0.85rem 1rem; margin-bottom: 1.25rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                            <span id="reuploadRefDisplay" style="font-size: 0.85rem; font-weight: 800; color: var(--primary-purple); font-family: monospace;"></span>
                            <span id="reuploadAmountDisplay" style="font-size: 1.15rem; font-weight: 800; color: var(--bamboo-gold);"></span>
                        </div>
                        <div id="reuploadItemName" style="font-weight: 700; color: #1e293b; font-size: 0.95rem;"></div>
                    </div>

                    <!-- Admin Rejection Callout -->
                    <div id="reuploadReasonBox" style="background: #fef2f2; border: 1.5px solid #fecaca; border-radius: 10px; padding: 0.85rem 1rem; margin-bottom: 1.25rem; font-size: 0.85rem; color: #991b1b;">
                        <div style="font-weight: 800; display: flex; align-items: center; gap: 6px; margin-bottom: 4px; color: #b91c1c;">
                            <i class="fa-solid fa-circle-exclamation"></i> Reason For Rejection:
                        </div>
                        <div id="reuploadReasonText" style="color: #7f1d1d; line-height: 1.4;"></div>
                    </div>

                    <!-- Resort GCash Account Mini Banner -->
                    <div style="background: #005ce6; color: #ffffff; border-radius: 10px; padding: 0.85rem 1rem; margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <div>
                            <span style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.9; display: block;">Send GCash Payment To:</span>
                            <strong style="font-size: 0.92rem; letter-spacing: 0.5px;">PURPLE BAMBOO RESORT</strong>
                        </div>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <code style="font-size: 1.1rem; font-family: monospace; background: rgba(255,255,255,0.2); padding: 3px 8px; border-radius: 6px; font-weight: 800; color: #fbbf24;">0956 697 8679</code>
                            <button type="button" onclick="navigator.clipboard.writeText('09566978679'); alert('GCash number copied to clipboard: 0956 697 8679');" style="background: rgba(255,255,255,0.25); border: none; color: #fff; padding: 4px 8px; border-radius: 6px; cursor: pointer;" title="Copy number">
                                <i class="fa-solid fa-copy"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Form Inputs -->
                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label for="reuploadGcashRef" style="font-weight: 700; font-size: 0.88rem; color: #334155; display: block; margin-bottom: 0.35rem;">
                            <i class="fa-solid fa-receipt" style="color: #005ce6;"></i> 13-Digit GCash Reference Number <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="text" id="reuploadGcashRef" name="gcash_ref" class="form-control" placeholder="e.g. 1002938475612 (13 digits)" required maxlength="13" minlength="13" inputmode="numeric" pattern="[0-9]{13}" autocomplete="off" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 13);" style="font-size: 1rem; font-family: monospace; letter-spacing: 0.5px;">
                        <small style="color: #64748b; font-size: 0.75rem; margin-top: 3px; display: block;">Found at the top or bottom of your GCash receipt after sending.</small>
                    </div>

                    <div class="form-group" style="margin-bottom: 0.5rem;">
                        <label for="reuploadProofImage" style="font-weight: 700; font-size: 0.88rem; color: #334155; display: block; margin-bottom: 0.35rem;">
                            <i class="fa-solid fa-image" style="color: var(--primary-purple);"></i> New Receipt Screenshot / Photo <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="file" id="reuploadProofImage" name="proof_image" class="form-control" accept="image/*" required onchange="previewReuploadReceipt(this)">
                        <small style="color: #64748b; font-size: 0.75rem; margin-top: 3px; display: block;">Ensure the Reference Number, Date, and Amount are clearly visible.</small>

                        <!-- Preview box -->
                        <div id="reuploadPreviewBox" style="display: none; margin-top: 0.75rem; border: 1.5px dashed #cbd5e1; border-radius: 8px; padding: 0.65rem; text-align: center; background: #f8fafc;">
                            <img id="reuploadPreviewImg" src="" alt="New Receipt Preview" style="max-height: 180px; max-width: 100%; object-fit: contain; border-radius: 6px; box-shadow: 0 2px 6px rgba(0,0,0,0.1);">
                        </div>
                    </div>

                </div>

                <div class="modal-footer-custom" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.6rem;">
                    <a id="reuploadFullPageLink" href="#" style="font-size: 0.82rem; color: #6d28d9; font-weight: 700; text-decoration: underline;">
                        <i class="fa-solid fa-qrcode"></i> Open GCash QR Gateway
                    </a>
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="button" class="btn btn-outline btn-sm" onclick="closeReuploadPaymentModal()">Cancel</button>
                        <button type="submit" class="btn btn-sm" style="background: #ef4444; color: #ffffff; border-color: #dc2626; font-weight: 700; padding: 0.5rem 1rem;">
                            <i class="fa-solid fa-arrow-up-from-bracket"></i> Submit New Proof
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- MODAL 5: RIDE & ARRIVAL GUIDE MODAL -->
    <!-- ============================================================= -->
    <div id="rideGuideModal" class="booking-modal-overlay" onclick="if(event.target === this) closeRideGuideModal();">
        <div class="booking-modal-dialog" style="max-width: 860px; padding: 0;">
            <div style="padding: 1rem 1.5rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1.5px solid #e2e8f0; background: #faf5ff; border-radius: 16px 16px 0 0;">
                <h3 style="margin: 0; font-family: 'Outfit', sans-serif; color: var(--primary-purple, #6b21a8); font-size: 1.25rem; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-map-location-dot" style="color: var(--primary-magenta, #e0129c);"></i> Resort Ride &amp; Arrival Guide
                </h3>
                <button type="button" class="modal-close-btn" style="position: static;" onclick="closeRideGuideModal()">&times;</button>
            </div>
            <div style="padding: 1.5rem; max-height: 80vh; overflow-y: auto;">
                <?php include __DIR__ . '/includes/ride_guide_widget.php'; ?>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>

    <script>
        // -------------------------------------------------------------
        // TAB SWITCHING
        // -------------------------------------------------------------
        function switchBookingTab(tabKey) {
            document.querySelectorAll('.tab-pane-content').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.booking-tab-btn').forEach(btn => btn.classList.remove('active'));

            const targetPane = document.getElementById('tab-pane-' + tabKey);
            if (targetPane) {
                targetPane.style.display = 'block';
            }

            // Find clicked button
            const clickedBtn = Array.from(document.querySelectorAll('.booking-tab-btn')).find(b => b.getAttribute('onclick').includes(tabKey));
            if (clickedBtn) {
                clickedBtn.classList.add('active');
            }
        }

        // -------------------------------------------------------------
        // VIEW DETAILS MODAL
        // -------------------------------------------------------------
        function showBookingDetails(b, addons) {
            document.getElementById('modalRefNo').innerText = '#' + (b.id || '');
            document.getElementById('modalCreatedAt').innerText = 'Booked on: ' + (b.created_at ? new Date(b.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A');

            // Status Badge
            const stRaw = (b.booking_status || 'pending').toLowerCase();
            const isReschedPending = (b.resched_pending == 1 || stRaw === 'reschedule_requested');
            let stClass = 'status-pending', stText = 'PENDING';
            if (isReschedPending) { stClass = 'status-resched'; stText = 'RESCHEDULE PENDING'; }
            else if (['active', 'checked_in'].includes(stRaw)) { stClass = 'status-active'; stText = 'ACTIVE'; }
            else if (['confirmed', 'approved'].includes(stRaw)) { stClass = 'status-confirmed'; stText = 'CONFIRMED'; }
            else if (['completed', 'checked_out'].includes(stRaw)) { stClass = 'status-completed'; stText = 'COMPLETED'; }
            else if (['cancelled', 'rejected', 'forfeited'].includes(stRaw)) { stClass = 'status-cancelled'; stText = 'CANCELLED'; }

            document.getElementById('modalStatusBadge').innerHTML = `<span class="status-badge ${stClass}">${stText}</span>`;

            // Reschedule Notice Banner in Modal
            const reschedBanner = document.getElementById('modalReschedBanner');
            if (reschedBanner) {
                if (isReschedPending) {
                    reschedBanner.style.display = 'block';
                    let reqDates = b.resched_check_in ? b.resched_check_in.substring(0, 10) : '';
                    if (b.resched_check_out && b.resched_check_out !== b.resched_check_in) {
                        reqDates += ' → ' + b.resched_check_out.substring(0, 10);
                    }
                    document.getElementById('modalReschedDatesText').innerText = 'Requested Schedule: ' + (reqDates || 'Dates specified') + (b.resched_reason ? ' (' + b.resched_reason + ')' : '');
                } else {
                    reschedBanner.style.display = 'none';
                }
            }

            // Guest info
            document.getElementById('modalGuestName').innerText = b.guest_name || 'Guest';
            document.getElementById('modalGuestPax').innerText = (b.pax || 1) + ' Guest' + ((b.pax || 1) > 1 ? 's' : '');
            document.getElementById('modalGuestPhone').innerText = b.guest_phone || 'N/A';
            document.getElementById('modalGuestEmail').innerText = b.guest_email || 'N/A';

            // Reservation details
            document.getElementById('modalItemName').innerText = b.item_name || 'Resort Stay';
            document.getElementById('modalType').innerText = b.type || 'house';

            let datesText = b.check_in || '';
            if (b.check_out && b.check_out !== b.check_in) {
                datesText += ' → ' + b.check_out;
            }
            document.getElementById('modalDates').innerText = datesText;

            let timesText = 'Check-in: 2:00 PM • Check-out: 12:00 PM';
            if (b.type === 'activity') {
                timesText = b.time_slot || (b.start_time ? b.start_time : 'Standard Schedule');
            }
            document.getElementById('modalTimes').innerText = timesText;

            // Duration
            let durationText = '1 Night';
            if (b.type === 'house' && b.check_in && b.check_out) {
                const diffDays = Math.max(1, Math.round((new Date(b.check_out) - new Date(b.check_in)) / (1000 * 60 * 60 * 24)));
                durationText = diffDays + ' Night' + (diffDays > 1 ? 's' : '');
            } else if (b.type === 'activity') {
                durationText = b.duration || 'Activity Session';
            }
            document.getElementById('modalDuration').innerText = durationText;

            // Payment
            document.getElementById('modalTotalAmount').innerText = '₱' + parseFloat(b.total_amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2 });
            const ptRaw = (b.payment_status || '').toLowerCase();
            let ptClass = 'payment-pending', ptText = 'PENDING';
            if (['verified', 'paid', 'approved'].includes(ptRaw)) { ptClass = 'payment-verified'; ptText = 'VERIFIED'; }
            else if (ptRaw === 'rejected') { ptClass = 'payment-rejected'; ptText = 'REJECTED'; }

            document.getElementById('modalPaymentStatus').innerHTML = `<strong class="${ptClass}">${ptText}</strong>`;

            if (b.gcash_ref) {
                document.getElementById('modalGcashRefBox').style.display = 'block';
                document.getElementById('modalGcashRef').innerText = b.gcash_ref;
            } else {
                document.getElementById('modalGcashRefBox').style.display = 'none';
            }

            // Add-ons list
            const addonsContainer = document.getElementById('modalAddonsContainer');
            const addonsList = document.getElementById('modalAddonsList');
            if (addons && addons.length > 0) {
                addonsContainer.style.display = 'block';
                let html = '<ul style="margin: 0; padding-left: 1.25rem; font-size: 0.88rem; color: #334155;">';
                addons.forEach(a => {
                    html += `<li style="margin-bottom: 0.35rem;">
                        <strong>${a.activity_name || 'Activity'}</strong> &bull; Date: ${a.activity_date || 'Same date'}
                        ${a.quantity ? ' (Qty: ' + a.quantity + ')' : ''}
                    </li>`;
                });
                html += '</ul>';
                addonsList.innerHTML = html;
            } else {
                addonsContainer.style.display = 'none';
            }

            // Special notes
            const notesBox = document.getElementById('modalNotesBox');
            if (b.special_notes && b.special_notes.trim() !== '') {
                notesBox.style.display = 'block';
                document.getElementById('modalNotesText').innerText = b.special_notes;
            } else {
                notesBox.style.display = 'none';
            }

            // Cancel / Rejection reason
            const cancelBox = document.getElementById('modalCancelBox');
            if (b.rejection_reason && b.rejection_reason.trim() !== '') {
                cancelBox.style.display = 'block';
                document.getElementById('modalCancelText').innerText = b.rejection_reason;
            } else {
                cancelBox.style.display = 'none';
            }

            // Payment rejection callout in details modal
            const payRejectBanner = document.getElementById('modalPaymentRejectedBanner');
            if (payRejectBanner) {
                if (ptRaw === 'rejected') {
                    payRejectBanner.style.display = 'block';
                    document.getElementById('modalPaymentRejectReasonText').innerText = (b.rejection_reason && b.rejection_reason.trim() !== '') ? b.rejection_reason : 'Proof could not be verified by resort staff.';
                    const resubmitBtn = document.getElementById('modalResubmitProofBtn');
                    if (resubmitBtn) {
                        resubmitBtn.onclick = function() {
                            closeDetailsModal();
                            openReuploadPaymentModal(b);
                        };
                    }
                } else {
                    payRejectBanner.style.display = 'none';
                }
            }

            // Ride guide button in details modal
            const rideBtn = document.getElementById('modalRideGuideBtn');
            if (rideBtn) {
                if (['verified', 'proof_submitted'].includes(ptRaw)) {
                    rideBtn.style.display = 'inline-flex';
                } else {
                    rideBtn.style.display = 'none';
                }
            }

            document.getElementById('viewDetailsModal').style.display = 'flex';
        }

        function closeDetailsModal() {
            document.getElementById('viewDetailsModal').style.display = 'none';
        }

        // -------------------------------------------------------------
        // RESUBMIT PAYMENT PROOF MODAL
        // -------------------------------------------------------------
        function openReuploadPaymentModal(b) {
            if (!b) return;
            document.getElementById('reuploadRef').value = b.id || '';
            document.getElementById('reuploadRefDisplay').innerText = 'Booking #' + (b.id || '');
            document.getElementById('reuploadAmountDisplay').innerText = '₱' + parseFloat(b.total_amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2 });
            document.getElementById('reuploadItemName').innerText = b.item_name || 'Resort Stay';

            const reason = (b.rejection_reason && b.rejection_reason.trim() !== '') ? b.rejection_reason : 'Proof could not be verified by resort front desk.';
            document.getElementById('reuploadReasonText').innerText = reason;

            // Reset inputs
            document.getElementById('reuploadGcashRef').value = '';
            const fileInput = document.getElementById('reuploadProofImage');
            if (fileInput) fileInput.value = '';
            const previewBox = document.getElementById('reuploadPreviewBox');
            if (previewBox) previewBox.style.display = 'none';
            const previewImg = document.getElementById('reuploadPreviewImg');
            if (previewImg) previewImg.src = '';

            const fullPageLink = document.getElementById('reuploadFullPageLink');
            if (fullPageLink) {
                fullPageLink.href = 'payment_method.php?ref=' + encodeURIComponent(b.id) + '&reupload=1';
            }

            document.getElementById('reuploadPaymentModal').style.display = 'flex';
        }

        function closeReuploadPaymentModal() {
            document.getElementById('reuploadPaymentModal').style.display = 'none';
        }

        function previewReuploadReceipt(input) {
            const box = document.getElementById('reuploadPreviewBox');
            const img = document.getElementById('reuploadPreviewImg');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    img.src = e.target.result;
                    box.style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            } else {
                box.style.display = 'none';
            }
        }

        // -------------------------------------------------------------
        // RIDE & ARRIVAL GUIDE MODAL
        // -------------------------------------------------------------
        function openRideGuideModal() {
            document.getElementById('rideGuideModal').style.display = 'flex';
        }

        function closeRideGuideModal() {
            document.getElementById('rideGuideModal').style.display = 'none';
        }

        // -------------------------------------------------------------
        // RESCHEDULE MODAL & LIVE AVAILABILITY
        // -------------------------------------------------------------
        let currentReschedBooking = null;

        function openRescheduleModal(b) {
            if (b.resched_pending == 1 || b.booking_status === 'reschedule_requested') {
                alert('A reschedule request is already pending verification for this booking. You cannot reschedule again until it has been approved by admin.');
                return;
            }
            const count = parseInt(b.resched_count || 0, 10);
            if (count >= 2) {
                alert('This reservation has already reached the maximum limit of 2 reschedules.');
                return;
            }
            if (b.created_at) {
                const createdDate = new Date(b.created_at.replace(/-/g, '/'));
                if (!isNaN(createdDate.getTime())) {
                    const diffMs = Date.now() - createdDate.getTime();
                    if (diffMs > 3 * 24 * 60 * 60 * 1000) {
                        alert('This reservation can no longer be rescheduled because more than 3 days have passed since it was booked. Rescheduling is only permitted within 3 days of booking.');
                        return;
                    }
                }
            }
            const attemptBadge = document.getElementById('reschedAttemptBadge');
            if (attemptBadge) {
                attemptBadge.textContent = `Attempt ${count + 1} of 2`;
            }

            currentReschedBooking = b;
            document.getElementById('reschedRef').value = b.id;
            document.getElementById('reschedCurrentItem').innerText = b.item_name || 'Resort Stay';
            
            let datesText = b.check_in || '';
            if (b.check_out && b.check_out !== b.check_in) {
                datesText += ' → ' + b.check_out;
            }
            document.getElementById('reschedCurrentDates').innerText = 'Dates: ' + datesText;
            document.getElementById('reschedCurrentAmount').innerText = '₱' + parseFloat(b.total_amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2 });

            const checkInInput = document.getElementById('reschedNewCheckIn');
            const checkOutInput = document.getElementById('reschedNewCheckOut');
            const checkOutGroup = document.getElementById('reschedCheckOutGroup');
            const timeSlotGroup = document.getElementById('reschedTimeSlotGroup');

            // Calculate stay duration
            let origNights = 1;
            if (b.check_in && b.check_out) {
                const dIn = new Date(b.check_in.split(' ')[0] + 'T00:00:00');
                const dOut = new Date(b.check_out.split(' ')[0] + 'T00:00:00');
                const diffDays = Math.round((dOut - dIn) / (1000 * 3600 * 24));
                if (diffDays > 0) origNights = diffDays;
            }
            const durationBadge = document.getElementById('reschedDurationBadge');
            if (durationBadge) {
                durationBadge.innerText = origNights + (origNights === 1 ? ' Night Stay' : ' Nights Stay');
            }
            const checkOutHint = document.getElementById('reschedCheckOutHint');
            if (checkOutHint) {
                checkOutHint.innerHTML = `<i class="fa-solid fa-calculator" style="color: #7c3aed;"></i> Auto-calculated based on your booked ${origNights}-night duration.`;
            }

            if (b.type === 'activity') {
                checkOutGroup.style.display = 'none';
                timeSlotGroup.style.display = 'block';
                if (durationBadge) durationBadge.innerText = 'Activity Reservation';
            } else {
                checkOutGroup.style.display = 'block';
                timeSlotGroup.style.display = 'none';
            }

            // Reset state
            document.getElementById('reschedAvailBox').className = 'avail-status-box';
            document.getElementById('reschedAvailBox').style.display = 'none';
            document.getElementById('reschedPriceBox').style.display = 'none';
            document.getElementById('btnConfirmReschedule').disabled = true;

            // Automatically set default check-in date to tomorrow and calculate check-out immediately
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            const tmrwY = tomorrow.getFullYear();
            const tmrwM = String(tomorrow.getMonth() + 1).padStart(2, '0');
            const tmrwD = String(tomorrow.getDate()).padStart(2, '0');
            const tmrwStr = `${tmrwY}-${tmrwM}-${tmrwD}`;
            checkInInput.min = tmrwStr;
            checkInInput.value = tmrwStr;

            document.getElementById('rescheduleModal').style.display = 'flex';

            // Trigger auto-calculation
            onReschedCheckInChange();
        }

        function closeRescheduleModal() {
            document.getElementById('rescheduleModal').style.display = 'none';
            currentReschedBooking = null;
        }

        function onReschedCheckInChange() {
            const checkInVal = document.getElementById('reschedNewCheckIn').value;
            if (checkInVal && currentReschedBooking && currentReschedBooking.type !== 'activity') {
                let origNights = 1;
                if (currentReschedBooking.check_in && currentReschedBooking.check_out) {
                    const dIn = new Date(currentReschedBooking.check_in.split(' ')[0] + 'T00:00:00');
                    const dOut = new Date(currentReschedBooking.check_out.split(' ')[0] + 'T00:00:00');
                    const diffDays = Math.round((dOut - dIn) / (1000 * 3600 * 24));
                    if (diffDays > 0) origNights = diffDays;
                }
                const targetDate = new Date(checkInVal + 'T00:00:00');
                targetDate.setDate(targetDate.getDate() + origNights);
                const y = targetDate.getFullYear();
                const m = String(targetDate.getMonth() + 1).padStart(2, '0');
                const d = String(targetDate.getDate()).padStart(2, '0');
                
                const nextDay = new Date(checkInVal + 'T00:00:00');
                nextDay.setDate(nextDay.getDate() + 1);
                const minOutY = nextDay.getFullYear();
                const minOutM = String(nextDay.getMonth() + 1).padStart(2, '0');
                const minOutD = String(nextDay.getDate()).padStart(2, '0');
                
                const checkOutEl = document.getElementById('reschedNewCheckOut');
                checkOutEl.min = `${minOutY}-${minOutM}-${minOutD}`;
                checkOutEl.value = `${y}-${m}-${d}`;
            }
            checkRescheduleAvailability();
        }

        async function checkRescheduleAvailability() {
            if (!currentReschedBooking) return;

            const checkIn = document.getElementById('reschedNewCheckIn').value;
            const checkOut = document.getElementById('reschedNewCheckOut').value;
            const availBox = document.getElementById('reschedAvailBox');
            const priceBox = document.getElementById('reschedPriceBox');
            const confirmBtn = document.getElementById('btnConfirmReschedule');

            if (!checkIn) {
                availBox.style.display = 'none';
                priceBox.style.display = 'none';
                confirmBtn.disabled = true;
                return;
            }

            availBox.className = 'avail-status-box';
            availBox.style.display = 'flex';
            availBox.style.background = '#f1f5f9';
            availBox.style.color = '#475569';
            availBox.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Checking resort schedule availability...';

            try {
                const url = `history.php?action=check_reschedule_avail&ref=${encodeURIComponent(currentReschedBooking.id)}&check_in=${encodeURIComponent(checkIn)}&check_out=${encodeURIComponent(checkOut)}`;
                const res = await fetch(url);
                const data = await res.json();

                if (data.success && data.available) {
                    availBox.className = 'avail-status-box avail-yes';
                    availBox.innerHTML = `<i class="fa-solid fa-circle-check"></i> ${data.message}`;
                    confirmBtn.disabled = false;

                    // Show price comparison (retains identical total price)
                    priceBox.style.display = 'block';
                    document.getElementById('priceCurrent').innerText = '₱' + parseFloat(data.current_amount).toLocaleString('en-US', { minimumFractionDigits: 2 });
                    document.getElementById('priceNew').innerText = '₱' + parseFloat(data.new_amount).toLocaleString('en-US', { minimumFractionDigits: 2 });
                    const diffEl = document.getElementById('priceDiff');
                    diffEl.innerText = data.diff_text || '₱0.00 (No change)';
                    diffEl.style.color = '#10b981';
                } else {
                    availBox.className = 'avail-status-box avail-no';
                    availBox.innerHTML = `<i class="fa-solid fa-circle-xmark"></i> ${data.message || data.error || 'Schedule unavailable'}`;
                    confirmBtn.disabled = true;
                    priceBox.style.display = 'none';
                }
            } catch (err) {
                availBox.className = 'avail-status-box avail-no';
                availBox.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Error verifying availability. Please try again.';
                confirmBtn.disabled = true;
                priceBox.style.display = 'none';
            }
        }

        // -------------------------------------------------------------
        // CANCEL MODAL
        // -------------------------------------------------------------
        function openCancelModal(ref, itemName, datesStr) {
            document.getElementById('cancelRef').value = ref;
            document.getElementById('cancelRefDisplay').innerText = '#' + ref;
            document.getElementById('cancelItemDisplay').innerText = itemName + ' (' + datesStr + ')';
            document.getElementById('cancelConfirmModal').style.display = 'flex';
        }

        function closeCancelModal() {
            document.getElementById('cancelConfirmModal').style.display = 'none';
        }
    </script>
</body>
</html>
