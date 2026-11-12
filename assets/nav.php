<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_page = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['role'] ?? 'guest';
$is_logged_in = isset($_SESSION['user_id']);
$user_name = '';
if (!empty(trim($_SESSION['full_name'] ?? '')) && strtolower(trim($_SESSION['full_name'])) !== 'guest visitor') {
    $user_name = trim($_SESSION['full_name']);
} elseif (!empty(trim($_SESSION['first_name'] ?? '')) && strtolower(trim($_SESSION['first_name'])) !== 'guest') {
    $user_name = trim($_SESSION['first_name'] . ' ' . ($_SESSION['last_name'] ?? ''));
} elseif (!empty(trim($_SESSION['username'] ?? '')) && strtolower(trim($_SESSION['username'])) !== 'guest visitor') {
    $user_name = trim($_SESSION['username']);
} elseif (!empty($_SESSION['email'])) {
    $user_name = ucwords(str_replace(['.', '_', '-'], ' ', explode('@', $_SESSION['email'])[0]));
} else {
    $user_name = 'Member';
}

$auth_pages = ['login.php', 'register.php', 'admin-login.php'];
$is_auth_page = in_array(strtolower($current_page), $auth_pages);
?>

<!-- Resort Top Header Navigation -->
<header class="hs-top-header">
    <button class="hs-mobile-menu-btn" onclick="toggleAppSidebar()" aria-label="Toggle Menu">
        <i class="fa-solid fa-bars"></i>
    </button>
   <br><br><br>

    <div class="hs-header-right">
        <?php if ($is_logged_in): ?>
            <?php if (in_array(strtolower($user_role), ['admin', 'staff'])): ?>
                <a href="admin-dashboard.php" class="hs-btn-login" style="background: linear-gradient(135deg, #7c3aed, #4f46e5); color: #fff; border: none; font-size: 0.8rem; padding: 0.4rem 0.85rem; border-radius: 9999px; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 8px rgba(124, 58, 237, 0.3);">
                    <i class="fa-solid fa-shield-halved"></i> Admin Console
                </a>
            <?php endif; ?>
            <a href="user-dashboard.php" class="hs-user-badge">
                <span class="hs-user-avatar"><?= strtoupper(substr($user_name, 0, 1)) ?></span>
                <span class="hs-user-name"><?= htmlspecialchars($user_name) ?></span>
            </a>
            <a href="logout.php" class="hs-btn-logout" onclick="openLogoutModal(event)" title="Sign Out"><i class="fa-solid fa-sign-out-alt"></i></a>
        <?php else: ?>
            <?php if (!$is_auth_page): ?>
                <a href="register.php" class="hs-btn-orange"><i class="fa-solid fa-user-plus"></i> Register</a>
                <a href="login.php" class="hs-btn-login"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</header>


<!-- Mobile Sidebar Backdrop Overlay -->
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleAppSidebar()"></div>

