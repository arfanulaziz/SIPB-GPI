<?php
/**
 * send_notification_v2.php
 * Send email via Gmail SMTP with better error handling
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>📧 PUSH NOTIFICATION TEST - v2</h2>";

// Load config
try {
    require_once 'config/config.php';
    echo "✅ Config loaded<br>";
} catch (Exception $e) {
    die("❌ Config error: " . $e->getMessage());
}

// Get SMTP settings
$mail_host = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
$mail_port = intval(getenv('MAIL_PORT') ?: 587);
$mail_user = getenv('MAIL_USERNAME') ?: 'muhammadarfanulaziz@gmail.com';
$mail_pass = getenv('MAIL_PASSWORD') ?: '';
$mail_from = getenv('MAIL_FROM') ?: 'muhammadarfanulaziz@gmail.com';
$mail_from_name = getenv('MAIL_FROM_NAME') ?: 'SIPB-GPI Notification System';

echo "✅ SMTP loaded: $mail_host:$mail_port<br><br>";

// Query approvers
$query = "SELECT u.id, u.name as approver_name, u.email, ap.approval_level
          FROM users u
          LEFT JOIN approval_positions ap ON u.position_id = ap.id
          WHERE u.role = 'approver' AND u.is_active = 1 AND u.is_approved = 1
          ORDER BY ap.approval_level, u.name";

$result = $conn->query($query);
if (!$result) die("❌ DB error: " . $conn->error);

$approvers = [];
while ($row = $result->fetch_assoc()) {
    $approvers[] = $row;
}

if (empty($approvers)) {
    die("⚠️ No approvers found");
}

echo "Found " . count($approvers) . " approver(s)<br>";
foreach ($approvers as $app) {
    echo "├─ {$app['approver_name']}: {$app['email']}<br>";
}
echo "<br>";

// Test data
$sipb_id = 'TEST-001';
$submitter = 'Sheryn Makmur';
$link = "http://localhost/SIPB-GPI/public_html/approval/approve.php?sipb_id=$sipb_id";

echo "═══════════════════════════════════════════════<br>";
echo "📤 SENDING EMAILS<br>";
echo "═══════════════════════════════════════════════<br><br>";

$sent = 0;
$failed = 0;

foreach ($approvers as $app) {
    $email = $app['email'];
    $name = $app['approver_name'];
    $level = $app['approval_level'] ?? 'General';

    $subject = "SIPB Approval Required - $sipb_id";
    $body = "Hello $name,\n\nA new SIPB submission ($sipb_id) from $submitter requires your approval.\n\nApprove here: $link\n\nThanks,\nSIPB System";

    // Use native mail() with SMTP relay
    $headers = "From: $mail_from_name <$mail_from>\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    echo "→ Sending to $email...<br>";

    if (mail($email, $subject, $body, $headers)) {
        echo "✅ OK: $name ($email)<br>";
        $sent++;
    } else {
        echo "❌ FAILED: $name ($email)<br>";
        $failed++;
    }

    // Small delay between sends
    usleep(500000);
}

echo "<br>";
echo "═══════════════════════════════════════════════<br>";
echo "📊 SUMMARY<br>";
echo "═══════════════════════════════════════════════<br>";
echo "Sent: <strong>$sent</strong> | Failed: <strong>$failed</strong><br><br>";

if ($sent > 0) {
    echo "✅ Email(s) sent! Check inboxes:<br>";
    foreach ($approvers as $app) {
        echo "├─ {$app['email']}<br>";
    }
} else {
    echo "❌ No emails sent. SMTP may not be configured properly.<br>";
    echo "To fix: Install PHPMailer or configure sendmail/postfix<br>";
}

echo "<br>";
echo "═══════════════════════════════════════════════<br>";

// Also show PHP mail configuration
echo "<br>";
echo "🔧 PHP MAIL CONFIGURATION<br>";
echo "═══════════════════════════════════════════════<br>";
echo "SMTP: " . ini_get('SMTP') . "<br>";
echo "smtp_port: " . ini_get('smtp_port') . "<br>";
echo "sendmail_from: " . ini_get('sendmail_from') . "<br>";
echo "sendmail_path: " . ini_get('sendmail_path') . "<br>";

?>
