<?php
/**
 * public_html/logout.php
 *
 * Logout page - destroy session and redirect to login
 * Logs logout activity to audit trail
 */

// NOTE: session_start() sengaja tidak dipanggil di sini - lihat config.php
// Load config
include __DIR__ . '/config/config.php';

// Get user info before destroying session
$user_id = $_SESSION['user_id'] ?? null;
$user_name = $_SESSION['user_name'] ?? 'Unknown';

// Log logout activity
if ($user_id) {
    log_audit($conn, 0, 'logout', "User logout: $user_name", null, null);
}

// Destroy all session data
session_unset();
session_destroy();

// Clear session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Redirect to login
header("Location: login.php?msg=logout_success");
exit();
?>
