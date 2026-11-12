<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/booking_store.php';
require_once __DIR__ . '/inventory_store.php';

// Require logged-in guest session
if (empty($_SESSION['user_id'])) {
    header("Location: login.php?msg=" . urlencode("Please sign in first to rebook your stay.") . "&redirect=user-dashboard.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$profile_id = $_SESSION['profile_id'] ?? null;
$email = $_SESSION['email'] ?? '';

$ref = $_GET['ref'] ?? '';
if (empty($ref)) {
    header("Location: user-dashboard.php");
    exit();
}

$booking = BookingStore::getBookingById($ref);
if (!$booking) {
    header("Location: user-dashboard.php");
    exit();
}

// Verify ownership
$is_owner = (($booking['user_id'] ?? null) == $user_id) 
            || (!empty($booking['profile_id']) && $booking['profile_id'] === $profile_id)
            || (!empty($email) && strtolower(trim($booking['guest_email'] ?? '')) === strtolower(trim($email)));

if (!$is_owner) {
    header("Location: user-dashboard.php");
    exit();
}

// Check if eligible for rebook
if (empty($booking['rebook_deadline']) || !empty($booking['is_forfeited'])) {
    die("This booking is not eligible for rebooking or the payment has been forfeited.");
}
if (strtotime($booking['rebook_deadline']) < time()) {
    // Should be caught by dashboard logic, but just in case
    die("Your rebooking window has expired and the payment is forfeited.");
}

$error_msg = '';
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $check_in = $_POST['check_in'] ?? '';
    $check_out = $_POST['check_out'] ?? '';

    if (empty($check_in)) {
        $error_msg = "Please select a Check-In date.";
    } elseif ($check_in < date('Y-m-d')) {
        $error_msg = "Check-In date cannot be in the past.";
    } elseif (!empty($check_out) && $check_out <= $check_in) {
        $error_msg = "Check-Out date must be after Check-In date.";
    } else {
        // Ensure checkout is set
        if (empty($check_out)) {
            $check_out = date('Y-m-d', strtotime($check_in . ' +1 day'));
        }

        // Check availability
        $isAvailable = BookingStore::isItemAvailable($booking['item_id'], $check_in, $check_out, $booking['type'], $booking['id']);
        
        if (!$isAvailable) {
            $error_msg = "Sorry, this accommodation has 0 units available for the selected dates. Please choose different dates.";
        } else {
            // Process Rebook
            BookingStore::processRebook($booking['id'], $check_in, $check_out);
            
            // Redirect to dashboard
            echo "<script>alert('Rebooking successful! Your new dates have been reserved and status is pending approval.'); window.location.href='user-dashboard.php';</script>";
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rebook Reservation | Purple Bamboo Resort</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'nav.php'; ?>

    <div class="container" style="max-width: 700px; margin-top: 3rem; margin-bottom: 3rem;">
        <div class="card" style="padding: 2.5rem; text-align: left; border-top: 5px solid var(--bamboo-gold);">
            <h2 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-bottom: 0.5rem;">
                <i class="fa-solid fa-rotate"></i> Rebook Your Stay
            </h2>
            <p style="color: var(--text-muted); margin-bottom: 2rem;">
                Select new dates for your reservation. Your previous payment will be carried over to these new dates.
            </p>

            <?php if ($error_msg): ?>
                <div style="background: #fee2e2; border: 1px solid #ef4444; color: #b91c1c; padding: 1rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem;">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error_msg) ?>
                </div>
            <?php endif; ?>

            <div style="background: var(--purple-light); padding: 1.5rem; border-radius: var(--radius-sm); margin-bottom: 2rem; border: 1px dashed var(--primary-purple);">
                <p style="margin: 0 0 0.5rem 0; font-size: 0.9rem;"><strong>Ref #:</strong> <?= htmlspecialchars($booking['id']) ?></p>
                <p style="margin: 0 0 0.5rem 0; font-size: 0.9rem;"><strong>Accommodation:</strong> <?= htmlspecialchars($booking['item_name']) ?></p>
                <p style="margin: 0 0 0.5rem 0; font-size: 0.9rem;"><strong>Amount Paid/Verified:</strong> ₱<?= number_format(floatval($booking['total_amount'])) ?></p>
                <p style="margin: 0; font-size: 0.9rem; color: #d97706;"><strong>Rebook Deadline:</strong> <?= date('M d, Y h:i A', strtotime($booking['rebook_deadline'])) ?></p>
            </div>

            <form action="rebook.php?ref=<?= urlencode($ref) ?>" method="POST">
                <div class="form-group">
                    <label style="font-weight: 700; color: var(--text-dark);">New Check-In Date <span style="color: #ef4444;">*</span></label>
                    <input type="date" name="check_in" class="form-control" required min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($_POST['check_in'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label style="font-weight: 700; color: var(--text-dark);">New Check-Out Date (Optional for Day Tours)</label>
                    <input type="date" name="check_out" class="form-control" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" value="<?= htmlspecialchars($_POST['check_out'] ?? '') ?>">
                    <small style="color: var(--text-muted); display: block; margin-top: 5px;">Leave empty if staying for only 1 night/day.</small>
                </div>

                <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                    <a href="user-dashboard.php" class="btn btn-outline" style="flex: 1; text-align: center;">Cancel</a>
                    <button type="submit" class="btn btn-gold" style="flex: 2; font-weight: 700; font-size: 1.1rem;">
                        <i class="fa-solid fa-check"></i> Confirm Rebooking
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php include 'footer.php'; ?>
</body>
</html>
