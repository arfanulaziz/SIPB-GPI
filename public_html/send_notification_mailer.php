<?php
/**
 * send_notification_mailer.php
 * Send email notification ke approvers menggunakan PHPMailer
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>📧 PUSH NOTIFICATION TEST - PHPMailer</h2>";

// Load config
try {
    require_once 'config/config.php';
    echo "✅ Config loaded<br>";
} catch (Exception $e) {
    die("❌ Config error: " . $e->getMessage());
}

// Load PHPMailer
try {
    require_once __DIR__ . '/../vendor/autoload.php';
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;
    echo "✅ PHPMailer loaded<br>";
} catch (Exception $e) {
    die("❌ PHPMailer error: " . $e->getMessage());
}

// Get SMTP settings from .env
$mail_host = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
$mail_port = getenv('MAIL_PORT') ?: 587;
$mail_user = getenv('MAIL_USERNAME') ?: 'muhammadarfanulaziz@gmail.com';
$mail_pass = getenv('MAIL_PASSWORD') ?: '';
$mail_from = getenv('MAIL_FROM') ?: 'muhammadarfanulaziz@gmail.com';
$mail_from_name = getenv('MAIL_FROM_NAME') ?: 'SIPB-GPI Notification System';

echo "✅ SMTP Settings loaded<br>";
echo "├─ Host: $mail_host<br>";
echo "├─ Port: $mail_port<br>";
echo "├─ User: $mail_user<br>";
echo "└─ From: $mail_from_name<br><br>";

// Query approvers
$query = "SELECT u.id, u.name as approver_name, u.email, u.role, ap.approval_level
          FROM users u
          LEFT JOIN approval_positions ap ON u.position_id = ap.id
          WHERE u.role = 'approver' AND u.is_active = 1 AND u.is_approved = 1
          ORDER BY ap.approval_level, u.name";

$result = $conn->query($query);

if (!$result) {
    die("❌ Database error: " . $conn->error);
}

$approvers = [];
while ($row = $result->fetch_assoc()) {
    $approvers[] = $row;
}

if (empty($approvers)) {
    echo "⚠️ No active approvers found in database<br>";
    die();
}

echo "Found " . count($approvers) . " active approver(s):<br>";
foreach ($approvers as $approver) {
    $level = $approver['approval_level'] ?? 'N/A';
    echo "├─ " . $approver['approver_name'] . " ($level): " . $approver['email'] . "<br>";
}
echo "<br>";

// Test data
$sipb_id = 'TEST-001';
$submitter_name = 'Sheryn Makmur';
$approval_link = "http://localhost/SIPB-GPI/public_html/approval/approve.php?sipb_id=$sipb_id";

echo "═══════════════════════════════════════════════<br>";
echo "📤 SENDING NOTIFICATIONS WITH PHPMAILER<br>";
echo "═══════════════════════════════════════════════<br><br>";

$sent_count = 0;
$failed_count = 0;
$errors = [];

foreach ($approvers as $approver) {
    $to_email = $approver['email'];
    $to_name = $approver['approver_name'];
    $level = $approver['approval_level'] ?? 'General';

    try {
        // Create PHPMailer instance
        $mail = new PHPMailer(true);

        // Server settings
        $mail->isSMTP();
        $mail->Host = $mail_host;
        $mail->Port = $mail_port;
        $mail->SMTPAuth = true;
        $mail->Username = $mail_user;
        $mail->Password = $mail_pass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS (port 587)

        // Sender
        $mail->setFrom($mail_from, $mail_from_name);

        // Recipient
        $mail->addAddress($to_email, $to_name);

        // Content
        $mail->isHTML(false);
        $mail->Subject = "SIPB Approval Required - $sipb_id";
        $mail->Body = <<<EOT
Hello {$to_name},

A new SIPB submission requires your approval.

Details:
├─ SIPB ID: {$sipb_id}
├─ Submitted by: {$submitter_name}
├─ Approval Level: {$level}
└─ Date: " . date('Y-m-d H:i:s') . "

Please click the link below to review and approve:
{$approval_link}

---
SIPB-GPI Notification System
EOT;

        // Send
        echo "Sending to: $to_email...<br>";
        if ($mail->send()) {
            echo "✅ Sent to: <strong>$to_name</strong> ($to_email) [$level]<br>";
            $sent_count++;
        } else {
            echo "❌ Failed to send to: <strong>$to_name</strong> ($to_email)<br>";
            echo "   Error: " . $mail->ErrorInfo . "<br>";
            $failed_count++;
            $errors[] = $to_email . ": " . $mail->ErrorInfo;
        }

    } catch (Exception $e) {
        echo "❌ Exception for $to_email: " . $e->getMessage() . "<br>";
        $failed_count++;
        $errors[] = $to_email . ": " . $e->getMessage();
    }
}

echo "<br>";
echo "═══════════════════════════════════════════════<br>";
echo "📊 RESULT<br>";
echo "═══════════════════════════════════════════════<br>";
echo "Total sent: <strong>$sent_count</strong><br>";
echo "Total failed: <strong>$failed_count</strong><br><br>";

if ($sent_count == count($approvers) && $sent_count > 0) {
    echo "✅ All notifications sent successfully!<br>";
    echo "Check email inboxes:<br>";
    foreach ($approvers as $approver) {
        echo "├─ " . $approver['email'] . "<br>";
    }
} else if ($sent_count > 0) {
    echo "⚠️ Some notifications sent but some failed.<br>";
    if (!empty($errors)) {
        echo "<br>Errors:<br>";
        foreach ($errors as $error) {
            echo "├─ $error<br>";
        }
    }
} else {
    echo "❌ No notifications sent. Check SMTP settings.<br>";
    if (!empty($errors)) {
        echo "<br>Errors:<br>";
        foreach ($errors as $error) {
            echo "├─ $error<br>";
        }
    }
}

echo "<br>";
echo "═══════════════════════════════════════════════<br>";

?>
