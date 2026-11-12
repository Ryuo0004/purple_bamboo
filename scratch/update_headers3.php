<?php
$files = ['bookings.php', 'checkin_checkout.php', 'dining_monitoring.php', 'activity_monitoring.php', 'user_management.php', 'reviews.php', 'admin_profile.php', 'manage_menu.php'];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);
    
    // Check if it has an admin-top-header
    if (strpos($content, 'class="admin-top-header"') !== false) {
        $pageName = ucfirst(str_replace(['.php', '_'], ['', ' '], $file));
        
        $replace = '<header class="admin-top-header">
                <div class="admin-header-left">
                    <button type="button" class="admin-mobile-toggle-btn" onclick="toggleAdminSidebar()" aria-label="Open Navigation Menu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <div class="admin-header-context">
                        <div class="admin-breadcrumbs">
                            <a href="admin-dashboard.php" class="crumb-link"><i class="fa-solid fa-house-chimney"></i> Console</a>
                            <span class="crumb-divider">/</span>
                            <span class="crumb-current"><i class="fa-solid fa-folder"></i> ' . $pageName . '</span>
                        </div>
                        <div class="admin-header-title-row">
                            <span class="admin-header-page-heading" style="font-family: \'Outfit\', sans-serif;">' . $pageName . '</span>
                        </div>
                    </div>
                </div>

                <div class="admin-header-actions">
                    <a href="checkin_checkout.php" class="admin-btn admin-btn-outline admin-btn-sm" style="border-radius: 8px; padding: 0.4rem 0.75rem;">
                        <i class="fa-solid fa-key"></i> Front Desk
                    </a>
                </div>
            </header>';
            
        // Use regex to replace to avoid whitespace mismatch
        $content = preg_replace('/<header class="admin-top-header">.*?<\/header>/s', $replace, $content);
        
        file_put_contents($file, $content);
        echo "Updated $file\n";
    }
}
?>
