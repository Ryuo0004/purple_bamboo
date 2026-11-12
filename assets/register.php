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
    } else {
    // Profile picture removed from registration
    $profile_picture_path = '';

    // 2. Process Valid ID Document Upload
    $id_image_path = '';
    if (!empty($_FILES['id_image']['name']) && $_FILES['id_image']['error'] === UPLOAD_ERR_OK) {
        $target_dir = __DIR__ . '/uploads/ids/';
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        $file_ext = strtolower(pathinfo($_FILES['id_image']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        if (in_array($file_ext, $allowed_exts)) {
            $new_filename = 'id_' . uniqid() . '.' . $file_ext;
            if (move_uploaded_file($_FILES['id_image']['tmp_name'], $target_dir . $new_filename)) {
                $id_image_path = 'uploads/ids/' . $new_filename;
            }
        }
    }

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
        if (!empty($redirect) && !str_contains($redirect, '://')) {
            header("Location: " . $redirect);
            exit();
        }
        header("Location: index.php?registered=1");
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
</head>
<body>

    <?php include 'nav.php'; ?>

    <div class="container" style="max-width: 860px; margin-top: 2.5rem; margin-bottom: 3rem;">
        <div class="card" style="padding: 2.5rem;">
            <div style="text-align: center; margin-bottom: 2rem;">
                <span class="badge badge-gold" style="margin-bottom: 0.5rem;"><i class="fa-solid fa-user-shield"></i> Guest Membership Portal</span>
                <h1 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-top: 0.2rem;">Guest Registration</h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">Register your official resort guest account for bamboo kubo bookings and activity access</p>
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
                
                <!-- SECTION 1: PERSONAL INFORMATION & SEPARATED NAME -->
                <div style="margin-bottom: 2rem;">
                    <h3 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); border-bottom: 2px solid var(--purple-light); padding-bottom: 0.5rem; margin-bottom: 1.25rem;">
                        <i class="fa-solid fa-id-card" style="color: var(--bamboo-gold);"></i> 1. Personal Details & Full Name Structure
                    </h3>

                    <div style="display: grid; grid-template-columns: 2fr 1.5fr 2fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label><i class="fa-solid fa-user"></i> First Name *</label>
                            <input type="text" name="first_name" id="reg_first_name" class="form-control name-only-input" placeholder="Juan" required pattern="^[A-Za-z\s\-\.ñÑ']+$" title="First name must only contain letters, spaces, and hyphens (no numbers allowed)">
                            <small class="field-hint" style="color: #64748b; font-size: 0.76rem; display: block; margin-top: 3px;"><i class="fa-solid fa-circle-info"></i> Letters only (no numbers allowed)</small>
                        </div>
                        <div class="form-group">
                            <label>Middle Name</label>
                            <input type="text" name="middle_name" id="reg_middle_name" class="form-control name-only-input" placeholder="Santos" pattern="^[A-Za-z\s\-\.ñÑ']*$" title="Middle name must only contain letters, spaces, and hyphens (no numbers allowed)">
                            <small class="field-hint" style="color: #64748b; font-size: 0.76rem; display: block; margin-top: 3px;"><i class="fa-solid fa-circle-info"></i> Letters only (no numbers allowed)</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fa-solid fa-user"></i> Surname / Last Name *</label>
                            <input type="text" name="last_name" id="reg_last_name" class="form-control name-only-input" placeholder="Dela Cruz" required pattern="^[A-Za-z\s\-\.ñÑ']+$" title="Last name must only contain letters, spaces, and hyphens (no numbers allowed)">
                            <small class="field-hint" style="color: #64748b; font-size: 0.76rem; display: block; margin-top: 3px;"><i class="fa-solid fa-circle-info"></i> Letters only (no numbers allowed)</small>
                        </div>
                        <div class="form-group">
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

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 0.5rem;">
                        <div class="form-group">
                            <label><i class="fa-solid fa-envelope"></i> Email Address *</label>
                            <input type="email" name="email" class="form-control" placeholder="juan@example.com" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fa-solid fa-phone"></i> Mobile Phone Number *</label>
                            <input type="tel" name="phone" id="reg_phone" class="form-control mobile-09-input" placeholder="09171234567" required maxlength="11" pattern="^09[0-9]{9}$" inputmode="numeric" title="Mobile number must start with 09 and contain exactly 11 digits (e.g. 09171234567). Numbers only, no letters.">

                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fa-solid fa-lock"></i> Password *</label>
                        <div class="password-input-wrapper">
                            <input type="password" id="register_password" name="password" class="form-control" placeholder="Create strong 8-character password" required maxlength="8" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,8}" title="Must contain exactly 8 characters, including 1 uppercase (Big) letter, lowercase letters, and numbers">
                            <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility('register_password', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                        
                        <div class="password-requirements-card">
                            <div class="password-requirements-title">
                                <i class="fa-solid fa-shield-halved" style="color: var(--primary-purple);"></i> Password Requirements:
                            </div>
                            <ul class="password-requirements-list">
                                <li id="req-length" class="password-requirement-item invalid">
                                    <i class="fa-solid fa-circle-xmark"></i> Exactly 8 characters
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
                </div>

                <!-- SECTION 2: EMERGENCY CONTACT INFORMATION -->
                <div style="margin-bottom: 2rem; background: var(--purple-bg-soft); padding: 1.5rem; border-radius: var(--radius-sm); border: 1px dashed var(--border-color);">
                    <h3 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-bottom: 0.5rem;">
                        <i class="fa-solid fa-kit-medical" style="color: #ef4444;"></i> 2. Emergency Contact Information
                    </h3>
                    <p style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 1.25rem;">Persons to notify immediately in case of resort emergency or medical dispatch needs.</p>

                    <div class="emergency-contact-fields">
                        <div class="form-group">
                            <label><i class="fa-solid fa-user-gear"></i> Primary Emergency Contact Person *</label>
                            <input type="text" name="emergency_contact_name" id="reg_emergency_contact_name" class="form-control name-only-input" placeholder="e.g. Maria Dela Cruz" required pattern="^[A-Za-z\s\-\.ñÑ']+$" title="Emergency contact name must only contain letters, spaces, and hyphens (no numbers allowed)">
                            <small class="field-hint" style="color: #64748b; font-size: 0.76rem; display: block; margin-top: 3px;"><i class="fa-solid fa-circle-info"></i> Letters only (no numbers allowed)</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fa-solid fa-people-arrows"></i> Relationship *</label>
                            <select name="emergency_contact_relationship" class="form-control" required>
                                <option value="">-- Select --</option>
                                <option value="Parent">Parent</option>
                                <option value="Spouse">Spouse</option>
                                <option value="Sibling">Sibling</option>
                                <option value="Child">Child</option>
                                <option value="Relative">Relative</option>
                                <option value="Friend">Friend</option>
                                <option value="Guardian">Guardian</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="emergency-contact-fields">
                        <div class="form-group">
                            <label><i class="fa-solid fa-phone-volume"></i> Primary Emergency Phone *</label>
                            <input type="tel" name="emergency_contact_phone" id="reg_emergency_phone" class="form-control mobile-09-input" placeholder="09189876543" required maxlength="11" pattern="^09[0-9]{9}$" inputmode="numeric" title="Mobile number must start with 09 and contain exactly 11 digits (e.g. 09189876543). Numbers only, no letters.">
                            
                        </div>
                        <div class="form-group">
                            <label><i class="fa-solid fa-phone"></i> Secondary / Alt Emergency Phone <span style="font-size: 0.8rem; font-weight: normal; color: var(--text-muted);">(Optional)</span></label>
                            <input type="tel" name="emergency_contact_alt_phone" id="reg_emergency_alt_phone" class="form-control mobile-09-input" placeholder="09223334444" maxlength="11" pattern="^(|09[0-9]{9})$" inputmode="numeric" title="Mobile number must start with 09 and contain exactly 11 digits (e.g. 09223334444). Numbers only, no letters.">
                            
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: IDENTITY VERIFICATION & PROFILE ID -->
                <div style="margin-bottom: 2rem;">
                    <h3 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); border-bottom: 2px solid var(--purple-light); padding-bottom: 0.5rem; margin-bottom: 1.25rem;">
                        <i class="fa-solid fa-passport" style="color: var(--bamboo-gold);"></i> 3. Profile ID & Identity Verification
                    </h3>

                    <div style="background: #fdf4ff; border: 1px solid #f5d0fe; padding: 0.85rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1.25rem; font-size: 0.88rem; color: #86198f;">
                        <i class="fa-solid fa-circle-info"></i> <strong>Resort Profile ID Assignment:</strong> A unique Guest Profile ID (e.g. <code>PBR-GST-00123</code>) will be generated automatically and assigned to your account upon registration.
                    </div>

                    <div class="identity-id-grid">
                        <div class="form-group">
                            <label><i class="fa-solid fa-list-check"></i> Type of Government / Valid ID *</label>
                            <select name="id_type" class="form-control" required> <br>
                                <option value="">-- Select Valid ID Type --</option>
                                <option value="PhilID / National ID">PhilID / Philippine National ID</option>
                                <option value="Passport">Philippine / Foreign Passport</option>
                                <option value="Driver's License">Driver's License (LTO)</option>
                                <option value="SSS / UMID Card">SSS / UMID Card</option>
                                <option value="PRC ID">PRC License ID</option>
                                <option value="Postal ID">Postal ID</option>
                                <option value="Voter's ID / Certificate">Voter's ID / Certificate</option>
                                <option value="PhilHealth ID">PhilHealth Healthpass ID</option>
                                <option value="Senior Citizen / PWD ID">Senior Citizen / PWD ID</option>
                                <option value="Student ID">Student ID / School ID</option>
                                <option value="Other Valid ID">Other Official Valid Government ID</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fa-solid fa-hashtag"></i> Valid ID Number / Card No. *</label>
                            <input type="text" name="id_number" class="form-control" placeholder="e.g. 1234-5678-9012" required>
                            
                        </div>

                        <div class="form-group">
                            <label><i class="fa-solid fa-file-arrow-up"></i> Upload Valid ID Document / Photo *</label>
                            <input type="file" id="id_image_input" name="id_image" class="form-control" accept="image/*,application/pdf" required onchange="handleFilePreview(this, 'id_image_preview', 'id_image_name', 'id_image_thumb')">
                            <small style="color: var(--text-muted); font-size: 0.78rem;">JPG, PNG, WEBP, PDF (Max 5MB)</small>

                            <div id="id_image_preview" class="image-preview-card" style="display: none;">
                                <img id="id_image_thumb" src="" class="image-preview-thumb" alt="ID Document Preview" onclick="openPictureModal(this.src, 'Valid Government ID Document Preview')">
                                <div class="image-preview-details">
                                    <div id="id_image_name" class="image-preview-name">id_document.jpg</div>
                                    <div class="image-preview-actions">
                                        <button type="button" class="btn btn-outline btn-sm" style="padding: 0.25rem 0.6rem; font-size: 0.78rem;" onclick="openPictureModal(document.getElementById('id_image_thumb').src, 'Valid Government ID Document')">
                                            <i class="fa-solid fa-eye"></i> View Picture
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 4: GUEST HEALTH & MEDICAL INFORMATION -->
                <div style="margin-bottom: 2rem; background: #f0fdf4; border: 1px solid #bbf7d0; padding: 1.5rem; border-radius: var(--radius-sm);">
                    <h3 style="font-family: 'Outfit', sans-serif; color: #166534; margin-bottom: 0.5rem;">
                        <i class="fa-solid fa-heart-pulse" style="color: #10b981;"></i> 4. Guest Health Information & Medical Details
                    </h3>
                    <p style="font-size: 0.88rem; color: #15803d; margin-bottom: 1rem;">Select applicable health conditions so resort medical and lifeguards can assist appropriately in emergencies.</p>

                    <label style="font-weight: 700; font-size: 0.9rem; color: #166534;"><i class="fa-solid fa-notes-medical"></i> Select Medical Conditions / Health Alerts:</label>
                    <div class="health-chip-grid">
                        <label class="health-chip-item">
                            <input type="checkbox" name="health_conditions[]" value="Asthma">
                            <span>🫁 Asthma</span>
                        </label>
                        <label class="health-chip-item">
                            <input type="checkbox" name="health_conditions[]" value="Heart Attack / Cardiovascular Condition">
                            <span>🫀 Heart Condition</span>
                        </label>
                        <label class="health-chip-item">
                            <input type="checkbox" name="health_conditions[]" value="Food / Drug Allergies">
                            <span>⚠️ Allergies</span>
                        </label>
                        <label class="health-chip-item">
                            <input type="checkbox" name="health_conditions[]" value="Hypertension">
                            <span>🩸 Hypertension</span>
                        </label>
                        <label class="health-chip-item">
                            <input type="checkbox" name="health_conditions[]" value="Diabetes">
                            <span>🩺 Diabetes</span>
                        </label>
                        <label class="health-chip-item">
                            <input type="checkbox" name="health_conditions[]" value="Epilepsy / Seizures">
                            <span>⚡ Epilepsy / Seizures</span>
                        </label>
                        <label class="health-chip-item">
                            <input type="checkbox" name="health_conditions[]" value="Mobility / Physical Assistance">
                            <span>♿ Mobility Assistance</span>
                        </label>
                        <label class="health-chip-item">
                            <input type="checkbox" name="health_conditions[]" value="Pregnancy">
                            <span>🤰 Pregnant</span>
                        </label>
                    </div>

                    <div style="margin-top: 1.25rem;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label><i class="fa-solid fa-comment-medical"></i> Specific Health & Emergency Instructions <span style="font-size: 0.8rem; font-weight: normal; color: var(--text-muted);">(Optional)</span></label>
                            <textarea name="health_info" class="form-control" rows="3" placeholder="Provide additional details regarding allergies (e.g. seafood, peanuts, penicillin), medications carried (e.g. EpiPen, inhaler), or special instructions..."></textarea>
                        </div>
                    </div>
                </div>
                <!-- Mandatory Data Privacy & Terms Consent Checkbox -->
                <div class="form-group" style="margin-top: 1.5rem; margin-bottom: 1.5rem; background: #f8fafc; padding: 1rem 1.25rem; border-radius: var(--radius-sm); border: 1px solid #cbd5e1;">
                    <label for="consent_terms" style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer; color: var(--text-dark); font-size: 0.9rem; font-weight: 500;">
                        <input type="checkbox" id="consent_terms" name="consent_terms" required value="1" style="margin-top: 3px; accent-color: var(--primary-purple); width: 20px; height: 20px;">
                        <span>
                            I agree to the <a href="terms.php" target="_blank" style="color: var(--primary-purple); font-weight: 700; text-decoration: underline;">Terms & Conditions</a> and <a href="privacy-policy.php" target="_blank" style="color: var(--primary-purple); font-weight: 700; text-decoration: underline;">Privacy Policy</a>, and I consent to the collection & processing of my personal, contact, and health details under Republic Act No. 10173 (Data Privacy Act of 2012). *
                        </span>
                    </label>
                </div>

                <button type="submit" class="btn btn-gold" style="width: 100%; margin-top: 0.5rem; padding: 0.9rem; font-size: 1.1rem; font-weight: 700;">
                    <i class="fa-solid fa-user-plus"></i> Complete Guest Registration
                </button>
            </form>

            <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color); text-align: center;">
                <p style="font-size: 0.9rem; color: var(--text-muted);">Already registered?</p>
                <a href="login.php" class="btn btn-outline btn-sm" style="margin-top: 0.5rem;">Sign In To Your Account</a>
            </div>
        </div>
    </div>

    <!-- Picture Lightbox Modal -->
    <div id="picture_modal" class="picture-modal-overlay" onclick="closePictureModal(event)">
        <div class="picture-modal-container" onclick="event.stopPropagation()">
            <button type="button" class="picture-modal-close" onclick="closePictureModal()">&times;</button>
            <h3 id="picture_modal_title" style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-bottom: 0.5rem;">Uploaded Picture View</h3>
            <img id="picture_modal_img" src="" class="picture-modal-img" alt="Enlarged View">
        </div>
    </div>

    <?php include 'footer.php'; ?>

    <script>
    function togglePasswordVisibility(inputId, btnElement) {
        const input = document.getElementById(inputId);
        const icon = btnElement.querySelector('i');
        if (!input) return;
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    function handleFilePreview(input, cardId, nameId, thumbId) {
        const file = input.files[0];
        const card = document.getElementById(cardId);
        const name = document.getElementById(nameId);
        const thumb = document.getElementById(thumbId);
        if (!card || !name || !thumb) return;

        if (file && file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                thumb.src = e.target.result;
                name.textContent = file.name;
                card.style.display = 'flex';
            };
            reader.readAsDataURL(file);
        } else if (file) {
            name.textContent = file.name + " (PDF Document)";
            thumb.src = "https://cdn-icons-png.flaticon.com/512/337/337946.png";
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    }

    function openPictureModal(imgSrc, title) {
        if (!imgSrc) return;
        document.getElementById('picture_modal_img').src = imgSrc;
        document.getElementById('picture_modal_title').textContent = title || 'Picture Preview';
        document.getElementById('picture_modal').classList.add('active');
    }

    function closePictureModal(e) {
        document.getElementById('picture_modal').classList.remove('active');
    }

    function selectPaymentMethod(type, element) {
        document.querySelectorAll('.payment-method-card').forEach(el => el.classList.remove('selected'));
        element.classList.add('selected');
        const radio = element.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;
    }

    document.addEventListener('DOMContentLoaded', function() {
        const pwInput = document.getElementById('register_password');
        const reqLength = document.getElementById('req-length');
        const reqUppercase = document.getElementById('req-uppercase');
        const reqLowercase = document.getElementById('req-lowercase');
        const reqNumber = document.getElementById('req-number');
        const strengthBar = document.getElementById('password-strength-bar');
        const strengthText = document.getElementById('password-strength-text');

        if (pwInput) {
            pwInput.addEventListener('input', function() {
                const val = pwInput.value;
                let passedCount = 0;

                // Length requirement (exactly 8 chars)
                if (val.length === 8) {
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
                    strengthText.innerHTML = '<span style="color:#16a34a;"><i class="fa-solid fa-circle-check"></i> Strong Password!</span><span>All 4 rules met</span>';
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
