<?php
/**
 * test_email_log.php
 * Test email with detailed logging
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>📧 EMAIL TEST WITH LOGGING</h2>";

// Load config
require_once 'config/config.php';
require_once __DIR__ . '/../vendor_manual/PHPMailer.php';

// Create log file
$log_file = __DIR__ . '/../email_test.log';
file_put_contents($log_file, "\n\n=== EMAIL TEST " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND);

function log_msg($msg) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    echo $msg . "<br>";
    file_put_contents($log_file, "[$timestamp] $msg\n", FILE_APPEND);
}

// Get settings
$mail_host = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
$mail_port = intval(getenv('MAIL_PORT') ?: 587);
$mail_user = getenv('MAIL_USERNAME') ?: 'muhammadarfanulaziz@gmail.com';
$mail_pass = getenv('MAIL_PASSWORD') ?: '';
$mail_from = getenv('MAIL_FROM') ?: 'muhammadarfanulaziz@gmail.com';

log_msg("✅ Config loaded");
log_msg("SMTP Host: $mail_host:$mail_port");
log_msg("SMTP User: $mail_user");
log_msg("Mail From: $mail_from");

// Test 1: Send to Mahyar
log_msg("\n--- TEST 1: Send to Mahyar ---");
$to_email = 'azizarfanul5@gmail.com';
$to_name = 'Mahyar';

try {
    $mail = new PHPMailer();
    $mail->isSMTP();
    $mail->Host = $mail_host;
    $mail->Port = $mail_port;
    $mail->SMTPAuth = true;
    $mail->Username = $mail_user;
    $mail->Password = $mail_pass;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

    $mail->setFrom($mail_from, 'SIPB Test');
    $mail->addAddress($to_email, $to_name);

    $mail->isHTML = false;
    $mail->Subject = "TEST EMAIL - " . date('Y-m-d H:i:s');
    $mail->Body = "This is a test email to verify SMTP is working.\n\nSent at: " . date('Y-m-d H:i:s');

    log_msg("Attempting to send...");

    if ($mail->send()) {
        log_msg("✅ SUCCESS: Email sent to $to_email");
        echo "<span style='color: green;'>✅ SUCCESS</span>";
    } else {
        log_msg("❌ FAILED: " . $mail->ErrorInfo);
        echo "<span style='color: red;'>❌ FAILED: " . $mail->ErrorInfo . "</span>";
    }
} catch (Exception $e) {
    log_msg("❌ EXCEPTION: " . $e->getMessage());
    echo "<span style='color: red;'>❌ EXCEPTION: " . $e->getMessage() . "</span>";
}

// Test 2: Send to M. Kahfi Offi
log_msg("\n--- TEST 2: Send to M. Kahfi Offi ---");
$to_email = 'hydrocolloidindogumproduct@gmail.com';
$to_name = 'M. Kahfi Offi';

try {
    $mail = new PHPMailer();
    $mail->isSMTP();
    $mail->Host = $mail_host;
    $mail->Port = $mail_port;
    $mail->SMTPAuth = true;
    $mail->Username = $mail_user;
    $mail->Password = $mail_pass;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

    $mail->setFrom($mail_from, 'SIPB Test');
    $mail->addAddress($to_email, $to_name);

    $mail->isHTML = false;
    $mail->Subject = "TEST EMAIL - " . date('Y-m-d H:i:s');
    $mail->Body = "This is a test email to verify SMTP is working.\n\nSent at: " . date('Y-m-d H:i:s');

    log_msg("Attempting to send...");

    if ($mail->send()) {
        log_msg("✅ SUCCESS: Email sent to $to_email");
        echo "<span style='color: green;'>✅ SUCCESS</span>";
    } else {
        log_msg("❌ FAILED: " . $mail->ErrorInfo);
        echo "<span style='color: red;'>❌ FAILED: " . $mail->ErrorInfo . "</span>";
    }
} catch (Exception $e) {
    log_msg("❌ EXCEPTION: " . $e->getMessage());
    echo "<span style='color: red;'>❌ EXCEPTION: " . $e->getMessage() . "</span>";
}

echo "<br><br>";
echo "📝 Log file: <a href='../email_test.log' target='_blank'>email_test.log</a><br>";
echo "Check your Gmail inbox (including Spam folder)<br>";

?>
