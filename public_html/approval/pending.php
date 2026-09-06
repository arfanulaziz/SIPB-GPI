<?php
/**
 * public_html/approval/pending.php
 *
 * Pending Approvals Page
 * Display SIPB menunggu persetujuan dari current user
 * 2-level approval: SPV -> PM (Plant Manager)
 *
 * Schema notes (sipb_documents):
 *   status: enum('Draft','Submitted','Approved','Rejected')
 *   approval_spv_status: enum('Pending','Approved','Rejected')
 *   approval_pm_status: enum('Pending','Approved','Rejected')
 */

// NOTE: session_start() sengaja tidak dipanggil di sini - lihat config.php
date_default_timezone_set('Asia/Jakarta');

require __DIR__ . '/../config/config.php';
require_login();

// Only approvers can see this
require_roles(['approver', 'superadmin']);

$current_user = get_logged_in_user();
$user_id = $current_user['id'];
$user_role = $current_user['role'];
$user_approval_level = $current_user['approval_level']; // 'SPV', 'PM', atau null - berdasarkan posisi

$db = new Database($conn);

// Get pending approvals based on POSISI (approval_level) user, bukan role
$pending = [];

if ($user_role === 'approver' && $user_approval_level === 'SPV') {
    // Approver dengan posisi Level SPV
    $pending = $db->getRows(
        "SELECT sd.*, COUNT(si.id) as item_count,
                u.name as creator_name
         FROM sipb_documents sd
         LEFT JOIN sipb_items si ON sd.id = si.sipb_id
         LEFT JOIN users u ON sd.created_by = u.id
         WHERE sd.status = 'Submitted'
         AND sd.approval_spv_status = 'Pending'
         GROUP BY sd.id
         ORDER BY sd.created_at ASC",
        []
    ) ?? [];

} elseif ($user_role === 'approver' && $user_approval_level === 'PM') {
    // Approver dengan posisi Level PM (hanya setelah SPV approve)
    $pending = $db->getRows(
        "SELECT sd.*, COUNT(si.id) as item_count,
                u.name as creator_name
         FROM sipb_documents sd
         LEFT JOIN sipb_items si ON sd.id = si.sipb_id
         LEFT JOIN users u ON sd.created_by = u.id
         WHERE sd.status = 'Submitted'
         AND sd.approval_spv_status = 'Approved'
         AND sd.approval_pm_status = 'Pending'
         GROUP BY sd.id
         ORDER BY sd.created_at ASC",
        []
    ) ?? [];

} elseif ($user_role === 'superadmin') {
    // Superadmin sees all pending
    $pending = $db->getRows(
        "SELECT sd.*, COUNT(si.id) as item_count,
                u.name as creator_name
         FROM sipb_documents sd
         LEFT JOIN sipb_items si ON sd.id = si.sipb_id
         LEFT JOIN users u ON sd.created_by = u.id
         WHERE sd.status = 'Submitted'
         GROUP BY sd.id
         ORDER BY sd.created_at ASC",
        []
    ) ?? [];
}

// Count approvals waiting at each level
$count_spv = $db->getScalar(
    "SELECT COUNT(*) FROM sipb_documents
     WHERE status = 'Submitted' AND approval_spv_status = 'Pending'",
    []
) ?? 0;

$count_pm = $db->getScalar(
    "SELECT COUNT(*) FROM sipb_documents
     WHERE status = 'Submitted' AND approval_spv_status = 'Approved' AND approval_pm_status = 'Pending'",
    []
) ?? 0;

$total_pending = count($pending);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approval SIPB - SIPB-GPI System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo rtrim(getenv("APP_URL") ?: "http://localhost/SIPB-GPI", "/"); ?>/assets/css/theme.css" rel="stylesheet">
</head>
<body>

<?php $active_page = 'pending'; ?>
<div class="app-shell">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-clipboard-check"></i> Approval SIPB</h1>
        </div>

        <div class="stat-grid">
            <div class="stat-card blue">
                <div class="stat-label"><i class="fas fa-inbox"></i> Total Pending (Anda)</div>
                <div class="stat-number"><?php echo $total_pending; ?></div>
            </div>
            <div class="stat-card orange">
                <div class="stat-label"><i class="fas fa-user-tie"></i> Waiting at SPV Level</div>
                <div class="stat-number"><?php echo $count_spv; ?></div>
            </div>
            <div class="stat-card red">
                <div class="stat-label"><i class="fas fa-user-check"></i> Waiting at PM Level</div>
                <div class="stat-number"><?php echo $count_pm; ?></div>
            </div>
        </div>

        <div class="content-card">
            <?php if (count($pending) > 0): ?>
                <div style="overflow-x: auto;">
                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th>Doc Number</th><th>Kategori</th><th>Remarks</th><th>Items</th>
                                <th>Created By</th><th>Submitted</th><th>Waiting At</th><th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending as $approval): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($approval['doc_number']); ?></strong></td>
                                    <td><span class="badge-pill badge-neutral"><?php echo htmlspecialchars($approval['category']); ?></span></td>
                                    <td><?php echo htmlspecialchars($approval['remarks'] ?? '-'); ?></td>
                                    <td><span class="badge-pill badge-submitted"><?php echo intval($approval['item_count']); ?> items</span></td>
                                    <td><?php echo htmlspecialchars($approval['creator_name'] ?? 'N/A'); ?></td>
                                    <td><small><?php echo date('d M Y H:i', strtotime($approval['created_at'])); ?></small></td>
                                    <td>
                                        <?php $waiting_level = ($approval['approval_spv_status'] === 'Approved') ? 'PM' : 'SPV'; ?>
                                        <span class="badge-pill badge-pending"><?php echo $waiting_level; ?></span>
                                    </td>
                                    <td>
                                        <a href="approve.php?id=<?php echo $approval['id']; ?>" class="btn-action edit" title="Review"><i class="fas fa-pen"></i></a>
                                        <a href="../sipb/view.php?id=<?php echo $approval['id']; ?>" class="btn-action view" title="View"><i class="fas fa-eye"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 50px 20px; color: var(--text-muted);">
                    <i class="fas fa-inbox" style="font-size: 40px; margin-bottom: 12px; display: block;"></i>
                    <p>Tidak ada SIPB yang menunggu persetujuan Anda.</p>
                    <p style="font-size: 13px;">
                        <?php
                        if ($user_approval_level === 'SPV') {
                            echo "Semua SIPB sudah dipersetujui atau belum disubmit.";
                        } elseif ($user_approval_level === 'PM') {
                            echo "Semua SIPB sudah dipersetujui atau masih menunggu SPV.";
                        }
                        ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

