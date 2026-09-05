<?php
/**
 * send_notification.php
 * Send email notification ke approvers - FIXED VERSION
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>📧 PUSH NOTIFICATION TEST</h2>";

// Load config
try {
    require_once 'config/config.php';
    echo "✅ Config loaded<br>";
} catch (Exception $e) {
    die("❌ Config error: " . $e->getMessage());
}

// Query approvers dari users table
// Approvers = users dengan role='approver' dan is_active=1 dan is_approved=1
$query = "SELECT u.id, u.name as approver_name, u.email, u.role, ap.approval_level
          FROM users u
          LEFT JOIN approval_positions ap ON u.position_id = ap.id
          WHERE u.role = 'approver' AND u.is_active = 1 AND u.is_approved = 1
          ORDER BY ap.approval_level, u.name";

echo "Debug: Query = $query<br><br>";

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
    echo "Checked: users WHERE role='approver' AND is_active=1 AND is_approved=1<br>";

    // Show what approvers exist
    echo "<br>Approvers in database:<br>";
    $check = $conn->query("SELECT id, nik, name, email, role, is_active, is_approved FROM users WHERE role='approver'");
    if ($check && $check->num_rows > 0) {
        while ($row = $check->fetch_assoc()) {
            echo "├─ {$row['name']} (NIK {$row['nik']}): {$row['email']} | Active: {$row['is_active']} | Approved: {$row['is_approved']}<br>";
        }
    }
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
echo "📤 SENDING NOTIFICATIONS<br>";
echo "═══════════════════════════════════════════════<br><br>";

$sent_count = 0;
$failed_count = 0;

foreach ($approvers as $approver) {
    $to_email = $approver['email'];
    $to_name = $approver['approver_name'];
    $level = $approver['approval_level'] ?? 'General';

    $subject = "SIPB Approval Required - $sipb_id";

    // Email body
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

    // Headers
    $mail_from = getenv('MAIL_FROM') ?: 'muhammadarfanulaziz@gmail.com';
    $mail_from_name = getenv('MAIL_FROM_NAME') ?: 'SIPB-GPI Notification System';

    $headers = "From: $mail_from_name <$mail_from>\r\n";
    $headers .= "Reply-To: $mail_from\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    echo "Sending to: $to_email...<br>";

    // Send email
    if (mail($to_email, $subject, $body, $headers)) {
        echo "✅ Sent to: <strong>$to_name</strong> ($to_email) [$level]<br>";
        $sent_count++;
    } else {
        echo "❌ Failed to send to: <strong>$to_name</strong> ($to_email)<br>";
        $failed_count++;
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
} else {
    echo "❌ No notifications sent. Check SMTP settings and database.<br>";
}

echo "<br>";
echo "═══════════════════════════════════════════════<br>";

?>
