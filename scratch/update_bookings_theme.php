<?php
$files = [
    __DIR__ . '/../bookings.php',
    __DIR__ . '/../purple_bamboo/bookings.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);

    // Update top header bar in bookings.php
    $old_header = '<header class="admin-top-header">' . "\n" . '                <div class="admin-header-actions"></div>' . "\n" . '            </header>';
    $old_header_crlf = '<header class="admin-top-header">' . "\r\n" . '                <div class="admin-header-actions"></div>' . "\r\n" . '            </header>';

    $new_header = '<!-- Executive Sticky Top Header Bar -->
            <header class="admin-top-header">
                <!-- Left: Breadcrumbs & Live Context -->
                <div class="admin-header-left">
                    <button type="button" class="admin-mobile-toggle-btn" onclick="toggleAdminSidebar()" aria-label="Open Navigation Menu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <div class="admin-header-context">
                        <div class="admin-breadcrumbs">
                            <a href="admin-dashboard.php" class="crumb-link"><i class="fa-solid fa-house-chimney"></i> Console</a>
                            <span class="crumb-divider">/</span>
                            <span class="crumb-current"><i class="fa-solid fa-book-bookmark"></i> Bookings</span>
                        </div>
                        <div class="admin-header-title-row">
                            <span class="admin-header-page-heading" style="font-family: \'Outfit\', sans-serif;">Booking Management</span>
                            <span class="admin-badge" style="background: #fffbeb; color: #b45309; border: 1px solid #fde68a; font-size: 0.72rem; padding: 2px 8px; font-weight: 700; border-radius: 6px; font-family: \'Plus Jakarta Sans\', sans-serif;">
                                <i class="fa-solid fa-circle-check"></i> <?= count($active_bookings) ?> Active
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Right: Header Controls & Actions -->
                <div class="admin-header-actions">
                    <button type="button" onclick="openManualModal()" class="admin-btn admin-btn-sm" style="background: #f59e0b; color: #ffffff; border: none; font-weight: 700; border-radius: 8px; padding: 0.5rem 1rem; font-family: \'Plus Jakarta Sans\', sans-serif; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-plus"></i> <span>Add Booking</span>
                    </button>
                    <a href="calendar.php" class="admin-btn admin-btn-sm" style="background: #7c3aed; color: #ffffff; border: none; font-weight: 700; border-radius: 8px; padding: 0.5rem 1rem; font-family: \'Plus Jakarta Sans\', sans-serif; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-calendar-days"></i> <span>Calendar</span>
                    </a>
                    <a href="admin-dashboard.php" class="admin-btn admin-btn-outline admin-btn-sm" style="background: #ffffff; border: 1.5px solid #e2e8f0; color: #475569; border-radius: 8px; font-weight: 700; font-family: \'Plus Jakarta Sans\', sans-serif;" title="Back to Dashboard">
                        <i class="fa-solid fa-arrow-left"></i> <span>Dashboard</span>
                    </a>
                </div>
            </header>';

    $content = str_replace([$old_header, $old_header_crlf], $new_header, $content);

    // KPI cards - update borders to strictly purple and gold
    $content = str_replace(
        '<div class="<?= $is_admin_layout ? \'admin-card admin-stat-card\' : \'card\' ?>">',
        '<div class="<?= $is_admin_layout ? \'admin-card admin-stat-card\' : \'card\' ?>" style="border-top: 3px solid #7c3aed; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">',
        $content
    );

    // Ensure table background is white and borders are clean
    $content = str_replace('background: var(--theme-bg-card, #1c0f3d) !important;', 'background: #ffffff !important;', $content);
    $content = str_replace('border: 1.5px solid var(--theme-border, #d8b4fe) !important;', 'border: 1px solid #e2e8f0 !important;', $content);
    $content = str_replace('border-bottom: 1.5px solid var(--theme-border, #d8b4fe) !important;', 'border-bottom: 1.5px solid #e2e8f0 !important;', $content);
    $content = str_replace('border-bottom: 1px solid #d8b4fe !important;', 'border-bottom: 1px solid #6d28d9 !important;', $content);

    file_put_contents($file, $content);
    echo "Header & styles updated in $file\n";
}
