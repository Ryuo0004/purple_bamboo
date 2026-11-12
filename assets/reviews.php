<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/review_store.php';
require_once __DIR__ . '/booking_store.php';

$user_role = $_SESSION['role'] ?? 'guest';
$is_admin_layout = ($user_role === 'admin' || $user_role === 'staff');
$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$current_user_name = $_SESSION['full_name'] ?? '';
$current_profile_id = $_SESSION['profile_id'] ?? '';

$success_msg = '';
$error_msg = '';

if (isset($_GET['posted']) && $_GET['posted'] == 1) {
    $success_msg = 'Salamat! Your review has been published and is now visible to all guests and management.';
} elseif (isset($_GET['replied']) && $_GET['replied'] == 1) {
    $success_msg = 'Official staff response posted successfully!';
} elseif (isset($_GET['deleted']) && $_GET['deleted'] == 1) {
    $success_msg = 'Review record removed successfully.';
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_review'])) {
        if ($is_admin_layout) {
            $error_msg = 'Administrators and staff members cannot publish guest reviews. Only verified resort guests can submit reviews.';
        } else {
            $rating = (int)($_POST['rating'] ?? 5);
            $item = trim($_POST['item'] ?? '');
            if ($item === 'Other' && !empty($_POST['custom_item'])) {
                $item = trim($_POST['custom_item']);
            }
            if (empty($item)) {
                $item = 'Purple Bamboo Resort Stay';
            }

            $comment = trim($_POST['comment'] ?? '');
            $guest_name = trim($_POST['guest_name'] ?? '');

            if ($is_logged_in && !empty($current_user_name)) {
                $guest_name = $current_user_name;
            } elseif (empty($guest_name)) {
                $guest_name = 'Resort Guest';
            }

            if (!empty($comment)) {
                ReviewStore::addReview([
                    'guest' => $guest_name,
                    'guest_id' => $_SESSION['user_id'] ?? null,
                    'profile_id' => $_SESSION['profile_id'] ?? null,
                    'item' => $item,
                    'rating' => $rating,
                    'comment' => $comment
                ]);

                header("Location: reviews.php?posted=1");
                exit();
            } else {
                $error_msg = 'Please provide your review feedback comment before submitting.';
            }
        }
    } elseif (isset($_POST['submit_reply'])) {
        $review_id = (int)($_POST['review_id'] ?? 0);
        $reply_text = trim($_POST['reply_text'] ?? '');
        $staff_name = !empty($_SESSION['full_name']) ? $_SESSION['full_name'] : 'Purple Bamboo Management';

        if ($review_id > 0 && !empty($reply_text)) {
            ReviewStore::addReply($review_id, $reply_text, $staff_name);
            header("Location: reviews.php?replied=1");
            exit();
        } else {
            $error_msg = 'Please write a response message before submitting.';
        }
    } elseif (isset($_POST['delete_review']) && $is_admin_layout) {
        $review_id = (int)($_POST['review_id'] ?? 0);
        if ($review_id > 0) {
            ReviewStore::deleteReview($review_id);
            header("Location: reviews.php?deleted=1");
            exit();
        }
    }
}

// Fetch query filters
$search = trim($_GET['search'] ?? '');
$filter = trim($_GET['filter'] ?? 'all'); // 'all', 'pending_reply', 'replied'

$reviews_list = ReviewStore::getAll($search, $filter);
$stats = ReviewStore::getStats();
$total_reviews = $stats['total_reviews'];
$avg_rating = $stats['avg_rating'];
$with_replies = $stats['with_replies'];
$pending_replies = $stats['pending_replies'];

