<?php
/**
 * send_approval_notification.php
 * Send approval notification emails to all active approvers
 *
 * Usage:
 * require_once 'config/send_approval_notification.php';
 * send_approval_notification($conn, $sipb_id, $doc_number, $submitter_name);
 */

// Load minimal PHPMailer
if (!class_exists('PHPMailer')) {
    require_once __DIR__ . '/../vendor_manual/PHPMailer.php';
}

/**
 * Send approval notification emails
 *
 * @param mysqli $conn Database connection
 * @param int $sipb_id SIPB document ID
 * @param string $doc_number SIPB document number
 * @param string $submitter_name Name of SIPB submitter
 * @return array ['success' => bool, 'sent' => int, 'failed' => int, 'errors' => []]
 */
function send_approval_notification($conn, $sipb_id, $doc_number, $submitter_name) {
    $result = [
        'success' => false,
        'sent' => 0,
        'failed' => 0,
        'errors' => []
    ];

    try {
        // Get SMTP settings from environment
        $mail_host = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
        $mail_port = intval(getenv('MAIL_PORT') ?: 587);
        $mail_user = getenv('MAIL_USERNAME') ?: 'muhammadarfanulaziz@gmail.com';
        $mail_pass = getenv('MAIL_PASSWORD') ?: '';
        $mail_from = getenv('MAIL_FROM') ?: 'muhammadarfanulaziz@gmail.com';
        $mail_from_name = getenv('MAIL_FROM_NAME') ?: 'SIPB-GPI Notification System';

        // Query active approvers
        $query = "SELECT u.id, u.name as approver_name, u.email, ap.approval_level
                  FROM users u
                  LEFT JOIN approval_positions ap ON u.position_id = ap.id
                  WHERE u.role = 'approver' AND u.is_active = 1 AND u.is_approved = 1
                  ORDER BY ap.approval_level, u.name";

        $query_result = $conn->query($query);
        if (!$query_result) {
            $result['errors'][] = "Database error: " . $conn->error;
            return $result;
        }

        $approvers = [];
        while ($row = $query_result->fetch_assoc()) {
            $approvers[] = $row;
        }

        if (empty($approvers)) {
            $result['errors'][] = "No active approvers found";
            return $result;
        }

        // Prepare email details
        $app_url = rtrim(getenv('APP_URL') ?: 'http://localhost/SIPB-GPI', '/');
        $approval_link = "$app_url/approval/?sipb_id=$sipb_id";

        $email_subject = "SIPB Approval Required - $doc_number";
        $email_body = <<<EOT
Hello {approver_name},

A new SIPB submission requires your approval.

Details:
├─ SIPB Number: $doc_number
├─ Submitted by: $submitter_name
├─ Approval Level: {approval_level}
└─ Date: " . date('Y-m-d H:i:s') . "

Please click the link below to review and approve:
$approval_link

---
SIPB-GPI Notification System
EOT;

        // Send emails to each approver
        foreach ($approvers as $approver) {
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
                $mail->addAddress($approver['email'], $approver['approver_name']);

                $mail->isHTML = false;
                $mail->Subject = $email_subject;

                // Replace placeholders in body
                $body = str_replace(
                    ['{approver_name}', '{approval_level}'],
                    [$approver['approver_name'], $approver['approval_level'] ?? 'General'],
                    $email_body
                );
                $mail->Body = $body;

                if ($mail->send()) {
                    $result['sent']++;
                } else {
                    $result['failed']++;
                    $result['errors'][] = $approver['email'] . ": " . $mail->ErrorInfo;
                }

            } catch (Exception $e) {
                $result['failed']++;
                $result['errors'][] = $approver['email'] . ": " . $e->getMessage();
            }
        }

        $result['success'] = ($result['failed'] == 0);
        return $result;

    } catch (Exception $e) {
        $result['errors'][] = "General error: " . $e->getMessage();
        return $result;
    }
}

?>
