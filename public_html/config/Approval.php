<?php
/**
 * public_html/config/Approval.php
 *
 * Approval workflow management with reassign/takeover support
 * Handles:
 * - Approve/Reject SIPB
 * - Reassign approval to another user (same role)
 * - Takeover approval (temporary delegation)
 * - Send notifications
 */

class Approval {
    protected $conn;
    protected $mailer;

    public function __construct($conn, $mailer = null) {
        $this->conn = $conn;
        $this->mailer = $mailer;
    }

    /**
     * Get current approval owner (including delegations)
     * Returns the effective approver considering active delegations
     *
     * @param int $sipb_id
     * @param string $approval_level 'SPV' or 'PM'
     * @return array|null Approver info with ['id', 'name', 'email', 'is_delegated', 'delegated_from']
     */
    public function getCurrentApprover($sipb_id, $approval_level) {
        $approval_level = strtoupper($approval_level);

        $query = "
        SELECT
            COALESCE(del.delegated_to_user_id, d.approval_" . strtolower($approval_level) . "_by) as approver_id,
            COALESCE(u_del.name, u_orig.name) as approver_name,
            COALESCE(u_del.email, u_orig.email) as approver_email,
            CASE WHEN del.id IS NOT NULL THEN 1 ELSE 0 END as is_delegated,
            CASE WHEN del.id IS NOT NULL THEN u_orig.name ELSE NULL END as delegated_from_name

        FROM sipb_documents d
        LEFT JOIN users u_orig ON d.approval_" . strtolower($approval_level) . "_by = u_orig.id

        /* Check for active delegation */
        LEFT JOIN approval_delegation del ON
            d.approval_" . strtolower($approval_level) . "_by = del.original_user_id
            AND del.approval_level = ?
            AND del.is_active = 1
            AND NOW() BETWEEN del.valid_from AND COALESCE(del.valid_until, NOW())

        LEFT JOIN users u_del ON del.delegated_to_user_id = u_del.id

        WHERE d.id = ?
        LIMIT 1";

        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('si', $approval_level, $sipb_id);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result->num_rows > 0 ? $result->fetch_assoc() : null;
    }

    /**
     * Check if user can approve this SIPB at given level
     *
     * @param int $user_id
     * @param int $sipb_id
     * @param string $approval_level 'SPV' or 'PM'
     * @return bool
     */
    public function canApprove($user_id, $sipb_id, $approval_level) {
        $current_approver = $this->getCurrentApprover($sipb_id, $approval_level);

        if (!$current_approver) {
            return false;
        }

        return $current_approver['approver_id'] == $user_id;
    }

