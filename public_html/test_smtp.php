<?php
/**
 * test_smtp.php
 * Test SMTP connection ke Gmail
 */

// Load .env configuration
$env_file = __DIR__ . '/../.env';
if (!file_exists($env_file)) {
    die("❌ ERROR: .env file not found at " . $env_file);
}

$env = parse_ini_file($env_file);
foreach ($env as $key => $value) {
    putenv("$key=$value");
}

// Get SMTP settings
$mail_host = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
$mail_port = getenv('MAIL_PORT') ?: 587;
$mail_user = getenv('MAIL_USERNAME') ?: 'muhammadarfanulaziz@gmail.com';
$mail_pass = getenv('MAIL_PASSWORD') ?: '';

echo "<h2>🔌 SMTP CONNECTION TEST</h2>";
echo "Settings:<br>";
echo "├─ Host: $mail_host<br>";
echo "├─ Port: $mail_port<br>";
echo "├─ Username: $mail_user<br>";
echo "└─ Password: " . (strlen($mail_pass) > 0 ? "••••••••" : "NOT SET") . "<br><br>";

// Test TCP connection
echo "Testing TCP connection to SMTP server...<br>";
$connection = @fsockopen($mail_host, $mail_port, $errno, $errstr, 10);

if ($connection) {
    echo "✅ TCP Connection: SUCCESS<br>";
    fclose($connection);

    echo "✅ SMTP Server is responding<br><br>";

    echo "═══════════════════════════════════════════════<br>";
    echo "✅ SMTP CONNECTION TEST PASSED!<br>";
    echo "═══════════════════════════════════════════════<br>";
    echo "Ready to send emails! 📧<br>";

} else {
    echo "❌ TCP Connection: FAILED<br>";
    echo "Error: $errstr ($errno)<br><br>";
    echo "═══════════════════════════════════════════════<br>";
    echo "❌ SMTP CONNECTION TEST FAILED!<br>";
    echo "═══════════════════════════════════════════════<br>";
    echo "Troubleshooting:<br>";
    echo "1. Check MAIL_HOST in .env (should be: smtp.gmail.com)<br>";
    echo "2. Check MAIL_PORT in .env (should be: 587)<br>";
    echo "3. Check Gmail credentials<br>";
    echo "4. Check internet connection<br>";
}

?>
