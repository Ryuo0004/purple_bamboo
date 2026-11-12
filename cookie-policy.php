<?php
header("Location: privacy-policy.php");
exit();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cookie Policy | The Blessed Purple Bamboo Resort</title>
    <link rel="icon" type="image/jpeg" href="assets/logo.jpg">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
</head>
<body>

    <?php include 'nav.php'; ?>

    <main class="main-content">
        <div class="container" style="max-width: 900px; margin-top: 1.5rem; margin-bottom: 4rem;">
            
            <div style="margin-bottom: 2rem;">
                <span class="admin-badge admin-badge-purple" style="margin-bottom: 0.5rem;">
                    <i class="fa-solid fa-cookie-bite"></i> Cookie Transparency & Privacy
                </span>
                <h1 style="font-size: 2.2rem; color: var(--purple-dark); margin-bottom: 0.5rem;">
                    Cookie Policy
                </h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">
                    Last updated: September 2026 | How we use cookies and browser storage
                </p>
            </div>

            <div class="admin-card" style="padding: 2.5rem; line-height: 1.8;">

                <p style="color: var(--text-main); font-size: 1.05rem;">
                    This Cookie Policy explains how <strong>The Blessed Purple Bamboo Beach Resort</strong> uses cookies and similar storage technologies to recognize you when you visit our website and guest portal.
                </p>

                <h2 style="color: #4c1d95; font-size: 1.35rem; margin-top: 2rem; margin-bottom: 0.75rem;">1. What Are Cookies?</h2>
                <p style="color: var(--text-main);">
                    Cookies are small data files stored on your computer or mobile device when you visit a web portal. They are widely used by website operators to make websites work efficiently, enhance user experience, and provide reporting information.
                </p>

                <h2 style="color: #4c1d95; font-size: 1.35rem; margin-top: 2rem; margin-bottom: 0.75rem;">2. Cookies We Use</h2>
                <div style="overflow-x: auto; margin-top: 1rem; margin-bottom: 1.5rem;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left;">
                        <thead>
                            <tr style="background: #f1f5f9; border-bottom: 2px solid #cbd5e1;">
                                <th style="padding: 10px; color: #1e293b;">Cookie Name</th>
                                <th style="padding: 10px; color: #1e293b;">Category</th>
                                <th style="padding: 10px; color: #1e293b;">Purpose</th>
                                <th style="padding: 10px; color: #1e293b;">Duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                <td style="padding: 10px;"><code>PHPSESSID</code></td>
                                <td style="padding: 10px;"><span class="admin-badge admin-badge-purple">Strictly Necessary</span></td>
                                <td style="padding: 10px;">Maintains secure user session state across pages for logged-in guests.</td>
                                <td style="padding: 10px;">Session (Closes with browser)</td>
                            </tr>
                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                <td style="padding: 10px;"><code>cookie_consent</code></td>
                                <td style="padding: 10px;"><span class="admin-badge admin-badge-gold">Preferences</span></td>
                                <td style="padding: 10px;">Stores your cookie consent selection so you are not prompted repeatedly.</td>
                                <td style="padding: 10px;">1 Year</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h2 style="color: #4c1d95; font-size: 1.35rem; margin-top: 2rem; margin-bottom: 0.75rem;">3. Third-Party CDN & Assets Safety</h2>
                <p style="color: var(--text-main);">
                    We utilize trusted Content Delivery Networks (FontAwesome CDN via Cloudflare and Google Fonts) to load UI icons and typography securely. These assets do not track or store personal identifying information.
                </p>

                <h2 style="color: #4c1d95; font-size: 1.35rem; margin-top: 2rem; margin-bottom: 0.75rem;">4. Managing Your Cookie Preferences</h2>
                <p style="color: var(--text-main);">
                    You have the right to decide whether to accept or decline non-essential cookies. You can set or amend your web browser controls to accept or refuse cookies. If you choose to reject cookies, you may still use our website, though access to some functionality (such as keeping active login sessions) may be restricted.
                </p>
                <div style="margin-top: 1.5rem;">
                    <button type="button" onclick="resetCookieConsent()" class="admin-btn admin-btn-primary">
                        <i class="fa-solid fa-rotate-left"></i> Reset Cookie Preferences
                    </button>
                </div>

            </div>
        </div>
    </main>

    <script>
    function resetCookieConsent() {
        localStorage.removeItem('cookie_consent');
        document.cookie = 'cookie_consent=; path=/; expires=Thu, 01 Jan 1970 00:00:01 GMT;';
        alert('Your cookie preferences have been reset. Reloading page...');
        window.location.reload();
    }
    </script>

    <?php include 'footer.php'; ?>

</body>
</html>