<!-- Left Sidebar Navigation (The Blessed Purple Bamboo Resort) -->
<aside class="app-sidebar" id="appSidebar">
    <!-- Sidebar Brand Header -->
    <div class="sidebar-brand">
        <a href="index.php" class="brand-wrapper">
            <img src="assets/logo.jpg" alt="The Blessed Purple Bamboo Beach Resort Logo" class="sidebar-brand-logo-img">
            <div class="brand-text-group">
                <span class="brand-title">PURPLE BAMBOO</span>
                <span class="brand-subtitle">BEACH RESORT</span>
            </div>
        </a>
        <button class="sidebar-close-btn" onclick="toggleAppSidebar()" aria-label="Close Navigation">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <!-- Sidebar Scrollable Navigation Content -->
    <div class="sidebar-content">
        <!-- Main Resort Section -->
        <div class="sidebar-section-label">Main Navigation</div>
        <ul class="sidebar-menu">
            <?php if ($is_logged_in && in_array(strtolower($user_role), ['admin', 'staff'])): ?>
            <li>
                <a href="admin-dashboard.php" class="sidebar-link" style="background: rgba(124, 58, 237, 0.08); color: var(--primary-purple); font-weight: 700; border-left: 3px solid var(--primary-purple);">
                    <i class="fa-solid fa-gauge-high" style="color: var(--primary-purple);"></i>
                    <span>Admin Command Center</span>
                </a>
            </li>
            <?php endif; ?>
            <li>
                <a href="index.php" class="sidebar-link <?= $current_page == 'index.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-house"></i>
                    <span>Home</span>
                </a>
            </li>
            <li>
                <a href="browse_kubos.php" class="sidebar-link <?= $current_page == 'browse_kubos.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-house-chimney"></i>
                    <span>Explore House Kubos</span>
                </a>
            </li>
            <li>
                <a href="browse_activities.php" class="sidebar-link <?= $current_page == 'browse_activities.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-water-ladder"></i>
                    <span>Pool & Activities</span>
                </a>
            </li>
            <?php if ($is_logged_in): ?>
            <li>
                <a href="dining.php" class="sidebar-link <?= $current_page == 'dining.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-utensils"></i>
                    <span>Dining Menu</span>
                </a>
            </li>
            <?php endif; ?>
            <li>
                <a href="reviews.php" class="sidebar-link <?= $current_page == 'reviews.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-star"></i>
                    <span>Guest Reviews</span>
                </a>
            </li>
            <li>
                <a href="<?= $is_logged_in ? 'history.php' : 'login.php?redirect=history.php' ?>" class="sidebar-link <?= in_array($current_page, ['history.php', 'my_bookings.php']) ? 'active' : '' ?>">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <span>My Bookings</span>
                </a>
            </li>
            <li>
                <a href="<?= $is_logged_in ? 'user-dashboard.php' : 'login.php?redirect=user-dashboard.php' ?>" class="sidebar-link <?= in_array($current_page, ['user-dashboard.php', 'profile.php']) ? 'active' : '' ?>">
                    <i class="fa-solid fa-user"></i>
                    <span>Profile</span>
                </a>
            </li>
            <?php if ($is_logged_in): ?>
            <li>
                <a href="logout.php" onclick="openLogoutModal(event)" class="sidebar-link sidebar-link-logout" style="color: #fb7185;">
                    <i class="fa-solid fa-arrow-right-from-bracket" style="color: #f43f5e;"></i>
                    <span>Logout</span>
                </a>
            </li>
            <?php else: ?>
            <li>
                <a href="login.php" class="sidebar-link <?= in_array($current_page, ['login.php']) ? 'active' : '' ?>">
                    <i class="fa-solid fa-right-to-bracket" style="color: #38bdf8;"></i>
                    <span>Login</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <!-- Legal & Policies Section -->
        <div class="sidebar-section-label">Legal & Policies</div>
        <ul class="sidebar-menu">
            <li>
                <a href="privacy-policy.php" class="sidebar-link <?= $current_page == 'privacy-policy.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-shield-halved"></i>
                    <span>Privacy Policy</span>
                </a>
            </li>
            <li>
                <a href="terms.php" class="sidebar-link <?= $current_page == 'terms.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-file-contract"></i>
                    <span>Terms & Conditions</span>
                </a>
            </li>
            <li>
                <a href="refund-policy.php" class="sidebar-link <?= $current_page == 'refund-policy.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-money-bill-transfer"></i>
                    <span>Refund Policy</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Sidebar Footer Info Badge -->
    <div class="sidebar-user-footer" style="flex-direction: column; align-items: stretch;">
        <?php if ($is_logged_in): ?>
            <div class="sidebar-user-card">
                <div class="sidebar-user-avatar">
                    <?= strtoupper(substr($user_name, 0, 1)) ?>
                </div>
                <div class="sidebar-user-info">
                    <span class="sidebar-user-name"><?= htmlspecialchars($user_name) ?></span>
                    <span class="sidebar-user-role"><?= in_array(strtolower($user_role), ['admin', 'staff']) ? (ucfirst($user_role) . ' (Console Access)') : 'Guest Member' ?></span>
                </div>
            </div>
            <a href="logout.php" onclick="openLogoutModal(event)" style="margin-top: 8px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; width: 100%; padding: 7px 12px; background: rgba(244,63,94,0.12); color: #f43f5e; border: 1.5px solid rgba(244,63,94,0.35); border-radius: 8px; font-weight: 700; text-decoration: none; font-size: 0.82rem;">
                <i class="fa-solid fa-sign-out-alt"></i> Sign Out
            </a>
        <?php else: ?>
            <div style="font-size: 0.85rem; color: var(--text-dark);">
                <p style="font-weight: 700; color: var(--primary-purple);"><i class="fa-solid fa-phone" style="color: var(--bamboo-amber);"></i> Resort Hotline:</p>
                <p style="font-weight: 800; color: #d97706; margin-top: 2px;">0956 697 8679</p>
            </div>
        <?php endif; ?>
    </div>
