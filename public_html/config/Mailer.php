<?php
/**
 * public_html/config/Mailer.php
 *
 * Email notification system using PHPMailer
 * Supports queue system for async email delivery
 *
 * Installation:
 * composer require phpmailer/phpmailer
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer {
    protected $conn;
    protected $mailer;
    protected $config;

    public function __construct($conn) {
        $this->conn = $conn;
        $this->loadSMTPConfig();
        $this->initializeMailer();
    }

    /**
     * Load SMTP configuration from database
     */
    private function loadSMTPConfig() {
        $query = "SELECT * FROM email_configuration WHERE is_enabled = 1 LIMIT 1";
        $result = $this->conn->query($query);

        if ($result->num_rows > 0) {
            $this->config = $result->fetch_assoc();
        } else {
            throw new Exception("Email configuration not found in database");
        }
    }

    /**
     * Initialize PHPMailer instance
     */
    private function initializeMailer() {
        $this->mailer = new PHPMailer(true);

        try {
            // SMTP configuration
            $this->mailer->isSMTP();
            $this->mailer->Host = $this->config['smtp_host'];
            $this->mailer->Port = $this->config['smtp_port'];
            $this->mailer->SMTPSecure = $this->config['smtp_secure'];
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = $this->config['smtp_username'];
            $this->mailer->Password = $this->config['smtp_password'];

            // From address
            $this->mailer->setFrom($this->config['from_email'], $this->config['from_name']);
            if ($this->config['reply_to_email']) {
                $this->mailer->addReplyTo($this->config['reply_to_email']);
            }

            // Charset
            $this->mailer->CharSet = 'UTF-8';
        } catch (Exception $e) {
            throw new Exception("Mailer initialization failed: " . $e->getMessage());
        }
    }

    /**
     * Send approval request email
     *
     * @param string $approver_email
     * @param string $approver_name
     * @param array $sipb_data SIPB document data
     * @param string $approval_level 'SPV' or 'PM'
     * @return bool
     */
    public function sendApprovalRequest($approver_email, $approver_name, $sipb_data, $approval_level) {
        $sipb_id = $sipb_data['id'];
        $doc_number = $sipb_data['doc_number'];
        $customer = $sipb_data['customer_name'];
        $created_by = $sipb_data['created_by_name'];

        $subject = "SIPB Approval Request: $doc_number";

        $body_html = "
        <html>
        <head><meta charset='UTF-8'></head>
        <body style='font-family: Arial, sans-serif;'>
            <h2>📋 SIPB Approval Request</h2>
            <p>Dear $approver_name,</p>
            <p>A new SIPB document requires your approval as <strong>$approval_level</strong>.</p>

            <table style='border-collapse: collapse; width: 100%; margin: 20px 0;'>
                <tr style='background: #f0f0f0;'>
                    <td style='padding: 10px; border: 1px solid #ccc;'><strong>Document Number</strong></td>
                    <td style='padding: 10px; border: 1px solid #ccc;'>$doc_number</td>
                </tr>
                <tr>
                    <td style='padding: 10px; border: 1px solid #ccc;'><strong>Customer</strong></td>
                    <td style='padding: 10px; border: 1px solid #ccc;'>$customer</td>
                </tr>
                <tr style='background: #f0f0f0;'>
                    <td style='padding: 10px; border: 1px solid #ccc;'><strong>Created By</strong></td>
                    <td style='padding: 10px; border: 1px solid #ccc;'>$created_by</td>
                </tr>
                <tr>
                    <td style='padding: 10px; border: 1px solid #ccc;'><strong>Date</strong></td>
                    <td style='padding: 10px; border: 1px solid #ccc;'>" . date('Y-m-d H:i:s') . "</td>
                </tr>
            </table>

            <p>
                <a href='" . getenv('APP_URL') . "/approval/approve.php?id=$sipb_id'
                   style='display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>
                   View & Approve
                </a>
            </p>

            <hr>
            <p style='font-size: 12px; color: #666;'>
                This is an automated message from SIPB-GPI System @ Salimagro. Please do not reply to this email.
            </p>
        </body>
        </html>";

        return $this->queueEmail(
            $sipb_id,
            $approver_email,
            $approver_name,
            $subject,
            $body_html,
            'approval_request'
        );
    }

    /**
     * Send approval notification (document approved)
     *
     * @param string $recipient_email
     * @param string $recipient_name
     * @param array $sipb_data
     * @param string $approver_name
     * @param string $approval_level
     * @return bool
     */
    public function sendApprovalNotification($recipient_email, $recipient_name, $sipb_data, $approver_name, $approval_level) {
        $doc_number = $sipb_data['doc_number'];
        $customer = $sipb_data['customer_name'];

        $subject = "✅ SIPB Approved: $doc_number";

        $body_html = "
        <html>
        <head><meta charset='UTF-8'></head>
        <body style='font-family: Arial, sans-serif;'>
            <h2>✅ SIPB Approved</h2>
            <p>Dear $recipient_name,</p>
            <p>Your SIPB document has been <strong>approved</strong> by $approver_name ($approval_level level).</p>

            <table style='border-collapse: collapse; width: 100%; margin: 20px 0;'>
                <tr style='background: #d4edda;'>
                    <td style='padding: 10px; border: 1px solid #ccc;'><strong>Document Number</strong></td>
                    <td style='padding: 10px; border: 1px solid #ccc;'>$doc_number</td>
                </tr>
                <tr>
                    <td style='padding: 10px; border: 1px solid #ccc;'><strong>Customer</strong></td>
                    <td style='padding: 10px; border: 1px solid #ccc;'>$customer</td>
                </tr>
                <tr style='background: #d4edda;'>
                    <td style='padding: 10px; border: 1px solid #ccc;'><strong>Approved By</strong></td>
                    <td style='padding: 10px; border: 1px solid #ccc;'>$approver_name ($approval_level)</td>
                </tr>
            </table>

            <p>You can view the full document in the SIPB system.</p>

            <hr>
            <p style='font-size: 12px; color: #666;'>
                This is an automated message from SIPB-GPI System @ Salimagro.
            </p>
        </body>
        </html>";

        return $this->queueEmail(
            $sipb_data['id'],
            $recipient_email,
            $recipient_name,
            $subject,
            $body_html,
            'approval_approved'
        );
    }

    /**
     * Send rejection notification
     *
     * @param string $recipient_email
     * @param string $recipient_name
     * @param array $sipb_data
     * @param string $rejector_name
     * @param string $rejection_reason
     * @return bool
     */
    public function sendRejectionNotification($recipient_email, $recipient_name, $sipb_data, $rejector_name, $rejection_reason) {
        $doc_number = $sipb_data['doc_number'];
        $customer = $sipb_data['customer_name'];

        $subject = "❌ SIPB Rejected: $doc_number";

        $body_html = "
        <html>
        <head><meta charset='UTF-8'></head>
        <body style='font-family: Arial, sans-serif;'>
            <h2>❌ SIPB Rejected</h2>
            <p>Dear $recipient_name,</p>
            <p>Your SIPB document has been <strong>rejected</strong> by $rejector_name.</p>

            <table style='border-collapse: collapse; width: 100%; margin: 20px 0;'>
                <tr style='background: #f8d7da;'>
                    <td style='padding: 10px; border: 1px solid #ccc;'><strong>Document Number</strong></td>
                    <td style='padding: 10px; border: 1px solid #ccc;'>$doc_number</td>
                </tr>
                <tr>
                    <td style='padding: 10px; border: 1px solid #ccc;'><strong>Customer</strong></td>
                    <td style='padding: 10px; border: 1px solid #ccc;'>$customer</td>
                </tr>
                <tr style='background: #f8d7da;'>
                    <td style='padding: 10px; border: 1px solid #ccc;'><strong>Rejection Reason</strong></td>
                    <td style='padding: 10px; border: 1px solid #ccc;'>$rejection_reason</td>
                </tr>
            </table>

            <p>Please revise and resubmit the document if needed.</p>

            <hr>
            <p style='font-size: 12px; color: #666;'>
                This is an automated message from SIPB-GPI System @ Salimagro.
            </p>
        </body>
        </html>";

        return $this->queueEmail(
            $sipb_data['id'],
            $recipient_email,
            $recipient_name,
            $subject,
            $body_html,
            'approval_rejected'
        );
    }

    /**
     * Send reassignment notification
     *
     * @param string $new_approver_email
     * @param string $new_approver_name
     * @param array $sipb_data
     * @param string $original_approver_name
     * @param string $approval_level
     * @return bool
     */
    public function sendReassignmentNotification($new_approver_email, $new_approver_name, $sipb_data, $original_approver_name, $approval_level) {
        $doc_number = $sipb_data['doc_number'];
        $customer = $sipb_data['customer_name'];

        $subject = "📌 SIPB Reassigned to You: $doc_number";

        $body_html = "
        <html>
        <head><meta charset='UTF-8'></head>
        <body style='font-family: Arial, sans-serif;'>
            <h2>📌 SIPB Approval Reassigned</h2>
            <p>Dear $new_approver_name,</p>
            <p>The approval of SIPB document below has been reassigned to you from $original_approver_name.</p>

            <table style='border-collapse: collapse; width: 100%; margin: 20px 0;'>
                <tr style='background: #e3f2fd;'>
                    <td style='padding: 10px; border: 1px solid #ccc;'><strong>Document Number</strong></td>
                    <td style='padding: 10px; border: 1px solid #ccc;'>$doc_number</td>
                </tr>
                <tr>
                    <td style='padding: 10px; border: 1px solid #ccc;'><strong>Customer</strong></td>
                    <td style='padding: 10px; border: 1px solid #ccc;'>$customer</td>
                </tr>
                <tr style='background: #e3f2fd;'>
                    <td style='padding: 10px; border: 1px solid #ccc;'><strong>Approval Level</strong></td>
                    <td style='padding: 10px; border: 1px solid #ccc;'>$approval_level</td>
                </tr>
            </table>

            <p>
                <a href='" . getenv('APP_URL') . "/approval/approve.php?id=" . $sipb_data['id'] . "'
                   style='display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>
                   View & Approve
                </a>
            </p>

            <hr>
            <p style='font-size: 12px; color: #666;'>
                This is an automated message from SIPB-GPI System @ Salimagro.
            </p>
        </body>
        </html>";

        return $this->queueEmail(
            $sipb_data['id'],
            $new_approver_email,
            $new_approver_name,
            $subject,
            $body_html,
            'reassign_notification'
        );
    }

    /**
     * Queue email for delivery (async processing)
     * Emails will be sent by a cron job or background task
     *
     * @param int $sipb_id
     * @param string $email
     * @param string $name
     * @param string $subject
     * @param string $body_html
     * @param string $email_type
     * @return bool
     */
    private function queueEmail($sipb_id, $email, $name, $subject, $body_html, $email_type) {
        $query = "INSERT INTO email_queue (sipb_id, recipient_email, recipient_name, subject, body_html, email_type)
                  VALUES (?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('isssss', $sipb_id, $email, $name, $subject, $body_html, $email_type);
        return $stmt->execute();
    }

    /**
     * Send queued emails (call this from cron job or background task)
     * Example: php public_html/cron/send_emails.php
     *
     * @param int $batch_size Number of emails to send per run
     * @return array Status array with counts
     */
    public function sendQueuedEmails($batch_size = 10) {
        $query = "SELECT * FROM email_queue WHERE status = 'Pending' AND attempt_count < ? ORDER BY created_at ASC LIMIT ?";
        $max_retry = $this->config['max_retry'] ?? 3;

        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return ['sent' => 0, 'failed' => 0];
        }

        $stmt->bind_param('ii', $max_retry, $batch_size);
        $stmt->execute();
        $result = $stmt->get_result();

        $sent = 0;
        $failed = 0;

        while ($email = $result->fetch_assoc()) {
            try {
                // Clear previous recipients
                $this->mailer->clearAllRecipients();

                // Set recipient
                $this->mailer->addAddress($email['recipient_email'], $email['recipient_name']);
                $this->mailer->Subject = $email['subject'];
                $this->mailer->msgHTML($email['body_html']);

                // Send
                $this->mailer->send();

                // Mark as sent
                $update_query = "UPDATE email_queue SET status = 'Sent', sent_at = NOW() WHERE id = ?";
                $update_stmt = $this->conn->prepare($update_query);
                $update_stmt->bind_param('i', $email['id']);
                $update_stmt->execute();

                $sent++;
            } catch (Exception $e) {
                // Log error
                $error_msg = $e->getMessage();
                $attempt = $email['attempt_count'] + 1;

                $update_query = "UPDATE email_queue SET attempt_count = ?, last_error = ?";
                if ($attempt >= $max_retry) {
                    $update_query .= ", status = 'Failed'";
                }
                $update_query .= " WHERE id = ?";

                $update_stmt = $this->conn->prepare($update_query);
                $update_stmt->bind_param('isi', $attempt, $error_msg, $email['id']);
                $update_stmt->execute();

                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Test email configuration (sends test email)
     *
     * @param string $test_email
     * @return bool
     */
    public function testConfiguration($test_email) {
        try {
            $this->mailer->clearAllRecipients();
            $this->mailer->addAddress($test_email);
            $this->mailer->Subject = "Test Email from SIPB-GPI";
            $this->mailer->msgHTML("<p>This is a test email from SIPB-GPI system. If you received this, SMTP configuration is working correctly.</p>");

            return $this->mailer->send();
        } catch (Exception $e) {
            throw new Exception("Test email failed: " . $e->getMessage());
        }
    }
}


