<?php
/**
 * public_html/profile.php
 *
 * User profile page: view account details & change password
 * Access: All authenticated users (user, approver, superadmin)
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/Database.php';

$current_user = $_SESSION['user'] ?? [];
$user_id = $_SESSION['user_id'] ?? null;

// Redirect if not logged in
if (!$user_id) {
    header('Location: login.php');
    exit;
}

// Load user from session or database
if (empty($current_user)) {
    global $conn;
    $stmt = $conn->prepare("SELECT id, nik, name, email, role, section, whatsapp FROM users WHERE id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $current_user = $result->fetch_assoc() ?? [];
    if (!empty($current_user)) {
        $_SESSION['user'] = $current_user;
    }
}

if (empty($current_user)) {
    die("❌ User data not found.");
}

// ===========================
// Handle password change
// ===========================
$password_error = '';
$password_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    // CSRF check
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $password_error = "❌ CSRF validation failed";
    } else {
        $current_pwd = $_POST['current_password'] ?? '';
        $new_pwd = $_POST['new_password'] ?? '';
        $confirm_pwd = $_POST['confirm_password'] ?? '';

        // Validate inputs
        if (empty($current_pwd) || empty($new_pwd) || empty($confirm_pwd)) {
            $password_error = "❌ All password fields required";
        } elseif (strlen($new_pwd) < 8) {
            $password_error = "❌ New password must be at least 8 characters";
        } elseif ($new_pwd !== $confirm_pwd) {
            $password_error = "❌ New passwords do not match";
        } elseif ($new_pwd === $current_pwd) {
            $password_error = "❌ New password cannot be same as current password";
        } else {
            // Verify current password
            global $conn;
            $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_data = $result->fetch_assoc();

            if (!$user_data || !password_verify($current_pwd, $user_data['password_hash'])) {
                $password_error = "❌ Current password is incorrect";
            } else {
                // Hash new password
                $new_hash = password_hash($new_pwd, PASSWORD_BCRYPT);

                // Update database
                $update = $conn->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
                $update->bind_param('si', $new_hash, $user_id);

                if ($update->execute()) {
                    // Audit log
                    log_audit($conn, $user_id, 'password_changed', "User changed their own password", null, null);
                    $password_success = "✅ Password changed successfully";
                    // Clear password fields
                    $_POST = [];
                } else {
                    $password_error = "❌ Database error: " . $conn->error;
                }
            }
        }
    }
}

// Regenerate CSRF for form
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - SIPB-GPI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f5f5f5; }
        .profile-card { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); padding: 30px; margin-bottom: 20px; }
        .profile-header { border-bottom: 2px solid #0d6efd; padding-bottom: 20px; margin-bottom: 20px; }
        .profile-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
        .profile-label { font-weight: 600; color: #666; min-width: 150px; }
        .profile-value { color: #333; }
        .badge-role { font-size: 12px; padding: 4px 8px; }
    </style>
</head>
<body>
<div class="container mt-5 mb-5">
    <div class="row">
        <div class="col-lg-8 offset-lg-2">
            <!-- Back button -->
            <a href="index.php" class="btn btn-secondary btn-sm mb-3"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

            <!-- Profile Card -->
            <div class="profile-card">
                <div class="profile-header">
                    <h2><i class="fas fa-user-circle"></i> My Profile</h2>
                </div>

                <div class="profile-row">
                    <span class="profile-label">NIK</span>
                    <span class="profile-value"><strong><?php echo htmlspecialchars($current_user['nik']); ?></strong></span>
                </div>

                <div class="profile-row">
                    <span class="profile-label">Name</span>
                    <span class="profile-value"><?php echo htmlspecialchars($current_user['name']); ?></span>
                </div>

                <div class="profile-row">
                    <span class="profile-label">Email</span>
                    <span class="profile-value"><?php echo htmlspecialchars($current_user['email']); ?></span>
                </div>

                <div class="profile-row">
                    <span class="profile-label">Role</span>
                    <span class="profile-value">
                        <span class="badge badge-role <?php
                            echo $current_user['role'] === 'superadmin' ? 'bg-danger' :
                                ($current_user['role'] === 'approver' ? 'bg-warning text-dark' : 'bg-info');
                        ?>">
                            <?php echo ucfirst($current_user['role']); ?>
                        </span>
                    </span>
                </div>

                <div class="profile-row">
                    <span class="profile-label">Section</span>
                    <span class="profile-value"><?php echo htmlspecialchars($current_user['section'] ?? '-'); ?></span>
                </div>

                <div class="profile-row">
                    <span class="profile-label">WhatsApp</span>
                    <span class="profile-value"><?php echo htmlspecialchars($current_user['whatsapp'] ?? '-'); ?></span>
                </div>
            </div>

            <!-- Change Password Card -->
            <div class="profile-card">
                <div class="profile-header">
                    <h3><i class="fas fa-key"></i> Change Password</h3>
                </div>

                <?php if (!empty($password_error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($password_error); ?></div>
                <?php endif; ?>

                <?php if (!empty($password_success)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($password_success); ?></div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div class="mb-3">
                        <label class="form-label">Current Password <span class="text-danger">*</span></label>
                        <input type="password" name="current_password" class="form-control" required
                               placeholder="Enter current password">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">New Password <span class="text-danger">*</span></label>
                        <input type="password" name="new_password" class="form-control" required
                               placeholder="Enter new password (min. 8 characters)">
                        <small class="text-muted">Minimum 8 characters</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                        <input type="password" name="confirm_password" class="form-control" required
                               placeholder="Confirm new password">
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-lock"></i> Change Password
                    </button>
                </form>

                <hr>
                <small class="text-muted">
                    <i class="fas fa-info-circle"></i>
                    Password changes are logged for security audit purposes.
                </small>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>