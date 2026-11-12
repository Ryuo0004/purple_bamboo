<?php
/**
 * settings.php — Purple Bamboo Resort Unified Settings Console
 *
 * Contains:
 *  1. Resort Profile (Logo, Brand Title, Brand Subtitle, Resort Name, Live Preview)
 *  2. Contact & Advisory (Location/Address, Phone, Email, Top Announcement Advisory Bar)
 *  3. Manage Staff (CRUD: Add, View, Update, Delete Staff personnel)
 *  4. Admin Profile (Redesigned Profile Card, Personal Info, Change Password, Add New Administrator)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/content_store.php';
require_once __DIR__ . '/staff_store.php';

// Strict Role-Based Access Control: Admin only (Staff restricted)
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    if (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'staff') {
        if (isset($_GET['tab']) && $_GET['tab'] === 'profile') {
            header("Location: admin_profile.php");
            exit();
        }
        header("Location: bookings.php?error=admin_only");
        exit();
    }
    $err = (isset($_SESSION['role']) && $_SESSION['role'] === 'guest') ? 'guest_restricted' : 'unauthorized';
    header("Location: admin-login.php?error=" . $err);
    exit();
}

$viewer_role  = 'admin';
$is_admin     = true;
$viewer_email = strtolower(trim($_SESSION['email'] ?? ''));
$viewer_name  = $_SESSION['full_name'] ?? 'Resort Administrator';

/**
 * Flash message helper using Post-Redirect-Get pattern
 */
function set_settings_flash($type, $msg, $reopen_modal = null) {
    $_SESSION['settings_flash'] = [
        'type'   => $type,
        'msg'    => $msg,
        'reopen' => $reopen_modal
    ];
}

function get_settings_flash() {
    $f = $_SESSION['settings_flash'] ?? null;
    unset($_SESSION['settings_flash']);
    return $f;
}

// ============================================================
// POST REQUEST PROCESSING
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $action = $_POST['action'] ?? '';

    // ------------------------------------------------------------
    // 1. SAVE RESORT PROFILE
    // ------------------------------------------------------------
    if ($action === 'save_resort_profile') {
        if (!$is_admin) {
            set_settings_flash('error', 'Only administrators can update the resort branding.');
            header("Location: settings.php?tab=resort");
            exit();
        }

        $current = ContentStore::getContent();
        $resort_logo_path = trim($_POST['resort_logo'] ?? ($current['resort_logo'] ?? 'assets/logo.jpg'));

        if (!empty($_POST['remove_logo'])) {
            $resort_logo_path = 'assets/logo.jpg';
        }

        // Handle File Upload
        if (!empty($_FILES['resort_logo_file']['tmp_name']) && is_uploaded_file($_FILES['resort_logo_file']['tmp_name'])) {
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'svg', 'gif'];
            $ext = strtolower(pathinfo($_FILES['resort_logo_file']['name'], PATHINFO_EXTENSION));
            $max_size = 5 * 1024 * 1024; // 5MB

            if (!in_array($ext, $allowed)) {
                set_settings_flash('error', 'Invalid logo format. Allowed types: JPG, JPEG, PNG, WEBP, SVG, GIF.');
                header("Location: settings.php?tab=resort");
                exit();
            } elseif ($_FILES['resort_logo_file']['size'] > $max_size) {
                set_settings_flash('error', 'Logo file is too large. Maximum size allowed is 5MB.');
                header("Location: settings.php?tab=resort");
                exit();
            } else {
                $brand_dir = __DIR__ . '/uploads/branding';
                if (!is_dir($brand_dir)) {
                    @mkdir($brand_dir, 0777, true);
                }
                $new_filename = 'resort_logo_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
                $destination = $brand_dir . '/' . $new_filename;
                if (move_uploaded_file($_FILES['resort_logo_file']['tmp_name'], $destination)) {
                    $resort_logo_path = 'uploads/branding/' . $new_filename;
                    @copy($destination, __DIR__ . '/assets/logo.jpg');
                    @copy($destination, __DIR__ . '/logo.jpg');
                } else {
                    set_settings_flash('error', 'Failed to upload logo file. Please verify folder permissions.');
                    header("Location: settings.php?tab=resort");
                    exit();
                }
            }
        }

        $updateData = [
            'resort_logo'           => $resort_logo_path,
            'resort_brand_title'    => trim($_POST['resort_brand_title'] ?? '') ?: ($current['resort_brand_title'] ?? 'PURPLE BAMBOO'),
            'resort_brand_subtitle' => trim($_POST['resort_brand_subtitle'] ?? '') ?: ($current['resort_brand_subtitle'] ?? 'BEACH RESORT'),
            'resort_name'           => trim($_POST['resort_name'] ?? '') ?: ($current['resort_name'] ?? 'The Blessed Purple Bamboo Beach Resort')
        ];

        ContentStore::saveContent($updateData, $viewer_name);
        set_settings_flash('success', 'Resort Profile and Branding updated successfully!');
        header("Location: settings.php?tab=resort");
        exit();
    }

    // ------------------------------------------------------------
    // 2. SAVE CONTACT & ADVISORY
    // ------------------------------------------------------------
    elseif ($action === 'save_contact_info') {
        if (!$is_admin) {
            set_settings_flash('error', 'Only administrators can update resort contact information.');
            header("Location: settings.php?tab=contact");
            exit();
        }

        $current = ContentStore::getContent();
        $updateData = [
            'resort_location'      => trim($_POST['resort_location'] ?? ($current['resort_location'] ?? 'Alaminos City, Pangasinan')),
            'resort_phone'         => trim($_POST['resort_phone'] ?? ($current['resort_phone'] ?? '0956 697 8679')),
            'resort_email'         => trim($_POST['resort_email'] ?? ($current['resort_email'] ?? 'info@purplebambooresort.com')),
            'announcement_enabled' => isset($_POST['announcement_enabled']),
            'announcement_text'    => trim($_POST['announcement_text'] ?? ($current['announcement_text'] ?? '')),
            'announcement_link'    => trim($_POST['announcement_link'] ?? ($current['announcement_link'] ?? 'browse_kubos.php'))
        ];

        ContentStore::saveContent($updateData, $viewer_name);
        set_settings_flash('success', 'Official Contact details and Announcement Advisory saved successfully!');
        header("Location: settings.php?tab=contact");
        exit();
    }

    // ------------------------------------------------------------
    // 2.5. SAVE GCASH PAYMENT CHANNEL & QR SETTINGS
    // ------------------------------------------------------------
    elseif ($action === 'save_gcash') {
        if (!$is_admin) {
            set_settings_flash('error', 'Only administrators can update GCash payment settings.');
            header("Location: settings.php?tab=gcash");
            exit();
        }

        $current = ContentStore::getContent();
        $qr_image_path = trim($_POST['gcash_qr_image'] ?? ($current['gcash_qr_image'] ?? 'assets/gcash_qr.jpg'));

        if (!empty($_POST['remove_qr'])) {
            $qr_image_path = 'assets/gcash_qr.jpg';
        }

        if (!empty($_FILES['gcash_qr_file']['tmp_name']) && is_uploaded_file($_FILES['gcash_qr_file']['tmp_name'])) {
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            $ext = strtolower(pathinfo($_FILES['gcash_qr_file']['name'], PATHINFO_EXTENSION));
            $max_size = 5 * 1024 * 1024; // 5MB

            if (!in_array($ext, $allowed)) {
                set_settings_flash('error', 'Invalid QR format. Allowed types: JPG, JPEG, PNG, WEBP.');
                header("Location: settings.php?tab=gcash");
                exit();
            } elseif ($_FILES['gcash_qr_file']['size'] > $max_size) {
                set_settings_flash('error', 'QR file is too large. Maximum size allowed is 5MB.');
                header("Location: settings.php?tab=gcash");
                exit();
            } else {
                $qr_dir = __DIR__ . '/uploads/qr';
                if (!is_dir($qr_dir)) {
                    @mkdir($qr_dir, 0777, true);
                }
                $new_filename = 'gcash_qr_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
                $destination = $qr_dir . '/' . $new_filename;
                if (move_uploaded_file($_FILES['gcash_qr_file']['tmp_name'], $destination)) {
                    $qr_image_path = 'uploads/qr/' . $new_filename;
                    @copy($destination, __DIR__ . '/assets/gcash_qr.jpg');
                } else {
                    set_settings_flash('error', 'Failed to upload QR code. Please check directory permissions.');
                    header("Location: settings.php?tab=gcash");
                    exit();
                }
            }
        }

        $updateData = [
            'gcash_name'          => trim($_POST['gcash_name'] ?? '') ?: ($current['gcash_name'] ?? 'PURPLE BAMBOO RESORT'),
            'gcash_number'        => trim($_POST['gcash_number'] ?? '') ?: ($current['gcash_number'] ?? '0956 697 8679'),
            'gcash_card_title'    => trim($_POST['gcash_card_title'] ?? '') ?: ($current['gcash_card_title'] ?? 'Official GCash Payment Channel'),
            'gcash_card_subtitle' => trim($_POST['gcash_card_subtitle'] ?? '') ?: ($current['gcash_card_subtitle'] ?? 'Scan with GCash or InstaPay for Instant Reservation Verification'),
            'gcash_instructions'  => trim($_POST['gcash_instructions'] ?? ($current['gcash_instructions'] ?? '')),
            'gcash_show_on_home'  => isset($_POST['gcash_show_on_home']),
            'gcash_qr_image'      => $qr_image_path
        ];

        ContentStore::saveContent($updateData, $viewer_name);
        set_settings_flash('success', 'Official GCash Payment Channel & QR settings saved successfully!');
        header("Location: settings.php?tab=gcash");
        exit();
    }

    // ------------------------------------------------------------
    // 3. CREATE STAFF
    // ------------------------------------------------------------
    elseif ($action === 'create_staff') {
        if (!$is_admin) {
            set_settings_flash('error', 'Only administrators can create new staff personnel.');
            header("Location: settings.php?tab=staff");
            exit();
        }

        $staffData = [
            'role'             => 'staff',
            'first_name'       => trim($_POST['first_name'] ?? ''),
            'middle_name'      => trim($_POST['middle_name'] ?? ''),
            'last_name'        => trim($_POST['last_name'] ?? ''),
            'suffix'           => trim($_POST['suffix'] ?? ''),
            'email'            => trim($_POST['email'] ?? ''),
            'phone'            => trim($_POST['phone'] ?? ''),
            'password'         => $_POST['password'] ?? '',
            'confirm_password' => $_POST['confirm_password'] ?? ''
        ];

        $res = StaffStore::createPersonnel($staffData);
        if ($res['success']) {
            $assignedPassword = !empty($res['generated_password']) ? $res['generated_password'] : (!empty($staffData['password']) ? $staffData['password'] : ($res['plain_password'] ?? ''));
            $msg = "New staff member \"{$res['full_name']}\" ({$res['profile_id']}) created successfully!";
            try {
                require_once __DIR__ . '/includes/EmailHelper.php';
                $emailRes = EmailHelper::sendStaffCredentialsEmail(
                    $staffData['email'],
                    $res['full_name'],
                    $res['profile_id'],
                    $assignedPassword,
                    'created',
                    'staff'
                );
                if (!empty($emailRes['success'])) {
                    $msg .= " System-generated credentials and login password have been automatically sent to {$staffData['email']}.";
                } else {
                    $msg .= " (Credentials logged locally in archive).";
                }
            } catch (Exception $e) {
                error_log("Failed to auto-send staff credentials email: " . $e->getMessage());
            }
            set_settings_flash('success', $msg);
        } else {
            set_settings_flash('error', $res['error'], ['modal' => 'addStaffModal', 'data' => $staffData]);
        }
        header("Location: settings.php?tab=staff");
        exit();
    }

    // ------------------------------------------------------------
    // 4. UPDATE STAFF
    // ------------------------------------------------------------
    elseif ($action === 'update_staff') {
        if (!$is_admin) {
            set_settings_flash('error', 'Only administrators can edit staff accounts.');
            header("Location: settings.php?tab=staff");
            exit();
        }

        $targetEmail = trim($_POST['target_email'] ?? '');
        $staffData = [
            'first_name'       => trim($_POST['first_name'] ?? ''),
            'middle_name'      => trim($_POST['middle_name'] ?? ''),
            'last_name'        => trim($_POST['last_name'] ?? ''),
            'suffix'           => trim($_POST['suffix'] ?? ''),
            'phone'            => trim($_POST['phone'] ?? ''),
            'password'         => $_POST['password'] ?? '',
            'confirm_password' => $_POST['confirm_password'] ?? ''
        ];

        $res = StaffStore::updatePersonnel($targetEmail, $staffData);
        if ($res['success']) {
            $msg = "Staff account for {$targetEmail} updated successfully!";
            if (!empty($res['password_updated']) && !empty($staffData['password'])) {
                try {
                    require_once __DIR__ . '/includes/EmailHelper.php';
                    $emailRes = EmailHelper::sendStaffCredentialsEmail(
                        $targetEmail,
                        $res['full_name'] ?? 'Staff Personnel',
                        $res['profile_id'] ?? '',
                        $staffData['password'],
                        'updated',
                        'staff'
                    );
                    if (!empty($emailRes['success'])) {
                        $msg .= " Updated password has been automatically sent to {$targetEmail}.";
                    } else {
                        $msg .= " (Updated password logged locally in archive).";
                    }
                } catch (Exception $e) {
                    error_log("Failed to auto-send staff password update email: " . $e->getMessage());
                }
            }
            set_settings_flash('success', $msg);
        } else {
            set_settings_flash('error', $res['error']);
        }
        header("Location: settings.php?tab=staff");
        exit();
    }

    // ------------------------------------------------------------
    // 5. DELETE STAFF
    // ------------------------------------------------------------
    elseif ($action === 'delete_staff') {
        if (!$is_admin) {
            set_settings_flash('error', 'Only administrators can delete staff accounts.');
            header("Location: settings.php?tab=staff");
            exit();
        }

        $targetEmail = trim($_POST['target_email'] ?? '');
        $res = StaffStore::deletePersonnel($targetEmail, $viewer_email);
        if ($res['success']) {
            set_settings_flash('success', "Staff member ({$targetEmail}) has been deleted successfully.");
        } else {
            set_settings_flash('error', $res['error']);
        }
        header("Location: settings.php?tab=staff");
        exit();
    }

    // ------------------------------------------------------------
    // 6. UPDATE ADMIN PROFILE (CURRENT USER)
    // ------------------------------------------------------------
    elseif ($action === 'update_admin_profile') {
        $profileData = [
            'first_name'  => trim($_POST['first_name'] ?? ''),
            'middle_name' => trim($_POST['middle_name'] ?? ''),
            'last_name'   => trim($_POST['last_name'] ?? ''),
            'suffix'      => trim($_POST['suffix'] ?? ''),
            'phone'       => trim($_POST['phone'] ?? '')
        ];

        $res = StaffStore::updatePersonnel($viewer_email, $profileData);
        if ($res['success']) {
            set_settings_flash('success', 'Your administrator profile details have been updated!');
        } else {
            set_settings_flash('error', $res['error']);
        }
        header("Location: settings.php?tab=profile");
        exit();
    }

    // ------------------------------------------------------------
    // 7. CHANGE ADMIN PASSWORD
    // ------------------------------------------------------------
    elseif ($action === 'change_admin_password') {
        $curPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confPass = $_POST['confirm_password'] ?? '';

        $res = StaffStore::changePassword($viewer_email, $curPass, $newPass, $confPass);
        if ($res['success']) {
            $msg = 'Your administrator password has been updated successfully!';
            try {
                require_once __DIR__ . '/includes/EmailHelper.php';
                $curAdmin = StaffStore::findByEmail($viewer_email);
                $adminName = !empty($res['full_name']) ? $res['full_name'] : ($curAdmin['full_name'] ?? $viewer_name);
                $adminPid = !empty($res['profile_id']) ? $res['profile_id'] : ($curAdmin['profile_id'] ?? 'PBR-ADM-00001');
                $emailRes = EmailHelper::sendStaffCredentialsEmail(
                    $viewer_email,
                    $adminName,
                    $adminPid,
                    $newPass,
                    'updated',
                    'admin'
                );
                if (!empty($emailRes['success'])) {
                    $msg .= " Your updated password has been automatically sent to {$viewer_email}.";
                } else {
                    $msg .= " (Updated credentials logged locally in archive).";
                }
            } catch (Exception $e) {
                error_log("Failed to auto-send admin password update email: " . $e->getMessage());
            }
            set_settings_flash('success', $msg);
        } else {
            set_settings_flash('error', $res['error']);
        }
        header("Location: settings.php?tab=profile");
        exit();
    }

    // ------------------------------------------------------------
    // 8. ADD NEW ADMINISTRATOR
    // ------------------------------------------------------------
    elseif ($action === 'create_admin') {
        if (!$is_admin) {
            set_settings_flash('error', 'Only current administrators can register new administrators.');
            header("Location: settings.php?tab=profile");
            exit();
        }

        $adminData = [
            'role'             => 'admin',
            'first_name'       => trim($_POST['first_name'] ?? ''),
            'middle_name'      => trim($_POST['middle_name'] ?? ''),
            'last_name'        => trim($_POST['last_name'] ?? ''),
            'suffix'           => trim($_POST['suffix'] ?? ''),
            'email'            => trim($_POST['email'] ?? ''),
            'phone'            => trim($_POST['phone'] ?? ''),
            'password'         => $_POST['password'] ?? '',
            'confirm_password' => $_POST['confirm_password'] ?? ''
        ];

        $res = StaffStore::createPersonnel($adminData);
        if ($res['success']) {
            $assignedPassword = !empty($res['generated_password']) ? $res['generated_password'] : (!empty($adminData['password']) ? $adminData['password'] : ($res['plain_password'] ?? ''));
            $msg = "New Administrator \"{$res['full_name']}\" ({$res['profile_id']}) registered successfully!";
            try {
                require_once __DIR__ . '/includes/EmailHelper.php';
                $emailRes = EmailHelper::sendStaffCredentialsEmail(
                    $adminData['email'],
                    $res['full_name'],
                    $res['profile_id'],
                    $assignedPassword,
                    'created',
                    'admin'
                );
                if (!empty($emailRes['success'])) {
                    $msg .= " System-generated credentials and login password have been automatically sent to {$adminData['email']}.";
                } else {
                    $msg .= " (Credentials logged locally in archive).";
                }
            } catch (Exception $e) {
                error_log("Failed to auto-send admin credentials email: " . $e->getMessage());
            }
            set_settings_flash('success', $msg);
        } else {
            set_settings_flash('error', $res['error'], ['modal' => 'addAdminModal', 'data' => $adminData]);
        }
        header("Location: settings.php?tab=profile");
        exit();
    }
}

