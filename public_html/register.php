<?php
/**
 * public_html/register.php
 *
 * User Registration Page
 * Features:
 * - Form validation (server-side)
 * - Password hashing (bcrypt)
 * - CSRF protection
 * - Email verification (future)
 * - Admin approval required
 */

// NOTE: session_start() sengaja tidak dipanggil di sini - lihat config.php
include __DIR__ . '/config/config.php';

// ============================================
// Initialize Variables
// ============================================

$error = '';
$success = '';
$form_data = [
    'nik' => '',
    'name' => '',
    'email' => '',
    'section' => '',
    'whatsapp' => '',
];

// ============================================
// Handle Registration Form Submission
// ============================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Verify CSRF token
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "❌ CSRF token tidak valid.";
    } else {
        // Get & sanitize input
        $nik = trim($_POST['nik'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';
        $section = $_POST['section'] ?? '';
        $whatsapp = trim($_POST['whatsapp'] ?? '');

        // Store for form repopulation
        $form_data = compact('nik', 'name', 'email', 'section', 'whatsapp');

        // ============================================
        // VALIDATION
        // ============================================

        $validation_errors = [];

        // NIK validation
        if (empty($nik)) {
            $validation_errors[] = "NIK harus diisi";
        } elseif (!preg_match('/^[0-9]{4,10}$/', $nik)) {
            $validation_errors[] = "NIK harus berupa angka (4-10 digit)";
        }

        // Name validation
        if (empty($name)) {
            $validation_errors[] = "Nama harus diisi";
        } elseif (strlen($name) < 3) {
            $validation_errors[] = "Nama minimal 3 karakter";
        }

        // Email validation
        if (empty($email)) {
            $validation_errors[] = "Email harus diisi";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $validation_errors[] = "Format email tidak valid";
        }

        // Section validation
        $valid_sections = ['R&D', 'QC', 'Warehouse', 'Production', 'PPIC', 'HR', 'Admin'];
        if (empty($section) || !in_array($section, $valid_sections)) {
            $validation_errors[] = "Departemen harus dipilih";
        }

        // WhatsApp validation
        if (empty($whatsapp)) {
            $validation_errors[] = "Nomor WhatsApp harus diisi";
        } elseif (!preg_match('/^\+62[0-9]{9,12}$/', $whatsapp)) {
            $validation_errors[] = "Format WhatsApp: +62XXXXXXXXXX";
        }

        // Password validation
        if (empty($password)) {
            $validation_errors[] = "Password harus diisi";
        } elseif (strlen($password) < 8) {
            $validation_errors[] = "Password minimal 8 karakter";
        } elseif ($password !== $password_confirm) {
            $validation_errors[] = "Password tidak cocok";
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $password)) {
            $validation_errors[] = "Password harus kombinasi: huruf besar, huruf kecil, angka";
        }

        // If validation errors, display them
        if (!empty($validation_errors)) {
            $error = "<strong>Kesalahan Validasi:</strong><br>" . implode("<br>", $validation_errors);
        } else {
            // ============================================
            // CHECK IF NIK/EMAIL ALREADY EXISTS
            // ============================================

            $db = new Database($conn);

            // Check NIK
            $nik_exists = $db->getScalar("SELECT id FROM users WHERE nik = ?", [$nik]);
            if ($nik_exists) {
                $error = "❌ NIK sudah terdaftar. Gunakan NIK lain.";
            } else {
                // Check Email
                $email_exists = $db->getScalar("SELECT id FROM users WHERE email = ?", [$email]);
                if ($email_exists) {
                    $error = "❌ Email sudah terdaftar. Gunakan email lain.";
                } else {
                    // ============================================
                    // CREATE NEW USER
                    // ============================================

                    $password_hash = password_hash($password, PASSWORD_BCRYPT);
                    $is_active = 1;
                    $is_approved = 0; // Require admin approval
                    $role = 'user'; // Default role

                    try {
                        $insert_result = $db->execute(
                            "INSERT INTO users (nik, name, email, password_hash, role, section, whatsapp, is_active, is_approved)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [$nik, $name, $email, $password_hash, $role, $section, $whatsapp, $is_active, $is_approved]
                        );

                        if ($insert_result > 0) {
                            $new_user_id = $db->getConnection()->insert_id;

                            // Log registration
                            log_audit($conn, 0, 'user_registration', "Registrasi user baru: $name ($nik)", null, [
                                'user_id' => $new_user_id,
                                'email' => $email,
                                'section' => $section
                            ]);

                            // Show success message
                            $success = "✅ Registrasi berhasil! Akun Anda akan diaktifkan setelah disetujui admin. Silakan tunggu email konfirmasi.";

                            // Clear form data
                            $form_data = [
                                'nik' => '',
                                'name' => '',
                                'email' => '',
                                'section' => '',
                                'whatsapp' => '',
                            ];
                        } else {
                            $error = "❌ Terjadi kesalahan saat membuat akun. Silakan coba lagi.";
                        }
                    } catch (Exception $e) {
                        $error = "❌ Kesalahan database: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - SIPB-GPI</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        body {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .register-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            max-width: 600px;
            width: 100%;
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

        .register-header {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            padding: 40px 30px;
            text-align: center;
            color: white;
        }

        .register-header h1 {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .register-header p {
            opacity: 0.95;
            margin: 0;
        }

        .register-form {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            color: #2c3e50;
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 8px;
            display: block;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ecf0f1;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-group input::placeholder {
            color: #bdc3c7;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .password-strength {
            margin-top: 8px;
            height: 4px;
            background: #ecf0f1;
            border-radius: 2px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0;
            background: #e74c3c;
            transition: all 0.3s;
        }

        .password-strength-bar.weak {
            width: 33%;
            background: #e74c3c;
        }

        .password-strength-bar.medium {
            width: 66%;
            background: #f39c12;
        }

        .password-strength-bar.strong {
            width: 100%;
            background: #27ae60;
        }

        .password-requirements {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 10px;
            line-height: 1.6;
        }

        .requirement {
            margin: 5px 0;
        }

        .requirement i {
            margin-right: 5px;
            color: #95a5a6;
        }

        .requirement.met i {
            color: #27ae60;
        }

        .btn-register {
            width: 100%;
            padding: 12px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 20px;
        }

        .btn-register:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }

        .btn-register:disabled {
            background: #95a5a6;
            cursor: not-allowed;
            transform: none;
        }

        .back-to-login {
            text-align: center;
            margin-top: 20px;
        }

        .back-to-login a {
            color: #3498db;
            text-decoration: none;
            font-size: 13px;
        }

        .back-to-login a:hover {
            text-decoration: underline;
        }

        .alert-message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
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
    </style>
</head>
<body>
    <div class="register-container">
        <!-- HEADER -->
        <div class="register-header">
            <h1><i class="fas fa-box-open"></i> SIPB-GPI</h1>
            <p>Daftar Akun Baru</p>
        </div>

        <!-- FORM -->
        <div class="register-form">
            <!-- Error message -->
            <?php if ($error): ?>
                <div class="alert-message alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Success message -->
            <?php if ($success): ?>
                <div class="alert-message alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success; ?>
                </div>
                <div class="back-to-login">
                    <a href="login.php">← Kembali ke Login</a>
                </div>
            <?php else: ?>

                <form method="POST" action="" onsubmit="return validateForm()">
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <!-- NIK & Name -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="nik">NIK / Employee ID *</label>
                            <input type="text" id="nik" name="nik" value="<?php echo htmlspecialchars($form_data['nik']); ?>" placeholder="ex: 82086" required>
                        </div>
                        <div class="form-group">
                            <label for="name">Nama Lengkap *</label>
                            <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($form_data['name']); ?>" placeholder="ex: M Arfanul Aziz" required>
                        </div>
                    </div>

                    <!-- Email -->
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($form_data['email']); ?>" placeholder="ex: aziz@salimagro.com" required>
                    </div>

                    <!-- Section & WhatsApp -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="section">Departemen *</label>
                            <select id="section" name="section" required>
                                <option value="">-- Pilih Departemen --</option>
                                <option value="R&D" <?php echo $form_data['section'] === 'R&D' ? 'selected' : ''; ?>>R&D</option>
                                <option value="QC" <?php echo $form_data['section'] === 'QC' ? 'selected' : ''; ?>>QC</option>
                                <option value="Warehouse" <?php echo $form_data['section'] === 'Warehouse' ? 'selected' : ''; ?>>Warehouse</option>
                                <option value="Production" <?php echo $form_data['section'] === 'Production' ? 'selected' : ''; ?>>Production</option>
                                <option value="PPIC" <?php echo $form_data['section'] === 'PPIC' ? 'selected' : ''; ?>>PPIC</option>
                                <option value="HR" <?php echo $form_data['section'] === 'HR' ? 'selected' : ''; ?>>HR</option>
                                <option value="Admin" <?php echo $form_data['section'] === 'Admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="whatsapp">WhatsApp *</label>
                            <input type="text" id="whatsapp" name="whatsapp" value="<?php echo htmlspecialchars($form_data['whatsapp']); ?>" placeholder="+62812345678" required>
                        </div>
                    </div>

                    <!-- Password -->
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password" placeholder="Minimal 8 karakter" required onkeyup="checkPasswordStrength()">
                        <div class="password-strength">
                            <div class="password-strength-bar" id="passwordStrengthBar"></div>
                        </div>
                        <div class="password-requirements">
                            <div class="requirement" id="req-length">
                                <i class="fas fa-times"></i> Minimal 8 karakter
                            </div>
                            <div class="requirement" id="req-upper">
                                <i class="fas fa-times"></i> Minimal 1 huruf besar (A-Z)
                            </div>
                            <div class="requirement" id="req-lower">
                                <i class="fas fa-times"></i> Minimal 1 huruf kecil (a-z)
                            </div>
                            <div class="requirement" id="req-number">
                                <i class="fas fa-times"></i> Minimal 1 angka (0-9)
                            </div>
                        </div>
                    </div>

                    <!-- Password Confirm -->
                    <div class="form-group">
                        <label for="password_confirm">Konfirmasi Password *</label>
                        <input type="password" id="password_confirm" name="password_confirm" placeholder="Ulangi password" required>
                    </div>

                    <!-- Submit -->
                    <button type="submit" class="btn-register" id="submitBtn">
                        <i class="fas fa-user-plus"></i> Daftar Akun
                    </button>
                </form>

                <!-- Back to Login -->
                <div class="back-to-login">
                    Sudah punya akun? <a href="login.php">Login di sini</a>
                </div>

            <?php endif; ?>
        </div>
    </div>

    <script>
        function checkPasswordStrength() {
            const password = document.getElementById('password').value;
            const bar = document.getElementById('passwordStrengthBar');

            let strength = 0;
            const requirements = {
                length: password.length >= 8,
                upper: /[A-Z]/.test(password),
                lower: /[a-z]/.test(password),
                number: /\d/.test(password)
            };

            // Update requirements display
            Object.keys(requirements).forEach(req => {
                const element = document.getElementById('req-' + req);
                if (requirements[req]) {
                    element.classList.add('met');
                    element.querySelector('i').className = 'fas fa-check';
                    strength++;
                } else {
                    element.classList.remove('met');
                    element.querySelector('i').className = 'fas fa-times';
                }
            });

            // Update strength bar
            bar.className = 'password-strength-bar';
            if (strength === 1) {
                bar.classList.add('weak');
            } else if (strength === 2 || strength === 3) {
                bar.classList.add('medium');
            } else if (strength === 4) {
                bar.classList.add('strong');
            }
        }

        function validateForm() {
            const password = document.getElementById('password').value;
            const passwordConfirm = document.getElementById('password_confirm').value;

            if (password !== passwordConfirm) {
                alert('❌ Password tidak cocok!');
                return false;
            }

            if (password.length < 8) {
                alert('❌ Password minimal 8 karakter!');
                return false;
            }

            if (!/[A-Z]/.test(password) || !/[a-z]/.test(password) || !/\d/.test(password)) {
                alert('❌ Password harus kombinasi huruf besar, huruf kecil, dan angka!');
                return false;
            }

            return true;
        }

        // Check password on page load
        window.addEventListener('load', checkPasswordStrength);
    </script>

</body>
</html>
