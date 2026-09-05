<?php
/**
 * send_notification_simple.php
 * Send email via SMTP using fsockopen (no composer dependency)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>📧 PUSH NOTIFICATION TEST - Simple SMTP</h2>";

// Load config
try {
    require_once 'config/config.php';
    echo "✅ Config loaded<br>";
} catch (Exception $e) {
    die("❌ Config error: " . $e->getMessage());
}

// Get SMTP settings from .env
$mail_host = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
$mail_port = intval(getenv('MAIL_PORT') ?: 587);
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

// Simple SMTP function
function send_email_smtp($host, $port, $user, $pass, $from_email, $from_name, $to_email, $to_name, $subject, $body) {
    try {
        // Connect to SMTP server
        echo "Connecting to SMTP server...<br>";
        $socket = fsockopen($host, $port, $errno, $errstr, 10);

        if (!$socket) {
            return array(false, "Connection failed: $errstr ($errno)");
        }

        function smtp_command($socket, $cmd, &$response) {
            fputs($socket, $cmd . "\r\n");
            $response = fgets($socket, 256);
            return $response;
        }

        // Read welcome message
        $response = fgets($socket, 256);

        // Send HELO
        smtp_command($socket, "HELO " . getenv('APP_NAME') ?: 'SIPB', $response);

        // Start TLS
        smtp_command($socket, "STARTTLS", $response);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return array(false, "STARTTLS failed");
        }

        // Send EHLO again after TLS
        smtp_command($socket, "EHLO " . getenv('APP_NAME') ?: 'SIPB', $response);

        // Authenticate
        smtp_command($socket, "AUTH LOGIN", $response);
        smtp_command($socket, base64_encode($user), $response);
        smtp_command($socket, base64_encode($pass), $response);

        // Send FROM
        $cmd = "MAIL FROM:<$from_email>";
        smtp_command($socket, $cmd, $response);
        if (strpos($response, '250') === false) {
            return array(false, "MAIL FROM failed: $response");
        }

        // Send TO
        $cmd = "RCPT TO:<$to_email>";
        smtp_command($socket, $cmd, $response);
        if (strpos($response, '250') === false) {
            return array(false, "RCPT TO failed: $response");
        }

        // Send DATA
        smtp_command($socket, "DATA", $response);

        // Prepare email headers
        $headers = "From: $from_name <$from_email>\r\n";
        $headers .= "To: $to_name <$to_email>\r\n";
        $headers .= "Subject: $subject\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "\r\n";

        $full_email = $headers . $body . "\r\n.\r\n";
        fputs($socket, $full_email);

        $response = fgets($socket, 256);
        if (strpos($response, '250') === false) {
            return array(false, "Data transmission failed: $response");
        }

        // Send QUIT
        smtp_command($socket, "QUIT", $response);

        fclose($socket);
        return array(true, "Email sent successfully");

    } catch (Exception $e) {
        return array(false, $e->getMessage());
    }
}

// Test data
$sipb_id = 'TEST-001';
$submitter_name = 'Sheryn Makmur';
$approval_link = "http://localhost/SIPB-GPI/public_html/approval/approve.php?sipb_id=$sipb_id";

echo "═══════════════════════════════════════════════<br>";
echo "📤 SENDING NOTIFICATIONS WITH SIMPLE SMTP<br>";
echo "═══════════════════════════════════════════════<br><br>";

$sent_count = 0;
$failed_count = 0;
$errors = [];

foreach ($approvers as $approver) {
    $to_email = $approver['email'];
    $to_name = $approver['approver_name'];
    $level = $approver['approval_level'] ?? 'General';

    $subject = "SIPB Approval Required - $sipb_id";
    $body = <<<EOT
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

    echo "Sending to: $to_email...<br>";

    list($success, $message) = send_email_smtp(
        $mail_host,
        $mail_port,
        $mail_user,
        $mail_pass,
        $mail_from,
        $mail_from_name,
        $to_email,
        $to_name,
        $subject,
        $body
    );

    if ($success) {
        echo "✅ Sent to: <strong>$to_name</strong> ($to_email) [$level]<br>";
        $sent_count++;
    } else {
        echo "❌ Failed to send to: <strong>$to_name</strong> ($to_email)<br>";
        echo "   Error: $message<br>";
        $failed_count++;
        $errors[] = $to_email . ": " . $message;
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
