<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/security.php';  // for csrf_field() / verify_csrf_token()

$message = '';
$notice = trim($_GET['msg'] ?? '');
$redirect = trim($_GET['redirect'] ?? ($_POST['redirect'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();  // FIX BUG-03: CSRF check on every login POST
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role_pref = trim($_POST['role_preference'] ?? 'auto');

    if (empty($email) || empty($password)) {
        $message = 'Please enter both your email address and password.';
    } else {
        $result = Auth::login($email, $password, $role_pref);
        if ($result['success']) {
            $actual_role = strtolower($result['role'] ?? ($result['user']['role'] ?? 'guest'));
            
            // ROLE-BASED REDIRECTION FOR BOTH GUEST AND ADMIN
            if ($actual_role === 'admin') {
                header("Location: admin-dashboard.php");
                exit();
            } elseif ($actual_role === 'staff') {
                header("Location: bookings.php");
                exit();
            } else {
                if (!empty($redirect) && !str_contains($redirect, '://')) {
                    header("Location: " . $redirect);
                    exit();
                }
                header("Location: index.php?login=1");
                exit();
            }
        } else {
            $message = $result['error'] ?? 'Invalid login credentials. Please check your email and password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | The Blessed Purple Bamboo Resort</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <?php include 'nav.php'; ?>

    <div class="container" style="max-width: 480px; margin-top: 3.5rem; margin-bottom: 4rem;">
        <div class="card" style="padding: 2.5rem;">
          
            <div style="text-align: center; margin-bottom: 2rem;">
                <h1 style="font-family: 'Outfit', sans-serif; color: var(--primary-purple); margin-bottom: 0.35rem;">Resort Sign In</h1>
                <p style="color: var(--text-muted); font-size: 0.9rem;">Sign in with your registered account </p>
            </div>

            <?php if (isset($_GET['logged_out'])): ?>
                <div style="background: rgba(16, 185, 129, 0.1); color: #047857; padding: 0.85rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1.25rem; font-size: 0.88rem; border: 1.5px solid #a7f3d0; display: flex; align-items: center; gap: 8px; font-weight: 600;">
                    <i class="fa-solid fa-circle-check" style="color: #10b981; font-size: 1.2rem;"></i> 
                    <div>You have been safely signed out. Please enter your credentials to sign in.</div>
                </div>
            <?php endif; ?>

            <?php if (!empty($notice)): ?>
                <div style="background: rgba(124, 58, 237, 0.1); color: var(--primary-purple); padding: 0.85rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1.25rem; font-size: 0.88rem; border: 1.5px solid #d8b4fe; display: flex; align-items: flex-start; gap: 8px; font-weight: 600;">
                    <i class="fa-solid fa-circle-info" style="margin-top: 2px; color: var(--primary-purple); font-size: 1.1rem;"></i> 
                    <div><?= htmlspecialchars($notice) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div style="background: #fee2e2; color: #b91c1c; padding: 0.75rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1.25rem; font-size: 0.88rem; border: 1px solid #fca5a5; display: flex; align-items: flex-start; gap: 8px;">
                    <i class="fa-solid fa-circle-exclamation" style="margin-top: 2px;"></i> 
                    <div><?= htmlspecialchars($message) ?></div>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST">
                <?php if (!empty($redirect)): ?>
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                <?php endif; ?>
                <?= csrf_field() ?>  <!-- FIX BUG-03: CSRF hidden token -->

                <div class="form-group">
                    <label><i class="fa-solid fa-envelope"></i> Email Address</label>
                    <input type="email" id="login_email" name="email" class="form-control" placeholder="name@domain.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label><i class="fa-solid fa-lock"></i> Password</label>
                    <div class="password-input-wrapper">
                        <!-- FIX BUG-18: maxlength was 15, silently truncating strong passwords before server sees them -->
                        <input type="password" id="login_password" name="password" class="form-control" placeholder="••••••••" required minlength="8" maxlength="128" title="Must be at least 8 characters with at least 1 uppercase letter and 1 number">
                        <button type="button" class="toggle-password-btn" onclick="togglePasswordVisibility('login_password', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                    <div style="font-size: 0.78rem; color: #64748b; margin-top: 0.35rem; display: flex; align-items: center; gap: 5px;">
                        
                    </div>
                </div>


                <!-- Forgot Password Link -->
                <div style="text-align: right; margin-top: -0.25rem; margin-bottom: 1.25rem;">
                    <a href="forgot-password.php" class="forgot-link" style="font-size: 0.85rem;">
                        <i class="fa-regular fa-circle-question"></i> Forgot Password?
                    </a>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.85rem; font-size: 1rem; font-weight: 700;">
                    <i class="fa-solid fa-right-to-bracket"></i> Sign In to Account
                </button>
            </form>


            <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color); text-align: center;">
                <p style="font-size: 0.9rem; color: var(--text-muted);">Don't have an account yet?</p>
                <a href="register.php<?= !empty($redirect) ? '?redirect=' . urlencode($redirect) : '' ?>" class="btn btn-outline btn-sm" style="margin-top: 0.5rem;">
                    <i class="fa-solid fa-user-plus"></i> Register New Guest Account
                </a>
            </div>
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
    </script>

</body>
</html>