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

/**
 * Helper: Find user across MySQL and data/users.json
 */
function findUserRecordByEmail($email) {
    global $pdo;
    $email = strtolower(trim($email));
    if (empty($email)) return null;

    // 1. Try MySQL
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT user_id, COALESCE(id, user_id) AS id, full_name, email, reset_token, reset_token_expires, password_hash FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                return $user;
            }
        } catch (PDOException $e) {
            error_log("findUserRecordByEmail MySQL error: " . $e->getMessage());
        }
    }

    // 2. Fallback to data/users.json
    $jsonFile = __DIR__ . '/data/users.json';
    if (file_exists($jsonFile)) {
        $users = json_decode(file_get_contents($jsonFile), true) ?? [];
        foreach ($users as $u) {
            if (strtolower(trim($u['email'] ?? '')) === $email) {
                return [
                    'user_id' => $u['id'] ?? 100,
                    'id' => $u['id'] ?? 100,
                    'full_name' => $u['full_name'] ?? 'Valued Guest',
                    'email' => $u['email'],
                    'reset_token' => $u['reset_token'] ?? null,
                    'reset_token_expires' => $u['reset_token_expires'] ?? null,
                    'password_hash' => $u['password_hash'] ?? ''
                ];
            }
        }
    }

    return null;
}

/**
 * Helper: Find user by ID across MySQL and data/users.json
 */
function findUserRecordById($userId) {
    global $pdo;
    $userId = intval($userId);
    if ($userId <= 0) return null;

    // 1. Try MySQL
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT user_id, COALESCE(id, user_id) AS id, full_name, email, reset_token, reset_token_expires, password_hash FROM users WHERE user_id = ? OR id = ? LIMIT 1");
            $stmt->execute([$userId, $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                return $user;
            }
        } catch (PDOException $e) {}
    }

    // 2. Fallback to data/users.json
    $jsonFile = __DIR__ . '/data/users.json';
    if (file_exists($jsonFile)) {
        $users = json_decode(file_get_contents($jsonFile), true) ?? [];
        foreach ($users as $u) {
            if (intval($u['id'] ?? 0) === $userId) {
                return [
                    'user_id' => $u['id'],
                    'id' => $u['id'],
                    'full_name' => $u['full_name'] ?? 'Valued Guest',
                    'email' => $u['email'],
                    'reset_token' => $u['reset_token'] ?? null,
                    'reset_token_expires' => $u['reset_token_expires'] ?? null,
                    'password_hash' => $u['password_hash'] ?? ''
                ];
            }
        }
    }

    return null;
}

/**
 * Helper: Save OTP and expiration to both MySQL and data/users.json
 */
function saveUserResetOTP($email, $userId, $otpCode, $expires) {
    global $pdo;
    $email = strtolower(trim($email));

    // 1. Update MySQL
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE LOWER(email) = LOWER(?) OR user_id = ? OR id = ?");
            $stmt->execute([$otpCode, $expires, $email, $userId, $userId]);
        } catch (PDOException $e) {
            error_log("saveUserResetOTP MySQL error: " . $e->getMessage());
        }
    }

    // 2. Update data/users.json
    $jsonFile = __DIR__ . '/data/users.json';
    if (file_exists($jsonFile)) {
        $users = json_decode(file_get_contents($jsonFile), true) ?? [];
        $updated = false;
        foreach ($users as $idx => $u) {
            if (strtolower(trim($u['email'] ?? '')) === $email || intval($u['id'] ?? 0) === intval($userId)) {
                $users[$idx]['reset_token'] = $otpCode;
                $users[$idx]['reset_token_expires'] = $expires;
                $updated = true;
            }
        }
        if ($updated) {
            file_put_contents($jsonFile, json_encode($users, JSON_PRETTY_PRINT));
        }
    }
}

/**
 * Helper: Update Password in both MySQL and data/users.json
 */
