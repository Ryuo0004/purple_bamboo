<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/auth.php';

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['consent_terms'])) {
        $error = 'You must accept the Privacy Policy and Terms & Conditions to complete registration.';
    } elseif (empty($_POST['password']) || empty($_POST['confirm_password'])) {
        $error = 'Please enter both password and confirm password.';
    } elseif ($_POST['password'] !== $_POST['confirm_password']) {
        $error = 'Passwords do not match. Please ensure both password fields are identical.';
    } else {
        // Profile picture removed from registration (guests can upload/manage their avatar in their guest dashboard)
        $profile_picture_path = '';
        $id_image_path = '';

        // Gather Registration Data
        $data = [
            'first_name' => trim($_POST['first_name'] ?? ''),
            'middle_name' => trim($_POST['middle_name'] ?? ''),
            'last_name' => trim($_POST['last_name'] ?? ''),
            'suffix' => trim($_POST['suffix'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'emergency_contact_name' => trim($_POST['emergency_contact_name'] ?? ''),
            'emergency_contact_relationship' => trim($_POST['emergency_contact_relationship'] ?? ''),
            'emergency_contact_phone' => trim($_POST['emergency_contact_phone'] ?? ''),
            'emergency_contact_alt_phone' => trim($_POST['emergency_contact_alt_phone'] ?? ''),
            'id_type' => trim($_POST['id_type'] ?? ''),
            'id_number' => trim($_POST['id_number'] ?? ''),
            'id_image' => $id_image_path,
            'profile_picture' => $profile_picture_path,
            'health_conditions' => $_POST['health_conditions'] ?? [],
            'health_info' => trim($_POST['health_info'] ?? '')
        ];

        $redirect = trim($_GET['redirect'] ?? ($_POST['redirect'] ?? ''));

        $result = Auth::register($data, 'guest');
        if ($result['success']) {
            if (!empty($redirect) && !str_contains($redirect, '://') && !in_array($redirect, ['user-dashboard.php', 'index.php'])) {
                header("Location: " . $redirect . (str_contains($redirect, '?') ? '&' : '?') . "registered=1");
                exit();
            }
            header("Location: user-dashboard.php?registered=1");
            exit();
        } else {
            $error = $result['error'] ?? 'Registration failed. Please try again.';
        }
    }
}
$redirect = trim($_GET['redirect'] ?? ($_POST['redirect'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Registration | Purple Bamboo Resort</title>
    <link rel="icon" type="image/jpeg" href="assets/logo.jpg">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
    /* ============================================================
       RESPONSIVE GUEST REGISTRATION SYSTEM
       ============================================================ */
    .reg-container {
        max-width: 860px;
        margin: 2.5rem auto 3.5rem auto;
        width: 100%;
        box-sizing: border-box;
    }
    .reg-card {
        padding: 2.5rem;
        border-radius: var(--radius-md, 16px);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
        background: #ffffff;
        border: 1px solid var(--border-color, #e2e8f0);
        box-sizing: border-box;
    }
    .reg-header-title {
        font-family: 'Outfit', sans-serif;
        color: var(--primary-purple, #7c3aed);
        margin-top: 0.2rem;
        font-size: clamp(1.5rem, 4vw, 2.2rem);
    }
    .reg-header-desc {
        color: var(--text-muted, #64748b);
        font-size: 0.95rem;
        line-height: 1.5;
    }
    .reg-section-title {
        font-family: 'Outfit', sans-serif;
        color: var(--primary-purple, #7c3aed);
        border-bottom: 2px solid var(--purple-light, #ede9fe);
        padding-bottom: 0.5rem;
        margin-bottom: 1.25rem;
        font-size: clamp(1.05rem, 2.5vw, 1.25rem);
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    /* Form Grids */
    .reg-grid-name {
        display: grid;
        grid-template-columns: 2fr 1.5fr 2fr 1fr;
        gap: 1rem;
    }
    .reg-grid-two-col {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-top: 0.5rem;
    }
    .reg-grid-passwords {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-top: 0.5rem;
    }
    .reg-grid-emergency-person {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    .reg-grid-emergency-phone {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }
    /* Section Cards */
    .reg-emergency-box {
        margin-bottom: 2rem;
        background: var(--purple-bg-soft, #f5f3ff);
        padding: 1.5rem;
        border-radius: var(--radius-sm, 10px);
        border: 1px dashed var(--border-color, #cbd5e1);
        box-sizing: border-box;
    }

    /* ============================================================
       REDESIGNED SECTION 3: GUEST HEALTH & MEDICAL DETAILS
       ============================================================ */
    .reg-health-box {
        margin-bottom: 2.25rem;
        background: linear-gradient(145deg, #f0fdf4 0%, #ffffff 50%, #f0fdf4 100%);
        border: 1.5px solid #86efac;
        padding: 1.85rem;
        border-radius: var(--radius-md, 16px);
        box-shadow: 0 10px 30px -8px rgba(16, 185, 129, 0.12);
        position: relative;
        overflow: hidden;
        box-sizing: border-box;
    }
    .reg-health-box::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #10b981, #059669, #34d399, #10b981);
    }
    .health-watermark-icon {
        position: absolute;
        top: -15px;
        right: -15px;
        font-size: 7.5rem;
        color: rgba(16, 185, 129, 0.05);
        pointer-events: none;
        user-select: none;
        z-index: 0;
    }
    .health-header-wrap {
        position: relative;
        z-index: 1;
        margin-bottom: 1.25rem;
    }
    .health-top-badges {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 0.6rem;
    }
    .health-safety-tag {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #dcfce7;
        color: #166534;
        font-size: 0.76rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 0.25rem 0.75rem;
        border-radius: 999px;
        border: 1px solid #bbf7d0;
    }
    .health-confidential-tag {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #ffffff;
        color: #047857;
        font-size: 0.76rem;
        font-weight: 600;
        padding: 0.25rem 0.75rem;
        border-radius: 999px;
        border: 1px solid #a7f3d0;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    }
    .health-title-main {
        font-family: 'Outfit', sans-serif;
        color: #14532d;
        font-size: clamp(1.2rem, 3vw, 1.45rem);
        font-weight: 700;
        margin: 0.35rem 0 0.4rem 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .health-title-main i {
        color: #10b981;
        filter: drop-shadow(0 2px 4px rgba(16, 185, 129, 0.25));
    }
    .health-desc-main {
        font-size: 0.9rem;
        color: #15803d;
        line-height: 1.5;
        margin: 0;
        max-width: 720px;
    }

    /* Toolbar: Medical Alert Counts & Selection Controls */
    .health-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 1rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid #dcfce7;
        position: relative;
        z-index: 1;
    }
    .health-toolbar-title {
        font-weight: 700;
        font-size: 0.92rem;
        color: #166534;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .health-toolbar-actions {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .health-count-badge {
        background: #ffffff;
        color: #065f46;
        border: 1.5px solid #a7f3d0;
        font-size: 0.78rem;
        font-weight: 700;
        padding: 0.25rem 0.65rem;
        border-radius: 999px;
        transition: all 0.2s ease;
    }
    .health-count-badge.has-selected {
        background: #10b981;
        color: #ffffff;
        border-color: #059669;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
    }
    .health-btn-action {
        background: #ffffff;
        color: #166534;
        border: 1px solid #86efac;
        padding: 0.3rem 0.75rem;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: all 0.2s ease;
    }
    .health-btn-action:hover {
        background: #dcfce7;
        color: #14532d;
        border-color: #059669;
        transform: translateY(-1px);
    }
    .health-btn-action.btn-clear {
        color: #475569;
        border-color: #cbd5e1;
    }
    .health-btn-action.btn-clear:hover {
        background: #f1f5f9;
        color: #0f172a;
        border-color: #94a3b8;
    }

    /* Interactive Health Condition Cards */
    .health-conditions-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0.85rem;
        margin-bottom: 1.5rem;
        position: relative;
        z-index: 1;
    }
    .health-card {
        position: relative;
        background: #ffffff;
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.85rem 0.8rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 10px;
        transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
        user-select: none;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
    }
    .health-card input[type="checkbox"] {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
        pointer-events: none;
    }
    .health-card:hover {
        border-color: #10b981;
        background: #fcfdfc;
        transform: translateY(-2px);
        box-shadow: 0 6px 14px rgba(16, 185, 129, 0.1);
    }
    .health-card.active,
    .health-card:has(input[type="checkbox"]:checked) {
        border-color: #059669;
        background: #ecfdf5;
        box-shadow: 0 4px 14px rgba(5, 150, 105, 0.16);
    }
    .health-card-icon {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.15rem;
        flex-shrink: 0;
        transition: transform 0.2s ease;
    }
    .health-card:hover .health-card-icon,
    .health-card.active .health-card-icon,
    .health-card:has(input[type="checkbox"]:checked) .health-card-icon {
        transform: scale(1.08);
    }
    .icon-asthma { background: #e0f2fe; color: #0284c7; }
    .icon-heart { background: #ffe4e6; color: #e11d48; }
    .icon-allergies { background: #fef3c7; color: #d97706; }
    .icon-hypertension { background: #fee2e2; color: #dc2626; }
    .icon-diabetes { background: #f3e8ff; color: #9333ea; }
    .icon-epilepsy { background: #ffedd5; color: #ea580c; }
    .icon-mobility { background: #dbeafe; color: #2563eb; }
    .icon-pregnant { background: #fce7f3; color: #db2777; }

    .health-card-content {
        flex: 1;
        min-width: 0;
    }
    .health-card-label {
        font-size: 0.85rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
        line-height: 1.25;
        transition: color 0.2s ease;
    }
    .health-card.active .health-card-label,
    .health-card:has(input[type="checkbox"]:checked) .health-card-label {
        color: #065f46;
    }
    .health-card-sub {
        font-size: 0.7rem;
        color: #64748b;
        display: block;
        margin-top: 2px;
        line-height: 1.2;
    }
    .health-card.active .health-card-sub,
    .health-card:has(input[type="checkbox"]:checked) .health-card-sub {
        color: #047857;
    }

    .health-card-check {
        width: 18px;
        height: 18px;
        border-radius: 5px;
        border: 1.5px solid #cbd5e1;
        background: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.65rem;
        color: transparent;
        transition: all 0.2s ease;
        flex-shrink: 0;
    }
    .health-card.active .health-card-check,
    .health-card:has(input[type="checkbox"]:checked) .health-card-check {
        background: #10b981;
        border-color: #059669;
        color: #ffffff;
        box-shadow: 0 2px 6px rgba(16, 185, 129, 0.4);
    }

    /* Redesigned Instructions Textarea Card */
    .health-instructions-card {
        background: #ffffff;
        border: 1px solid #bbf7d0;
        border-radius: 12px;
        padding: 1.25rem;
        position: relative;
        z-index: 1;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
    }
    .health-instructions-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 0.35rem;
    }
    .health-instructions-title {
        font-size: 0.92rem;
        font-weight: 700;
        color: #166534;
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 0;
    }
    .health-optional-badge {
        font-size: 0.72rem;
        font-weight: 600;
        background: #f1f5f9;
        color: #64748b;
        padding: 0.15rem 0.5rem;
        border-radius: 6px;
    }
    .health-instructions-subtitle {
        font-size: 0.84rem;
        color: #475569;
        margin: 0 0 0.75rem 0;
        line-height: 1.45;
    }

    /* Quick suggestion tag buttons */
    .health-quick-tags {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
        margin-bottom: 0.75rem;
    }
    .health-quick-tag-label {
        font-size: 0.76rem;
        font-weight: 700;
        color: #047857;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-right: 2px;
    }
    .health-quick-pill {
        background: #f0fdf4;
        color: #166534;
        border: 1px solid #bbf7d0;
        padding: 0.25rem 0.6rem;
        border-radius: 999px;
        font-size: 0.76rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: all 0.2s ease;
    }
    .health-quick-pill:hover {
        background: #10b981;
        color: #ffffff;
        border-color: #059669;
        transform: translateY(-1px);
        box-shadow: 0 2px 6px rgba(16, 185, 129, 0.25);
    }

    .health-textarea {
        width: 100%;
        border: 1.5px solid #cbd5e1;
        border-radius: 10px;
        padding: 0.75rem 0.85rem;
        font-size: 0.9rem;
        color: #1e293b;
        line-height: 1.5;
        resize: vertical;
        min-height: 85px;
        box-sizing: border-box;
        transition: all 0.2s ease;
        background: #ffffff;
    }
    .health-textarea:focus {
        outline: none;
        border-color: #10b981;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.18);
        background: #ffffff;
    }
    .health-textarea-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 0.5rem;
        font-size: 0.78rem;
        color: #64748b;
    }
    .health-privacy-footnote {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        color: #047857;
        font-weight: 500;
    }
    .reg-consent-box {
        margin-top: 1.5rem;
        margin-bottom: 1.5rem;
        background: #f8fafc;
        padding: 1rem 1.25rem;
        border-radius: var(--radius-sm, 10px);
        border: 1px solid #cbd5e1;
        box-sizing: border-box;
    }
    .reg-consent-label {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        cursor: pointer;
        color: var(--text-dark, #0f172a);
        font-size: 0.9rem;
        font-weight: 500;
        line-height: 1.45;
    }
    .reg-consent-checkbox {
        margin-top: 3px;
        accent-color: var(--primary-purple, #e0129c);
        width: 20px;
        height: 20px;
        flex-shrink: 0;
    }

    /* Inputs & Buttons Touch Friendliness */
    .form-control {
        width: 100%;
        box-sizing: border-box;
    }
    .reg-submit-btn {
        width: 100%;
        margin-top: 0.5rem;
        padding: 0.95rem;
        font-size: 1.1rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        border-radius: 12px;
    }

    /* Tablet Responsiveness */
    @media (max-width: 860px) {
        .reg-grid-name {
            grid-template-columns: 1fr 1fr;
        }
        .reg-col-surname {
            grid-column: 1 / 2;
        }
        .reg-col-suffix {
            grid-column: 2 / 3;
        }
        .health-conditions-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
    }

    /* Mobile Responsiveness */
    @media (max-width: 640px) {
        .reg-container {
            margin-top: 1.25rem !important;
            margin-bottom: 2rem !important;
            padding-left: 0.75rem !important;
            padding-right: 0.75rem !important;
        }
        .reg-card {
            padding: 1.25rem 1rem !important;
            border-radius: 14px !important;
        }
        .reg-header-desc {
            font-size: 0.88rem !important;
        }
        .reg-grid-name {
            grid-template-columns: 1fr 1fr !important;
            gap: 0.75rem !important;
        }
        .reg-grid-name > .form-group:nth-child(1),
        .reg-grid-name > .form-group:nth-child(2) {
            grid-column: 1 / -1;
        }
        .reg-col-surname {
            grid-column: 1 / 2 !important;
        }
        .reg-col-suffix {
            grid-column: 2 / 3 !important;
        }
        .reg-grid-two-col {
            grid-template-columns: 1fr !important;
            gap: 0.75rem !important;
        }
        .reg-grid-passwords {
            grid-template-columns: 1fr !important;
            gap: 0.75rem !important;
        }
        .reg-grid-emergency-person {
            grid-template-columns: 1fr !important;
            gap: 0.75rem !important;
            margin-bottom: 0.75rem !important;
        }
        .reg-grid-emergency-phone {
            grid-template-columns: 1fr !important;
            gap: 0.75rem !important;
        }

        .reg-emergency-box,
        .reg-health-box {
            padding: 1.15rem 0.9rem !important;
            margin-bottom: 1.5rem !important;
        }
        .health-conditions-grid {
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 0.6rem !important;
        }
        .health-card {
            padding: 0.7rem 0.65rem !important;
        }
        .health-card-icon {
            width: 32px !important;
            height: 32px !important;
            font-size: 0.95rem !important;
        }
        .health-card-label {
            font-size: 0.8rem !important;
        }
        .health-card-sub {
            font-size: 0.67rem !important;
        }
        .health-instructions-card {
            padding: 1rem 0.85rem !important;
        }
        .reg-consent-box {
            padding: 0.85rem !important;
        }
        .reg-consent-label {
            font-size: 0.82rem !important;
        }

        .password-requirements-card {
            padding: 0.75rem !important;
        }
        .password-requirements-title {
            font-size: 0.82rem !important;
        }
        .password-requirement-item {
            font-size: 0.78rem !important;
        }

        .reg-submit-btn {
            padding: 0.85rem !important;
            font-size: 1rem !important;
            border-radius: 10px !important;
        }

        /* Prevent auto-zooming on tap in mobile iOS */
        .form-control {
            font-size: 16px !important;
            min-height: 44px;
        }
    }

    /* Small Mobile (<= 380px) */
    @media (max-width: 380px) {
        .reg-container {
            padding-left: 0.4rem !important;
            padding-right: 0.4rem !important;
        }
        .reg-card {
            padding: 1rem 0.75rem !important;
        }
        .health-conditions-grid {
            grid-template-columns: 1fr !important;
        }
        .reg-grid-name {
            grid-template-columns: 1fr !important;
        }
        .reg-col-surname,
        .reg-col-suffix {
            grid-column: 1 / -1 !important;
        }
    }
    </style>
</head>
<body>

    <?php include 'nav.php'; ?>

    <div class="container reg-container">
        <div class="card reg-card">
            <div style="text-align: center; margin-bottom: 2rem;">
                <span class="badge badge-gold" style="margin-bottom: 0.5rem;">
                    <i class="fa-solid fa-user-shield"></i> Guest Membership Portal
                </span>
                <h1 class="reg-header-title">Guest Registration</h1>
                <p class="reg-header-desc">Register your official resort guest account for bamboo kubo bookings and activity access</p>
            </div>

            <?php if (!empty($error)): ?>
                <div style="background: #fee2e2; color: #b91c1c; padding: 1rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem; border: 1px solid #fca5a5;">
                    <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form action="register.php" method="POST" enctype="multipart/form-data">
                <?php if (!empty($redirect)): ?>
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                <?php endif; ?>

                <!-- ============================================================
                     SECTION 1: PERSONAL INFORMATION & SEPARATED NAME
                     ============================================================ -->
                <div class="reg-section">
                    <h3 class="reg-section-title">
                        <i class="fa-solid fa-id-card" style="color: var(--bamboo-gold);"></i> 1. Personal Details & Full Name Structure
                    </h3>

                    <!-- Name Fields Row -->
                    <div class="reg-grid-name">
                        <div class="form-group">
                            <label><i class="fa-solid fa-user"></i> First Name *</label>
                            <input type="text" name="first_name" id="reg_first_name" class="form-control name-only-input" 
                                   placeholder="Juan" required pattern="^[A-Za-z\s\-\.ñÑ']+$" 
                                   title="First name must only contain letters, spaces, and hyphens (no numbers allowed)">
                        </div>
                        <div class="form-group">
                            <label>Middle Name</label>
                            <input type="text" name="middle_name" id="reg_middle_name" class="form-control name-only-input" 
                                   placeholder="Santos" pattern="^[A-Za-z\s\-\.ñÑ']*$" 
                                   title="Middle name must only contain letters, spaces, and hyphens (no numbers allowed)">
                        </div>
                        <div class="form-group reg-col-surname">
                            <label><i class="fa-solid fa-user"></i> Surname / Last Name *</label>
                            <input type="text" name="last_name" id="reg_last_name" class="form-control name-only-input" 
                                   placeholder="Dela Cruz" required pattern="^[A-Za-z\s\-\.ñÑ']+$" 
                                   title="Last name must only contain letters, spaces, and hyphens (no numbers allowed)">
                        </div>
                        <div class="form-group reg-col-suffix">
                            <label>Suffix</label>
                            <select name="suffix" class="form-control">
                                <option value="">None</option>
                                <option value="Jr.">Jr.</option>
                                <option value="Sr.">Sr.</option>
                                <option value="III">III</option>
                                <option value="IV">IV</option>
                                <option value="V">V</option>
                            </select>
                        </div>
                    </div>

                    <!-- Email & Phone Row -->
                    <div class="reg-grid-two-col">
                        <div class="form-group">
                            <label><i class="fa-solid fa-envelope"></i> Email Address *</label>
                            <input type="email" name="email" class="form-control" placeholder="juan@gmail.com" pattern="^[a-zA-Z0-9._%+-]+@gmail\.com$" title="Only @gmail.com email addresses are allowed" required>
                            <small class="form-text text-muted" style="font-size: 0.78rem; color: #64748b;">Only valid @gmail.com addresses accepted.</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fa-solid fa-phone"></i> Mobile Phone Number *</label>
                            <input type="tel" name="phone" id="reg_phone" class="form-control mobile-09-input" 
                                   placeholder="09171234567" required maxlength="11" pattern="^09[0-9]{9}$" 
                                   inputmode="numeric" title="Mobile number must start with 09 and contain exactly 11 digits (e.g. 09171234567). Numbers only, no letters.">
                        </div>
                    </div>

                    <!-- Password & Confirm Password Row -->
                    <div class="reg-grid-passwords">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label><i class="fa-solid fa-lock"></i> Password *</label>
                            <div class="password-input-wrapper">
                                <input type="password" id="register_password" name="password" class="form-control" 
                                       placeholder="Minimum 9 characters" required minlength="9" 
                                       pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{9,}" 
                                       title="Must be at least 9 characters long, including 1 uppercase (Big) letter, lowercase letters, and numbers">
                                <button type="button" class="toggle-password-btn" 
                                        onclick="togglePasswordVisibility('register_password', this)" 
                                        title="Show/Hide Password" aria-label="Toggle password visibility">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label><i class="fa-solid fa-shield-check"></i> Confirm Password *</label>
                            <div class="password-input-wrapper">
                                <input type="password" id="register_confirm_password" name="confirm_password" 
                                       class="form-control" placeholder="Re-enter password to confirm" 
                                       required minlength="9" title="Must match the password entered above">
                                <button type="button" class="toggle-password-btn" 
                                        onclick="togglePasswordVisibility('register_confirm_password', this)" 
                                        title="Show/Hide Confirm Password" aria-label="Toggle confirm password visibility">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                            <div id="confirm_password_feedback" style="font-size: 0.8rem; font-weight: 700; margin-top: 4px; display: none;"></div>
                        </div>
                    </div>

                    <!-- Password Requirements Card -->
                    <div class="password-requirements-card" style="margin-top: 1rem;">
                        <div class="password-requirements-title">
                            <i class="fa-solid fa-shield-halved" style="color: var(--primary-purple);"></i> Password Requirements:
                        </div>
                        <ul class="password-requirements-list">
                            <li id="req-length" class="password-requirement-item invalid">
                                <i class="fa-solid fa-circle-xmark"></i> At least 9 characters (no limit)
                            </li>
                            <li id="req-uppercase" class="password-requirement-item invalid">
                                <i class="fa-solid fa-circle-xmark"></i> At least 1 Big letter (A-Z)
                            </li>
                            <li id="req-lowercase" class="password-requirement-item invalid">
                                <i class="fa-solid fa-circle-xmark"></i> At least 1 lowercase letter (a-z)
                            </li>
                            <li id="req-number" class="password-requirement-item invalid">
                                <i class="fa-solid fa-circle-xmark"></i> At least 1 number (0-9)
                            </li>
                            <li id="req-match" class="password-requirement-item invalid">
                                <i class="fa-solid fa-circle-xmark"></i> Passwords match
                            </li>
                        </ul>

                        <div class="password-strength-container">
                            <div class="password-strength-bar-track">
                                <div id="password-strength-bar" class="password-strength-bar-fill"></div>
                            </div>
                            <div id="password-strength-text" class="password-strength-feedback">
                                <span>Strength: Enter password</span>
                                <span><i class="fa-solid fa-lock"></i> Required: Strong</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                     SECTION 2: EMERGENCY CONTACT INFORMATION
                     ============================================================ -->
                <div class="reg-emergency-box">
                    <h3 class="reg-section-title" style="border-bottom: none; padding-bottom: 0;">
                        <i class="fa-solid fa-kit-medical" style="color: #ef4444;"></i> 2. Emergency Contact Information
                    </h3>
                    <p style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 1.25rem;">
                        Persons to notify immediately in case of resort emergency or medical dispatch needs.
                    </p>

                    <div class="reg-grid-emergency-person">
                        <div class="form-group">
                            <label><i class="fa-solid fa-user-gear"></i> Primary Emergency Contact Person *</label>
                            <input type="text" name="emergency_contact_name" id="reg_emergency_contact_name" 
                                   class="form-control name-only-input" placeholder="e.g. Maria Dela Cruz" 
                                   required pattern="^[A-Za-z\s\-\.ñÑ']+$" 
                                   title="Emergency contact name must only contain letters, spaces, and hyphens (no numbers allowed)">
                        </div>
                        <div class="form-group">
                            <label><i class="fa-solid fa-people-arrows"></i> Relationship *</label>
                            <select name="emergency_contact_relationship" class="form-control" required>
                                <option value="">-- Select --</option>
                                <option value="Parent">Parent</option>
                                <option value="Partner">Partner</option>
                                <option value="Sibling">Sibling</option>
                                <option value="Child">Child</option>
                                <option value="Relative">Relative</option>
                                <option value="Friend">Friend</option>
                                <option value="Guardian">Guardian</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="reg-grid-emergency-phone">
                        <div class="form-group">
                            <label><i class="fa-solid fa-phone-volume"></i> Primary Emergency Phone *</label>
                            <input type="tel" name="emergency_contact_phone" id="reg_emergency_phone" 
                                   class="form-control mobile-09-input" placeholder="09189876543" 
                                   required maxlength="11" pattern="^09[0-9]{9}$" inputmode="numeric" 
                                   title="Mobile number must start with 09 and contain exactly 11 digits (e.g. 09189876543). Numbers only, no letters.">
                        </div>
                        <div class="form-group">
                            <label>
                                <i class="fa-solid fa-phone"></i> Secondary / Alt Emergency Phone 
                                <span style="font-size: 0.8rem; font-weight: normal; color: var(--text-muted);"></span>
                            </label>
                            <input type="tel" name="emergency_contact_alt_phone" id="reg_emergency_alt_phone" 
                                   class="form-control mobile-09-input" placeholder="09223334444" 
                                   maxlength="11" pattern="^(|09[0-9]{9})$" inputmode="numeric" 
                                   title="Mobile number must start with 09 and contain exactly 11 digits (e.g. 09223334444). Numbers only, no letters.">
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                     SECTION 3: GUEST HEALTH & MEDICAL INFORMATION (REDESIGNED)
                     ============================================================ -->
                <div class="reg-health-box">
                    <div class="health-watermark-icon">
                        <i class="fa-solid fa-heart-pulse"></i>
                    </div>

                    <div class="health-header-wrap">
                        <div class="health-top-badges">
                            <span class="health-safety-tag">
                                <i class="fa-solid fa-shield-heart"></i> Safety &amp; First Aid Protocol
                            </span>
                            <span class="health-confidential-tag">
                                <i class="fa-solid fa-lock"></i> Confidential Under RA 10173
                            </span>
                        </div>
                        <h3 class="health-title-main">
                            <i class="fa-solid fa-heart-pulse"></i> 3. Guest Health Information &amp; Medical Details
                        </h3>
                        <p class="health-desc-main">
                            Select applicable conditions so resort medical responders and certified on-duty lifeguards can assist promptly and appropriately in case of emergency.
                        </p>
                    </div>

                    <!-- Interactive Conditions Selection Toolbar -->
                    <div class="health-toolbar">
                        <div class="health-toolbar-title">
                            <i class="fa-solid fa-notes-medical" style="color: #10b981;"></i> Select Medical Conditions / Health Alerts:
                        </div>
                        <div class="health-toolbar-actions">
                            <span id="health_count_badge" class="health-count-badge">0 selected</span>
                            <button type="button" class="health-btn-action" onclick="toggleAllHealthConditions(true)">
                                <i class="fa-solid fa-check-double"></i> Select All
                            </button>
                            <button type="button" class="health-btn-action btn-clear" onclick="toggleAllHealthConditions(false)">
                                <i class="fa-solid fa-rotate-left"></i> Clear All
                            </button>
                        </div>
                    </div>

                    <!-- Modern Interactive Health Condition Cards Grid -->
                    <div class="health-conditions-grid">
                        <!-- Asthma -->
                        <label class="health-card" for="cond_asthma">
                            <input type="checkbox" id="cond_asthma" name="health_conditions[]" value="Asthma" onchange="syncHealthCardState(this)">
                            <div class="health-card-icon icon-asthma">
                                <i class="fa-solid fa-lungs"></i>
                            </div>
                            <div class="health-card-content">
                                <span class="health-card-label">Asthma</span>
                                <span class="health-card-sub">Respiratory care</span>
                            </div>
                            <div class="health-card-check">
                                <i class="fa-solid fa-check"></i>
                            </div>
                        </label>

                        <!-- Heart Condition -->
                        <label class="health-card" for="cond_heart">
                            <input type="checkbox" id="cond_heart" name="health_conditions[]" value="Heart Attack / Cardiovascular Condition" onchange="syncHealthCardState(this)">
                            <div class="health-card-icon icon-heart">
                                <i class="fa-solid fa-heart-pulse"></i>
                            </div>
                            <div class="health-card-content">
                                <span class="health-card-label">Heart Condition</span>
                                <span class="health-card-sub">Cardiac health</span>
                            </div>
                            <div class="health-card-check">
                                <i class="fa-solid fa-check"></i>
                            </div>
                        </label>

                        <!-- Allergies -->
                        <label class="health-card" for="cond_allergies">
                            <input type="checkbox" id="cond_allergies" name="health_conditions[]" value="Food / Drug Allergies" onchange="syncHealthCardState(this)">
                            <div class="health-card-icon icon-allergies">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                            </div>
                            <div class="health-card-content">
                                <span class="health-card-label">Allergies</span>
                                <span class="health-card-sub">Food / drug alerts</span>
                            </div>
                            <div class="health-card-check">
                                <i class="fa-solid fa-check"></i>
                            </div>
                        </label>

                        <!-- Hypertension -->
                        <label class="health-card" for="cond_hypertension">
                            <input type="checkbox" id="cond_hypertension" name="health_conditions[]" value="Hypertension" onchange="syncHealthCardState(this)">
                            <div class="health-card-icon icon-hypertension">
                                <i class="fa-solid fa-droplet"></i>
                            </div>
                            <div class="health-card-content">
                                <span class="health-card-label">Hypertension</span>
                                <span class="health-card-sub">High blood pressure</span>
                            </div>
                            <div class="health-card-check">
                                <i class="fa-solid fa-check"></i>
                            </div>
                        </label>

                        <!-- Diabetes -->
                        <label class="health-card" for="cond_diabetes">
                            <input type="checkbox" id="cond_diabetes" name="health_conditions[]" value="Diabetes" onchange="syncHealthCardState(this)">
                            <div class="health-card-icon icon-diabetes">
                                <i class="fa-solid fa-stethoscope"></i>
                            </div>
                            <div class="health-card-content">
                                <span class="health-card-label">Diabetes</span>
                                <span class="health-card-sub">Blood sugar alert</span>
                            </div>
                            <div class="health-card-check">
                                <i class="fa-solid fa-check"></i>
                            </div>
                        </label>

                        <!-- Epilepsy / Seizures -->
                        <label class="health-card" for="cond_epilepsy">
                            <input type="checkbox" id="cond_epilepsy" name="health_conditions[]" value="Epilepsy / Seizures" onchange="syncHealthCardState(this)">
                            <div class="health-card-icon icon-epilepsy">
                                <i class="fa-solid fa-bolt"></i>
                            </div>
                            <div class="health-card-content">
                                <span class="health-card-label">Epilepsy / Seizures</span>
                                <span class="health-card-sub">Neurological care</span>
                            </div>
                            <div class="health-card-check">
                                <i class="fa-solid fa-check"></i>
                            </div>
                        </label>

                        <!-- Mobility Assistance -->
                        <label class="health-card" for="cond_mobility">
                            <input type="checkbox" id="cond_mobility" name="health_conditions[]" value="Mobility / Physical Assistance" onchange="syncHealthCardState(this)">
                            <div class="health-card-icon icon-mobility">
                                <i class="fa-solid fa-wheelchair"></i>
                            </div>
                            <div class="health-card-content">
                                <span class="health-card-label">Mobility Assistance</span>
                                <span class="health-card-sub">Physical support</span>
                            </div>
                            <div class="health-card-check">
                                <i class="fa-solid fa-check"></i>
                            </div>
                        </label>

                        <!-- Pregnancy -->
                        <label class="health-card" for="cond_pregnant">
                            <input type="checkbox" id="cond_pregnant" name="health_conditions[]" value="Pregnancy" onchange="syncHealthCardState(this)">
                            <div class="health-card-icon icon-pregnant">
                                <i class="fa-solid fa-person-pregnant"></i>
                            </div>
                            <div class="health-card-content">
                                <span class="health-card-label">Pregnant</span>
                                <span class="health-card-sub">Maternity care</span>
                            </div>
                            <div class="health-card-check">
                                <i class="fa-solid fa-check"></i>
                            </div>
                        </label>
                    </div>

                    <!-- Full-Width Specific Health & Emergency Instructions Card -->
                    <div class="health-instructions-card">
                        <div class="health-instructions-header">
                            <label class="health-instructions-title" for="reg_health_info">
                                <i class="fa-solid fa-comment-medical" style="color: #10b981;"></i> Specific Health &amp; Emergency Instructions
                            </label>
                            <span class="health-optional-badge">Optional</span>
                        </div>
                        <p class="health-instructions-subtitle">
                            Provide critical medical notes regarding specific allergens, carried rescue medications (e.g., EpiPen, inhaler), or special assistance requested for staff awareness.
                        </p>

                        <!-- Quick suggestion pills -->
                        <div class="health-quick-tags">
                            <span class="health-quick-tag-label"><i class="fa-solid fa-wand-magic-sparkles"></i> Quick-add details:</span>
                            <button type="button" class="health-quick-pill" onclick="appendHealthInstruction('Severe seafood/shellfish allergy')">
                                <i class="fa-solid fa-plus"></i> Seafood Allergy
                            </button>
                            <button type="button" class="health-quick-pill" onclick="appendHealthInstruction('Carries prescribed emergency EpiPen')">
                                <i class="fa-solid fa-plus"></i> Carrying EpiPen
                            </button>
                            <button type="button" class="health-quick-pill" onclick="appendHealthInstruction('Carries rescue asthma inhaler')">
                                <i class="fa-solid fa-plus"></i> Carrying Inhaler
                            </button>
                            <button type="button" class="health-quick-pill" onclick="appendHealthInstruction('Severe peanut/tree-nut allergy')">
                                <i class="fa-solid fa-plus"></i> Peanut Allergy
                            </button>
                            <button type="button" class="health-quick-pill" onclick="appendHealthInstruction('Requires step-free/wheelchair accessible path')">
                                <i class="fa-solid fa-plus"></i> Step-free Access
                            </button>
                        </div>

                        <textarea name="health_info" id="reg_health_info" class="health-textarea" rows="3" 
                                  placeholder="Provide additional details regarding allergies (e.g. seafood, peanuts, penicillin), medications carried (e.g. EpiPen, inhaler), or special instructions..."></textarea>

                        <div class="health-textarea-footer">
                            <span class="health-privacy-footnote">
                                <i class="fa-solid fa-shield-halved"></i> Strictly encrypted and accessible only by certified resort first responders.
                            </span>
                            <span id="health_char_count" style="font-size: 0.76rem; color: #94a3b8;">0 characters</span>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                     MANDATORY DATA PRIVACY & TERMS CONSENT
                     ============================================================ -->
                <div class="form-group reg-consent-box">
                    <label for="consent_terms" class="reg-consent-label">
                        <input type="checkbox" id="consent_terms" name="consent_terms" required value="1" 
                               class="reg-consent-checkbox">
                        <span>
                            I agree to the <a href="terms.php" target="_blank" style="color: var(--primary-purple); font-weight: 700; text-decoration: underline;">Terms & Conditions</a> 
                            and <a href="privacy-policy.php" target="_blank" style="color: var(--primary-purple); font-weight: 700; text-decoration: underline;">Privacy Policy</a>, 
                            and I consent to the collection & processing of my personal, contact, and health details under Republic Act No. 10173 (Data Privacy Act of 2012). *
                        </span>
                    </label>
                </div>

                <button type="submit" class="btn btn-gold reg-submit-btn">
                    <i class="fa-solid fa-user-plus"></i> Complete Guest Registration
                </button>
            </form>

            <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color); text-align: center;">
                <p style="font-size: 0.9rem; color: var(--text-muted);">Already registered?</p>
                <a href="login.php" class="btn btn-outline btn-sm" style="margin-top: 0.5rem;">Sign In To Your Account</a>
            </div>
        </div>
    </div>


    <?php include 'footer.php'; ?>

    <script>
    // ============================================================
    // PASSWORD VISIBILITY TOGGLE
    // ============================================================
    function togglePasswordVisibility(inputId, btnElement) {
        const input = document.getElementById(inputId);
        const icon = btnElement ? btnElement.querySelector('i') : null;
        if (!input) return;
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

    // ============================================================
    // HEALTH SECTION INTERACTIVE LOGIC
    // ============================================================
    function syncHealthCardState(input) {
        const card = input.closest('.health-card');
        if (card) {
            if (input.checked) {
                card.classList.add('active');
            } else {
                card.classList.remove('active');
            }
        }
        updateHealthCountBadge();
    }

    function updateHealthCountBadge() {
        const checked = document.querySelectorAll('input[name="health_conditions[]"]:checked');
        const badge = document.getElementById('health_count_badge');
        if (!badge) return;
        const count = checked.length;
        if (count === 0) {
            badge.textContent = '0 selected';
            badge.classList.remove('has-selected');
        } else if (count === 1) {
            badge.textContent = '1 condition selected';
            badge.classList.add('has-selected');
        } else {
            badge.textContent = count + ' conditions selected';
            badge.classList.add('has-selected');
        }
    }

    function toggleAllHealthConditions(selectAll) {
        document.querySelectorAll('input[name="health_conditions[]"]').forEach(cb => {
            cb.checked = selectAll;
            const card = cb.closest('.health-card');
            if (card) {
                if (selectAll) card.classList.add('active');
                else card.classList.remove('active');
            }
        });
        updateHealthCountBadge();
    }

    function appendHealthInstruction(text) {
        const textarea = document.getElementById('reg_health_info');
        if (!textarea) return;
        let cur = textarea.value.trim();
        if (cur.length === 0) {
            textarea.value = text;
        } else if (!cur.includes(text)) {
            textarea.value = cur + '; ' + text;
        }
        textarea.focus();
        updateHealthCharCount();
    }

    function updateHealthCharCount() {
        const textarea = document.getElementById('reg_health_info');
        const counter = document.getElementById('health_char_count');
        if (textarea && counter) {
            counter.textContent = textarea.value.length + ' characters';
        }
    }

    // ============================================================
    // PAYMENT METHOD SELECTION (placeholder)
    // ============================================================
    function selectPaymentMethod(type, element) {
        document.querySelectorAll('.payment-method-card').forEach(el => el.classList.remove('selected'));
        element.classList.add('selected');
        const radio = element.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;
    }

    // ============================================================
    // DOM READY - FORM VALIDATION & UX ENHANCEMENTS
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        // --- Initialize Health Condition Cards & Instructions ---
        document.querySelectorAll('input[name="health_conditions[]"]').forEach(cb => {
            if (cb.checked) {
                const card = cb.closest('.health-card');
                if (card) card.classList.add('active');
            }
        });
        updateHealthCountBadge();

        const healthTextarea = document.getElementById('reg_health_info');
        if (healthTextarea) {
            healthTextarea.addEventListener('input', updateHealthCharCount);
            updateHealthCharCount();
        }

        // --- Password Elements ---
        const pwInput = document.getElementById('register_password');
        const confirmPwInput = document.getElementById('register_confirm_password');
        const confirmFeedback = document.getElementById('confirm_password_feedback');
        const reqLength = document.getElementById('req-length');
        const reqUppercase = document.getElementById('req-uppercase');
        const reqLowercase = document.getElementById('req-lowercase');
        const reqNumber = document.getElementById('req-number');
        const reqMatch = document.getElementById('req-match');
        const strengthBar = document.getElementById('password-strength-bar');
        const strengthText = document.getElementById('password-strength-text');

        // --- Password Match Checker ---
        function checkPasswordMatch() {
            if (!confirmPwInput) return;
            const pVal = pwInput ? pwInput.value : '';
            const cVal = confirmPwInput.value;

            if (cVal.length === 0) {
                if (reqMatch) {
                    reqMatch.className = 'password-requirement-item invalid';
                    reqMatch.querySelector('i').className = 'fa-solid fa-circle-xmark';
                }
                if (confirmFeedback) confirmFeedback.style.display = 'none';
                confirmPwInput.setCustomValidity('');
                return;
            }

            if (pVal === cVal && pVal.length >= 9) {
                if (reqMatch) {
                    reqMatch.className = 'password-requirement-item valid';
                    reqMatch.querySelector('i').className = 'fa-solid fa-circle-check';
                }
                if (confirmFeedback) {
                    confirmFeedback.style.display = 'block';
                    confirmFeedback.style.color = '#16a34a';
                    confirmFeedback.innerHTML = '<i class="fa-solid fa-circle-check"></i> Passwords match!';
                }
                confirmPwInput.setCustomValidity('');
            } else {
                if (reqMatch) {
                    reqMatch.className = 'password-requirement-item invalid';
                    reqMatch.querySelector('i').className = 'fa-solid fa-circle-xmark';
                }
                if (confirmFeedback) {
                    confirmFeedback.style.display = 'block';
                    confirmFeedback.style.color = '#dc2626';
                    confirmFeedback.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Passwords do not match';
                }
                confirmPwInput.setCustomValidity('Passwords do not match');
            }
        }

        if (confirmPwInput) {
            confirmPwInput.addEventListener('input', checkPasswordMatch);
        }

        // --- Password Strength & Requirements ---
        if (pwInput) {
            pwInput.addEventListener('input', function() {
                const val = pwInput.value;
                let passedCount = 0;

                // Length requirement (minimum 9 chars, no limit)
                if (val.length >= 9) {
                    reqLength.className = 'password-requirement-item valid';
                    reqLength.querySelector('i').className = 'fa-solid fa-circle-check';
                    passedCount++;
                } else {
                    reqLength.className = 'password-requirement-item invalid';
                    reqLength.querySelector('i').className = 'fa-solid fa-circle-xmark';
                }

                // Uppercase / Big letter requirement
                if (/[A-Z]/.test(val)) {
                    reqUppercase.className = 'password-requirement-item valid';
                    reqUppercase.querySelector('i').className = 'fa-solid fa-circle-check';
                    passedCount++;
                } else {
                    reqUppercase.className = 'password-requirement-item invalid';
                    reqUppercase.querySelector('i').className = 'fa-solid fa-circle-xmark';
                }

                // Lowercase letter requirement
                if (/[a-z]/.test(val)) {
                    reqLowercase.className = 'password-requirement-item valid';
                    reqLowercase.querySelector('i').className = 'fa-solid fa-circle-check';
                    passedCount++;
                } else {
                    reqLowercase.className = 'password-requirement-item invalid';
                    reqLowercase.querySelector('i').className = 'fa-solid fa-circle-xmark';
                }

                // Number requirement
                if (/[0-9]/.test(val)) {
                    reqNumber.className = 'password-requirement-item valid';
                    reqNumber.querySelector('i').className = 'fa-solid fa-circle-check';
                    passedCount++;
                } else {
                    reqNumber.className = 'password-requirement-item invalid';
                    reqNumber.querySelector('i').className = 'fa-solid fa-circle-xmark';
                }

                // Update Strength Meter
                if (val.length === 0) {
                    strengthBar.className = 'password-strength-bar-fill';
                    strengthText.className = 'password-strength-feedback';
                    strengthText.innerHTML = '<span>Strength: Enter password</span><span><i class="fa-solid fa-lock"></i> Required: Strong</span>';
                } else if (passedCount === 1) {
                    strengthBar.className = 'password-strength-bar-fill weak';
                    strengthText.className = 'password-strength-feedback';
                    strengthText.innerHTML = '<span style="color:#ef4444;">Strength: Weak</span><span>1 of 4 rules met</span>';
                } else if (passedCount === 2) {
                    strengthBar.className = 'password-strength-bar-fill fair';
                    strengthText.className = 'password-strength-feedback';
                    strengthText.innerHTML = '<span style="color:#f97316;">Strength: Fair</span><span>2 of 4 rules met</span>';
                } else if (passedCount === 3) {
                    strengthBar.className = 'password-strength-bar-fill good';
                    strengthText.className = 'password-strength-feedback';
                    strengthText.innerHTML = '<span style="color:#eab308;">Strength: Good</span><span>3 of 4 rules met</span>';
                } else if (passedCount === 4) {
                    strengthBar.className = 'password-strength-bar-fill strong';
                    strengthText.className = 'password-strength-feedback strong';
                    strengthText.innerHTML = '<span style="color:#16a34a;"><i class="fa-solid fa-circle-check"></i> Strong Password!</span><span>All rules met</span>';
                }

                if (confirmPwInput && confirmPwInput.value.length > 0) {
                    checkPasswordMatch();
                }
            });
        }

        // --- Form Submission Validation ---
        const regForm = document.querySelector('form');
        if (regForm) {
            regForm.addEventListener('submit', function(e) {
                if (pwInput && confirmPwInput) {
                    if (pwInput.value.length < 9) {
                        e.preventDefault();
                        alert('Password must be at least 9 characters long.');
                        pwInput.focus();
                        return false;
                    }
                    if (pwInput.value !== confirmPwInput.value) {
                        e.preventDefault();
                        alert('Passwords do not match. Please ensure both password fields are identical.');
                        confirmPwInput.focus();
                        return false;
                    }
                }
            });
        }

        // ---------- Personal Name Validation: Letters Only (No Numbers Allowed) ----------
        document.querySelectorAll('.name-only-input').forEach(function(input) {
            function sanitizeNameInput(el) {
                // Instantly remove any numeric digits (0-9) so numbers cannot be inserted
                const filtered = el.value.replace(/[0-9]/g, '');
                if (el.value !== filtered) {
                    el.value = filtered;
                }
            }
            input.addEventListener('input', function() {
                sanitizeNameInput(this);
            });
            input.addEventListener('paste', function() {
                setTimeout(() => sanitizeNameInput(this), 10);
            });
        });

        // ---------- Mobile Phone Validation: Starts with 09, Numbers Only, Max 11 Digits ----------
        document.querySelectorAll('.mobile-09-input').forEach(function(input) {
            function sanitizePhoneInput(el) {
                // Strip out all non-digit characters (no letters, no symbols)
                let val = el.value.replace(/\D/g, '');

                // Ensure it begins with 09
                if (val.length === 1 && val !== '0') {
                    val = '09' + val;
                } else if (val.length === 2 && val !== '09') {
                    val = '09';
                } else if (val.length > 2 && !val.startsWith('09')) {
                    if (val.startsWith('639')) {
                        val = '09' + val.substring(3);
                    } else if (val.startsWith('9')) {
                        val = '09' + val.substring(1);
                    } else {
                        val = '09' + val.replace(/^0+/, '');
                    }
                }

                // Cap at exactly 11 digits
                if (val.length > 11) {
                    val = val.substring(0, 11);
                }

                if (el.value !== val) {
                    el.value = val;
                }
            }

            input.addEventListener('input', function() {
                sanitizePhoneInput(this);
            });
            input.addEventListener('paste', function() {
                setTimeout(() => sanitizePhoneInput(this), 10);
            });
        });
    });
    </script>

</body>
</html>