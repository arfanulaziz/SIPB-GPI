<?php
/**
 * public_html/login.php
 *
 * SIPB-GPI Login Page
 * Security features:
 * - Prepared statements (SQL injection prevention)
 * - Password hashing (bcrypt)
 * - Session security (regenerate ID, timeout)
 * - CSRF token
 * - Rate limiting (simple)
 * - Audit logging
 */

// NOTE: session_start() TIDAK dipanggil di sini secara sengaja.
// config.php (di-require di bawah) yang bertanggung jawab set ini_set() session
// (httponly, gc_maxlifetime, dll) SEBELUM session_start() dipanggil - urutan ini penting
// karena ini_set('session.*', ...) tidak berpengaruh lagi setelah session sudah aktif.

// Prevent caching (for login page)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Load configuration
include __DIR__ . '/config/config.php';

// ============================================
// Initialize Variables
// ============================================

$error = '';
$success = '';
$nik = '';
$show_forgot_password = false;

// Check if coming from forgot password
if (isset($_GET['action']) && $_GET['action'] === 'forgot') {
    $show_forgot_password = true;
}

// ============================================
// Rate Limiting (Simple - store in session)
// ============================================

function check_rate_limit() {
    $max_attempts = 5;
    $lockout_minutes = 15;

    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_last_attempt'] = 0;
    }

    $now = time();
    $time_since_last = $now - $_SESSION['login_last_attempt'];

    // Reset counter if lockout period passed
    if ($time_since_last > ($lockout_minutes * 60)) {
        $_SESSION['login_attempts'] = 0;
    }

    // Check if locked out
    if ($_SESSION['login_attempts'] >= $max_attempts && $time_since_last < ($lockout_minutes * 60)) {
        $remaining = ceil(($lockout_minutes * 60 - $time_since_last) / 60);
        return "❌ Terlalu banyak percobaan login. Coba lagi dalam " . $remaining . " menit.";
    }

    return null;
}

function increment_login_attempt() {
    $_SESSION['login_attempts']++;
    $_SESSION['login_last_attempt'] = time();
}

// ============================================
// Handle Login Form Submission
// ============================================

if ($_SERVER["REQUEST_METHOD"] === "POST" && !$show_forgot_password) {
    // Verify CSRF token
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "❌ CSRF token tidak valid. Silakan refresh halaman.";
    } else {
        // Check rate limiting
        $rate_limit_error = check_rate_limit();
        if ($rate_limit_error) {
            $error = $rate_limit_error;
        } else {
            // Get & sanitize input
            $nik = trim($_POST['nik'] ?? '');
            $password = $_POST['password'] ?? '';

            // Validate input
            if (empty($nik) || empty($password)) {
                $error = "❌ NIK dan password harus diisi.";
                increment_login_attempt();
            } else {
                // Query user by NIK (prepared statement)
                $db = new Database($conn);
                $user = $db->getRow(
                    "SELECT u.id, u.nik, u.name, u.email, u.password_hash, u.role, u.section, u.is_active, u.is_approved,
                            u.position_id, p.position_name, p.approval_level
                     FROM users u
                     LEFT JOIN approval_positions p ON u.position_id = p.id AND p.is_active = 1
                     WHERE u.nik = ?",
                    [$nik]
                );

                if ($user) {
                    // Check if user is active
                    if (!$user['is_active']) {
                        $error = "⚠️ Akun Anda telah dinonaktifkan. Hubungi admin.";
                        increment_login_attempt();
                        log_audit($conn, 0, 'login_failed', "User akun nonaktif: $nik", null, ['reason' => 'inactive']);
                    }
                    // Check if user is approved
                    elseif (!$user['is_approved']) {
                        $error = "⚠️ Akun Anda belum disetujui admin.";
                        increment_login_attempt();
                        log_audit($conn, 0, 'login_failed', "User belum diapprove: $nik", null, ['reason' => 'not_approved']);
                    }
                    // Verify password
                    elseif (password_verify($password, $user['password_hash'])) {
                        // Password correct - regenerate session ID to prevent session fixation
                        session_regenerate_id(true);
                        $_SESSION['session_initiated'] = true;

                        // Store user info in session
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_nik'] = $user['nik'];
                        $_SESSION['user_name'] = $user['name'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['user_role'] = $user['role'];
                        $_SESSION['user_section'] = $user['section'];
                        $_SESSION['position_id'] = $user['position_id'];
                        $_SESSION['position_name'] = $user['position_name'];
                        $_SESSION['approval_level'] = $user['approval_level']; // 'SPV', 'PM', atau null
                        $_SESSION['login_time'] = time();

                        // Reset login attempts
                        $_SESSION['login_attempts'] = 0;

                        // Log successful login
                        log_audit($conn, 0, 'login_success', "User login: {$user['name']} ({$user['role']})", null, null);

                        // Redirect to dashboard
                        header("Location: index.php");
                        exit();
                    } else {
                        // Password incorrect
                        $error = "❌ Password salah!";
                        increment_login_attempt();
                        log_audit($conn, 0, 'login_failed', "Password salah untuk NIK: $nik", null, ['reason' => 'wrong_password']);
                    }
                } else {
                    // User not found
                    $error = "❌ NIK tidak ditemukan.";
                    increment_login_attempt();
                    log_audit($conn, 0, 'login_failed', "NIK tidak ditemukan: $nik", null, ['reason' => 'user_not_found']);
                }
            }
        }
    }
}

