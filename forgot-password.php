<?php
// forgot-password.php - OTP Version (Adapted from friend's code)

session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/includes/EmailHelper.php';
require_once __DIR__ . '/security.php';

$error = '';
$success = '';
$step = 'email';

// Check session for OTP flow
if (isset($_SESSION['reset_email']) && isset($_SESSION['reset_user_id']) && isset($_SESSION['otp_sent'])) {
    $step = 'verify';
}

// Generate OTP
function generateOTP($length = 6) {
    return str_pad(rand(0, 999999), $length, '0', STR_PAD_LEFT);
}

function isLocalDevHost() {
    $host = strtolower($_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? php_uname('n'));
    $host = preg_replace('/:\d+$/', '', $host);
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function normalizeResetUserRow($row, $table = 'users') {
    if (!$row) {
        return null;
    }
    $id = (int) ($row['id'] ?? 0);
    $row['user_id'] = $id;
    $row['id'] = $id;
    $row['account_table'] = $table;
    $row['reset_token'] = $row['reset_otp'] ?? null;
    $row['reset_token_expires'] = $row['reset_expires'] ?? null;
    if (empty($row['full_name'])) {
        $row['full_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    }
    return $row;
}

function accountTables() {
    return ['users', 'staff', 'admins'];
}

/**
 * Find a guest, staff, or admin account by email.
 */
function findUserRecordByEmail($email) {
    global $pdo;
    $email = strtolower(trim($email));
    if (empty($email) || !$pdo) {
        return null;
    }

    foreach (accountTables() as $table) {
        try {
            $stmt = $pdo->prepare("SELECT id, CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name, NULLIF(suffix, '')) AS full_name, email, reset_otp, reset_expires, password_hash FROM `$table` WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                return normalizeResetUserRow($user, $table);
            }
        } catch (PDOException $e) {
            error_log("findUserRecordByEmail MySQL error ($table): " . $e->getMessage());
        }
    }

    return null;
}

/**
 * Find a guest, staff, or admin account by ID.
 */
function findUserRecordById($userId) {
    global $pdo;
    $userId = intval($userId);
    if ($userId <= 0 || !$pdo) {
        return null;
    }

    foreach (accountTables() as $table) {
        try {
            $stmt = $pdo->prepare("SELECT id, CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name, NULLIF(suffix, '')) AS full_name, email, reset_otp, reset_expires, password_hash FROM `$table` WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                return normalizeResetUserRow($user, $table);
            }
        } catch (PDOException $e) {
            error_log("findUserRecordById MySQL error ($table): " . $e->getMessage());
        }
    }

    return null;
}

function saveUserResetOTP($email, $userId, $otpCode, $expires) {
    global $pdo;
    $email = strtolower(trim($email));
    if (!$pdo) {
        return;
    }

    foreach (accountTables() as $table) {
        try {
            $stmt = $pdo->prepare("UPDATE `$table` SET reset_otp = ?, reset_expires = ? WHERE LOWER(email) = LOWER(?)");
            $stmt->execute([$otpCode, $expires, $email]);
            if ($stmt->rowCount() > 0) {
                return;
            }
        } catch (PDOException $e) {
            error_log("saveUserResetOTP MySQL error ($table): " . $e->getMessage());
        }
    }
}

function updateUserPassword($email, $userId, $passwordHash) {
    global $pdo;
    $email = strtolower(trim($email));
    if (!$pdo) {
        return;
    }

    foreach (accountTables() as $table) {
        try {
            $stmt = $pdo->prepare("UPDATE `$table` SET password_hash = ?, reset_otp = NULL, reset_expires = NULL WHERE LOWER(email) = LOWER(?)");
            $stmt->execute([$passwordHash, $email]);
            if ($stmt->rowCount() > 0) {
                return;
            }
        } catch (PDOException $e) {
            error_log("updateUserPassword MySQL error ($table): " . $e->getMessage());
        }
    }
}

// ============================================================
// AJAX: Resend OTP
// ============================================================
if (isset($_POST['resend_otp'])) {
    header('Content-Type: application/json');
    
    $email = $_SESSION['reset_email'] ?? '';
    $user_id = $_SESSION['reset_user_id'] ?? 0;
    
    if (empty($email) && empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please start over.']);
        exit();
    }
    
    $user = findUserRecordById($user_id) ?: findUserRecordByEmail($email);
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User account not found.']);
        exit();
    }
    
    $otp_code = generateOTP(6);
    $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    
    saveUserResetOTP($user['email'], $user['user_id'], $otp_code, $expires);
    
    $email_sent = EmailHelper::sendPasswordResetEmail($user['email'], $user['full_name'], $otp_code);
    $allowLocalOtp = !$email_sent && isLocalDevHost();

    if ($email_sent || $allowLocalOtp) {
        $_SESSION['otp_sent'] = true;
        $message = $email_sent
            ? '✅ New OTP sent to your email!'
            : ('Email SMTP failed (' . Mailer::lastError() . '). Local OTP: ' . $otp_code);
        echo json_encode(['success' => true, 'message' => $message]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to send OTP email: ' . Mailer::lastError()]);
    }
    exit();
}

// ============================================================
// STEP 1: Send OTP
// ============================================================
if (isset($_POST['send_otp'])) {
    $email = trim($_POST['email']);
    
    if (empty($email)) {
        $error = "Please enter your registered email address.";
    } else {
        $user = findUserRecordByEmail($email);
        
        if ($user) {
            $otp_code = generateOTP(6);
            $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            saveUserResetOTP($user['email'], $user['user_id'], $otp_code, $expires);
            
            $email_sent = EmailHelper::sendPasswordResetEmail($user['email'], $user['full_name'], $otp_code);
            $allowLocalOtp = !$email_sent && isLocalDevHost();

            if ($email_sent || $allowLocalOtp) {
                $_SESSION['reset_email'] = $user['email'];
                $_SESSION['reset_user_id'] = $user['user_id'];
                $_SESSION['otp_sent'] = true;
                $step = 'verify';
                if ($email_sent) {
                    $success = "✅ OTP code sent to your email (" . htmlspecialchars($user['email']) . "). Please check your inbox or spam folder.";
                } else {
                    $success = "⚠️ Gmail SMTP could not send the email (" . htmlspecialchars(Mailer::lastError()) . "). For local testing, your OTP is <strong>" . htmlspecialchars($otp_code) . "</strong>.";
                }
            } else {
                $error = "❌ Failed to send OTP email: " . htmlspecialchars(Mailer::lastError() ?: 'Unknown mailer error');
            }
        } else {
            $error = "❌ Email address not found in our registered accounts. Please verify your email or register a new account.";
        }
    }
}

// ============================================================
// STEP 2: Verify OTP
// ============================================================
if (isset($_POST['verify_otp'])) {
    $otp_code = trim($_POST['otp_code']);
    $email = $_SESSION['reset_email'] ?? '';
    
    if (empty($otp_code)) {
        $error = "Please enter the 6-digit OTP code.";
    } else {
        $user = findUserRecordByEmail($email);
        
        if ($user && !empty($user['reset_token']) && $user['reset_token'] === $otp_code) {
            $expires = !empty($user['reset_token_expires']) ? strtotime($user['reset_token_expires']) : 0;
            $now = time();
            
            if ($now > $expires) {
                $error = "❌ OTP code has expired. Please click 'Resend OTP' to request a new code.";
            } else {
                $_SESSION['reset_verified'] = true;
                $step = 'reset';
                $success = "✅ OTP verified successfully! You can now create your new password.";
            }
        } else {
            $error = "❌ Invalid OTP code. Please check the code sent to your email and try again.";
        }
    }
}

// ============================================================
// STEP 3: Reset Password
// ============================================================
if (isset($_POST['reset_password'])) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $user_id = $_SESSION['reset_user_id'] ?? 0;
    $email = $_SESSION['reset_email'] ?? '';
    
    if (empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all fields.";
    } else {
        $pw_check = validate_password_strength($new_password);
        if (!$pw_check['valid']) {
            $error = $pw_check['error'];
        } elseif ($new_password !== $confirm_password) {
            $error = "Passwords do not match.";
        } else {
            $hashed = password_hash($new_password, PASSWORD_BCRYPT);
            
            updateUserPassword($email, $user_id, $hashed);
            
            // Clear session
            unset($_SESSION['reset_email']);
            unset($_SESSION['reset_user_id']);
            unset($_SESSION['reset_verified']);
            unset($_SESSION['otp_sent']);
            
            $step = 'done';
            $success = "✅ Password reset successfully! You can now log in with your new password.";
        }
    }
}

// Clear session
if (isset($_GET['clear_session'])) {
    unset($_SESSION['reset_email']);
    unset($_SESSION['reset_user_id']);
    unset($_SESSION['reset_verified']);
    unset($_SESSION['otp_sent']);
    header("Location: forgot-password.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | The Blessed Purple Bamboo Resort</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <?php include 'nav.php'; ?>

    <div class="container" style="max-width: 480px; margin-top: 3.5rem; margin-bottom: 4rem;">
        <div class="card" style="padding: 2.5rem;">

            <!-- Header Badge -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; margin-bottom: 1.75rem; display: flex; align-items: center; gap: 10px;">
                <span style="background: rgba(124, 58, 237, 0.12); color: var(--primary-purple); width: 28px; height: 28px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.85rem;">
                    <i class="fa-solid fa-key"></i>
                </span>
                <span style="font-size: 0.82rem; font-weight: 700; color: #1e1b4b;">Account Recovery</span>
            </div>

            <?php if ($step == 'done'): ?>
                <!-- SUCCESS STATE -->
                <div style="text-align: center; margin-bottom: 2rem;">
                    <div style="width: 64px; height: 64px; background: rgba(16, 185, 129, 0.12); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem;">
                        <i class="fa-solid fa-circle-check" style="color: #10b981; font-size: 2rem;"></i>
                    </div>
                    <h1 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-bottom: 0.35rem;">Password Reset!</h1>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Your password has been changed successfully.</p>
                </div>
                <div style="background: rgba(16, 185, 129, 0.1); color: #047857; padding: 0.85rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem; font-size: 0.88rem; border: 1.5px solid #a7f3d0; display: flex; align-items: center; gap: 8px; font-weight: 600;">
                    <i class="fa-solid fa-circle-check" style="color: #10b981; font-size: 1.2rem;"></i>
                    <div>You can now sign in with your new password.</div>
                </div>
                <a href="login.php" class="btn btn-primary" style="width: 100%; padding: 0.85rem; font-size: 1rem; font-weight: 700; text-decoration: none; text-align: center; display: block;">
                    <i class="fa-solid fa-right-to-bracket"></i> Sign In to Account
                </a>

            <?php elseif ($step == 'reset'): ?>
                <!-- STEP 3: Reset Password -->
                <div style="text-align: center; margin-bottom: 2rem;">
                    <h1 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-bottom: 0.35rem;">Create New Password</h1>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Enter and confirm your new password below.</p>
                </div>

                <?php if ($success): ?>
                    <div style="background: rgba(16, 185, 129, 0.1); color: #047857; padding: 0.85rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1.25rem; font-size: 0.88rem; border: 1.5px solid #a7f3d0; display: flex; align-items: flex-start; gap: 8px; font-weight: 600;">
                        <i class="fa-solid fa-circle-check" style="color: #10b981; font-size: 1.1rem; margin-top: 2px;"></i>
                        <div><?php echo htmlspecialchars($success); ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div style="background: #fee2e2; color: #b91c1c; padding: 0.75rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1.25rem; font-size: 0.88rem; border: 1px solid #fca5a5; display: flex; align-items: flex-start; gap: 8px;">
                        <i class="fa-solid fa-circle-exclamation" style="margin-top: 2px;"></i>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label><i class="fa-solid fa-lock"></i> New Password</label>
                        <div class="password-input-wrapper">
                            <input type="password" id="reset_password_input" name="new_password" class="form-control" placeholder="Minimum 9 characters" required minlength="9">
                            <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility('reset_password_input', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-requirements-card" style="margin-top: 0.75rem;">
                            <div class="password-requirements-title" style="font-size: 0.85rem;">
                                <i class="fa-solid fa-shield-halved" style="color: var(--primary-purple);"></i> Password Requirements:
                            </div>
                            <ul class="password-requirements-list" style="margin-bottom: 0;">
                                <li id="reset-req-length" class="password-requirement-item invalid"><i class="fa-solid fa-circle-xmark"></i> At least 9 characters (no limit)</li>
                                <li id="reset-req-uppercase" class="password-requirement-item invalid"><i class="fa-solid fa-circle-xmark"></i> At least 1 Big letter (A-Z)</li>
                                <li id="reset-req-lowercase" class="password-requirement-item invalid"><i class="fa-solid fa-circle-xmark"></i> At least 1 lowercase letter (a-z)</li>
                                <li id="reset-req-number" class="password-requirement-item invalid"><i class="fa-solid fa-circle-xmark"></i> At least 1 number (0-9)</li>
                            </ul>
                            <div class="password-strength-container">
                                <div class="password-strength-bar-track">
                                    <div id="reset-strength-bar" class="password-strength-bar-fill"></div>
                                </div>
                                <div id="reset-strength-text" class="password-strength-feedback">
                                    <span>Strength: Enter password</span>
                                    <span><i class="fa-solid fa-lock"></i> Required: Strong</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fa-solid fa-lock"></i> Confirm Password</label>
                        <div class="password-input-wrapper">
                            <input type="password" id="confirm_password_input" name="confirm_password" class="form-control" placeholder="Re-enter password to confirm" required minlength="9">
                            <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility('confirm_password_input', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" name="reset_password" class="btn btn-primary" style="width: 100%; padding: 0.85rem; font-size: 1rem; font-weight: 700;">
                        <i class="fa-solid fa-check-circle"></i> Reset Password
                    </button>
                </form>

                <div style="margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--border-color); text-align: center;">
                    <a href="forgot-password.php?clear_session=1" class="forgot-link" style="font-size: 0.85rem;">
                        <i class="fa-solid fa-rotate-left"></i> Start over
                    </a>
                </div>

            <?php elseif ($step == 'verify'): ?>
                <!-- STEP 2: Verify OTP -->
                <div style="text-align: center; margin-bottom: 2rem;">
                    <h1 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-bottom: 0.35rem;">Enter OTP Code</h1>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">We sent a 6-digit verification code to your email.</p>
                </div>

                <?php if ($success): ?>
                    <div id="successAlert" style="background: rgba(16, 185, 129, 0.1); color: #047857; padding: 0.85rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1.25rem; font-size: 0.88rem; border: 1.5px solid #a7f3d0; display: flex; align-items: flex-start; gap: 8px; font-weight: 600;">
                        <i class="fa-solid fa-circle-check" style="color: #10b981; font-size: 1.1rem; margin-top: 2px;"></i>
                        <div><?php echo htmlspecialchars($success); ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div style="background: #fee2e2; color: #b91c1c; padding: 0.75rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1.25rem; font-size: 0.88rem; border: 1px solid #fca5a5; display: flex; align-items: flex-start; gap: 8px;">
                        <i class="fa-solid fa-circle-exclamation" style="margin-top: 2px;"></i>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>

                <div id="resendMessage" style="display: none; background: rgba(16, 185, 129, 0.1); color: #047857; padding: 0.85rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1.25rem; font-size: 0.88rem; border: 1.5px solid #a7f3d0; align-items: center; gap: 8px; font-weight: 600;">
                    <i class="fa-solid fa-circle-check" style="color: #10b981;"></i> New OTP sent to your email!
                </div>

                <form method="POST" id="otpForm">
                    <div class="form-group">
                        <label><i class="fa-solid fa-envelope-open-text"></i> OTP Code</label>
                        <input type="text" name="otp_code" id="otpCode" class="form-control" placeholder="000000" maxlength="6" pattern="[0-9]*" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '');" required autofocus style="letter-spacing: 0.35em; font-size: 1.4rem; font-weight: 700; text-align: center;">
                        <div style="font-size: 0.78rem; color: #64748b; margin-top: 0.35rem;">Enter the 6-digit code sent to your email address</div>
                    </div>
                    <button type="submit" name="verify_otp" class="btn btn-primary" style="width: 100%; padding: 0.85rem; font-size: 1rem; font-weight: 700;">
                        <i class="fa-solid fa-circle-check"></i> Verify OTP Code
                    </button>
                </form>

                <div style="margin-top: 1.25rem; text-align: center;">
                    <button id="resendBtn" onclick="resendOTP()" disabled style="background: none; border: none; color: var(--primary-purple); font-size: 0.85rem; font-weight: 600; cursor: pointer; opacity: 0.5; padding: 0.4rem 0.75rem; border-radius: 6px; transition: all 0.2s;">
                        <i class="fa-solid fa-rotate-right"></i> Resend OTP
                    </button>
                    <div id="timerDisplay" style="font-size: 0.8rem; color: #64748b; margin-top: 0.25rem;">
                        <i class="fa-regular fa-clock"></i> Resend available in <span id="countdown">30</span> seconds
                    </div>
                </div>

                <div style="margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--border-color); text-align: center;">
                    <a href="forgot-password.php?clear_session=1" class="forgot-link" style="font-size: 0.85rem;">
                        <i class="fa-solid fa-rotate-left"></i> Start over
                    </a>
                </div>

            <?php else: ?>
                <!-- STEP 1: Enter Email -->
                <div style="text-align: center; margin-bottom: 2rem;">
                    <h1 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-bottom: 0.35rem;">Forgot Password?</h1>
                    <p style="color: var(--text-muted); font-size: 0.9rem;">Enter your email and we'll send you an OTP code to reset your password.</p>
                </div>

                <?php if ($success): ?>
                    <div style="background: rgba(16, 185, 129, 0.1); color: #047857; padding: 0.85rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1.25rem; font-size: 0.88rem; border: 1.5px solid #a7f3d0; display: flex; align-items: flex-start; gap: 8px; font-weight: 600;">
                        <i class="fa-solid fa-circle-check" style="color: #10b981; font-size: 1.1rem; margin-top: 2px;"></i>
                        <div><?php echo htmlspecialchars($success); ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div style="background: #fee2e2; color: #b91c1c; padding: 0.75rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1.25rem; font-size: 0.88rem; border: 1px solid #fca5a5; display: flex; align-items: flex-start; gap: 8px;">
                        <i class="fa-solid fa-circle-exclamation" style="margin-top: 2px;"></i>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label><i class="fa-solid fa-envelope"></i> Email Address</label>
                        <input type="email" name="email" class="form-control" placeholder="name@domain.com" required autofocus>
                        <div style="font-size: 0.78rem; color: #64748b; margin-top: 0.35rem;">Enter the email address you used to register</div>
                    </div>
                    <button type="submit" name="send_otp" class="btn btn-primary" style="width: 100%; padding: 0.85rem; font-size: 1rem; font-weight: 700;">
                        <i class="fa-solid fa-paper-plane"></i> Send OTP Code
                    </button>
                </form>

                <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color); text-align: center;">
                    <p style="font-size: 0.9rem; color: var(--text-muted);">Remembered your password?</p>
                    <a href="login.php" class="btn btn-outline btn-sm" style="margin-top: 0.5rem;">
                        <i class="fa-solid fa-right-to-bracket"></i> Back to Sign In
                    </a>
                </div>

            <?php endif; ?>

        </div>
    </div>

    <?php include 'footer.php'; ?>

<script>
<?php if ($step == 'verify'): ?>
let countdown = 30;
let timerInterval;
let isResendDisabled = true;
const resendBtn = document.getElementById('resendBtn');

function startTimer() {
    const countdownEl = document.getElementById('countdown');
    timerInterval = setInterval(function() {
        countdown--;
        countdownEl.textContent = countdown;
        if (countdown <= 0) {
            clearInterval(timerInterval);
            isResendDisabled = false;
            resendBtn.disabled = false;
            resendBtn.style.opacity = '1';
            document.getElementById('timerDisplay').innerHTML = '<i class="fa-solid fa-circle-check" style="color: #10b981;"></i> You can now resend the OTP code';
        }
    }, 1000);
}
startTimer();

function resendOTP() {
    if (isResendDisabled) return;
    resendBtn.disabled = true;
    resendBtn.style.opacity = '0.5';
    resendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    fetch('forgot-password.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'resend_otp=1'
    })
    .then(r => r.json())
    .then(data => {
        const msgDiv = document.getElementById('resendMessage');
        if (data.success) {
            msgDiv.textContent = '✅ ' + data.message;
            msgDiv.style.display = 'flex';
            countdown = 30;
            isResendDisabled = true;
            document.getElementById('timerDisplay').innerHTML = '<i class="fa-regular fa-clock"></i> Resend available in <span id="countdown">30</span> seconds';
            resendBtn.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Resend OTP';
            resendBtn.disabled = true;
            clearInterval(timerInterval);
            startTimer();
            setTimeout(() => { msgDiv.style.display = 'none'; }, 5000);
        } else {
            alert('❌ ' + data.message);
            resendBtn.disabled = false;
            resendBtn.style.opacity = '1';
            resendBtn.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Resend OTP';
        }
    })
    .catch(() => {
        alert('❌ Failed to resend OTP. Please try again.');
        resendBtn.disabled = false;
        resendBtn.style.opacity = '1';
        resendBtn.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Resend OTP';
    });
}
document.getElementById('otpCode').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') document.getElementById('otpForm').submit();
});
<?php endif; ?>

function togglePasswordVisibility(inputId, btnElement) {
    const input = document.getElementById(inputId);
    const icon = btnElement.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fa-solid fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fa-solid fa-eye';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const pwInput = document.getElementById('reset_password_input');
    const reqLength = document.getElementById('reset-req-length');
    const reqUppercase = document.getElementById('reset-req-uppercase');
    const reqLowercase = document.getElementById('reset-req-lowercase');
    const reqNumber = document.getElementById('reset-req-number');
    const strengthBar = document.getElementById('reset-strength-bar');
    const strengthText = document.getElementById('reset-strength-text');

    if (pwInput && reqLength) {
        pwInput.addEventListener('input', function() {
            const val = pwInput.value;
            let passedCount = 0;
            function check(el, passed) {
                el.className = 'password-requirement-item ' + (passed ? 'valid' : 'invalid');
                el.querySelector('i').className = 'fa-solid ' + (passed ? 'fa-circle-check' : 'fa-circle-xmark');
                if (passed) passedCount++;
            }
            check(reqLength,    val.length >= 9);
            check(reqUppercase, /[A-Z]/.test(val));
            check(reqLowercase, /[a-z]/.test(val));
            check(reqNumber,    /[0-9]/.test(val));
            if (strengthBar && strengthText) {
                const levels = ['', 'weak', 'fair', 'good', 'strong'];
                const labels = [
                    '<span>Strength: Enter password</span><span><i class="fa-solid fa-lock"></i> Required: Strong</span>',
                    '<span style="color:#ef4444;">Strength: Weak</span><span>1 of 4 rules met</span>',
                    '<span style="color:#f97316;">Strength: Fair</span><span>2 of 4 rules met</span>',
                    '<span style="color:#eab308;">Strength: Good</span><span>3 of 4 rules met</span>',
                    '<span style="color:#16a34a;"><i class="fa-solid fa-circle-check"></i> Strong Password!</span><span>All 4 rules met</span>'
                ];
                const idx = val.length === 0 ? 0 : passedCount;
                strengthBar.className = 'password-strength-bar-fill' + (levels[idx] ? ' ' + levels[idx] : '');
                strengthText.innerHTML = labels[idx];
            }
        });
    }
});
</script>

</body>
</html>