// ============================================================
// DATA PREPARATION FOR RENDERING
// ============================================================
$flash = get_settings_flash();
$active_tab = $_GET['tab'] ?? 'resort';
if (!in_array($active_tab, ['resort', 'contact', 'gcash', 'staff', 'profile'])) {
    $active_tab = 'resort';
}

$cms = ContentStore::getContent();
$staff_list = StaffStore::getUsersByRole('staff');
$admin_list = StaffStore::getUsersByRole('admin');
$current_admin = StaffStore::findByEmail($viewer_email) ?: [
    'first_name'  => $_SESSION['first_name'] ?? 'Super',
    'middle_name' => $_SESSION['middle_name'] ?? '',
    'last_name'   => $_SESSION['last_name'] ?? 'Admin',
    'suffix'      => $_SESSION['suffix'] ?? '',
    'full_name'   => $_SESSION['full_name'] ?? 'Super Admin',
    'email'       => $viewer_email,
    'phone'       => $_SESSION['phone'] ?? '09566978679',
    'profile_id'  => $_SESSION['profile_id'] ?? 'PBR-ADM-00001',
    'role'        => $viewer_role,
    'created_at'  => date('Y-m-d')
];

$active_staff_count = count($staff_list);
$active_admin_count = count($admin_list);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resort Settings & Personnel | Purple Bamboo Admin</title>
    <!-- Core Admin Styles -->
    <link rel="stylesheet" href="admin-style.css?v=<?= time() ?>">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* ============================================================
           SETTINGS EXECUTIVE LUXURY DESIGN SYSTEM
           Matches user's exact screen reference:
           - Sleek dark gradient header & tab bar
           - Golden glowing active tab pill
           - Glassmorphism & executive cards
           ============================================================ */
        :root {
            --st-gold: #fbbf24;
            --st-gold-glow: rgba(251, 191, 36, 0.35);
            --st-gold-dark: #d97706;
            --st-purple: #7c3aed;
            --st-purple-glow: rgba(124, 58, 237, 0.4);
            --st-navy-dark: #0b1120;
            --st-card-bg: #ffffff;
            --st-card-border: rgba(226, 232, 240, 0.9);
            --st-text-main: #0f172a;
            --st-text-sub: #64748b;
        }

        body.admin-layout-body {
            background: #f8fafc;
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--st-text-main);
        }

        .settings-container {
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 1.25rem 1.5rem 3.5rem;
            flex: 1;
            box-sizing: border-box;
        }

        /* Top Page Header Banner */
        .settings-header-banner {
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .settings-header-title {
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
        .settings-header-title i {
            color: var(--st-gold-dark);
            background: rgba(245, 158, 11, 0.12);
            padding: 10px;
            border-radius: 12px;
            font-size: 1.3rem;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.15);
        }
        .settings-header-subtitle {
            font-size: 0.92rem;
            color: var(--st-text-sub);
            margin: 0;
        }

        /* ============================================================
           HORIZONTAL TAB NAVIGATION STRIP (AS SHOWN IN USER SCREENSHOT)
           Deep navy luxury background, glowing gold active pill
           ============================================================ */
        .settings-tabs-navbar {
            background: linear-gradient(90deg, #090e1a 0%, #111827 45%, #1e113a 100%);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 14px;
            padding: 8px 10px;
            display: inline-flex;
            width: fit-content;
            max-width: 100%;
            align-items: center;
            gap: 8px;
            margin-bottom: 2rem;
            box-shadow: 0 8px 30px rgba(11, 17, 32, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            overflow-x: auto;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }
        .settings-tabs-navbar::-webkit-scrollbar {
            display: none;
        }

        .settings-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 22px;
            border-radius: 10px;
            font-family: 'Outfit', sans-serif;
            font-size: 0.96rem;
            font-weight: 600;
            text-decoration: none;
            color: #94a3b8;
            background: transparent;
            border: 1px solid transparent;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
            cursor: pointer;
        }
        .settings-tab-btn i {
            font-size: 1rem;
            transition: transform 0.2s ease;
        }
        .settings-tab-btn:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.06);
            transform: translateY(-1px);
        }
        .settings-tab-btn:hover i {
            transform: scale(1.1);
        }

        /* ACTIVE TAB STATE (Matches screenshot with gold border & glow) */
        .settings-tab-btn.active {
            background: rgba(251, 191, 36, 0.12);
            color: var(--st-gold);
            border: 1px solid var(--st-gold);
            font-weight: 700;
            box-shadow: 0 0 20px rgba(251, 191, 36, 0.25), inset 0 0 10px rgba(251, 191, 36, 0.08);
        }
        .settings-tab-btn.active i {
            color: var(--st-gold);
            filter: drop-shadow(0 0 6px rgba(251, 191, 36, 0.5));
        }

        .tab-badge {
            background: rgba(251, 191, 36, 0.2);
            color: var(--st-gold);
            font-size: 0.72rem;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 10px;
            margin-left: 2px;
            border: 1px solid rgba(251, 191, 36, 0.3);
        }

        /* ============================================================
           CARDS & CONTENT PANELS
           ============================================================ */
        .settings-panel {
            display: none;
            animation: fadeIn 0.3s ease forwards;
        }
        .settings-panel.active {
            display: block;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .st-card {
            background: #ffffff;
            border: 1px solid var(--st-card-border);
            border-radius: 16px;
            padding: 1.75rem;
            box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.05);
            margin-bottom: 1.5rem;
            position: relative;
            transition: box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .st-card:hover {
            box-shadow: 0 8px 30px -4px rgba(15, 23, 42, 0.08);
            border-color: rgba(203, 213, 225, 0.8);
        }

        .st-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #f1f5f9;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .st-card-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.22rem;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }
        .st-card-title i {
            color: var(--st-purple);
            font-size: 1.15rem;
        }
        .st-card-subtitle {
            font-size: 0.85rem;
            color: var(--st-text-sub);
            margin: 3px 0 0 0;
        }

        /* Form elements */
        .st-form-group {
            margin-bottom: 1.25rem;
        }
        .st-label {
            display: block;
            font-size: 0.85rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }
        .st-label i {
            color: #64748b;
            margin-right: 4px;
        }
        .st-input, .st-select, .st-textarea {
            width: 100%;
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            padding: 0.65rem 0.95rem;
            font-size: 0.92rem;
            color: #0f172a;
            font-family: inherit;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }
        .st-input:focus, .st-select:focus, .st-textarea:focus {
            outline: none;
            background: #ffffff;
            border-color: var(--st-purple);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15);
        }
        .st-help {
            font-size: 0.78rem;
            color: #64748b;
            margin-top: 4px;
            display: block;
        }

        /* Grid layouts */
        .st-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        .st-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.25rem;
        }
        .st-grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
        }

        /* Gold & Purple Action Buttons */
        .st-btn-gold {
            background: linear-gradient(135deg, #d97706, #f59e0b);
            color: #ffffff;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 0.92rem;
            padding: 0.65rem 1.4rem;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(217, 119, 6, 0.3);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .st-btn-gold:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(217, 119, 6, 0.45);
            color: #ffffff;
        }

        .st-btn-purple {
            background: linear-gradient(135deg, #6d28d9, #8b5cf6);
            color: #ffffff;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 0.92rem;
            padding: 0.65rem 1.4rem;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(109, 40, 217, 0.3);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .st-btn-purple:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(109, 40, 217, 0.45);
            color: #ffffff;
        }

        .st-btn-outline {
            background: #ffffff;
            color: #475569;
            border: 1.5px solid #cbd5e1;
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            font-size: 0.9rem;
            padding: 0.6rem 1.2rem;
            border-radius: 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .st-btn-outline:hover {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #94a3b8;
        }

        .st-btn-danger-outline {
            background: #fff5f5;
            color: #dc2626;
            border: 1.5px solid #fecaca;
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            font-size: 0.85rem;
            padding: 0.4rem 0.85rem;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        .st-btn-danger-outline:hover {
            background: #dc2626;
            color: #ffffff;
            border-color: #dc2626;
        }

        /* Flash Message Toast / Alert */
        .st-alert {
            padding: 1rem 1.25rem;
            border-radius: 12px;
            font-weight: 600;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: fadeIn 0.3s ease;
        }
        .st-alert-success {
            background: #ecfdf5;
            border: 1.5px solid #a7f3d0;
            color: #065f46;
        }
        .st-alert-error {
            background: #fef2f2;
            border: 1.5px solid #fecaca;
            color: #991b1b;
        }

        /* ============================================================
           STAFF & PERSONNEL DIRECTORY TABLE
           ============================================================ */
        .st-table-wrapper {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }
        .st-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            text-align: left;
        }
        .st-table th {
            background: #f8fafc;
            color: #475569;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 16px;
            border-bottom: 1.5px solid #e2e8f0;
        }
        .st-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.9rem;
            vertical-align: middle;
            color: #1e293b;
        }
        .st-table tr:last-child td {
            border-bottom: none;
        }
        .st-table tr:hover td {
            background: #f8fafc;
        }

        .user-avatar-badge {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            color: #ffffff;
            font-weight: 800;
            font-family: 'Outfit', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.92rem;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.25);
        }
        .user-avatar-badge.admin-badge-avatar {
            background: linear-gradient(135deg, #d97706, #f59e0b);
            box-shadow: 0 2px 8px rgba(217, 119, 6, 0.3);
        }

        .role-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.76rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .role-pill-staff {
            background: rgba(124, 58, 237, 0.1);
            color: #7c3aed;
            border: 1px solid rgba(124, 58, 237, 0.25);
        }
        .role-pill-admin {
            background: rgba(245, 158, 11, 0.12);
            color: #b45309;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        /* Staff KPI Chips in Toolbar */
        .st-kpi-group {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .st-kpi-chip {
            display: inline-flex;
            align-items: center;
            gap: 14px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 8px 18px;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .st-kpi-chip:hover {
            border-color: #cbd5e1;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.06);
            transform: translateY(-1px);
        }
        .st-kpi-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .st-kpi-purple {
            background: linear-gradient(135deg, rgba(124, 58, 237, 0.12), rgba(99, 102, 241, 0.15));
            color: #7c3aed;
            border: 1px solid rgba(124, 58, 237, 0.2);
        }
        .st-kpi-green {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(5, 150, 105, 0.15));
            color: #059669;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        .st-kpi-val {
            font-size: 1.35rem;
            font-weight: 800;
            font-family: 'Outfit', sans-serif;
            color: #0f172a;
            line-height: 1.1;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .st-kpi-lbl {
            font-size: 0.72rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }
        .st-pulse-dot {
            width: 8px;
            height: 8px;
            background-color: #10b981;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            animation: stPulse 1.8s infinite;
        }
        @keyframes stPulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .action-icon-btn {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            font-size: 0.9rem;
        }
        .action-icon-btn:hover {
            transform: translateY(-2px);
        }
        .action-icon-btn.view-btn {
            background: #eff6ff;
            color: #0284c7;
            border-color: #bae6fd;
        }
        .action-icon-btn.view-btn:hover {
            background: #0284c7;
            color: #ffffff;
            border-color: #0284c7;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.35);
        }
        .action-icon-btn.edit-btn {
            background: #faf5ff;
            color: #9333ea;
            border-color: #e9d5ff;
        }
        .action-icon-btn.edit-btn:hover {
            background: #9333ea;
            color: #ffffff;
            border-color: #9333ea;
            box-shadow: 0 4px 12px rgba(147, 51, 234, 0.35);
        }
        .action-icon-btn.del-btn {
            background: #fff1f2;
            color: #e11d48;
            border-color: #fecdd3;
        }
        .action-icon-btn.del-btn:hover {
            background: #e11d48;
            color: #ffffff;
            border-color: #e11d48;
            box-shadow: 0 4px 12px rgba(225, 29, 72, 0.35);
        }

        /* ============================================================
           MODALS (Add/Edit/View/Delete)
           ============================================================ */
        .st-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(4px);
            z-index: 1050;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .st-modal-backdrop.open {
            display: flex;
        }
        .st-modal {
            background: #ffffff;
            border-radius: 18px;
            width: 100%;
            max-width: 600px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
            animation: modalSlide 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        .st-modal form {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 0;
            overflow: hidden;
            margin: 0;
        }
        @keyframes modalSlide {
            from { opacity: 0; transform: translateY(20px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .st-modal-header {
            padding: 1.15rem 1.5rem;
            background: linear-gradient(90deg, #0b1120 0%, #1e113a 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .st-modal-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.18rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #ffffff;
        }
        .st-modal-title i {
            color: var(--st-gold);
        }
        .st-modal-close {
            background: rgba(255, 255, 255, 0.1);
            border: none;
            color: #ffffff;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        .st-modal-close:hover {
            background: rgba(239, 68, 68, 0.8);
            transform: rotate(90deg);
        }
        .st-modal-body {
            padding: 1.25rem 1.5rem;
            overflow-y: auto;
            flex: 1 1 auto;
            min-height: 0;
            max-height: calc(90vh - 130px);
        }
        .st-modal-footer {
            padding: 0.9rem 1.5rem;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            flex-shrink: 0;
        }

        /* Password input with eye toggle */
        .password-field-wrapper {
            position: relative;
        }
        .password-toggle-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 0.95rem;
        }
        .password-toggle-btn:hover {
            color: #475569;
        }

        /* Password strength meter */
        .pwd-strength-bar {
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            margin-top: 6px;
            overflow: hidden;
            display: flex;
        }
        .pwd-strength-fill {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease, background-color 0.3s ease;
        }

        /* Live Brand Preview Card */
        .brand-preview-box {
            background: linear-gradient(135deg, #0d0720 0%, #170d30 100%);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 14px;
            padding: 1.5rem;
            color: #ffffff;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }

        /* Responsive tweaks */
        @media (max-width: 991px) {
            .settings-container {
                padding: 1rem;
            }
            .st-grid-2, .st-grid-3, .st-grid-4 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="admin-layout-body">

    <!-- Include Executive Admin Navigation & Top Bar -->
    <?php include 'admin-nav.php'; ?>

    <div class="admin-main-wrapper">
        <header class="admin-top-header">
            <div class="admin-header-left">
                <button type="button" class="admin-mobile-toggle-btn" onclick="toggleAdminSidebar()" aria-label="Open Navigation Menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="admin-header-context">
                    <div class="admin-header-title-row">
                        <span class="admin-header-page-heading" style="font-family: 'Outfit', sans-serif;">
                            <i class="fa-solid fa-gear" style="color: #fbbf24; margin-right: 6px;"></i> System Settings
                        </span>
                    </div>
                </div>
            </div>

            <div class="admin-header-actions">
                <span class="admin-badge admin-badge-gold">
                    <i class="fa-solid fa-shield-halved"></i> <?= htmlspecialchars(ucfirst($viewer_role)) ?> Console
                </span>
                <a href="admin-dashboard.php" class="admin-btn admin-btn-outline admin-btn-sm">
                    <i class="fa-solid fa-arrow-left"></i> Dashboard
                </a>
            </div>
        </header>

        <main class="settings-container">

            <!-- Flash Alert Message -->
            <?php if (!empty($flash)): ?>
                <div class="st-alert st-alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
                    <i class="fa-solid fa-circle-<?= $flash['type'] === 'success' ? 'check' : 'exclamation' ?>" style="font-size: 1.25rem;"></i>
                    <div style="flex: 1;"><?= htmlspecialchars($flash['msg']) ?></div>
                    <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; color:inherit; cursor:pointer; font-size:1.1rem;">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Page Title Row -->
            <div class="settings-header-banner">
                <div>
                    <h1 class="settings-header-title">
                        <i class="fa-solid fa-sliders"></i> System & Resort Settings
                    </h1>
                    <p class="settings-header-subtitle">
                        Manage resort identity, official contacts, GCash payment channel, staff personnel directory, and administrator profile.
                    </p>
                </div>
            </div>

            <!-- ============================================================
                 HORIZONTAL TAB BAR (MATCHING USER SCREENSHOT EXACTLY)
                 ============================================================ -->
            <nav class="settings-tabs-navbar" aria-label="Settings Navigation Tabs">
                <!-- Tab 1: Resort Profile -->
                <button type="button" class="settings-tab-btn <?= $active_tab === 'resort' ? 'active' : '' ?>" onclick="switchTab('resort', this)">
                    <i class="fa-solid fa-hotel"></i>
                    <span>Resort Profile</span>
                </button>

                <!-- Tab 2: Contact -->
                <button type="button" class="settings-tab-btn <?= $active_tab === 'contact' ? 'active' : '' ?>" onclick="switchTab('contact', this)">
                    <i class="fa-solid fa-address-book"></i>
                    <span>Contact</span>
                </button>

                <!-- Tab 3: GCash Payment -->
                <button type="button" class="settings-tab-btn <?= $active_tab === 'gcash' ? 'active' : '' ?>" onclick="switchTab('gcash', this)">
                    <i class="fa-solid fa-qrcode"></i>
                    <span>GCash Payment</span>
                </button>

                <!-- Tab 4: Manage Staff -->
                <button type="button" class="settings-tab-btn <?= $active_tab === 'staff' ? 'active' : '' ?>" onclick="switchTab('staff', this)">
                    <i class="fa-solid fa-users-gear"></i>
                    <span>Manage Staff</span>
                    <span class="tab-badge"><?= $active_staff_count ?></span>
                </button>

                <!-- Tab 5: Admin Profile -->
                <button type="button" class="settings-tab-btn <?= $active_tab === 'profile' ? 'active' : '' ?>" onclick="switchTab('profile', this)">
                    <i class="fa-solid fa-user-shield"></i>
                    <span>Admin Profile</span>
                </button>
            </nav>

            <!-- ============================================================
                 TAB 1: RESORT PROFILE
                 ============================================================ -->
            <section id="panel-resort" class="settings-panel <?= $active_tab === 'resort' ? 'active' : '' ?>">
                <div class="st-grid-2">
                    <!-- Left: Form -->
                    <div class="st-card">
                        <div class="st-card-header">
                            <div>
                                <h2 class="st-card-title">
                                    <i class="fa-solid fa-palette"></i> Resort Identity & Logo Profile
                                </h2>
                                <p class="st-card-subtitle">Configure your resort logo, brand headers, and public legal name.</p>
                            </div>
                        </div>

                        <form action="settings.php?tab=resort" method="POST" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_resort_profile">

                            <!-- Logo Upload & Path -->
                            <div class="st-form-group">
                                <label class="st-label"><i class="fa-solid fa-image"></i> Official Resort Logo</label>
                                <div style="display: flex; gap: 15px; align-items: center; margin-bottom: 10px;">
                                    <img src="<?= htmlspecialchars($cms['resort_logo'] ?? 'assets/logo.jpg') ?>" 
                                         id="resortLogoPreview" 
                                         alt="Resort Logo" 
                                         style="width: 64px; height: 64px; border-radius: 12px; object-fit: cover; border: 2px solid #e2e8f0; box-shadow: 0 4px 10px rgba(0,0,0,0.1);" 
                                         onerror="this.src='assets/logo.jpg';">
                                    <div style="flex: 1;">
                                        <input type="file" name="resort_logo_file" id="resortLogoFileInput" accept="image/*" class="st-input" style="padding: 6px 10px;" onchange="previewLogoFile(this)">
                                        <span class="st-help">Supports JPG, PNG, WEBP, SVG (Max 5MB). Replaces logo on both admin and guest modules.</span>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <input type="text" name="resort_logo" id="resortLogoPathInput" value="<?= htmlspecialchars($cms['resort_logo'] ?? 'assets/logo.jpg') ?>" class="st-input" placeholder="assets/logo.jpg or https://..." oninput="previewLogoUrl(this.value)">
                                    <button type="submit" name="remove_logo" value="1" class="st-btn-outline" style="white-space: nowrap; color: #dc2626;" onclick="return confirm('Reset logo back to default assets/logo.jpg?');">
                                        <i class="fa-solid fa-rotate-left"></i> Reset
                                    </button>
                                </div>
                            </div>

                            <!-- Brand Title -->
                            <div class="st-form-group">
                                <label for="brand_title" class="st-label"><i class="fa-solid fa-heading"></i> Brand Title</label>
                                <input type="text" name="resort_brand_title" id="brand_title" value="<?= htmlspecialchars($cms['resort_brand_title'] ?? 'PURPLE BAMBOO') ?>" class="st-input" required oninput="updateBrandPreview()">
                                <span class="st-help">Primary wordmark displayed on top-left of sidebar & navigation (e.g. PURPLE BAMBOO).</span>
                            </div>

                            <!-- Brand Subtitle -->
                            <div class="st-form-group">
                                <label for="brand_subtitle" class="st-label"><i class="fa-solid fa-font"></i> Brand Subtitle</label>
                                <input type="text" name="resort_brand_subtitle" id="brand_subtitle" value="<?= htmlspecialchars($cms['resort_brand_subtitle'] ?? 'BEACH RESORT') ?>" class="st-input" required oninput="updateBrandPreview()">
                                <span class="st-help">Secondary gold badge label under the brand title (e.g. BEACH RESORT).</span>
                            </div>

                            <!-- Official Full Resort Name -->
                            <div class="st-form-group">
                                <label for="resort_name" class="st-label"><i class="fa-solid fa-building"></i> Official Full Resort Name</label>
                                <input type="text" name="resort_name" id="resort_name" value="<?= htmlspecialchars($cms['resort_name'] ?? 'The Blessed Purple Bamboo Beach Resort') ?>" class="st-input" required>
                                <span class="st-help">Used for legal footers, confirmation receipts, and official email notifications.</span>
                            </div>

                            <?php if ($is_admin): ?>
                                <div style="margin-top: 1.5rem; text-align: right;">
                                    <button type="submit" class="st-btn-gold">
                                        <i class="fa-solid fa-floppy-disk"></i> Save Resort Profile
                                    </button>
                                </div>
                            <?php else: ?>
                                <div style="background: #f1f5f9; padding: 10px; border-radius: 8px; color: #64748b; font-size: 0.85rem;">
                                    <i class="fa-solid fa-lock"></i> Only administrators can change resort branding.
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- Right: Live Mockup Preview -->
                    <div>
                        <div class="st-card">
                            <div class="st-card-header">
                                <h3 class="st-card-title">
                                    <i class="fa-solid fa-eye" style="color: var(--st-gold-dark);"></i> Live Branding Preview
                                </h3>
                                <span class="admin-badge admin-badge-purple" style="font-size: 0.72rem;">Interactive</span>
                            </div>
                            <p style="font-size: 0.86rem; color: #64748b; margin-top: -8px; margin-bottom: 1.25rem;">
                                Preview how your logo and titles render across the executive administration console and public guest views.
                            </p>

                            <!-- Sidebar Header Mockup -->
                            <div class="brand-preview-box" style="margin-bottom: 1.25rem;">
                                <div style="font-size: 0.7rem; font-weight: 800; color: #fbbf24; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px;">
                                    ADMIN CONSOLE SIDEBAR HEADER
                                </div>
                                <div style="display: flex; align-items: center; gap: 12px; padding: 10px 14px; background: rgba(255, 255, 255, 0.05); border-radius: 10px; border: 1px solid rgba(255, 255, 255, 0.08);">
                                    <img src="<?= htmlspecialchars($cms['resort_logo'] ?? 'assets/logo.jpg') ?>" 
                                         id="mockupLogoAdmin" 
                                         alt="Resort Logo" 
                                         style="width: 38px; height: 38px; border-radius: 8px; object-fit: cover; border: 1.5px solid rgba(251, 191, 36, 0.5);">
                                    <div>
                                        <div id="mockupTitleAdmin" style="font-family: 'Outfit', sans-serif; font-size: 1.05rem; font-weight: 800; color: #ffffff; letter-spacing: 0.5px;">
                                            <?= htmlspecialchars($cms['resort_brand_title'] ?? 'PURPLE BAMBOO') ?>
                                        </div>
                                        <div style="font-size: 0.68rem; color: #fbbf24; font-weight: 700; letter-spacing: 1px;">
                                            ADMIN CONSOLE
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Guest Header Mockup -->
                            <div class="brand-preview-box">
                                <div style="font-size: 0.7rem; font-weight: 800; color: #a78bfa; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px;">
                                    PUBLIC GUEST PORTAL NAVBAR
                                </div>
                                <div style="display: flex; align-items: center; gap: 12px; padding: 10px 14px; background: rgba(255, 255, 255, 0.05); border-radius: 10px; border: 1px solid rgba(255, 255, 255, 0.08);">
                                    <img src="<?= htmlspecialchars($cms['resort_logo'] ?? 'assets/logo.jpg') ?>" 
                                         id="mockupLogoGuest" 
                                         alt="Resort Logo" 
                                         style="width: 38px; height: 38px; border-radius: 8px; object-fit: cover; border: 1.5px solid #a855f7;">
                                    <div>
                                        <div id="mockupTitleGuest" style="font-family: 'Outfit', sans-serif; font-size: 1.05rem; font-weight: 800; color: #ffffff;">
                                            <?= htmlspecialchars($cms['resort_brand_title'] ?? 'PURPLE BAMBOO') ?>
                                        </div>
                                        <div id="mockupSubtitleGuest" style="font-size: 0.72rem; color: #fbbf24; font-weight: 700; letter-spacing: 1px;">
                                            <?= htmlspecialchars($cms['resort_brand_subtitle'] ?? 'BEACH RESORT') ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============================================================
                 TAB 2: CONTACT & ADVISORY
                 ============================================================ -->
            <section id="panel-contact" class="settings-panel <?= $active_tab === 'contact' ? 'active' : '' ?>">
                <div class="st-grid-2">
                    <!-- Left: Contact Details -->
                    <div class="st-card">
                        <div class="st-card-header">
                            <div>
                                <h2 class="st-card-title">
                                    <i class="fa-solid fa-phone"></i> Official Resort Contacts
                                </h2>
                                <p class="st-card-subtitle">Official communication channels visible on guest receipts and footer.</p>
                            </div>
                        </div>

                        <form action="settings.php?tab=contact" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_contact_info">

                            <!-- Location -->
                            <div class="st-form-group">
                                <label for="resort_location" class="st-label"><i class="fa-solid fa-location-dot"></i> Resort Physical Location</label>
                                <input type="text" name="resort_location" id="resort_location" value="<?= htmlspecialchars($cms['resort_location'] ?? 'Alaminos City, Pangasinan') ?>" class="st-input" placeholder="e.g. Alaminos City, Pangasinan" required>
                                <span class="st-help">Displayed in the footer, confirmation emails, and reservation vouchers.</span>
                            </div>

                            <!-- Phone -->
                            <div class="st-form-group">
                                <label for="resort_phone" class="st-label"><i class="fa-solid fa-phone-volume"></i> Official Front Desk Phone / Hotline</label>
                                <input type="text" name="resort_phone" id="resort_phone" value="<?= htmlspecialchars($cms['resort_phone'] ?? '0956 697 8679') ?>" class="st-input" placeholder="e.g. 0956 697 8679" required>
                                <span class="st-help">Primary guest inquiries & emergency support hotline.</span>
                            </div>

                            <!-- Contact Email -->
                            <div class="st-form-group">
                                <label for="resort_email" class="st-label"><i class="fa-solid fa-envelope"></i> Official Contact Email</label>
                                <input type="email" name="resort_email" id="resort_email" value="<?= htmlspecialchars($cms['resort_email'] ?? 'info@purplebambooresort.com') ?>" class="st-input" placeholder="info@purplebambooresort.com">
                                <span class="st-help">Public guest correspondence address.</span>
                            </div>

                            <hr style="border: none; border-top: 1px solid #f1f5f9; margin: 1.5rem 0;">

                            <!-- Guest Announcement Advisory Bar -->
                            <div style="margin-bottom: 1.25rem;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
                                    <label class="st-label" style="margin-bottom: 0;">
                                        <i class="fa-solid fa-bullhorn" style="color: var(--st-gold-dark);"></i> Top Announcement / Advisory Banner
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.85rem; font-weight: 700; color: #475569;">
                                        <input type="checkbox" name="announcement_enabled" value="1" <?= !empty($cms['announcement_enabled']) ? 'checked' : '' ?> style="width: 18px; height: 18px; accent-color: #7c3aed;">
                                        <span>Show on Public Site</span>
                                    </label>
                                </div>

                                <div class="st-form-group">
                                    <label for="announcement_text" class="st-label">Advisory Message</label>
                                    <textarea name="announcement_text" id="announcement_text" rows="3" class="st-textarea" placeholder="e.g. Welcome to The Blessed Purple Bamboo Resort! Book online now with instant GCash verification."><?= htmlspecialchars($cms['announcement_text'] ?? '') ?></textarea>
                                </div>

                                <div class="st-form-group">
                                    <label for="announcement_link" class="st-label">Button / Destination Link</label>
                                    <input type="text" name="announcement_link" id="announcement_link" value="<?= htmlspecialchars($cms['announcement_link'] ?? 'browse_kubos.php') ?>" class="st-input" placeholder="browse_kubos.php or https://...">
                                </div>
                            </div>

                            <?php if ($is_admin): ?>
                                <div style="margin-top: 1.5rem; text-align: right;">
                                    <button type="submit" class="st-btn-gold">
                                        <i class="fa-solid fa-floppy-disk"></i> Save Contact & Advisory
                                    </button>
                                </div>
                            <?php else: ?>
                                <div style="background: #f1f5f9; padding: 10px; border-radius: 8px; color: #64748b; font-size: 0.85rem;">
                                    <i class="fa-solid fa-lock"></i> Only administrators can edit contact info.
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- Right: Contact Quick Reference Card -->
                    <div>
                        <div class="st-card">
                            <div class="st-card-header">
                                <h3 class="st-card-title">
                                    <i class="fa-solid fa-map-location-dot"></i> Live Contact Card Preview
                                </h3>
                            </div>

                            <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 1.5rem;">
                                <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 1.25rem;">
                                    <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(124, 58, 237, 0.1); color: #7c3aed; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0;">
                                        <i class="fa-solid fa-location-dot"></i>
                                    </div>
                                    <div>
                                        <div style="font-size: 0.78rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Location</div>
                                        <div style="font-size: 0.95rem; font-weight: 700; color: #0f172a;"><?= htmlspecialchars($cms['resort_location'] ?? 'Alaminos City, Pangasinan') ?></div>
                                    </div>
                                </div>

                                <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 1.25rem;">
                                    <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(245, 158, 11, 0.12); color: #d97706; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0;">
                                        <i class="fa-solid fa-phone"></i>
                                    </div>
                                    <div>
                                        <div style="font-size: 0.78rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Hotline</div>
                                        <div style="font-size: 0.95rem; font-weight: 700; color: #0f172a;"><?= htmlspecialchars($cms['resort_phone'] ?? '0956 697 8679') ?></div>
                                    </div>
                                </div>

                                <div style="display: flex; align-items: flex-start; gap: 14px;">
                                    <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(16, 185, 129, 0.12); color: #059669; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0;">
                                        <i class="fa-solid fa-envelope"></i>
                                    </div>
                                    <div>
                                        <div style="font-size: 0.78rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Email</div>
                                        <div style="font-size: 0.95rem; font-weight: 700; color: #0f172a;"><?= htmlspecialchars($cms['resort_email'] ?? 'info@purplebambooresort.com') ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Announcement preview -->
                            <div style="margin-top: 1.5rem;">
                                <div style="font-size: 0.8rem; font-weight: 800; color: #475569; text-transform: uppercase; margin-bottom: 8px;">
                                    Live Announcement Bar Preview
                                </div>
                                <div style="background: linear-gradient(90deg, #7c3aed, #4f46e5); color: #ffffff; padding: 10px 16px; border-radius: 10px; display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 0.85rem; font-weight: 600;">
                                    <div style="display: flex; align-items: center; gap: 8px; min-width: 0;">
                                        <i class="fa-solid fa-bell" style="color: #fbbf24;"></i>
                                        <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?= !empty($cms['announcement_text']) ? htmlspecialchars($cms['announcement_text']) : 'No active advisory broadcast.' ?>
                                        </span>
                                    </div>
                                    <span style="background: rgba(251, 191, 36, 0.2); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.4); padding: 2px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 800; flex-shrink: 0;">
                                        <?= !empty($cms['announcement_enabled']) ? 'BROADCASTING' : 'DISABLED' ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============================================================
                 TAB 3: GCASH PAYMENT & QR SETTINGS
                 ============================================================ -->
            <section id="panel-gcash" class="settings-panel <?= $active_tab === 'gcash' ? 'active' : '' ?>">
                <div class="st-grid-2">
                    <!-- Left: Form -->
                    <div class="st-card">
                        <div class="st-card-header">
                            <div>
                                <h2 class="st-card-title">
                                    <i class="fa-solid fa-qrcode" style="color: #2563eb;"></i> GCash Payment & QR Configuration
                                </h2>
                                <p class="st-card-subtitle">Manage official recipient credentials, upload resort QR code, and configure guest checkout display.</p>
                            </div>
                            <span class="admin-badge admin-badge-gold">
                                <i class="fa-solid fa-shield-check"></i> System-Wide Sync
                            </span>
                        </div>

                        <!-- System-wide sync notice -->
                        <div style="background: rgba(37, 99, 235, 0.06); border: 1px solid rgba(37, 99, 235, 0.2); border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1.5rem;">
                            <div style="display: flex; align-items: flex-start; gap: 10px;">
                                <i class="fa-solid fa-circle-info" style="color: #2563eb; font-size: 1.1rem; margin-top: 2px;"></i>
                                <div style="font-size: 0.84rem; color: #334155; line-height: 1.5;">
                                    <strong>Instant Multi-Channel Sync:</strong> Updating this QR code and account details automatically propagates to the <strong>Homepage Showcase</strong>, <strong>Guest Online Booking Checkout</strong>, <strong>Payment Verification Desk</strong>, and <strong>Receipt Vouchers</strong>.
                                </div>
                            </div>
                        </div>

                        <form action="settings.php?tab=gcash" method="POST" enctype="multipart/form-data" id="gcashForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_gcash">
                            <input type="hidden" name="remove_qr" id="remove_qr_flag" value="">

                            <!-- Toggle: Show on Homepage -->
                            <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                                <div>
                                    <div style="font-weight: 700; color: #0f172a; font-size: 0.95rem;">
                                        <i class="fa-solid fa-eye" style="color: #7c3aed; margin-right: 6px;"></i> Showcase Card on Resort Homepage
                                    </div>
                                    <div style="font-size: 0.8rem; color: #64748b; margin-top: 2px;">
                                        Display the interactive GCash QR payment card on the public homepage.
                                    </div>
                                </div>
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.88rem; font-weight: 700; color: #334155;">
                                    <input type="checkbox" name="gcash_show_on_home" value="1" <?= !empty($cms['gcash_show_on_home']) ? 'checked' : '' ?> style="width: 20px; height: 20px; accent-color: #2563eb;">
                                    <span>Active on Home</span>
                                </label>
                            </div>

                            <!-- QR Code Upload -->
                            <div class="st-form-group">
                                <label class="st-label"><i class="fa-solid fa-image"></i> Official Resort GCash QR Code Image</label>
                                <div style="display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 14px; padding: 1.25rem;">
                                    <div style="width: 100px; height: 100px; border-radius: 12px; overflow: hidden; background: #ffffff; border: 2px solid #e2e8f0; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 4px 12px rgba(0,0,0,0.06);">
                                        <img src="<?= htmlspecialchars($cms['gcash_qr_image'] ?? 'assets/gcash_qr.jpg') ?>?v=<?= time() ?>" 
                                             id="settingsGcashQrThumb" 
                                             alt="Current QR Code" 
                                             style="max-width: 90px; max-height: 90px; object-fit: contain;">
                                    </div>
                                    <div style="flex: 1; min-width: 220px;">
                                        <div style="font-size: 0.88rem; font-weight: 700; color: #0f172a; margin-bottom: 4px;">
                                            Upload New Merchant QR Code
                                        </div>
                                        <input type="file" name="gcash_qr_file" id="gcash_qr_file" accept=".jpg,.jpeg,.png,.webp" class="st-input" style="padding: 0.45rem 0.6rem; font-size: 0.85rem;" onchange="previewGcashQrFile(this)">
                                        <div class="st-help">
                                            Current: <code style="color: #7c3aed;"><?= htmlspecialchars($cms['gcash_qr_image'] ?? 'assets/gcash_qr.jpg') ?></code>
                                        </div>
                                        <?php if (!empty($cms['gcash_qr_image']) && $cms['gcash_qr_image'] !== 'assets/gcash_qr.jpg'): ?>
                                            <button type="button" onclick="resetGcashQr()" style="background: none; border: none; color: #dc2626; font-size: 0.8rem; font-weight: 700; cursor: pointer; padding: 0; margin-top: 6px; display: inline-flex; align-items: center; gap: 5px;">
                                                <i class="fa-solid fa-arrow-rotate-left"></i> Reset to Default QR Image
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Registered Account Name -->
                            <div class="st-form-group">
                                <label for="gcash_name" class="st-label"><i class="fa-solid fa-user-tag"></i> Registered GCash Account / Merchant Name</label>
                                <input type="text" name="gcash_name" id="gcash_name" value="<?= htmlspecialchars($cms['gcash_name'] ?? 'PURPLE BAMBOO RESORT') ?>" class="st-input" placeholder="e.g. PURPLE BAMBOO RESORT" oninput="updateGcashPreview()" required>
                                <span class="st-help">Official recipient name displayed to guests during payment verification.</span>
                            </div>

                            <!-- Mobile / Account Number -->
                            <div class="st-form-group">
                                <label for="gcash_number" class="st-label"><i class="fa-solid fa-mobile-screen"></i> GCash Mobile / Account Number</label>
                                <input type="text" name="gcash_number" id="gcash_number" value="<?= htmlspecialchars($cms['gcash_number'] ?? '0956 697 8679') ?>" class="st-input" placeholder="e.g. 0956 697 8679" oninput="updateGcashPreview()" required>
                                <span class="st-help">Guests can tap to copy this number directly to their clipboard.</span>
                            </div>

                            <!-- Card Title -->
                            <div class="st-form-group">
                                <label for="gcash_card_title" class="st-label"><i class="fa-solid fa-heading"></i> Showcase Card Title</label>
                                <input type="text" name="gcash_card_title" id="gcash_card_title" value="<?= htmlspecialchars($cms['gcash_card_title'] ?? 'Official GCash Payment Channel') ?>" class="st-input" placeholder="Official GCash Payment Channel" oninput="updateGcashPreview()">
                            </div>

                            <!-- Card Subtitle -->
                            <div class="st-form-group">
                                <label for="gcash_card_subtitle" class="st-label"><i class="fa-solid fa-subscript"></i> Showcase Card Subtitle</label>
                                <input type="text" name="gcash_card_subtitle" id="gcash_card_subtitle" value="<?= htmlspecialchars($cms['gcash_card_subtitle'] ?? 'Scan with GCash or InstaPay for Instant Reservation Verification') ?>" class="st-input" placeholder="Scan with GCash or InstaPay..." oninput="updateGcashPreview()">
                            </div>

                            <!-- Instructions -->
                            <div class="st-form-group">
                                <label for="gcash_instructions" class="st-label"><i class="fa-solid fa-list-ol"></i> Step-by-Step Payment Instructions</label>
                                <textarea name="gcash_instructions" id="gcash_instructions" rows="4" class="st-textarea" placeholder="1. Open GCash app&#10;2. Scan QR code..." oninput="updateGcashPreview()"><?= htmlspecialchars($cms['gcash_instructions'] ?? "1. Open your GCash or Banking app.\n2. Tap 'Scan QR' and scan the official resort QR code.\n3. Input the reservation deposit or total amount.\n4. Take a screenshot of the receipt and reference number.") ?></textarea>
                                <span class="st-help">Clear bulleted instructions for guests paying online or checking in.</span>
                            </div>

                            <?php if ($is_admin): ?>
                                <div style="margin-top: 1.75rem; text-align: right;">
                                    <button type="submit" class="st-btn-gold">
                                        <i class="fa-solid fa-floppy-disk"></i> Save GCash Payment Settings
                                    </button>
                                </div>
                            <?php else: ?>
                                <div style="background: #f1f5f9; padding: 10px; border-radius: 8px; color: #64748b; font-size: 0.85rem;">
                                    <i class="fa-solid fa-lock"></i> Only administrators can modify payment credentials.
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- Right: Live GCash Payment Showcase Card Preview -->
                    <div>
                        <div class="st-card">
                            <div class="st-card-header">
                                <div>
                                    <h3 class="st-card-title">
                                        <i class="fa-solid fa-mobile-screen-button" style="color: #0284c7;"></i> Live GCash Showcase Card
                                    </h3>
                                    <p class="st-card-subtitle">Exact visual representation seen by guests on homepage & checkout.</p>
                                </div>
                                <span class="role-pill" style="background: rgba(37, 99, 235, 0.12); color: #2563eb; border: 1px solid rgba(37, 99, 235, 0.25);">
                                    <i class="fa-solid fa-circle-check"></i> Verified Merchant
                                </span>
                            </div>

                            <!-- Interactive GCash Preview Container -->
                            <div style="background: linear-gradient(145deg, #0056b3 0%, #007dfe 50%, #00a0fe 100%); border-radius: 20px; padding: 1.75rem; color: #ffffff; box-shadow: 0 15px 35px -5px rgba(0, 125, 254, 0.35); position: relative; overflow: hidden;">
                                <!-- Decorative background watermark -->
                                <div style="position: absolute; right: -25px; bottom: -30px; font-size: 11rem; font-weight: 900; color: rgba(255, 255, 255, 0.08); user-select: none; pointer-events: none; font-family: sans-serif; line-height: 1;">
                                    G
                                </div>

                                <!-- Card Header -->
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem; border-bottom: 1px solid rgba(255, 255, 255, 0.18); padding-bottom: 1rem; position: relative; z-index: 1;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="background: #ffffff; color: #007dfe; width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 1.2rem; box-shadow: 0 4px 10px rgba(0,0,0,0.15);">
                                            G
                                        </div>
                                        <div>
                                            <div id="prevGcashCardTitle" style="font-family: 'Outfit', sans-serif; font-weight: 800; font-size: 1.05rem; line-height: 1.2; text-shadow: 0 1px 2px rgba(0,0,0,0.2);">
                                                <?= htmlspecialchars($cms['gcash_card_title'] ?? 'Official GCash Payment Channel') ?>
                                            </div>
                                            <div id="prevGcashCardSubtitle" style="font-size: 0.76rem; color: rgba(255, 255, 255, 0.85); margin-top: 2px;">
                                                <?= htmlspecialchars($cms['gcash_card_subtitle'] ?? 'Scan with GCash or InstaPay for Instant Reservation Verification') ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Card Center: QR Code & Account Box -->
                                <div style="display: flex; gap: 1.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.25rem; position: relative; z-index: 1;">
                                    <!-- QR Box -->
                                    <div style="background: #ffffff; padding: 10px; border-radius: 14px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2); text-align: center; flex-shrink: 0; width: 140px; margin: 0 auto;">
                                        <img src="<?= htmlspecialchars($cms['gcash_qr_image'] ?? 'assets/gcash_qr.jpg') ?>?v=<?= time() ?>" 
                                             id="prevGcashQrImg" 
                                             alt="Resort QR" 
                                             style="width: 120px; height: 120px; object-fit: contain; display: block; border-radius: 8px;">
                                        <div style="font-size: 0.68rem; font-weight: 800; color: #007dfe; text-transform: uppercase; margin-top: 6px; letter-spacing: 0.5px;">
                                            SCAN TO PAY
                                        </div>
                                    </div>

                                    <!-- Account Meta -->
                                    <div style="flex: 1; min-width: 180px;">
                                        <div style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: rgba(255, 255, 255, 0.8); letter-spacing: 0.5px;">
                                            Registered Merchant
                                        </div>
                                        <div id="prevGcashName" style="font-size: 1.15rem; font-weight: 800; font-family: 'Outfit', sans-serif; letter-spacing: 0.5px; margin-bottom: 0.75rem;">
                                            <?= htmlspecialchars($cms['gcash_name'] ?? 'PURPLE BAMBOO RESORT') ?>
                                        </div>

                                        <div style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: rgba(255, 255, 255, 0.8); letter-spacing: 0.5px;">
                                            GCash Number
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 0.75rem;">
                                            <div id="prevGcashNumber" style="font-size: 1.25rem; font-weight: 800; font-family: monospace; letter-spacing: 1px;">
                                                <?= htmlspecialchars($cms['gcash_number'] ?? '0956 697 8679') ?>
                                            </div>
                                            <span style="background: rgba(255, 255, 255, 0.2); border: 1px solid rgba(255, 255, 255, 0.35); padding: 3px 8px; border-radius: 6px; font-size: 0.72rem; font-weight: 700; cursor: pointer;">
                                                <i class="fa-regular fa-copy"></i> Copy
                                            </span>
                                        </div>

                                        <div style="display: inline-flex; align-items: center; gap: 6px; background: rgba(0, 0, 0, 0.2); padding: 4px 10px; border-radius: 20px; font-size: 0.74rem; font-weight: 600;">
                                            <i class="fa-solid fa-bolt" style="color: #fbbf24;"></i> Instant Auto Reference Verification
                                        </div>
                                    </div>
                                </div>

                                <!-- Card Instructions Preview -->
                                <div style="background: rgba(0, 0, 0, 0.18); border-radius: 12px; padding: 12px 14px; position: relative; z-index: 1;">
                                    <div style="font-size: 0.72rem; font-weight: 800; text-transform: uppercase; color: #fbbf24; margin-bottom: 6px; letter-spacing: 0.5px;">
                                        <i class="fa-solid fa-circle-question"></i> Payment Instructions
                                    </div>
                                    <div id="prevGcashInstructions" style="font-size: 0.8rem; line-height: 1.5; color: rgba(255, 255, 255, 0.92); white-space: pre-line;">
                                        <?= htmlspecialchars($cms['gcash_instructions'] ?? "1. Open your GCash or Banking app.\n2. Tap 'Scan QR' and scan the official resort QR code.\n3. Input the reservation deposit or total amount.\n4. Take a screenshot of the receipt and reference number.") ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============================================================
                 TAB 4: MANAGE STAFF (ADD, VIEW, UPDATE, DELETE)
                 ============================================================ -->
            <section id="panel-staff" class="settings-panel <?= $active_tab === 'staff' ? 'active' : '' ?>">
                <!-- Stats & Search Header Row -->
                <div class="st-card">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1.25rem; margin-bottom: 1.5rem; padding-bottom: 1.25rem; border-bottom: 1px solid #f1f5f9;">
                        <!-- Left: KPI Stats Chips -->
                        <div class="st-kpi-group">
                            <div class="st-kpi-chip">
                                <div class="st-kpi-icon st-kpi-purple">
                                    <i class="fa-solid fa-users"></i>
                                </div>
                                <div>
                                    <div class="st-kpi-val"><?= $active_staff_count ?></div>
                                    <div class="st-kpi-lbl">Total Staff Members</div>
                                </div>
                            </div>

                            <div class="st-kpi-chip">
                                <div class="st-kpi-icon st-kpi-green">
                                    <i class="fa-solid fa-shield-halved"></i>
                                </div>
                                <div>
                                    <div class="st-kpi-val">
                                        <span class="st-pulse-dot"></span> Active
                                    </div>
                                    <div class="st-kpi-lbl">Operational Status</div>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Search & Single Add Staff button (Strictly 1 button on screen) -->
                        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                            <div style="position: relative; min-width: 280px;">
                                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.9rem;"></i>
                                <input type="text" id="staffSearchInput" oninput="filterStaffTable()" placeholder="Search by name, email, phone..." class="st-input" style="padding-left: 38px; height: 42px; background: #ffffff; min-width: 280px;">
                            </div>
                            <?php if ($is_admin): ?>
                                <button type="button" onclick="openModal('addStaffModal')" class="st-btn-gold" style="height: 42px; white-space: nowrap;">
                                    <i class="fa-solid fa-user-plus"></i> Add New Staff
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Staff Table -->
                    <div class="st-table-wrapper">
                        <table class="st-table" id="staffTable">
                            <thead>
                                <tr>
                                    <th>Staff Personnel</th>
                                    <th>Profile ID</th>
                                    <th>Email Address</th>
                                    <th>Phone Number</th>
                                    <th>Role</th>
                                    <th>Joined Date</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($staff_list)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 2.5rem; color: #64748b;">
                                            <i class="fa-solid fa-users-slash" style="font-size: 2rem; color: #cbd5e1; margin-bottom: 8px; display: block;"></i>
                                            No staff personnel found. Click <strong>"Add New Staff"</strong> above to register staff accounts.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($staff_list as $staff): 
                                        $initials = strtoupper(substr($staff['first_name'] ?: 'S', 0, 1) . substr($staff['last_name'] ?: 'T', 0, 1));
                                        $cleanPhone = preg_replace('/\D/', '', $staff['phone'] ?? '');
                                        if (strlen($cleanPhone) === 10 && str_starts_with($cleanPhone, '9')) {
                                            $cleanPhone = '0' . $cleanPhone;
                                        }
                                        $phoneFormatted = (strlen($cleanPhone) === 11 && str_starts_with($cleanPhone, '09'))
                                            ? substr($cleanPhone, 0, 4) . '-' . substr($cleanPhone, 4, 3) . '-' . substr($cleanPhone, 7)
                                            : ($staff['phone'] ?: '—');
                                    ?>
                                        <tr class="staff-row" data-search="<?= strtolower(htmlspecialchars($staff['full_name'] . ' ' . $staff['email'] . ' ' . $staff['phone'] . ' ' . $staff['profile_id'])) ?>">
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 12px;">
                                                    <div class="user-avatar-badge">
                                                        <?= $initials ?>
                                                    </div>
                                                    <div>
                                                        <div style="font-weight: 700; color: #0f172a; font-size: 0.94rem;">
                                                            <?= htmlspecialchars($staff['full_name']) ?>
                                                        </div>
                                                        <div style="font-size: 0.78rem; color: #64748b; margin-top: 1px;">
                                                            Staff Member
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span style="font-family: monospace; font-weight: 700; color: #475569; background: #f1f5f9; padding: 4px 9px; border-radius: 6px; font-size: 0.84rem; border: 1px solid #e2e8f0; display: inline-flex; align-items: center; gap: 5px;">
                                                    <i class="fa-solid fa-id-badge" style="color: #94a3b8; font-size: 0.75rem;"></i>
                                                    <?= htmlspecialchars($staff['profile_id']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="mailto:<?= htmlspecialchars($staff['email']) ?>" style="color: #2563eb; text-decoration: none; font-weight: 600; font-size: 0.88rem; display: inline-flex; align-items: center; gap: 6px;">
                                                    <i class="fa-regular fa-envelope" style="color: #94a3b8; font-size: 0.82rem;"></i>
                                                    <?= htmlspecialchars($staff['email']) ?>
                                                </a>
                                            </td>
                                            <td style="font-weight: 600; color: #334155; font-size: 0.88rem;">
                                                <span style="display: inline-flex; align-items: center; gap: 6px;">
                                                    <i class="fa-solid fa-phone" style="color: #94a3b8; font-size: 0.78rem;"></i>
                                                    <?= htmlspecialchars($phoneFormatted) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="role-pill role-pill-staff">
                                                    <i class="fa-solid fa-user-check"></i> STAFF
                                                </span>
                                            </td>
                                            <td style="color: #64748b; font-size: 0.86rem;">
                                                <span style="display: inline-flex; align-items: center; gap: 6px;">
                                                    <i class="fa-regular fa-calendar-check" style="color: #94a3b8; font-size: 0.82rem;"></i>
                                                    <?= date('M d, Y', strtotime($staff['created_at'])) ?>
                                                </span>
                                            </td>
                                            <td style="text-align: right;">
                                                <div style="display: inline-flex; gap: 8px; justify-content: flex-end;">
                                                    <!-- VIEW BUTTON -->
                                                    <button type="button" 
                                                            onclick='openViewStaffModal(<?= json_encode($staff) ?>)' 
                                                            class="action-icon-btn view-btn" 
                                                            title="View Staff Profile">
                                                        <i class="fa-solid fa-eye"></i>
                                                    </button>

                                                    <?php if ($is_admin): ?>
                                                        <!-- UPDATE BUTTON -->
                                                        <button type="button" 
                                                                onclick='openEditStaffModal(<?= json_encode($staff) ?>)' 
                                                                class="action-icon-btn edit-btn" 
                                                                title="Edit Staff Information">
                                                            <i class="fa-solid fa-pen-to-square"></i>
                                                        </button>

                                                        <!-- DELETE BUTTON -->
                                                        <button type="button" 
                                                                onclick="confirmDeleteStaff('<?= htmlspecialchars($staff['email'], ENT_QUOTES) ?>', '<?= htmlspecialchars($staff['full_name'], ENT_QUOTES) ?>')" 
                                                                class="action-icon-btn del-btn" 
                                                                title="Delete Staff Account">
                                                            <i class="fa-solid fa-trash-can"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <tr id="staffSearchEmptyRow" style="display: none;">
                                    <td colspan="7" style="text-align: center; padding: 2.5rem; color: #64748b;">
                                        <i class="fa-solid fa-user-xmark" style="font-size: 2rem; color: #cbd5e1; margin-bottom: 8px; display: block;"></i>
                                        No staff personnel matched your search query.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 18px; background: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 0.82rem; color: #64748b;">
                            <div>
                                Showing <strong id="staffVisibleCount" style="color: #0f172a;"><?= count($staff_list) ?></strong> active staff <?= count($staff_list) === 1 ? 'member' : 'members' ?>
                            </div>
                            <div style="display: flex; align-items: center; gap: 6px; font-weight: 600; color: #059669;">
                                <span class="st-pulse-dot" style="width: 7px; height: 7px;"></span> Directory Live
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============================================================
                 TAB 4: ADMIN PROFILE (REDESIGNED + ADD NEW ADMIN)
                 ============================================================ -->
            <section id="panel-profile" class="settings-panel <?= $active_tab === 'profile' ? 'active' : '' ?>">
                <!-- 2-Column: Personal Information & Password Security -->
                <div class="st-grid-2">
                    <!-- Column 1: Update Profile Info -->
                    <div class="st-card">
                        <div class="st-card-header">
                            <div>
                                <h3 class="st-card-title">
                                    <i class="fa-solid fa-user-pen"></i> Edit Personal Information
                                </h3>
                                <p class="st-card-subtitle">Update your administrator name and contact phone number.</p>
                            </div>
                        </div>

                        <form action="settings.php?tab=profile" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_admin_profile">

                            <div class="st-grid-2">
                                <div class="st-form-group">
                                    <label class="st-label">First Name *</label>
                                    <input type="text" name="first_name" value="<?= htmlspecialchars($current_admin['first_name']) ?>" class="st-input" required>
                                </div>
                                <div class="st-form-group">
                                    <label class="st-label">Middle Name</label>
                                    <input type="text" name="middle_name" value="<?= htmlspecialchars($current_admin['middle_name']) ?>" class="st-input" placeholder="Optional">
                                </div>
                            </div>

                            <div class="st-grid-2">
                                <div class="st-form-group">
                                    <label class="st-label">Last Name *</label>
                                    <input type="text" name="last_name" value="<?= htmlspecialchars($current_admin['last_name']) ?>" class="st-input" required>
                                </div>
                                <div class="st-form-group">
                                    <label class="st-label">Suffix</label>
                                    <select name="suffix" class="st-select">
                                        <option value="">None</option>
                                        <option value="Jr." <?= ($current_admin['suffix'] === 'Jr.') ? 'selected' : '' ?>>Jr.</option>
                                        <option value="Sr." <?= ($current_admin['suffix'] === 'Sr.') ? 'selected' : '' ?>>Sr.</option>
                                        <option value="II" <?= ($current_admin['suffix'] === 'II') ? 'selected' : '' ?>>II</option>
                                        <option value="III" <?= ($current_admin['suffix'] === 'III') ? 'selected' : '' ?>>III</option>
                                        <option value="IV" <?= ($current_admin['suffix'] === 'IV') ? 'selected' : '' ?>>IV</option>
                                        <option value="V" <?= ($current_admin['suffix'] === 'V') ? 'selected' : '' ?>>V</option>
                                    </select>
                                </div>
                            </div>

                            <div class="st-form-group">
                                <label class="st-label">Email Address (Read-only)</label>
                                <input type="email" value="<?= htmlspecialchars($current_admin['email']) ?>" class="st-input" style="background: #f1f5f9; cursor: not-allowed;" readonly>
                                <span class="st-help">Admin email is linked to system security and cannot be changed here.</span>
                            </div>

                            <div class="st-form-group">
                                <label class="st-label">Contact Phone Number *</label>
                                <input type="tel" name="phone" value="<?= htmlspecialchars($current_admin['phone']) ?>" class="st-input" placeholder="09171234567" maxlength="11" pattern="09[0-9]{9}" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);" required>
                                <span class="st-help">11 digits starting with 09 (numbers only)</span>
                            </div>

                            <div style="margin-top: 1.5rem; text-align: right;">
                                <button type="submit" class="st-btn-purple">
                                    <i class="fa-solid fa-check"></i> Save Personal Details
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Column 2: Change Password -->
                    <div class="st-card">
                        <div class="st-card-header">
                            <div>
                                <h3 class="st-card-title">
                                    <i class="fa-solid fa-key"></i> Security & Password
                                </h3>
                                <p class="st-card-subtitle">Ensure your administrator account uses a strong, unique password.</p>
                            </div>
                        </div>

                        <form action="settings.php?tab=profile" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="change_admin_password">

                            <div class="st-form-group">
                                <label class="st-label">Current Password *</label>
                                <div class="password-field-wrapper">
                                    <input type="password" name="current_password" id="cur_admin_pwd" class="st-input" placeholder="Enter your current password" required>
                                    <button type="button" class="password-toggle-btn" onclick="togglePasswordVis('cur_admin_pwd', this)">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="st-form-group">
                                <label class="st-label">New Password *</label>
                                <div class="password-field-wrapper">
                                    <input type="password" name="new_password" id="new_admin_pwd" class="st-input" placeholder="At least 9 chars, upper, lower, & number" required oninput="checkPwdStrength(this.value, 'admin_pwd_fill', 'admin_pwd_text')">
                                    <button type="button" class="password-toggle-btn" onclick="togglePasswordVis('new_admin_pwd', this)">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </div>
                                <div class="pwd-strength-bar">
                                    <div id="admin_pwd_fill" class="pwd-strength-fill"></div>
                                </div>
                                <span id="admin_pwd_text" class="st-help">Must contain min 9 chars with uppercase, lowercase, and numbers.</span>
                            </div>

                            <div class="st-form-group">
                                <label class="st-label">Confirm New Password *</label>
                                <div class="password-field-wrapper">
                                    <input type="password" name="confirm_password" id="conf_admin_pwd" class="st-input" placeholder="Re-enter new password" required>
                                    <button type="button" class="password-toggle-btn" onclick="togglePasswordVis('conf_admin_pwd', this)">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div style="background: rgba(251, 191, 36, 0.1); border: 1px solid rgba(251, 191, 36, 0.3); border-radius: 8px; padding: 10px 14px; margin-top: 14px; display: flex; align-items: center; gap: 10px; font-size: 0.82rem; color: #92400e;">
                                <i class="fa-solid fa-paper-plane" style="font-size: 1rem; color: #d97706;"></i>
                                <span><strong>Automatic Email Dispatch:</strong> Your updated administrator password will be automatically sent to your registered email address (<strong><?= htmlspecialchars($viewer_email) ?></strong>).</span>
                            </div>

                            <div style="margin-top: 1.5rem; text-align: right;">
                                <button type="submit" class="st-btn-gold">
                                    <i class="fa-solid fa-shield-halved"></i> Update Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Existing Administrators Directory List -->
                <div class="st-card">
                    <div class="st-card-header">
                        <div>
                            <h3 class="st-card-title">
                                <i class="fa-solid fa-users-shield"></i> Registered System Administrators
                            </h3>
                            <p class="st-card-subtitle">Overview of all active administrator accounts with console access.</p>
                        </div>
                        <?php if ($is_admin): ?>
                            <button type="button" onclick="openModal('addAdminModal')" class="st-btn-gold">
                                <i class="fa-solid fa-plus"></i> Add Administrator
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="st-table-wrapper">
                        <table class="st-table">
                            <thead>
                                <tr>
                                    <th>Admin Name</th>
                                    <th>Profile ID</th>
                                    <th>Email Address</th>
                                    <th>Phone Number</th>
                                    <th>Status</th>
                                    <th>Registered Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($admin_list as $adm): 
                                    $isMe = (strtolower($adm['email']) === $viewer_email);
                                    $admInitials = strtoupper(substr($adm['first_name'] ?: 'A', 0, 1) . substr($adm['last_name'] ?: 'D', 0, 1));
                                ?>
                                    <tr>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 12px;">
                                                <div class="user-avatar-badge admin-badge-avatar">
                                                    <?= $admInitials ?>
                                                </div>
                                                <div>
                                                    <div style="font-weight: 700; color: #0f172a;">
                                                        <?= htmlspecialchars($adm['full_name']) ?>
                                                        <?php if ($isMe): ?>
                                                            <span style="background: rgba(16, 185, 129, 0.12); color: #059669; font-size: 0.72rem; padding: 2px 7px; border-radius: 6px; font-weight: 800; margin-left: 5px;">YOU</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div style="font-size: 0.78rem; color: #64748b;">Administrator</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span style="font-family: monospace; font-weight: 700; color: #d97706; background: rgba(245, 158, 11, 0.1); padding: 3px 8px; border-radius: 6px; font-size: 0.84rem;">
                                                <?= htmlspecialchars($adm['profile_id']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="color: #0f172a; font-weight: 600;"><?= htmlspecialchars($adm['email']) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($adm['phone'] ?: '—') ?></td>
                                        <td>
                                            <span class="role-pill role-pill-admin">
                                                <i class="fa-solid fa-circle-check"></i> Active
                                            </span>
                                        </td>
                                        <td style="color: #64748b; font-size: 0.85rem;">
                                            <?= date('M d, Y', strtotime($adm['created_at'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <!-- ============================================================
         MODAL 1: ADD NEW STAFF MODAL
         ============================================================ -->
    <div id="addStaffModal" class="st-modal-backdrop">
        <div class="st-modal">
            <div class="st-modal-header">
                <h3 class="st-modal-title">
                    <i class="fa-solid fa-user-plus"></i> Add New Staff Member
                </h3>
                <button type="button" class="st-modal-close" onclick="closeModal('addStaffModal')">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <form action="settings.php?tab=staff" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_staff">
                <div class="st-modal-body">
                    <div class="st-grid-2">
                        <div class="st-form-group">
                            <label class="st-label">First Name *</label>
                            <input type="text" name="first_name" class="st-input" placeholder="e.g. Maria" required>
                        </div>
                        <div class="st-form-group">
                            <label class="st-label">Middle Name</label>
                            <input type="text" name="middle_name" class="st-input" placeholder="Optional">
                        </div>
                    </div>

                    <div class="st-grid-2">
                        <div class="st-form-group">
                            <label class="st-label">Last Name *</label>
                            <input type="text" name="last_name" class="st-input" placeholder="e.g. Santos" required>
                        </div>
                        <div class="st-form-group">
                            <label class="st-label">Suffix</label>
                            <select name="suffix" class="st-select">
                                <option value="">None</option>
                                <option value="Jr.">Jr.</option>
                                <option value="Sr.">Sr.</option>
                                <option value="II">II</option>
                                <option value="III">III</option>
                                <option value="IV">IV</option>
                                <option value="V">V</option>
                            </select>
                        </div>
                    </div>

                    <div class="st-form-group">
                        <label class="st-label">Staff Email Address *</label>
                        <input type="email" name="email" class="st-input" placeholder="staff.name@gmail.com" required>
                        <span class="st-help">Account credentials will be dispatched to this email address.</span>
                    </div>

                    <div class="st-form-group">
                        <label class="st-label">Contact Phone Number *</label>
                        <input type="tel" name="phone" class="st-input" placeholder="09171234567" maxlength="11" pattern="09[0-9]{9}" inputmode="numeric" oninput="let v = this.value.replace(/[^0-9]/g, ''); if(v.startsWith('639')) v = '0' + v.slice(2); this.value = v.slice(0, 11);" required>
                        <span class="st-help">11 digits starting with 09 (e.g. 09171234567)</span>
                    </div>

                    <div style="background: linear-gradient(135deg, rgba(79, 70, 229, 0.08) 0%, rgba(124, 58, 237, 0.08) 100%); border: 1px solid rgba(99, 102, 241, 0.3); border-radius: 12px; padding: 14px 16px; margin-top: 14px; display: flex; align-items: flex-start; gap: 12px; font-size: 0.85rem; color: #312e81;">
                        <i class="fa-solid fa-key" style="font-size: 1.25rem; color: #6366f1; margin-top: 2px; flex-shrink: 0;"></i>
                        <div>
                            <strong style="color: #1e1b4b; display: block; margin-bottom: 3px;">Auto-Generated Secure Password &amp; Email Dispatch</strong>
                            <span style="color: #4338ca; line-height: 1.45; display: block;">No manual password entry required. A secure temporary password will be automatically generated and emailed to the staff member's inbox with portal login instructions.</span>
                        </div>
                    </div>
                </div>
                <div class="st-modal-footer">
                    <button type="button" class="st-btn-outline" onclick="closeModal('addStaffModal')">Cancel</button>
                    <button type="submit" class="st-btn-gold">
                        <i class="fa-solid fa-user-plus"></i> Create Staff Account
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================
         MODAL 2: EDIT STAFF MODAL
         ============================================================ -->
    <div id="editStaffModal" class="st-modal-backdrop">
        <div class="st-modal">
            <div class="st-modal-header">
                <h3 class="st-modal-title">
                    <i class="fa-solid fa-pen-to-square"></i> Edit Staff Member
                </h3>
                <button type="button" class="st-modal-close" onclick="closeModal('editStaffModal')">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <form action="settings.php?tab=staff" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_staff">
                <input type="hidden" name="target_email" id="edit_staff_target_email">

                <div class="st-modal-body">
                    <div class="st-form-group">
                        <label class="st-label">Staff Email (Account Identifier)</label>
                        <input type="email" id="edit_staff_email_display" class="st-input" style="background:#f1f5f9; cursor:not-allowed;" readonly>
                    </div>

                    <div class="st-grid-2">
                        <div class="st-form-group">
                            <label class="st-label">First Name *</label>
                            <input type="text" name="first_name" id="edit_staff_first_name" class="st-input" required>
                        </div>
                        <div class="st-form-group">
                            <label class="st-label">Middle Name</label>
                            <input type="text" name="middle_name" id="edit_staff_middle_name" class="st-input">
                        </div>
                    </div>

                    <div class="st-grid-2">
                        <div class="st-form-group">
                            <label class="st-label">Last Name *</label>
                            <input type="text" name="last_name" id="edit_staff_last_name" class="st-input" required>
                        </div>
                        <div class="st-form-group">
                            <label class="st-label">Suffix</label>
                            <select name="suffix" id="edit_staff_suffix" class="st-select">
                                <option value="">None</option>
                                <option value="Jr.">Jr.</option>
                                <option value="Sr.">Sr.</option>
                                <option value="II">II</option>
                                <option value="III">III</option>
                                <option value="IV">IV</option>
                                <option value="V">V</option>
                            </select>
                        </div>
                    </div>

                    <div class="st-form-group">
                        <label class="st-label">Contact Phone Number</label>
                        <input type="tel" name="phone" id="edit_staff_phone" class="st-input" placeholder="09171234567" maxlength="11" pattern="09[0-9]{9}" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);">
                        <span class="st-help">11 digits starting with 09 (numbers only)</span>
                    </div>

                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px; margin-top: 10px;">
                        <span style="font-weight: 700; font-size: 0.85rem; color: #334155; display: block; margin-bottom: 8px;">
                            <i class="fa-solid fa-lock" style="color: #64748b;"></i> Reset Password (Optional)
                        </span>
                        <div class="st-grid-2">
                            <div class="st-form-group" style="margin-bottom: 0;">
                                <label class="st-label" style="font-size: 0.78rem;">New Password</label>
                                <input type="password" name="password" class="st-input" placeholder="Leave blank to keep unchanged">
                            </div>
                            <div class="st-form-group" style="margin-bottom: 0;">
                                <label class="st-label" style="font-size: 0.78rem;">Confirm Password</label>
                                <input type="password" name="confirm_password" class="st-input" placeholder="Confirm new password">
                            </div>
                        </div>
                        <span class="st-help" style="margin-top: 8px; display: block; color: #4338ca; font-size: 0.78rem;">
                            <i class="fa-solid fa-paper-plane"></i> If a new password is set, an updated credentials email will automatically be sent to the staff member.
                        </span>
                    </div>
                </div>
                <div class="st-modal-footer">
                    <button type="button" class="st-btn-outline" onclick="closeModal('editStaffModal')">Cancel</button>
                    <button type="submit" class="st-btn-purple">
                        <i class="fa-solid fa-floppy-disk"></i> Update Staff Details
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================
         MODAL 3: VIEW STAFF DETAILS MODAL
         ============================================================ -->
    <div id="viewStaffModal" class="st-modal-backdrop">
        <div class="st-modal">
            <div class="st-modal-header">
                <h3 class="st-modal-title">
                    <i class="fa-solid fa-id-card"></i> Staff Personnel Profile
                </h3>
                <button type="button" class="st-modal-close" onclick="closeModal('viewStaffModal')">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="st-modal-body">
                <div style="text-align: center; margin-bottom: 1.5rem;">
                    <div id="view_staff_avatar" class="user-avatar-badge" style="width: 72px; height: 72px; font-size: 1.8rem; margin: 0 auto 12px; border-radius: 16px;"></div>
                    <h3 id="view_staff_fullname" style="font-family: 'Outfit', sans-serif; font-size: 1.4rem; font-weight: 800; color: #0f172a; margin: 0 0 4px;"></h3>
                    <div style="display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <span id="view_staff_profile_id" style="font-family: monospace; font-weight: 700; color: #7c3aed; background: rgba(124, 58, 237, 0.1); padding: 3px 10px; border-radius: 6px; font-size: 0.85rem;"></span>
                        <span class="role-pill role-pill-staff">Staff Member</span>
                    </div>
                </div>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px;">
                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e2e8f0;">
                        <span style="color: #64748b; font-size: 0.86rem; font-weight: 600;">Email Address:</span>
                        <span id="view_staff_email" style="color: #0f172a; font-weight: 700; font-size: 0.88rem;"></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e2e8f0;">
                        <span style="color: #64748b; font-size: 0.86rem; font-weight: 600;">Phone Number:</span>
                        <span id="view_staff_phone" style="color: #0f172a; font-weight: 700; font-size: 0.88rem;"></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 8px 0;">
                        <span style="color: #64748b; font-size: 0.86rem; font-weight: 600;">Registration Date:</span>
                        <span id="view_staff_created" style="color: #0f172a; font-weight: 700; font-size: 0.88rem;"></span>
                    </div>
                </div>
            </div>
            <div class="st-modal-footer">
                <button type="button" class="st-btn-outline" onclick="closeModal('viewStaffModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- ============================================================
         MODAL 4: DELETE CONFIRMATION MODAL
         ============================================================ -->
    <div id="deleteStaffModal" class="st-modal-backdrop">
        <div class="st-modal" style="max-width: 480px;">
            <div class="st-modal-header" style="background: linear-gradient(90deg, #7f1d1d, #991b1b);">
                <h3 class="st-modal-title">
                    <i class="fa-solid fa-triangle-exclamation" style="color: #fecaca;"></i> Confirm Removal
                </h3>
                <button type="button" class="st-modal-close" onclick="closeModal('deleteStaffModal')">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <form action="settings.php?tab=staff" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_staff">
                <input type="hidden" name="target_email" id="delete_staff_target_email">

                <div class="st-modal-body" style="text-align: center; padding: 1.75rem 1.5rem;">
                    <i class="fa-solid fa-circle-exclamation" style="font-size: 3rem; color: #dc2626; margin-bottom: 12px; display: inline-block;"></i>
                    <h3 style="font-family: 'Outfit', sans-serif; font-size: 1.25rem; font-weight: 700; color: #0f172a; margin: 0 0 8px;">
                        Delete Staff Account?
                    </h3>
                    <p style="font-size: 0.9rem; color: #64748b; margin: 0 0 14px;">
                        Are you sure you want to permanently delete <strong id="delete_staff_name_display" style="color: #0f172a;"></strong> from the staff team?
                    </p>
                    <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 10px; font-size: 0.8rem; color: #991b1b; text-align: left;">
                        <i class="fa-solid fa-info-circle"></i> This will immediately revoke their console access. This action cannot be reversed.
                    </div>
                </div>
                <div class="st-modal-footer" style="justify-content: center;">
                    <button type="button" class="st-btn-outline" onclick="closeModal('deleteStaffModal')">Cancel</button>
                    <button type="submit" class="st-btn-gold" style="background: #dc2626; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);">
                        <i class="fa-solid fa-trash-can"></i> Yes, Delete Staff
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================
         MODAL 5: ADD NEW ADMINISTRATOR MODAL
         ============================================================ -->
    <div id="addAdminModal" class="st-modal-backdrop">
        <div class="st-modal">
            <div class="st-modal-header" style="background: linear-gradient(90deg, #1e113a 0%, #0d0720 100%); border-bottom: 1px solid rgba(251, 191, 36, 0.3);">
                <h3 class="st-modal-title">
                    <i class="fa-solid fa-shield-cat" style="color: #fbbf24;"></i> Register New Administrator
                </h3>
                <button type="button" class="st-modal-close" onclick="closeModal('addAdminModal')">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <form action="settings.php?tab=profile" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_admin">

                <div class="st-modal-body">
                    <div style="background: rgba(251, 191, 36, 0.1); border: 1px solid rgba(251, 191, 36, 0.3); border-radius: 10px; padding: 12px; margin-bottom: 1.25rem; font-size: 0.82rem; color: #b45309; display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-triangle-exclamation" style="font-size: 1.2rem; flex-shrink: 0;"></i>
                        <span>Administrators have complete administrative privileges across bookings, room rates, guest accounts, and financial settings.</span>
                    </div>

                    <div class="st-grid-2">
                        <div class="st-form-group">
                            <label class="st-label">First Name *</label>
                            <input type="text" name="first_name" class="st-input" placeholder="e.g. John" required>
                        </div>
                        <div class="st-form-group">
                            <label class="st-label">Middle Name</label>
                            <input type="text" name="middle_name" class="st-input" placeholder="Optional">
                        </div>
                    </div>

                    <div class="st-grid-2">
                        <div class="st-form-group">
                            <label class="st-label">Last Name *</label>
                            <input type="text" name="last_name" class="st-input" placeholder="e.g. Doe" required>
                        </div>
                        <div class="st-form-group">
                            <label class="st-label">Suffix</label>
                            <select name="suffix" class="st-select">
                                <option value="">None</option>
                                <option value="Jr.">Jr.</option>
                                <option value="Sr.">Sr.</option>
                                <option value="II">II</option>
                                <option value="III">III</option>
                                <option value="IV">IV</option>
                                <option value="V">V</option>
                            </select>
                        </div>
                    </div>

                    <div class="st-form-group">
                        <label class="st-label">Administrator Email *</label>
                        <input type="email" name="email" class="st-input" placeholder="admin.name@gmail.com" pattern="^[a-zA-Z0-9._%+-]+@gmail\.com$" title="Only @gmail.com email addresses are allowed" required>
                        <span class="st-help">Must be a valid @gmail.com address for admin authentication and credential dispatch.</span>
                    </div>

                    <div class="st-form-group">
                        <label class="st-label">Contact Phone Number *</label>
                        <input type="tel" name="phone" class="st-input" placeholder="09171234567" maxlength="11" pattern="09[0-9]{9}" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);" required>
                        <span class="st-help">11 digits starting with 09 (numbers only)</span>
                    </div>

                    <div style="background: linear-gradient(135deg, rgba(251, 191, 36, 0.12) 0%, rgba(245, 158, 11, 0.08) 100%); border: 1px solid rgba(251, 191, 36, 0.4); border-radius: 12px; padding: 14px 16px; margin-top: 14px; display: flex; align-items: flex-start; gap: 12px; font-size: 0.85rem; color: #78350f;">
                        <i class="fa-solid fa-key" style="font-size: 1.25rem; color: #d97706; margin-top: 2px; flex-shrink: 0;"></i>
                        <div>
                            <strong style="color: #451a03; display: block; margin-bottom: 3px;">Auto-Generated Secure Password &amp; Email Dispatch</strong>
                            <span style="color: #92400e; line-height: 1.45; display: block;">No manual password entry required. An administrator-grade secure password will be automatically generated and emailed to the administrator's email address upon registration.</span>
                        </div>
                    </div>
                </div>

                <div class="st-modal-footer">
                    <button type="button" class="st-btn-outline" onclick="closeModal('addAdminModal')">Cancel</button>
                    <button type="submit" class="st-btn-gold">
                        <i class="fa-solid fa-shield-cat"></i> Register Administrator
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================
         JAVASCRIPT CONTROLLERS
         ============================================================ -->
    <script>
    // Tab Switching Controller
    function switchTab(tabName, el) {
        document.querySelectorAll('.settings-tab-btn').forEach(btn => btn.classList.remove('active'));
        if (el) el.classList.add('active');

        document.querySelectorAll('.settings-panel').forEach(panel => panel.classList.remove('active'));
        const targetPanel = document.getElementById('panel-' + tabName);
        if (targetPanel) {
            targetPanel.classList.add('active');
        }

        // Update URL hash/query without reloading
        const url = new URL(window.location);
        url.searchParams.set('tab', tabName);
        window.history.replaceState({}, '', url);
    }

    // Modal Control Helpers
    function openModal(id) {
        const m = document.getElementById(id);
        if (m) {
            m.classList.add('open');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeModal(id) {
        const m = document.getElementById(id);
        if (m) {
            m.classList.remove('open');
            document.body.style.overflow = '';
        }
    }

    // Backdrop click listener to close modals
    document.querySelectorAll('.st-modal-backdrop').forEach(backdrop => {
        backdrop.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal(this.id);
            }
        });
    });

    // Escape key listener to close active modals
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.st-modal-backdrop.open').forEach(m => closeModal(m.id));
        }
    });

    // Password Visibility Toggle
    function togglePasswordVis(inputId, btn) {
        const input = document.getElementById(inputId);
        if (!input) return;
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            if (icon) {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            }
        } else {
            input.type = 'password';
            if (icon) {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    }

    // Live Password Strength Indicator
    function checkPwdStrength(pwd, fillId, textId) {
        const fill = document.getElementById(fillId);
        const text = document.getElementById(textId);
        if (!fill) return;

        let score = 0;
        if (pwd.length >= 9) score += 25;
        if (/[A-Z]/.test(pwd)) score += 25;
        if (/[a-z]/.test(pwd)) score += 25;
        if (/[0-9]/.test(pwd)) score += 25;

        fill.style.width = score + '%';

        if (score <= 25) {
            fill.style.backgroundColor = '#ef4444';
            if (text) text.textContent = 'Weak password: Must have 9+ chars, uppercase, lowercase & number.';
        } else if (score <= 75) {
            fill.style.backgroundColor = '#f59e0b';
            if (text) text.textContent = 'Moderate: Include all character requirements for full security.';
        } else {
            fill.style.backgroundColor = '#10b981';
            if (text) text.textContent = 'Strong password! All security criteria fulfilled.';
        }
    }

    // Live Logo File Preview
    function previewLogoFile(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const src = e.target.result;
                const p = document.getElementById('resortLogoPreview');
                if (p) p.src = src;
                const ma = document.getElementById('mockupLogoAdmin');
                if (ma) ma.src = src;
                const mg = document.getElementById('mockupLogoGuest');
                if (mg) mg.src = src;
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    // Live Logo URL Preview
    function previewLogoUrl(url) {
        if (!url) return;
        const p = document.getElementById('resortLogoPreview');
        if (p) p.src = url;
        const ma = document.getElementById('mockupLogoAdmin');
        if (ma) ma.src = url;
        const mg = document.getElementById('mockupLogoGuest');
        if (mg) mg.src = url;
    }

    // Live Brand Text Preview
    function updateBrandPreview() {
        const title = document.getElementById('brand_title')?.value || 'PURPLE BAMBOO';
        const subtitle = document.getElementById('brand_subtitle')?.value || 'BEACH RESORT';

        const maTitle = document.getElementById('mockupTitleAdmin');
        if (maTitle) maTitle.textContent = title;

        const mgTitle = document.getElementById('mockupTitleGuest');
        if (mgTitle) mgTitle.textContent = title;

        const mgSub = document.getElementById('mockupSubtitleGuest');
        if (mgSub) mgSub.textContent = subtitle;
    }

    // Live GCash QR File Preview
    function previewGcashQrFile(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const src = e.target.result;
                const thumb = document.getElementById('settingsGcashQrThumb');
                if (thumb) thumb.src = src;
                const prev = document.getElementById('prevGcashQrImg');
                if (prev) prev.src = src;
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    // Reset QR Flag
    function resetGcashQr() {
        if (confirm('Are you sure you want to reset to the default resort QR code?')) {
            document.getElementById('remove_qr_flag').value = '1';
            const thumb = document.getElementById('settingsGcashQrThumb');
            if (thumb) thumb.src = 'assets/gcash_qr.jpg';
            const prev = document.getElementById('prevGcashQrImg');
            if (prev) prev.src = 'assets/gcash_qr.jpg';
            document.getElementById('gcashForm').submit();
        }
    }

    // Live GCash Card Preview Text Updater
    function updateGcashPreview() {
        const name = document.getElementById('gcash_name')?.value || 'PURPLE BAMBOO RESORT';
        const num = document.getElementById('gcash_number')?.value || '0956 697 8679';
        const title = document.getElementById('gcash_card_title')?.value || 'Official GCash Payment Channel';
        const sub = document.getElementById('gcash_card_subtitle')?.value || 'Scan with GCash or InstaPay for Instant Reservation Verification';
        const inst = document.getElementById('gcash_instructions')?.value || '';

        const pName = document.getElementById('prevGcashName');
        if (pName) pName.textContent = name;

        const pNum = document.getElementById('prevGcashNumber');
        if (pNum) pNum.textContent = num;

        const pTitle = document.getElementById('prevGcashCardTitle');
        if (pTitle) pTitle.textContent = title;

        const pSub = document.getElementById('prevGcashCardSubtitle');
        if (pSub) pSub.textContent = sub;

        const pInst = document.getElementById('prevGcashInstructions');
        if (pInst) pInst.textContent = inst;
    }

    // Staff Search Filter with Live Counter & Empty State
    function filterStaffTable() {
        const q = (document.getElementById('staffSearchInput')?.value || '').toLowerCase().trim();
        const rows = document.querySelectorAll('.staff-row');
        let visibleCount = 0;
        rows.forEach(r => {
            const data = r.getAttribute('data-search') || '';
            const match = !q || data.includes(q);
            r.style.display = match ? '' : 'none';
            if (match) visibleCount++;
        });
        const emptyRow = document.getElementById('staffSearchEmptyRow');
        if (emptyRow) {
            emptyRow.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
        }
        const countEl = document.getElementById('staffVisibleCount');
        if (countEl) {
            countEl.textContent = visibleCount;
        }
    }

    // Open View Staff Modal
    function openViewStaffModal(staff) {
        const first = staff.first_name || '';
        const last = staff.last_name || '';
        const initials = ((first[0] || 'S') + (last[0] || 'T')).toUpperCase();

        document.getElementById('view_staff_avatar').textContent = initials;
        document.getElementById('view_staff_fullname').textContent = staff.full_name || (first + ' ' + last);
        document.getElementById('view_staff_profile_id').textContent = staff.profile_id || 'PBR-STF';
        document.getElementById('view_staff_email').textContent = staff.email || '—';
        document.getElementById('view_staff_phone').textContent = staff.phone || '—';

        let createdStr = '—';
        if (staff.created_at) {
            try {
                const d = new Date(staff.created_at.replace(/-/g, '/'));
                createdStr = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            } catch(e) {
                createdStr = staff.created_at;
            }
        }
        document.getElementById('view_staff_created').textContent = createdStr;

        openModal('viewStaffModal');
    }

    // Open Edit Staff Modal
    function openEditStaffModal(staff) {
        document.getElementById('edit_staff_target_email').value = staff.email || '';
        document.getElementById('edit_staff_email_display').value = staff.email || '';
        document.getElementById('edit_staff_first_name').value = staff.first_name || '';
        document.getElementById('edit_staff_middle_name').value = staff.middle_name || '';
        document.getElementById('edit_staff_last_name').value = staff.last_name || '';
        document.getElementById('edit_staff_suffix').value = staff.suffix || '';
        document.getElementById('edit_staff_phone').value = staff.phone || '';

        openModal('editStaffModal');
    }

    // Open Confirm Delete Staff Modal
    function confirmDeleteStaff(email, name) {
        document.getElementById('delete_staff_target_email').value = email;
        document.getElementById('delete_staff_name_display').textContent = '"' + name + '" (' + email + ')';
        openModal('deleteStaffModal');
    }

    // On Load: If flash requested modal reopening, trigger it
    <?php if (!empty($flash['reopen']['modal'])): ?>
    document.addEventListener('DOMContentLoaded', function() {
        openModal(<?= json_encode($flash['reopen']['modal']) ?>);
    });
    <?php endif; ?>
    </script>

</body>
</html>