</aside>

<script>
function toggleAppSidebar() {
    const sidebar = document.getElementById('appSidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (sidebar && backdrop) {
        sidebar.classList.toggle('open');
        backdrop.classList.toggle('open');
    }
}

/**
 * Smart Search Router — routes search queries to the correct page
 * based on keyword matching instead of always going to check_availability.php
 */
function handleSmartSearch(event) {
    event.preventDefault();
    const input = document.getElementById('hsSmartSearchInput');
    const query = (input ? input.value : '').trim().toLowerCase();

    if (!query) return false;

    // Define keyword-to-page routing map
    const routes = [
        {
            keywords: ['kubo', 'kubos', 'room', 'rooms', 'house', 'bamboo kubo', 'mahogany', 'orchid', 'pavilion', 'accommodation', 'stay', 'overnight', 'bed', 'villa'],
            page: 'browse_kubos.php'
        },
        {
            keywords: ['pool', 'swim', 'swimming', 'activity', 'activities', 'kayak', 'kayaking', 'paddle', 'spa', 'sauna', 'massage', 'water', 'slide', 'lifeguard', 'water park', 'pool pass', 'day pass'],
            page: 'browse_activities.php'
        },
        {
            keywords: ['dining', 'food', 'menu', 'eat', 'restaurant', 'meal', 'lunch', 'dinner', 'breakfast', 'snack', 'order', 'kitchen', 'rice', 'chicken', 'pork', 'fish', 'dessert', 'drink', 'beverage', 'kain'],
            page: '<?= $is_logged_in ? "dining.php" : "login.php?redirect=dining.php" ?>'
        },
        {
            keywords: ['review', 'reviews', 'rating', 'ratings', 'feedback', 'testimonial', 'comment', 'comments', 'star', 'stars', 'guest review'],
            page: 'reviews.php'
        },
        {
            keywords: ['refund', 'cancel', 'cancellation', 'return', 'money back', 'refund policy'],
            page: 'refund-policy.php'
        },
        {
            keywords: ['privacy', 'data privacy', 'personal data', 'ra 10173'],
            page: 'privacy-policy.php'
        },
        {
            keywords: ['terms', 'conditions', 'terms and conditions', 'agreement'],
            page: 'terms.php'
        },
        {
            keywords: ['book', 'booking', 'reserve', 'reservation', 'availability', 'available', 'check-in', 'check-out', 'check in', 'check out', 'date', 'dates', 'calendar', 'gcash'],
            page: 'check_availability.php'
        },
        {
            keywords: ['login', 'sign in', 'log in'],
            page: 'login.php'
        },
        {
            keywords: ['register', 'sign up', 'signup', 'create account'],
            page: 'register.php'
        },
        {
            keywords: ['dashboard', 'profile', 'my account', 'my booking', 'my bookings'],
            page: 'user-dashboard.php'
        }
    ];

    // Check for keyword matches (longest/most specific match first)
    let bestMatch = null;
    let bestMatchLength = 0;

    for (const route of routes) {
        for (const keyword of route.keywords) {
            if (query.includes(keyword) && keyword.length > bestMatchLength) {
                bestMatch = route.page;
                bestMatchLength = keyword.length;
            }
        }
    }

    // Navigate to the best matching page, passing the search query
    if (bestMatch) {
        window.location.href = bestMatch + '?search=' + encodeURIComponent(input.value.trim());
    } else {
        // Default fallback: go to check_availability.php
        window.location.href = 'check_availability.php?search=' + encodeURIComponent(input.value.trim());
    }

    return false;
}