// If logged-in guest, get their booked kubos/activities for easy selector
$user_past_items = [];
if ($is_logged_in && !empty($_SESSION['user_id'])) {
    $all_b = BookingStore::getAllBookings();
    $u_id = $_SESSION['user_id'];
    $p_id = $_SESSION['profile_id'] ?? '';
    $u_email = strtolower(trim($_SESSION['email'] ?? ''));
    foreach ($all_b as $mb) {
        $matches = (($mb['user_id'] ?? null) == $u_id) 
            || (!empty($mb['profile_id']) && $mb['profile_id'] === $p_id)
            || (!empty($u_email) && strtolower(trim($mb['guest_email'] ?? '')) === $u_email);
        if ($matches && !empty($mb['item_name']) && !in_array($mb['item_name'], $user_past_items)) {
            $user_past_items[] = $mb['item_name'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Reviews & Ratings | The Blessed Purple Bamboo Resort</title>
    <?php if ($is_admin_layout): ?>
        <link rel="stylesheet" href="admin-style.css">
    <?php else: ?>
        <link rel="stylesheet" href="style.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="<?= $is_admin_layout ? 'admin-layout-body' : '' ?>">

    <?php if ($is_admin_layout): ?>
        <?php include 'admin-nav.php'; ?>
        <div class="admin-main-wrapper">
            <header class="admin-top-header">
                <div class="admin-top-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" placeholder="Search reviews by guest, item, or comment..." value="<?= htmlspecialchars($search) ?>" onkeyup="if(event.key==='Enter') window.location='reviews.php?search='+encodeURIComponent(this.value)+'&filter=<?= htmlspecialchars($filter) ?>'">
                </div>
                <div class="admin-header-actions">
                    <div class="admin-header-profile-pill">
                        <div class="profile-avatar-circle"><?= strtoupper(substr($admin_full_name ?? 'Admin', 0, 1)) ?></div>
                        <div class="profile-meta">
                            <span class="profile-name"><?= htmlspecialchars($admin_full_name ?? 'Admin') ?></span>
                            <span class="profile-status">Online</span>
                        </div>
                    </div>
                </div>
            </header>
            <main class="admin-container">
    <?php else: ?>
        <?php include 'nav.php'; ?>
        <div class="container" style="max-width: 960px; margin-top: 2rem; margin-bottom: 3.5rem;">
    <?php endif; ?>

        <!-- Page Header -->
        <div class="<?= $is_admin_layout ? 'admin-page-header' : 'page-header' ?>" style="margin-bottom: 2rem;">
            <div>
                <h1 class="<?= $is_admin_layout ? 'admin-page-title' : '' ?>" style="<?= !$is_admin_layout ? 'font-family: Outfit, sans-serif; color: var(--primary-purple);' : '' ?>">
                    <i class="fa-solid fa-star" style="color: var(--gold-glow, var(--bamboo-gold));"></i> 
                    Guest Reviews & Ratings
                </h1>
                <p class="<?= $is_admin_layout ? 'admin-page-subtitle' : '' ?>" style="<?= !$is_admin_layout ? 'color: var(--text-muted);' : '' ?>">
                    <?= $is_admin_layout 
                        ? 'Real-time monitor of guest reviews, satisfaction ratings, and official management replies.' 
                        : 'Discover authentic experiences, verified ratings, and official responses from Purple Bamboo Resort.' ?>
                </p>
            </div>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                <?php if (!$is_admin_layout): ?>
                    <a href="#write-review" class="btn btn-gold" style="font-weight: 700;">
                        <i class="fa-solid fa-pen-nib"></i> Write a Review
                    </a>
                <?php endif; ?>
                <?php if ($is_admin_layout): ?>
                    <a href="admin-dashboard.php" class="admin-btn admin-btn-outline">
                        <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div style="background: rgba(16,185,129,0.15); color: #10b981; padding: 1rem 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; justify-content: space-between; border: 1px solid rgba(16,185,129,0.3);">
                <div>
                    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success_msg) ?>
                </div>
                <a href="reviews.php" style="color: inherit; text-decoration: none; font-size: 0.9rem;"><i class="fa-solid fa-xmark"></i> Dismiss</a>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_msg)): ?>
            <div style="background: rgba(239,68,68,0.15); color: #ef4444; padding: 1rem 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 600; display: flex; align-items: center; justify-content: space-between; border: 1px solid rgba(239,68,68,0.3);">
                <div>
                    <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error_msg) ?>
                </div>
                <a href="reviews.php" style="color: inherit; text-decoration: none; font-size: 0.9rem;"><i class="fa-solid fa-xmark"></i> Dismiss</a>
            </div>
        <?php endif; ?>

        <!-- KPI Metric Cards for Admin Layout -->
        <?php if ($is_admin_layout): ?>
            <div class="admin-grid" style="margin-bottom: 2rem;">
                <div class="admin-card admin-stat-card">
                    <span class="admin-stat-label">Overall Rating</span>
                    <div class="admin-stat-value" style="color: var(--gold-glow);"><?= $avg_rating ?> <span style="font-size: 1rem; color: #94a3b8;">/ 5.0</span></div>
                    <span class="admin-badge admin-badge-gold"><i class="fa-solid fa-star"></i> <?= $total_reviews ?> Verified Records</span>
                </div>
                <div class="admin-card admin-stat-card">
                    <span class="admin-stat-label">Total Published Reviews</span>
                    <div class="admin-stat-value" style="color: var(--primary-glow);"><?= $total_reviews ?></div>
                    <span class="admin-badge admin-badge-purple"><i class="fa-solid fa-comments"></i> Guest Feedback</span>
                </div>
                <div class="admin-card admin-stat-card">
                    <span class="admin-stat-label">Responded by Staff</span>
                    <div class="admin-stat-value" style="color: var(--emerald);"><?= $with_replies ?></div>
                    <span class="admin-badge admin-badge-success"><i class="fa-solid fa-reply"></i> Official Replies</span>
                </div>
                <div class="admin-card admin-stat-card">
                    <span class="admin-stat-label">Pending Staff Response</span>
                    <div class="admin-stat-value" style="color: <?= $pending_replies > 0 ? 'var(--rose)' : 'var(--emerald)' ?>;"><?= $pending_replies ?></div>
                    <?php if ($pending_replies > 0): ?>
                        <span class="admin-badge admin-badge-danger"><i class="fa-solid fa-clock"></i> Action Needed</span>
                    <?php else: ?>
                        <span class="admin-badge admin-badge-success"><i class="fa-solid fa-check-double"></i> All Responded</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Admin Filter Tabs -->
            <div style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; background: #0f172a; padding: 0.5rem; border-radius: 8px; border: 1px solid var(--admin-card-border); align-items: center;">
                <a href="reviews.php?filter=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" class="admin-btn admin-btn-sm" style="<?= $filter === 'all' ? 'background: var(--purple-accent); color: #fff;' : 'background: transparent; color: #94a3b8;' ?>">
                    <i class="fa-solid fa-list"></i> All Reviews (<?= $total_reviews ?>)
                </a>
                <a href="reviews.php?filter=pending_reply<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" class="admin-btn admin-btn-sm" style="<?= $filter === 'pending_reply' ? 'background: #f59e0b; color: #000;' : 'background: transparent; color: #f59e0b;' ?>">
                    <i class="fa-solid fa-clock"></i> Needs Response (<?= $pending_replies ?>)
                </a>
                <a href="reviews.php?filter=replied<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" class="admin-btn admin-btn-sm" style="<?= $filter === 'replied' ? 'background: #10b981; color: #fff;' : 'background: transparent; color: #34d399;' ?>">
                    <i class="fa-solid fa-reply-all"></i> Responded (<?= $with_replies ?>)
                </a>
            </div>
        <?php else: ?>
            <!-- Guest Overview Banner Card -->
            <div class="card" style="display: flex; flex-wrap: wrap; justify-content: space-around; align-items: center; text-align: center; margin-bottom: 2rem; background: linear-gradient(135deg, var(--primary-purple), var(--purple-accent)); color: #fff; border: none; padding: 2.2rem 1.5rem;">
                <div style="min-width: 200px;">
                    <h1 style="font-size: 3.8rem; font-family: 'Outfit', sans-serif; color: #fbbf24; margin: 0; line-height: 1;"><?= $avg_rating ?></h1>
                    <div style="color: #fbbf24; font-size: 1.35rem; margin-top: 0.5rem;">
                        <?php for ($i = 0; $i < floor($avg_rating); $i++): ?>
                            <i class="fa-solid fa-star"></i>
                        <?php endfor; ?>
                        <?php if ($avg_rating - floor($avg_rating) >= 0.5): ?>
                            <i class="fa-solid fa-star-half-stroke"></i>
                        <?php endif; ?>
                    </div>
                    <p style="font-size: 0.9rem; opacity: 0.9; margin-top: 0.4rem;">Overall Rating based on <?= $total_reviews ?> Reviews</p>
                </div>

                <div style="border-left: 1px solid rgba(255,255,255,0.25); padding-left: 2rem; text-align: left; min-width: 240px;">
                    <p style="margin-bottom: 0.5rem;"><i class="fa-solid fa-circle-check" style="color: #fbbf24;"></i> 99% Clean Bamboo Kubos & Suites</p>
                    <p style="margin-bottom: 0.5rem;"><i class="fa-solid fa-circle-check" style="color: #fbbf24;"></i> 98% Friendly & Attentive Staff Hospitality</p>
                    <p style="margin-bottom: 0.5rem;"><i class="fa-solid fa-circle-check" style="color: #fbbf24;"></i> 99% Instant Online GCash Verification</p>
                    <p style="margin: 0;"><i class="fa-solid fa-circle-check" style="color: #fbbf24;"></i> 100% Transparent Guest Feedback</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Submit Review Form for Guests -->
        <?php if (!$is_admin_layout): ?>
        <div class="card" id="write-review" style="margin-bottom: 2.5rem; border-top: 4px solid var(--bamboo-gold);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                <h3 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-pen-to-square" style="color: var(--bamboo-gold);"></i> 
                    Share Your Resort Experience
                </h3>
                <?php if ($is_logged_in): ?>
                    <span class="badge badge-gold">
                        <i class="fa-solid fa-user-check"></i> Verified Guest: <?= htmlspecialchars($current_user_name) ?>
                    </span>
                <?php else: ?>
                    <span style="font-size: 0.82rem; color: var(--text-muted);">
                        <a href="login.php?redirect=reviews.php" style="color: var(--primary-purple); font-weight: 600; text-decoration: underline;">Log In</a> for verified member badge
                    </span>
                <?php endif; ?>
            </div>

            <form action="reviews.php" method="POST">
                <?php if (!$is_logged_in): ?>
                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label style="font-weight: 600; color: var(--text-dark);"><i class="fa-solid fa-user"></i> Your Full Name *</label>
                        <input type="text" name="guest_name" class="form-control" placeholder="e.g. Maria Clara Santos" required>
                    </div>
                <?php endif; ?>

                <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-group">
                        <label style="font-weight: 600; color: var(--text-dark);"><i class="fa-solid fa-star" style="color: #fbbf24;"></i> Overall Rating *</label>
                        <select name="rating" class="form-control" required>
                            <option value="5" selected>⭐⭐⭐⭐⭐ 5 Stars - Outstanding & Exceptional</option>
                            <option value="4">⭐⭐⭐⭐ 4 Stars - Very Good & Enjoyable</option>
                            <option value="3">⭐⭐⭐ 3 Stars - Average / Standard Stay</option>
                            <option value="2">⭐⭐ 2 Stars - Below Expectations</option>
                            <option value="1">⭐ 1 Star - Poor Experience</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label style="font-weight: 600; color: var(--text-dark);"><i class="fa-solid fa-house-chimney" style="color: var(--primary-purple);"></i> Accommodation / Activity Stayed *</label>
                        <select name="item" id="reviewItemSelect" class="form-control" required onchange="toggleCustomItem(this)">
                            <?php if (!empty($user_past_items)): ?>
                                <optgroup label="Your Recent Bookings">
                                    <?php foreach ($user_past_items as $upi): ?>
                                        <option value="<?= htmlspecialchars($upi) ?>"><?= htmlspecialchars($upi) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <optgroup label="Bamboo Kubos & Pavilions">
                                <option value="Beach Kubo">Beach Kubo</option>
                                <option value="Orchid Honeymoon Kubo #2">Orchid Honeymoon Kubo #2</option>
                                <option value="Royal Purple Pavilion #3">Royal Purple Pavilion #3</option>
                                <option value="Family Bamboo Villa #4">Family Bamboo Villa #4</option>
                            </optgroup>
                            <optgroup label="Pool & Resort Activities">
                                <option value="Adult Crystal Swimming Pool Pass">Adult Crystal Swimming Pool Pass</option>
                                <option value="Kiddie Infinity Water Slide Pass">Kiddie Infinity Water Slide Pass</option>
                                <option value="Night Swimming Pass & Pavilion Access">Night Swimming Pass & Pavilion Access</option>
                                <option value="Clear Kayak & Lagoon Experience">Clear Kayak & Lagoon Experience</option>
                                <option value="Gazebo Lounge & BBQ Station">Gazebo Lounge & BBQ Station</option>
                                <option value="Resort Dining & In-Room Catering">Resort Dining & In-Room Catering</option>
                            </optgroup>
                            <option value="Other">Other / Custom Accommodation</option>
                        </select>
                    </div>
                </div>

                <div id="customItemWrapper" class="form-group" style="display: none; margin-bottom: 1rem;">
                    <label style="font-weight: 600; color: var(--text-dark);"><i class="fa-solid fa-tag"></i> Specify Custom Name</label>
                    <input type="text" name="custom_item" class="form-control" placeholder="e.g. Poolside Cabana #5">
                </div>

                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label style="font-weight: 600; color: var(--text-dark);"><i class="fa-solid fa-comment-dots"></i> Review Comments & Experience Details *</label>
                    <textarea name="comment" class="form-control" rows="4" placeholder="Describe your stay, the cleanliness of the facilities, staff hospitality, and overall experience..." required></textarea>
                </div>

                <button type="submit" name="submit_review" class="btn btn-gold" style="font-weight: 700; padding: 0.85rem 1.8rem; font-size: 1rem;">
                    <i class="fa-solid fa-paper-plane"></i> Publish My Review
                </button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Section Title for Reviews List -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
            <h2 style="font-family: 'Outfit', sans-serif; font-size: 1.4rem; color: <?= $is_admin_layout ? '#ffffff' : 'var(--primary-purple)' ?>; margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fa-solid fa-comments" style="color: var(--bamboo-gold);"></i> 
                Published Reviews (<?= count($reviews_list) ?>)
            </h2>
            <?php if (!empty($search) || $filter !== 'all'): ?>
                <a href="reviews.php" style="font-size: 0.85rem; color: #3b82f6; text-decoration: none;">
                    <i class="fa-solid fa-arrows-rotate"></i> Clear Filters
                </a>
            <?php endif; ?>
        </div>

        <!-- List of Published Reviews -->
        <div>
            <?php if (empty($reviews_list)): ?>
                <div class="<?= $is_admin_layout ? 'admin-card' : 'card' ?>" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                    <i class="fa-regular fa-comment-dots" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                    <p style="font-size: 1.1rem; margin: 0;">No reviews found matching your search criteria.</p>
                    <a href="reviews.php" style="margin-top: 1rem; display: inline-block; color: #7c3aed; font-weight: 600;">View all reviews</a>
                </div>
            <?php else: ?>
                <?php foreach ($reviews_list as $r): ?>
                    <?php
                    $is_registered = !empty($r['guest_id']) || !empty($r['profile_id']);
                    $r_id = $r['id'];
                    ?>
                    <div class="<?= $is_admin_layout ? 'admin-card' : 'card' ?>" style="margin-bottom: 1.5rem; position: relative;">
                        <!-- Review Card Header -->
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
                            <div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <h3 style="font-family: 'Outfit', sans-serif; margin: 0; font-size: 1.15rem; color: <?= $is_admin_layout ? '#ffffff' : 'var(--primary-purple)' ?>;">
                                        <?= htmlspecialchars($r['guest']) ?>
                                    </h3>
                                    <?php if ($is_registered): ?>
                                        <span class="<?= $is_admin_layout ? 'admin-badge admin-badge-success' : 'badge badge-success' ?>" style="font-size: 0.72rem; padding: 0.2rem 0.5rem;">
                                            <i class="fa-solid fa-user-check"></i> Verified Guest
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <span style="font-size: 0.85rem; color: var(--text-muted); display: flex; align-items: center; gap: 6px; margin-top: 3px;">
                                    <i class="fa-solid fa-house-chimney" style="font-size: 0.8rem; color: var(--bamboo-gold);"></i> <strong><?= htmlspecialchars($r['item']) ?></strong> 
                                    &bull; 
                                    <i class="fa-regular fa-calendar" style="font-size: 0.8rem;"></i> <?= htmlspecialchars($r['date']) ?>
                                </span>
                            </div>

                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div style="color: #fbbf24; font-size: 1.15rem;">
                                    <?php for ($i = 0; $i < (int)$r['rating']; $i++): ?>
                                        <i class="fa-solid fa-star"></i>
                                    <?php endfor; ?>
                                    <?php for ($i = (int)$r['rating']; $i < 5; $i++): ?>
                                        <i class="fa-regular fa-star" style="opacity: 0.4;"></i>
                                    <?php endfor; ?>
                                </div>

                                <?php if ($is_admin_layout): ?>
                                    <form action="reviews.php" method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to remove this review?');">
                                        <input type="hidden" name="review_id" value="<?= $r_id ?>">
                                        <button type="submit" name="delete_review" style="background: transparent; border: none; color: #ef4444; cursor: pointer; padding: 4px 8px; font-size: 0.9rem;" title="Delete Review">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Guest Review Content -->
                        <p style="color: <?= $is_admin_layout ? '#cbd5e1' : 'var(--text-dark)' ?>; margin: 0.75rem 0; line-height: 1.65; font-size: 0.96rem;">
                            <?= nl2br(htmlspecialchars($r['comment'])) ?>
                        </p>

                        <!-- Staff / Management Reply Box -->
                        <?php if (!empty($r['staff_reply'])): ?>
                            <div style="background: <?= $is_admin_layout ? '#1e293b' : '#faf5ff' ?>; padding: 1.1rem; border-radius: 8px; margin-top: 1rem; border-left: 4px solid #8b5cf6; border: 1px solid <?= $is_admin_layout ? '#334155' : '#f3e8ff' ?>;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem; flex-wrap: wrap; gap: 6px;">
                                    <span style="font-weight: 700; font-size: 0.88rem; color: <?= $is_admin_layout ? '#c084fc' : 'var(--primary-purple)' ?>; display: flex; align-items: center; gap: 6px;">
                                        <i class="fa-solid fa-reply"></i> 🌿 Response from <?= htmlspecialchars($r['staff_reply_by'] ?? 'Resort Management') ?>
                                    </span>
                                    <?php if (!empty($r['staff_reply_at'])): ?>
                                        <span style="font-size: 0.78rem; color: var(--text-muted);">
                                            <?= htmlspecialchars($r['staff_reply_at']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <p style="font-size: 0.92rem; color: <?= $is_admin_layout ? '#e2e8f0' : '#4b5563' ?>; margin: 0; line-height: 1.55;">
                                    <?= nl2br(htmlspecialchars($r['staff_reply'])) ?>
                                </p>

                                <?php if ($is_admin_layout): ?>
                                    <div style="margin-top: 0.75rem; text-align: right;">
                                        <button type="button" class="admin-btn admin-btn-sm" style="background: transparent; color: #a855f7; border: 1px dashed #a855f7; font-size: 0.78rem; padding: 0.25rem 0.6rem;" onclick="toggleReplyEdit('replyEditForm_<?= $r_id ?>')">
                                            <i class="fa-solid fa-pen"></i> Edit Official Response
                                        </button>
                                    </div>

                                    <form id="replyEditForm_<?= $r_id ?>" action="reviews.php" method="POST" style="margin-top: 0.75rem; display: none;">
                                        <input type="hidden" name="review_id" value="<?= $r_id ?>">
                                        <div style="display: flex; gap: 0.5rem;">
                                            <input type="text" name="reply_text" class="form-control" value="<?= htmlspecialchars($r['staff_reply']) ?>" style="flex: 1; background: #0f172a; color: #fff; border: 1px solid var(--admin-card-border);" required>
                                            <button type="submit" name="submit_reply" class="admin-btn admin-btn-gold btn-sm">
                                                <i class="fa-solid fa-check"></i> Update
                                            </button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($is_admin_layout || $user_role === 'staff'): ?>
                            <!-- Reply Form for Unanswered Reviews -->
                            <div style="background: rgba(124, 58, 237, 0.08); padding: 1rem; border-radius: 8px; margin-top: 1rem; border: 1px dashed rgba(124, 58, 237, 0.4);">
                                <div style="font-size: 0.85rem; font-weight: 700; color: #c084fc; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 6px;">
                                    <i class="fa-solid fa-reply"></i> Post Official Management Reply to <?= htmlspecialchars($r['guest']) ?>:
                                </div>
                                <form action="reviews.php" method="POST">
                                    <input type="hidden" name="review_id" value="<?= $r_id ?>">
                                    <div style="display: flex; gap: 0.5rem;">
                                        <input type="text" name="reply_text" class="form-control" placeholder="Write staff response here (e.g. Salamat for visiting Purple Bamboo Resort...)" style="flex: 1; background: #0f172a; color: #fff; border: 1px solid var(--admin-card-border);" required>
                                        <button type="submit" name="submit_reply" class="admin-btn admin-btn-gold btn-sm" style="white-space: nowrap;">
                                            <i class="fa-solid fa-paper-plane"></i> Post Reply
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    <?php if ($is_admin_layout): ?>
            </main>
        </div>
    <?php else: ?>
        </div>
        <?php include 'footer.php'; ?>
    <?php endif; ?>

    <script>
    function toggleCustomItem(selectElem) {
        const customWrapper = document.getElementById('customItemWrapper');
        if (selectElem.value === 'Other') {
            customWrapper.style.display = 'block';
            customWrapper.querySelector('input').setAttribute('required', 'required');
        } else {
            customWrapper.style.display = 'none';
            customWrapper.querySelector('input').removeAttribute('required');
        }
    }

    function toggleReplyEdit(formId) {
        const form = document.getElementById(formId);
        if (form) {
            form.style.display = (form.style.display === 'none' || form.style.display === '') ? 'block' : 'none';
        }
    }
    </script>

</body>
</html>