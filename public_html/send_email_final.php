<?php
/**
 * send_email_final.php
 * Send email notification using minimal PHPMailer
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>📧 PUSH NOTIFICATION TEST - FINAL</h2>";

// Load config
try {
    require_once 'config/config.php';
    echo "✅ Config loaded<br>";
} catch (Exception $e) {
    die("❌ Config error: " . $e->getMessage());
}

// Load minimal PHPMailer
require_once __DIR__ . '/../vendor_manual/PHPMailer.php';
echo "✅ PHPMailer loaded<br>";

// Get SMTP settings
$mail_host = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
$mail_port = intval(getenv('MAIL_PORT') ?: 587);
$mail_user = getenv('MAIL_USERNAME') ?: 'muhammadarfanulaziz@gmail.com';
$mail_pass = getenv('MAIL_PASSWORD') ?: '';
$mail_from = getenv('MAIL_FROM') ?: 'muhammadarfanulaziz@gmail.com';
$mail_from_name = getenv('MAIL_FROM_NAME') ?: 'SIPB-GPI Notification System';

echo "✅ SMTP config loaded<br>";
echo "├─ Host: $mail_host<br>";
echo "├─ Port: $mail_port<br>";
echo "└─ From: $mail_from_name<br><br>";

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

echo "Found " . count($approvers) . " active approver(s):<br>";
foreach ($approvers as $app) {
    $level = $app['approval_level'] ?? 'N/A';
    echo "├─ {$app['approver_name']} ($level): {$app['email']}<br>";
}
echo "<br>";

// Test data
$sipb_id = 'TEST-001';
$submitter = 'Sheryn Makmur';
$link = "http://localhost/SIPB-GPI/public_html/approval/approve.php?sipb_id=$sipb_id";

echo "═══════════════════════════════════════════════<br>";
echo "📤 SENDING NOTIFICATIONS VIA SMTP<br>";
echo "═══════════════════════════════════════════════<br><br>";

$sent = 0;
$failed = 0;
$errors = [];

foreach ($approvers as $app) {
    $email = $app['email'];
    $name = $app['approver_name'];
    $level = $app['approval_level'] ?? 'General';

    try {
        $mail = new PHPMailer();

        $mail->isSMTP();
        $mail->Host = $mail_host;
        $mail->Port = $mail_port;
        $mail->SMTPAuth = true;
        $mail->Username = $mail_user;
        $mail->Password = $mail_pass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        $mail->setFrom($mail_from, $mail_from_name);
        $mail->addAddress($email, $name);

        $mail->isHTML = false;
        $mail->Subject = "SIPB Approval Required - $sipb_id";
        $mail->Body = <<<EOT
Hello {$name},

A new SIPB submission requires your approval.

Details:
├─ SIPB ID: {$sipb_id}
├─ Submitted by: {$submitter}
├─ Approval Level: {$level}
└─ Date: " . date('Y-m-d H:i:s') . "

Please click the link to review and approve:
{$link}

---
SIPB-GPI Notification System
EOT;

        echo "→ Sending to $email...<br>";

        if ($mail->send()) {
            echo "✅ SUCCESS: $name ($email) [$level]<br>";
            $sent++;
        } else {
            echo "❌ FAILED: $name ($email)<br>";
            echo "   Error: " . $mail->ErrorInfo . "<br>";
            $failed++;
            $errors[] = $email . ": " . $mail->ErrorInfo;
        }

    } catch (Exception $e) {
        echo "❌ EXCEPTION: $name ($email)<br>";
        echo "   Error: " . $e->getMessage() . "<br>";
        $failed++;
        $errors[] = $email . ": " . $e->getMessage();
    }
}

echo "<br>";
echo "═══════════════════════════════════════════════<br>";
echo "📊 SUMMARY<br>";
echo "═══════════════════════════════════════════════<br>";
echo "✅ Sent: <strong>$sent</strong> | ❌ Failed: <strong>$failed</strong><br><br>";

if ($sent > 0) {
    echo "✅ Email(s) sent successfully!<br>";
    echo "📧 Check inboxes:<br>";
    foreach ($approvers as $app) {
        echo "├─ {$app['email']}<br>";
    }
} else {
    echo "❌ No emails sent<br>";
    if (!empty($errors)) {
        echo "<br>Errors:<br>";
        foreach ($errors as $error) {
            echo "├─ $error<br>";
        }
    }
}

if ($sent == count($approvers)) {
    echo "<br>";
    echo "🎉 ALL NOTIFICATIONS SENT SUCCESSFULLY! 🎉<br>";
    echo "Push notification system is working! ✅<br>";
}

echo "<br>";
echo "═══════════════════════════════════════════════<br>";

?>