    /**
     * Approve SIPB at given level
     *
     * @param int $sipb_id
     * @param int $approver_id
     * @param string $approval_level 'SPV' or 'PM'
     * @param string $notes Optional approval notes
     * @return bool
     */
    public function approve($sipb_id, $approver_id, $approval_level, $notes = '') {
        $approval_level = strtoupper($approval_level);
        $field_prefix = "approval_" . strtolower($approval_level);

        // Verify permission
        if (!$this->canApprove($approver_id, $sipb_id, $approval_level)) {
            return false;
        }

        // Get SIPB data before update
        $old_data = $this->getSIPBData($sipb_id);

        try {
            $this->conn->begin_transaction();

            // Update approval status
            $query = "UPDATE sipb_documents SET
                      " . $field_prefix . "_status = 'Approved',
                      " . $field_prefix . "_by = ?,
                      " . $field_prefix . "_at = NOW(),
                      " . $field_prefix . "_note = ?";

            // Check if all approvals are done
            if ($approval_level === 'PM') {
                $query .= ", status = 'Approved'";
            }

            $query .= " WHERE id = ?";

            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare error: " . $this->conn->error);
            }

            $stmt->bind_param('isi', $approver_id, $notes, $sipb_id);
            if (!$stmt->execute()) {
                throw new Exception("Execute error: " . $stmt->error);
            }

            // Get approver info
            $approver = $this->getUserInfo($approver_id);

            // Log to audit trail
            $new_data = $this->getSIPBData($sipb_id);
            log_audit(
                $this->conn,
                $sipb_id,
                'approve',
                "Approved at $approval_level level by " . $approver['name'],
                $old_data,
                $new_data
            );

            // Log to approval_history
            $history_query = "INSERT INTO sipb_approval_history
                              (sipb_id, approval_level, approval_status, approver_id, approval_note)
                              VALUES (?, ?, 'Approved', ?, ?)";
            $history_stmt = $this->conn->prepare($history_query);
            if ($history_stmt) {
                $history_stmt->bind_param('isss', $sipb_id, $approval_level, $approver_id, $notes);
                $history_stmt->execute();
            }

            $this->conn->commit();

            // Send notifications
            if ($this->mailer) {
                $sipb_data = $this->getSIPBData($sipb_id);
                $creator = $this->getUserInfo($sipb_data['created_by']);
                $this->mailer->sendApprovalNotification(
                    $creator['email'],
                    $creator['name'],
                    $sipb_data,
                    $approver['name'],
                    $approval_level
                );
            }

            return true;
        } catch (Exception $e) {
            $this->conn->rollback();
            return false;
        }
    }

    /**
     * Reject SIPB at given level
     *
     * @param int $sipb_id
     * @param int $approver_id
     * @param string $approval_level 'SPV' or 'PM'
     * @param string $reason Rejection reason (mandatory)
     * @return bool
     */
    public function reject($sipb_id, $approver_id, $approval_level, $reason) {
        if (empty($reason)) {
            return false; // Rejection reason is mandatory
        }

        $approval_level = strtoupper($approval_level);
        $field_prefix = "approval_" . strtolower($approval_level);

        // Verify permission
        if (!$this->canApprove($approver_id, $sipb_id, $approval_level)) {
            return false;
        }

        $old_data = $this->getSIPBData($sipb_id);

        try {
            $this->conn->begin_transaction();

            // Update rejection status
            $query = "UPDATE sipb_documents SET
                      " . $field_prefix . "_status = 'Rejected',
                      " . $field_prefix . "_by = ?,
                      " . $field_prefix . "_at = NOW(),
                      " . $field_prefix . "_note = ?,
                      status = 'Rejected'
                      WHERE id = ?";

            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare error: " . $this->conn->error);
            }

            $stmt->bind_param('isi', $approver_id, $reason, $sipb_id);
            if (!$stmt->execute()) {
                throw new Exception("Execute error: " . $stmt->error);
            }

            // Get approver info
            $approver = $this->getUserInfo($approver_id);

            // Log to audit trail
            $new_data = $this->getSIPBData($sipb_id);
            log_audit(
                $this->conn,
                $sipb_id,
                'reject',
                "Rejected at $approval_level level by " . $approver['name'] . ": " . $reason,
                $old_data,
                $new_data
            );

            // Log to approval_history
            $history_query = "INSERT INTO sipb_approval_history
                              (sipb_id, approval_level, approval_status, approver_id, approval_note)
                              VALUES (?, ?, 'Rejected', ?, ?)";
            $history_stmt = $this->conn->prepare($history_query);
            if ($history_stmt) {
                $history_stmt->bind_param('isss', $sipb_id, $approval_level, $approver_id, $reason);
                $history_stmt->execute();
            }

            $this->conn->commit();

            // Send rejection notification
            if ($this->mailer) {
                $sipb_data = $this->getSIPBData($sipb_id);
                $creator = $this->getUserInfo($sipb_data['created_by']);
                $this->mailer->sendRejectionNotification(
                    $creator['email'],
                    $creator['name'],
                    $sipb_data,
                    $approver['name'],
                    $reason
                );
            }

            return true;
        } catch (Exception $e) {
            $this->conn->rollback();
            return false;
        }
    }

    /**
     * Reassign approval to another user (same role/level)
     * Example: Reassign SPV approval from User A to User B
     *
     * @param int $sipb_id
     * @param string $approval_level 'SPV' or 'PM'
     * @param int $new_approver_id
     * @param int $reassigned_by_id User who is doing the reassignment (superadmin)
     * @param string $reason Optional reason
     * @return bool
     */
    public function reassignApproval($sipb_id, $approval_level, $new_approver_id, $reassigned_by_id, $reason = '') {
        $approval_level = strtoupper($approval_level);
        $field_prefix = "approval_" . strtolower($approval_level);

        // Get current approver
        $current_approver = $this->getCurrentApprover($sipb_id, $approval_level);
        if (!$current_approver) {
            return false;
        }

        // Verify new approver exists and has correct role
        $new_approver = $this->getUserInfo($new_approver_id);
        if (!$new_approver) {
            return false;
        }

        // Verify new approver has correct role for this level
        $required_role = $approval_level === 'SPV' ? 'approver_spv' : 'approver_pm';
        if ($new_approver['role'] !== $required_role) {
            return false;
        }

        $old_data = $this->getSIPBData($sipb_id);

        try {
            $this->conn->begin_transaction();

            // Get original approver info
            $original_approver = $this->getUserInfo($current_approver['approver_id']);

            // Update SIPB document
            $query = "UPDATE sipb_documents SET
                      " . $field_prefix . "_by = ?,
                      " . $field_prefix . "_reassigned_from = ?,
                      " . $field_prefix . "_reassigned_at = NOW()
                      WHERE id = ?";

            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare error: " . $this->conn->error);
            }

            $stmt->bind_param('iii', $new_approver_id, $current_approver['approver_id'], $sipb_id);
            if (!$stmt->execute()) {
                throw new Exception("Execute error: " . $stmt->error);
            }

            // Log to audit trail
            $new_data = $this->getSIPBData($sipb_id);
            log_audit(
                $this->conn,
                $sipb_id,
                'reassign_approval',
                "Reassigned $approval_level approval from " . $original_approver['name'] . " to " . $new_approver['name'] . ": $reason",
                $old_data,
                $new_data
            );

            $this->conn->commit();

            // Send notification to new approver
            if ($this->mailer) {
                $sipb_data = $this->getSIPBData($sipb_id);
                $this->mailer->sendReassignmentNotification(
                    $new_approver['email'],
                    $new_approver['name'],
                    $sipb_data,
                    $original_approver['name'],
                    $approval_level
                );
            }

            return true;
        } catch (Exception $e) {
            $this->conn->rollback();
            return false;
        }
    }

    /**
     * Create a delegation (takeover or temporary reassign)
     *
     * @param int $original_user_id User who should be approving
     * @param int $delegated_to_user_id User taking over
     * @param string $approval_level 'SPV' or 'PM'
     * @param string $delegation_type 'reassign' or 'takeover'
     * @param string $valid_from Start date (Y-m-d H:i:s or 'now')
     * @param string|null $valid_until End date (Y-m-d H:i:s) or NULL for permanent
     * @param string $reason
     * @param int $created_by Superadmin ID
     * @return bool
     */
    public function createDelegation($original_user_id, $delegated_to_user_id, $approval_level, $delegation_type, $valid_from, $valid_until, $reason, $created_by) {
        // Normalize inputs
        $approval_level = strtoupper($approval_level);
        $delegation_type = strtolower($delegation_type);

        if (!in_array($delegation_type, ['reassign', 'takeover'])) {
            return false;
        }

        if ($valid_from === 'now') {
            $valid_from = date('Y-m-d H:i:s');
        }

        // Verify users exist
        if (!$this->getUserInfo($original_user_id) || !$this->getUserInfo($delegated_to_user_id)) {
            return false;
        }

        $query = "INSERT INTO approval_delegation
                  (original_user_id, delegated_to_user_id, approval_level, delegation_type, valid_from, valid_until, reason, created_by, is_active)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)";

        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('iissssi', $original_user_id, $delegated_to_user_id, $approval_level, $delegation_type, $valid_from, $valid_until, $reason, $created_by);
        return $stmt->execute();
    }

    /**
     * Deactivate/cancel a delegation
     *
     * @param int $delegation_id
     * @return bool
     */
    public function cancelDelegation($delegation_id) {
        $query = "UPDATE approval_delegation SET is_active = 0 WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $delegation_id);
        return $stmt->execute();
    }

    /**
     * Get SIPB document data
     *
     * @param int $sipb_id
     * @return array|null
     */
    protected function getSIPBData($sipb_id) {
        $query = "SELECT * FROM sipb_documents WHERE id = ? LIMIT 1";
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $sipb_id);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result->num_rows > 0 ? $result->fetch_assoc() : null;
    }

    /**
     * Get user info
     *
     * @param int $user_id
     * @return array|null
     */
    protected function getUserInfo($user_id) {
        $query = "SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1";
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result->num_rows > 0 ? $result->fetch_assoc() : null;
    }
}