function updateUserPassword($email, $userId, $passwordHash) {
    global $pdo;
    $email = strtolower(trim($email));

    // 1. Update MySQL
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE LOWER(email) = LOWER(?) OR user_id = ? OR id = ?");
            $stmt->execute([$passwordHash, $email, $userId, $userId]);
        } catch (PDOException $e) {
            error_log("updateUserPassword MySQL error: " . $e->getMessage());
        }
    }

    // 2. Update data/users.json
    $jsonFile = __DIR__ . '/data/users.json';
    if (file_exists($jsonFile)) {
        $users = json_decode(file_get_contents($jsonFile), true) ?? [];
        $updated = false;
        foreach ($users as $idx => $u) {
            if (strtolower(trim($u['email'] ?? '')) === $email || intval($u['id'] ?? 0) === intval($userId)) {
                $users[$idx]['password_hash'] = $passwordHash;
                $users[$idx]['reset_token'] = null;
                $users[$idx]['reset_token_expires'] = null;
                $updated = true;
            }
        }
        if ($updated) {
            file_put_contents($jsonFile, json_encode($users, JSON_PRETTY_PRINT));
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
    
    if ($email_sent) {
        $_SESSION['otp_sent'] = true;
        echo json_encode(['success' => true, 'message' => '✅ New OTP sent to your email!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to send OTP email. Please try again.']);
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
            
            if ($email_sent) {
                $_SESSION['reset_email'] = $user['email'];
                $_SESSION['reset_user_id'] = $user['user_id'];
                $_SESSION['otp_sent'] = true;
                $step = 'verify';
                $success = "✅ OTP code sent to your email (" . htmlspecialchars($user['email']) . "). Please check your inbox or spam folder.";
            } else {
                $error = "❌ Failed to send OTP email. Please verify your connection or try again.";
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
    <title>Forgot Password | Purple Bamboo Resort</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<div class="card">
    <div class="icon">
        <i class="fas fa-lock"></i>
    </div>
    
    <?php if ($step == 'done'): ?>
        <!-- SUCCESS -->
        <h2>✅ Password Reset!</h2>
        <p>Your password has been changed successfully.</p>
        <a href="login.php" class="btn btn-primary" style="text-decoration: none; display: block; text-align: center;">
            <i class="fas fa-sign-in-alt"></i> Login Now
        </a>
        
    <?php elseif ($step == 'reset'): ?>
        <!-- STEP 3: Reset Password -->
        <h2>🔐 Reset Password</h2>
        <p>Enter your new password below.</p>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="mb-3">
                <label class="form-label"><i class="fa-solid fa-lock"></i> New Password</label>
                <div class="password-input-wrapper">
                    <input type="password" id="reset_password_input" name="new_password" class="form-control" placeholder="Enter 8-character password" required maxlength="8" pattern="(?=.*\d)(?=.*[A-Z]).{8,8}" title="Must contain exactly 8 characters, including 1 uppercase letter and 1 number">
                    <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility('reset_password_input', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
                
                <div class="password-requirements-card" style="margin-top: 0.75rem; text-align: left;">
                    <div class="password-requirements-title" style="font-size: 0.85rem;">
                        <i class="fa-solid fa-shield-halved" style="color: var(--primary-purple);"></i> Password Requirements:
                    </div>
                    <ul class="password-requirements-list" style="margin-bottom: 0;">
                        <li id="reset-req-length" class="password-requirement-item invalid">
                            <i class="fa-solid fa-circle-xmark"></i> Exactly 8 characters
                        </li>
                        <li id="reset-req-uppercase" class="password-requirement-item invalid">
                            <i class="fa-solid fa-circle-xmark"></i> At least 1 Big letter (A-Z)
                        </li>
                        <li id="reset-req-lowercase" class="password-requirement-item invalid">
                            <i class="fa-solid fa-circle-xmark"></i> At least 1 lowercase letter (a-z)
                        </li>
                        <li id="reset-req-number" class="password-requirement-item invalid">
                            <i class="fa-solid fa-circle-xmark"></i> At least 1 number (0-9)
                        </li>
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
            <div class="mb-3">
                <label class="form-label"><i class="fa-solid fa-lock"></i> Confirm Password</label>
                <div class="password-input-wrapper">
                    <input type="password" id="confirm_password_input" name="confirm_password" class="form-control" placeholder="Confirm 8-character password" required maxlength="8" pattern="(?=.*\d)(?=.*[A-Z]).{8,8}">
                    <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility('confirm_password_input', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
            </div>
            <button type="submit" name="reset_password" class="btn btn-success" style="width: 100%;">
                <i class="fas fa-check-circle"></i> Reset Password
            </button>
        </form>
        <a href="forgot-password.php?clear_session=1" class="back-link">
            <i class="fas fa-arrow-left"></i> Start over
        </a>
        
    <?php elseif ($step == 'verify'): ?>
        <!-- STEP 2: Verify OTP -->
        <h2>📧 Enter OTP Code</h2>
        <p>We sent a 6-digit code to your email.</p>
        
        <?php if ($success): ?>
            <div class="alert alert-success" id="successAlert"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div id="resendMessage" style="display: none;" class="alert alert-success">
            <i class="fas fa-check-circle"></i> New OTP sent to your email!
        </div>
        
        <form method="POST" id="otpForm">
            <div class="mb-3">
                <label class="form-label">OTP Code</label>
                <input type="text" name="otp_code" id="otpCode" class="form-control otp-input" placeholder="000000" maxlength="6" pattern="[0-9]*" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '');" required autofocus>
                <div class="text-muted mt-1">Enter the 6-digit code sent to your email</div>
            </div>
            <button type="submit" name="verify_otp" class="btn btn-primary">
                <i class="fas fa-check-circle"></i> Verify OTP
            </button>
        </form>
        
        <button id="resendBtn" class="btn-resend" onclick="resendOTP()">
            <i class="fas fa-redo"></i> Resend OTP
        </button>
        <div id="timerDisplay" class="timer-text">
            <i class="fas fa-clock"></i> Resend available in <span id="countdown">30</span> seconds
        </div>
        
        <a href="forgot-password.php?clear_session=1" class="back-link">
            <i class="fas fa-arrow-left"></i> Start over
        </a>
        
    <?php else: ?>
        <!-- STEP 1: Enter Email -->
        <h2>🔐 Forgot Password?</h2>
        <p>Enter your email address and we'll send you an OTP code.</p>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="your@email.com" required autofocus>
                <div class="text-muted mt-1">Enter the email address you used to register</div>
            </div>
            <button type="submit" name="send_otp" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Send OTP Code
            </button>
        </form>
        
        <a href="login.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Login
        </a>
    <?php endif; ?>
</div>

<script>
<?php if ($step == 'verify'): ?>
let countdown = 30;
let timerInterval;
let isResendDisabled = true;

function startTimer() {
    const countdownEl = document.getElementById('countdown');
    const resendBtn = document.getElementById('resendBtn');
    
    timerInterval = setInterval(function() {
        countdown--;
        countdownEl.textContent = countdown;
        
        if (countdown <= 0) {
            clearInterval(timerInterval);
            isResendDisabled = false;
            resendBtn.disabled = false;
            countdownEl.textContent = '0';
            document.getElementById('timerDisplay').innerHTML = '<i class="fas fa-check-circle" style="color: #10b981;"></i> You can now resend the OTP code';
        }
    }, 1000);
}

startTimer();

function resendOTP() {
    if (isResendDisabled) return;
    
    const resendBtn = document.getElementById('resendBtn');
    resendBtn.disabled = true;
    resendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    
    fetch('forgot-password.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'resend_otp=1'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const msgDiv = document.getElementById('resendMessage');
            msgDiv.textContent = '✅ ' + data.message;
            msgDiv.style.display = 'block';
            
            countdown = 30;
            isResendDisabled = true;
            document.getElementById('timerDisplay').innerHTML = '<i class="fas fa-clock"></i> Resend available in <span id="countdown">30</span> seconds';
            resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend OTP';
            resendBtn.disabled = true;
            
            clearInterval(timerInterval);
            startTimer();
            
            setTimeout(() => {
                msgDiv.style.display = 'none';
            }, 5000);
        } else {
            alert('❌ ' + data.message);
            resendBtn.disabled = false;
            resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend OTP';
        }
    })
    .catch(error => {
        alert('❌ Failed to resend OTP. Please try again.');
        resendBtn.disabled = false;
        resendBtn.innerHTML = '<i class="fas fa-redo"></i> Resend OTP';
    });
}

document.getElementById('otpCode').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        document.getElementById('otpForm').submit();
    }
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

    if (pwInput && reqLength && reqUppercase && reqLowercase && reqNumber) {
        pwInput.addEventListener('input', function() {
            const val = pwInput.value;
            let passedCount = 0;

            // Exactly 8 characters
            if (val.length === 8) {
                reqLength.className = 'password-requirement-item valid';
                reqLength.querySelector('i').className = 'fa-solid fa-circle-check';
                passedCount++;
            } else {
                reqLength.className = 'password-requirement-item invalid';
                reqLength.querySelector('i').className = 'fa-solid fa-circle-xmark';
            }

            // Uppercase (Big) letter
            if (/[A-Z]/.test(val)) {
                reqUppercase.className = 'password-requirement-item valid';
                reqUppercase.querySelector('i').className = 'fa-solid fa-circle-check';
                passedCount++;
            } else {
                reqUppercase.className = 'password-requirement-item invalid';
                reqUppercase.querySelector('i').className = 'fa-solid fa-circle-xmark';
            }

            // Lowercase letter
            if (/[a-z]/.test(val)) {
                reqLowercase.className = 'password-requirement-item valid';
                reqLowercase.querySelector('i').className = 'fa-solid fa-circle-check';
                passedCount++;
            } else {
                reqLowercase.className = 'password-requirement-item invalid';
                reqLowercase.querySelector('i').className = 'fa-solid fa-circle-xmark';
            }

            // Number
            if (/[0-9]/.test(val)) {
                reqNumber.className = 'password-requirement-item valid';
                reqNumber.querySelector('i').className = 'fa-solid fa-circle-check';
                passedCount++;
            } else {
                reqNumber.className = 'password-requirement-item invalid';
                reqNumber.querySelector('i').className = 'fa-solid fa-circle-xmark';
            }

            // Update Strength Meter
            if (strengthBar && strengthText) {
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
            }
        });
    }
});
</script>

</body>
</html>