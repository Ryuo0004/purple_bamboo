<?php
$files = glob('*.php');

foreach ($files as $file) {
    if (in_array($file, ['calendar.php'])) continue; // Already correct
    $content = file_get_contents($file);
    
    $search1 = '<header class="admin-top-header">


            <div class="admin-header-actions">
            </div>
        </header>';
        
    $search2 = '<header class="admin-top-header">
                <div class="admin-header-actions"></div>
            </header>';
            
    $search3 = '<header class="admin-top-header">
                <div class="admin-header-actions">
                </div>
            </header>';
            
    $isMatch = false;
    if (strpos(str_replace(["\r", "\n", "\t", " "], "", $content), str_replace(["\r", "\n", "\t", " "], "", $search1)) !== false) $isMatch = true;
    if (strpos(str_replace(["\r", "\n", "\t", " "], "", $content), str_replace(["\r", "\n", "\t", " "], "", $search2)) !== false) $isMatch = true;
    if (strpos(str_replace(["\r", "\n", "\t", " "], "", $content), str_replace(["\r", "\n", "\t", " "], "", $search3)) !== false) $isMatch = true;

    if ($isMatch) {
        $pageName = ucfirst(str_replace(['.php', '_', '-'], ['', ' ', ' '], $file));
        
        $replace = '<header class="admin-top-header">
                <div class="admin-header-left">
                    <button type="button" class="admin-mobile-toggle-btn" onclick="toggleAdminSidebar()" aria-label="Open Navigation Menu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <div class="admin-header-context">
                        <div class="admin-breadcrumbs">
                            <a href="admin-dashboard.php" class="crumb-link"><i class="fa-solid fa-house-chimney"></i> Console</a>
                            <span class="crumb-divider">/</span>
                            <span class="crumb-current"><i class="fa-solid fa-folder"></i> ' . htmlspecialchars($pageName) . '</span>
                        </div>
                        <div class="admin-header-title-row">
                            <span class="admin-header-page-heading" style="font-family: \'Outfit\', sans-serif;">' . htmlspecialchars($pageName) . '</span>
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
        $content = preg_replace('/<header class="admin-top-header">\s*<div class="admin-header-actions">\s*<\/div>\s*<\/header>/s', $replace, $content);
        
        file_put_contents($file, $content);
        echo "Updated $file\n";
    }
}
?>
