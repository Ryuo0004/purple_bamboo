<?php
require_once __DIR__ . '/content_store.php';
$footer_cms = ContentStore::getContent();
$footer_logo = !empty($footer_cms['resort_logo']) ? $footer_cms['resort_logo'] : 'assets/logo.jpg';
$footer_title = !empty($footer_cms['resort_name']) ? $footer_cms['resort_name'] : (!empty($footer_cms['resort_brand_title']) ? $footer_cms['resort_brand_title'] : 'Purple Bamboo');
$uri = $_SERVER['REQUEST_URI'] ?? '';
$is_dashboard = strpos($uri, 'user-dashboard.php') !== false || strpos($uri, 'admin-dashboard.php') !== false;
$is_index = strpos($uri, 'index.php') !== false || rtrim(parse_url($uri, PHP_URL_PATH), '/') === '' || rtrim(parse_url($uri, PHP_URL_PATH), '/') === '/purple_bamboo';

?>
<?php if (!$is_dashboard): ?>
<footer style="background: #140218; border-top: 1px solid #380a42; padding: 3rem 2rem; margin-top: 3rem;">
    <div style="max-width: 1240px; margin: 0 auto;">
        <?php if ($is_index): ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr)); gap: 2rem; text-align: left; padding-bottom: 2.5rem; border-bottom: 1px solid #2d0b34;" class="footer-grid">
            
            <!-- Brand Section -->
            <div>
                <h3 style="color: #00c2ff; font-family: 'Outfit', sans-serif; font-size: 1.5rem; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 10px;">
                    <img src="<?= htmlspecialchars($footer_logo) ?>" alt="<?= htmlspecialchars($footer_title) ?> Logo" class="footer-brand-logo-img" onerror="this.onerror=null; this.src='logo.jpg';"> <span><?= htmlspecialchars($footer_title) ?></span>
                </h3>
                <p style="color: #94a3b8; font-size: 0.9rem; max-width: 300px; line-height: 1.6;">
                    Experience serene kubos, infinity pools, and tropical bliss In Telbang, Alaminos City, Pangasinan
                </p>
                <div style="margin-top: 1rem; display: flex; gap: 0.75rem;">
                    <a href="https://www.facebook.com/TheBlessedPurpleBambooBeachResort" target="_blank" rel="noopener noreferrer" aria-label="Visit Purple Bamboo Resort on Facebook" style="color: #94a3b8; font-size: 1.2rem; transition: color 0.3s;" onmouseover="this.style.color='#00c2ff'" onmouseout="this.style.color='#94a3b8'">
                        <i class="fa-brands fa-facebook"></i>
                    </a>
                </div>
            </div>

            <!-- Contact & Payments -->
            <div>
                <h4 style="color: #f8fafc; font-family: 'Outfit', sans-serif; font-size: 1rem; margin-bottom: 1rem; letter-spacing: 0.5px;">Contact & Location</h4>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <li style="color: #94a3b8; margin-bottom: 0.8rem; font-size: 0.9rem; display: flex; gap: 10px;">
                        <i class="fa-solid fa-location-dot" style="color: #00c2ff; width: 18px; margin-top: 3px;"></i>
                        <span>Zone 4, Magamo-gamo, Telbang, Alaminos City, Pangasinan, Philippines 2404</span>
                    </li>
                    <li style="color: #94a3b8; margin-bottom: 0.8rem; font-size: 0.9rem; display: flex; gap: 10px;">
                        <i class="fa-solid fa-phone" style="color: #00c2ff; width: 18px;"></i>
                        <span>📱 GCash Hotline: <strong style="color: #00c2ff;">09566978679</strong></span>
                    </li>
                    <li style="color: #94a3b8; margin-bottom: 0.8rem; font-size: 0.9rem; display: flex; gap: 10px;">
                        <i class="fa-solid fa-envelope" style="color: #00c2ff; width: 18px;"></i>
                        <span>theblessedpbbresort23@gmail.com</span>
                    </li>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <!-- Bottom Legal & Compliance Disclaimer Bar -->
        <div style="text-align: center; padding-top: <?= $is_index ? '1.5rem' : '0' ?>;">
            <div style="display: flex; justify-content: center; gap: 1.5rem; margin-bottom: 1rem; flex-wrap: wrap;">
                <a href="terms.php" style="color: #94a3b8; font-size: 0.85rem; text-decoration: none;" onmouseover="this.style.color='#e0129c'" onmouseout="this.style.color='#94a3b8'">Terms & Conditions</a>
                <a href="privacy-policy.php" style="color: #94a3b8; font-size: 0.85rem; text-decoration: none;" onmouseover="this.style.color='#e0129c'" onmouseout="this.style.color='#94a3b8'">Privacy Policy</a>
                <a href="refund-policy.php" style="color: #94a3b8; font-size: 0.85rem; text-decoration: none;" onmouseover="this.style.color='#e0129c'" onmouseout="this.style.color='#94a3b8'">Refund Policy</a>
            </div>
            <p style="color: #64748b; font-size: 0.82rem; margin: 0; line-height: 1.6;">
                &copy; <?= date('Y') ?> The Blessed Purple Bamboo Beach Resort. All rights reserved. Compliant with Philippine Data Privacy Act (RA 10173), Consumer Protection Act (RA 7394), and E-Commerce Act (RA 8792).
            </p>
        </div>
    </div>
</footer>
<?php endif; ?>



<script>
document.addEventListener('DOMContentLoaded', function() {
    // Enforce 11-digit numeric limit on all phone number inputs across all pages
    document.querySelectorAll('input[type="tel"]').forEach(function(input) {
        input.setAttribute('maxlength', '11');
        input.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 11);
        });
    });
});
</script>