<?php
/**
 * public_html/admin/cleanup-rejected.php
 *
 * Admin tool: Delete rejected SIPB documents older than 6 months
 * Purpose: Clean up historical records, free server storage
 * Access: Superadmin only
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';

// Load user from session or database
$current_user = $_SESSION['user'] ?? [];
$user_id = $_SESSION['user_id'] ?? null;

// If session user not set but user_id exists, fetch from database
if (empty($current_user) && $user_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT id, nik, name, email, role, section FROM users WHERE id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $current_user = $result->fetch_assoc() ?? [];
    if (!empty($current_user)) {
        $_SESSION['user'] = $current_user;  // Cache for this request
    }
}

// Check admin access
if (empty($current_user) || $current_user['role'] !== 'superadmin') {
    http_response_code(403);
    die("❌ Access Denied. Superadmin only.");
}

global $conn;
$user_id = $_SESSION['user_id'];

// ===========================
// Handle bulk delete action
// ===========================
$deleted_count = 0;
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cleanup') {
    // Validate CSRF
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_msg = "❌ CSRF validation failed";
    } else {
        $months_old = intval($_POST['months_old'] ?? 6);
        if ($months_old < 1 || $months_old > 60) {
            $error_msg = "❌ Invalid months range (1-60)";
        } else {
            // Calculate cutoff date (6+ months ago)
            $cutoff_date = date('Y-m-d', strtotime("-$months_old months"));

            try {
                $conn->begin_transaction();

                // Fetch documents to delete (with details for audit)
                $stmt = $conn->prepare(
                    "SELECT id, doc_number, created_at FROM sipb_documents
                     WHERE status = 'Rejected' AND created_at < ?
                     ORDER BY created_at ASC"
                );
                $stmt->bind_param('s', $cutoff_date);
                $stmt->execute();
                $result = $stmt->get_result();
                $docs_to_delete = [];
                while ($row = $result->fetch_assoc()) {
                    $docs_to_delete[] = $row;
                }

                if (empty($docs_to_delete)) {
                    $error_msg = "ℹ️ No rejected documents older than $months_old months found.";
                } else {
                    // Delete documents and their items
                    foreach ($docs_to_delete as $doc) {
                        $doc_id = $doc['id'];

                        // Delete items first (foreign key)
                        $del_items = $conn->prepare("DELETE FROM sipb_items WHERE sipb_id = ?");
                        $del_items->bind_param('i', $doc_id);
                        $del_items->execute();

                        // Delete approval history
                        $del_approval = $conn->prepare("DELETE FROM sipb_approval_history WHERE sipb_id = ?");
                        $del_approval->bind_param('i', $doc_id);
                        $del_approval->execute();

                        // Delete security verifications
                        $del_verify = $conn->prepare("DELETE FROM sipb_security_verifications WHERE sipb_id = ?");
                        $del_verify->bind_param('i', $doc_id);
                        $del_verify->execute();

                        // Delete the document itself
                        $del_doc = $conn->prepare("DELETE FROM sipb_documents WHERE id = ?");
                        $del_doc->bind_param('i', $doc_id);
                        $del_doc->execute();

                        // Audit log
                        log_audit($conn, $doc_id, 'sipb_cleanup_deleted',
                            "Admin cleanup: Rejected SIPB deleted (doc: {$doc['doc_number']}, created: {$doc['created_at']})",
                            null, null);

                        $deleted_count++;
                    }

                    $conn->commit();
                    $success_msg = "✅ Deleted $deleted_count rejected SIPB document(s) older than $months_old months.";
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "❌ Cleanup failed: " . $e->getMessage();
            }
        }
    }
}

// Regenerate CSRF token for form
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ===========================
// Stats: Show current rejected count
// ===========================
$stats = [
    'total_rejected' => 0,
    'rejected_6m_old' => 0,
    'rejected_12m_old' => 0,
];

$result = $conn->query("SELECT COUNT(*) n FROM sipb_documents WHERE status = 'Rejected'");
$stats['total_rejected'] = $result->fetch_assoc()['n'];

$cutoff_6m = date('Y-m-d', strtotime('-6 months'));
$result = $conn->query("SELECT COUNT(*) n FROM sipb_documents WHERE status = 'Rejected' AND created_at < '$cutoff_6m'");
$stats['rejected_6m_old'] = $result->fetch_assoc()['n'];

$cutoff_12m = date('Y-m-d', strtotime('-12 months'));
$result = $conn->query("SELECT COUNT(*) n FROM sipb_documents WHERE status = 'Rejected' AND created_at < '$cutoff_12m'");
$stats['rejected_12m_old'] = $result->fetch_assoc()['n'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cleanup Rejected SIPB - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .stat-box { background: #f8f9fa; border-left: 4px solid #0d6efd; padding: 15px; margin: 10px 0; }
        .stat-box strong { display: block; font-size: 24px; color: #0d6efd; }
        .stat-box small { color: #666; }
        .danger-zone { background: #fff5f5; border: 2px solid #dc3545; padding: 20px; border-radius: 8px; }
    </style>
</head>
<body>
<div class="container mt-5">
    <h2>🗑️ Cleanup Rejected SIPB Documents</h2>
    <p class="text-muted">Admin-only tool: Delete rejected SIPB older than specified months to free server storage.</p>

    <?php if (!empty($error_msg)): ?>
    <div class="alert alert-warning"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <?php if (!empty($success_msg)): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row">
        <div class="col-md-4">
            <div class="stat-box">
                <strong><?php echo $stats['total_rejected']; ?></strong>
                <small>Total Rejected SIPB in database</small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-box">
                <strong><?php echo $stats['rejected_6m_old']; ?></strong>
                <small>Rejected > 6 months old</small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-box">
                <strong><?php echo $stats['rejected_12m_old']; ?></strong>
                <small>Rejected > 12 months old</small>
            </div>
        </div>
    </div>

    <!-- Cleanup Form -->
    <div class="danger-zone mt-4">
        <h4>⚠️ Delete Rejected SIPB</h4>
        <p>Permanently delete rejected SIPB documents older than:</p>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="mb-3">
                <label class="form-label">Older than (months):</label>
                <select name="months_old" class="form-select" required>
                    <option value="">-- Select --</option>
                    <option value="6">6 months (<?php echo $stats['rejected_6m_old']; ?> documents)</option>
                    <option value="9">9 months</option>
                    <option value="12">12 months (<?php echo $stats['rejected_12m_old']; ?> documents)</option>
                    <option value="18">18 months</option>
                    <option value="24">24 months</option>
                </select>
            </div>

            <div class="form-check mb-3">
                <input type="checkbox" class="form-check-input" id="confirm_delete" required>
                <label class="form-check-label" for="confirm_delete">
                    ✓ I confirm: This action is <strong>permanent</strong> and cannot be undone.
                </label>
            </div>

            <button type="submit" name="action" value="cleanup" class="btn btn-danger">
                🗑️ Delete Rejected Documents
            </button>
            <a href="javascript:history.back()" class="btn btn-secondary">← Back</a>
        </form>
    </div>

    <!-- Footer -->
    <div class="mt-5 text-muted text-center" style="border-top: 1px solid #ddd; padding-top: 20px;">
        <small>
            All deletions are logged in audit_log for compliance.
            This tool is restricted to superadmin access only.
        </small>
    </div>
</div>
</body>
</html>