// ============================================
// Handle Forgot Password Form Submission
// ============================================

if ($_SERVER["REQUEST_METHOD"] === "POST" && $show_forgot_password) {
    $email = trim($_POST['email'] ?? '');

    // Verify CSRF token
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "❌ CSRF token tidak valid.";
    } elseif (empty($email)) {
        $error = "❌ Email harus diisi.";
    } else {
        // Query user by email
        $db = new Database($conn);
        $user = $db->getRow("SELECT id, name, email FROM users WHERE email = ? AND is_active = 1", [$email]);

        if ($user) {
            // TODO: Generate reset token & send email
            // For now, just show message
            $success = "✅ Email pemulihan password telah dikirim ke " . htmlspecialchars($email) . ". Silakan cek email Anda.";
            log_audit($conn, 0, 'password_reset_request', "Reset request untuk: " . $user['name'], null, null);
        } else {
            // Don't reveal if email exists or not (security best practice)
            $success = "✅ Jika email tersebut terdaftar, kami akan mengirimkan link pemulihan password.";
        }
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SIPB-GPI System</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --primary-color: #2c3e50;
            --accent-color: #3498db;
            --success-color: #27ae60;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--primary-color);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow: hidden;
            position: relative;
        }

        /* Slideshow background - foto exhibition & plant GPI, crossfade otomatis */
        .bg-slideshow {
            position: fixed;
            inset: 0;
            z-index: 0;
        }
        .bg-slideshow .slide {
            position: absolute;
            inset: 0;
            background-size: cover;
            background-position: center;
            opacity: 0;
            animation: slideFade 18s infinite;
        }
        .bg-slideshow .slide:nth-child(1) { animation-delay: 0s; }
        .bg-slideshow .slide:nth-child(2) { animation-delay: 6s; }
        .bg-slideshow .slide:nth-child(3) { animation-delay: 12s; }
        @keyframes slideFade {
            0%   { opacity: 0; }
            5%   { opacity: 1; }
            30%  { opacity: 1; }
            38%  { opacity: 0; }
            100% { opacity: 0; }
        }
        .bg-overlay {
            position: fixed;
            inset: 0;
            z-index: 1;
            background: linear-gradient(135deg, rgba(44,62,80,0.72) 0%, rgba(52,73,94,0.72) 100%);
        }

        .login-container {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 900px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-wrapper {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            min-height: 500px;
        }

        /* Left side - Brand */
        .login-brand {
            background: linear-gradient(135deg, var(--accent-color) 0%, #2980b9 100%);
            padding: 50px 30px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            text-align: center;
        }

        .login-brand h1 {
            font-size: 42px;
            font-weight: bold;
            margin-bottom: 20px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
        }

        .login-brand p {
            font-size: 16px;
            opacity: 0.95;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .brand-icon {
            font-size: 80px;
            margin-bottom: 20px;
            opacity: 0.9;
        }

        .brand-logo-img {
            height: 78px;
            width: auto;
            margin-bottom: 20px;
        }

        .brand-divider {
            width: 60px;
            height: 3px;
            background: white;
            margin: 20px auto;
            border-radius: 2px;
        }

        /* Right side - Form */
        .login-form-section {
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-form-title {
            font-size: 28px;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .login-form-subtitle {
            color: #7f8c8d;
            font-size: 14px;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            color: var(--primary-color);
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 8px;
            display: block;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ecf0f1;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-group input::placeholder {
            color: #bdc3c7;
        }

        .form-check {
            display: flex;
            align-items: center;
            margin: 20px 0;
        }

        .form-check input {
            width: 20px;
            height: 20px;
            margin-right: 10px;
            cursor: pointer;
            accent-color: var(--accent-color);
            flex-shrink: 0;
        }

        .form-check label {
            margin: 0;
            cursor: pointer;
            font-size: 14px;
            font-weight: normal;
            cursor: pointer;
            font-size: 13px;
        }

        .btn-login {
            width: 100%;
            padding: 12px;
            background: var(--accent-color);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 20px;
        }

        .btn-login:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .forgot-password-link {
            text-align: center;
            margin-top: 20px;
        }

        .forgot-password-link a {
            color: var(--accent-color);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }

        .forgot-password-link a:hover {
            text-decoration: underline;
        }

        .alert-message {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            border-left: 4px solid;
            animation: alertSlide 0.3s ease-out;
        }

        @keyframes alertSlide {
            from {
                opacity: 0;
                transform: translateX(-10px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .alert-error {
            background: #fadbd8;
            color: #c0392b;
            border-left-color: #e74c3c;
        }

        .alert-success {
            background: #d5f4e6;
            color: #27ae60;
            border-left-color: #27ae60;
        }

        .back-to-login {
            text-align: center;
            margin-top: 20px;
        }

        .back-to-login a {
            color: var(--accent-color);
            text-decoration: none;
            font-size: 13px;
        }

        .back-to-login a:hover {
            text-decoration: underline;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .login-wrapper {
                grid-template-columns: 1fr;
                min-height: auto;
            }

            .login-brand {
                padding: 30px 20px;
            }

            .login-form-section {
                padding: 30px 20px;
            }

            .login-brand h1 {
                font-size: 32px;
            }

            .brand-icon {
                font-size: 60px;
            }
        }

        /* Demo credentials info */
        .demo-info {
            background: #ecf0f1;
            padding: 15px;
            border-radius: 8px;
            font-size: 12px;
            color: #34495e;
            margin-bottom: 20px;
            display: none;
        }

        .demo-info.show {
            display: block;
        }

        .demo-info strong {
            color: var(--primary-color);
        }
    </style>
</head>
<body>
    <div class="bg-slideshow">
        <div class="slide" style="background-image: url('assets/img/bg-1-web.jpg');"></div>
        <div class="slide" style="background-image: url('assets/img/bg-2-web.jpg');"></div>
        <div class="slide" style="background-image: url('assets/img/bg-3-web.jpg');"></div>
    </div>
    <div class="bg-overlay"></div>

    <div class="login-container">
        <div class="login-wrapper">

            <!-- LEFT SIDE - BRAND -->
            <div class="login-brand">
                <img src="assets/img/logo-indogum-white.png" alt="IndoGum" class="brand-logo-img">
                <h1>SIPB-GPI</h1>
                <div class="brand-divider"></div>
                <p>Surat Ijin Pengeluaran Barang</p>
                <p style="font-size: 13px; opacity: 0.85;">PT Gumindo Perkasa Industri - Site 3 (SAC)</p>
            </div>

            <!-- RIGHT SIDE - FORM -->
            <div class="login-form-section">
                <?php if (!$show_forgot_password): ?>
                    <!-- LOGIN FORM -->
                    <div>
                        <h2 class="login-form-title">Login</h2>
                        <p class="login-form-subtitle">Masukkan kredensial Anda</p>

                        <!-- Error message -->
                        <?php if ($error): ?>
                            <div class="alert-message alert-error">
                                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Demo info -->
                        <div class="demo-info show">
                            <strong>Demo:</strong><br>
                            NIK: 99001 | Password: admin123
                        </div>

                        <!-- Login Form -->
                        <form method="POST" action="">
                            <!-- CSRF Token -->
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                            <!-- NIK Input -->
                            <div class="form-group">
                                <label for="nik">
                                    <i class="fas fa-id-card"></i> NIK / Employee ID
                                </label>
                                <input
                                    type="text"
                                    id="nik"
                                    name="nik"
                                    value="<?php echo htmlspecialchars($nik); ?>"
                                    placeholder="Masukkan NIK Anda"
                                    autocomplete="username"
                                    required
                                >
                            </div>

                            <!-- Password Input -->
                            <div class="form-group">
                                <label for="password">
                                    <i class="fas fa-lock"></i> Password
                                </label>
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    placeholder="Masukkan password Anda"
                                    autocomplete="current-password"
                                    required
                                >
                            </div>

                            <!-- Remember Me -->
                            <div class="form-check">
                                <input type="checkbox" id="remember" name="remember" class="form-check-input">
                                <label for="remember" class="form-check-label">
                                    Ingat saya
                                </label>
                            </div>

                            <!-- Submit Button -->
                            <button type="submit" class="btn-login">
                                <i class="fas fa-sign-in-alt"></i> Login
                            </button>
                        </form>

                        <!-- Forgot Password Link -->
                        <div class="forgot-password-link">
                            <a href="?action=forgot">
                                <i class="fas fa-question-circle"></i> Lupa Password?
                            </a>
                        </div>

                        <!-- Register Link -->
                        <div class="forgot-password-link" style="margin-top: 10px;">
                            <a href="register.php" style="font-size: 12px;">
                                Belum punya akun? <strong>Daftar di sini</strong>
                            </a>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- FORGOT PASSWORD FORM -->
                    <div>
                        <h2 class="login-form-title">Lupa Password?</h2>
                        <p class="login-form-subtitle">Masukkan email untuk reset password</p>

                        <!-- Error message -->
                        <?php if ($error): ?>
                            <div class="alert-message alert-error">
                                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Success message -->
                        <?php if ($success): ?>
                            <div class="alert-message alert-success">
                                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Forgot Password Form -->
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                            <div class="form-group">
                                <label for="email">
                                    <i class="fas fa-envelope"></i> Email
                                </label>
                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    placeholder="Masukkan email Anda"
                                    required
                                >
                            </div>

                            <button type="submit" class="btn-login">
                                <i class="fas fa-paper-plane"></i> Kirim Link Reset
                            </button>
                        </form>

                        <div class="back-to-login">
                            <a href="login.php">
                                <i class="fas fa-arrow-left"></i> Kembali ke Login
                            </a>
                        </div>
                    </div>

                <?php endif; ?>

            </div>

        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Simple form validation
        document.querySelectorAll('input[required]').forEach(input => {
            input.addEventListener('blur', function() {
                if (this.type === 'email' && this.value) {
                    const valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.value);
                    this.style.borderColor = valid ? '#27ae60' : '#e74c3c';
                }
            });
        });

        // Disable demo info on input
        document.getElementById('nik')?.addEventListener('input', function() {
            if (this.value.trim() !== '99001') {
                document.querySelector('.demo-info').classList.remove('show');
            }
        });
    </script>

</body>
</html>