// Global Logout Confirmation Modal Functions
function openLogoutModal(e) {
    if (e) e.preventDefault();
    const modal = document.getElementById('logoutConfirmModal');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}
function closeLogoutModal() {
    const modal = document.getElementById('logoutConfirmModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLogoutModal();
});
</script>

<!-- Logout Confirmation Modal Dialog -->
<div id="logoutConfirmModal" class="logout-modal-backdrop" style="display: none;" onclick="if(event.target === this) closeLogoutModal();">
    <div class="logout-modal-dialog">
        <div class="logout-modal-icon">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </div>
        <h3 class="logout-modal-title">Sign Out Confirmation</h3>
        <p class="logout-modal-text">Do you really want to logout?</p>
        <div class="logout-modal-actions">
            <button type="button" class="logout-modal-btn logout-modal-btn-cancel" onclick="closeLogoutModal()">
                <i class="fa-solid fa-xmark"></i> No
            </button>
            <a href="logout.php?confirm=1" class="logout-modal-btn logout-modal-btn-confirm">
                <i class="fa-solid fa-check"></i> Yes
            </a>
        </div>
    </div>
</div>

<style>
.logout-modal-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(15, 23, 42, 0.75);
    backdrop-filter: blur(4px);
    z-index: 999999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    box-sizing: border-box;
}
.logout-modal-dialog {
    background: #1e1b4b;
    border: 1.5px solid #4c1d95;
    border-radius: 16px;
    padding: 2rem 1.75rem;
    max-width: 400px;
    width: 100%;
    text-align: center;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5), 0 0 24px rgba(124, 58, 237, 0.2);
    animation: scaleInLogoutModal 0.2s ease-out;
}
.logout-modal-icon {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: rgba(244, 63, 94, 0.15);
    color: #f43f5e;
    border: 2px solid rgba(244, 63, 94, 0.35);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-bottom: 1rem;
}
.logout-modal-title {
    font-family: 'Outfit', sans-serif;
    color: #ffffff;
    font-size: 1.25rem;
    font-weight: 700;
    margin: 0 0 0.5rem 0;
}
.logout-modal-text {
    color: #cbd5e1;
    font-size: 1rem;
    font-weight: 500;
    margin: 0 0 1.5rem 0;
}
.logout-modal-actions {
    display: flex;
    gap: 0.75rem;
    justify-content: center;
}
.logout-modal-btn {
    flex: 1;
    padding: 0.7rem 1rem;
    border-radius: 10px;
    font-size: 0.95rem;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
}
.logout-modal-btn-cancel {
    background: #334155;
    color: #ffffff;
    border: 1px solid #475569;
}
.logout-modal-btn-cancel:hover {
    background: #475569;
}
.logout-modal-btn-confirm {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: #ffffff;
    border: 1px solid #ef4444;
}
.logout-modal-btn-confirm:hover {
    background: #b91c1c;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.35);
}
.sidebar-link-logout {
    color: #fb7185 !important;
    transition: all 0.2s ease !important;
}
.sidebar-link-logout i {
    color: #f43f5e !important;
    transition: transform 0.2s ease !important;
}
.sidebar-link-logout:hover {
    background: rgba(244, 63, 94, 0.16) !important;
    border-left-color: #f43f5e !important;
    color: #ffffff !important;
}
.sidebar-link-logout:hover i {
    transform: translateX(3px);
}
@keyframes scaleInLogoutModal {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}
</style>