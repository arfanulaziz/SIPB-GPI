<?php
/**
 * public_html/sipb/list.php
 *
 * SIPB List Page
 * Display semua SIPB dengan filter, search, dan pagination
 */

// NOTE: session_start() sengaja tidak dipanggil di sini - lihat config.php
date_default_timezone_set('Asia/Jakarta');

require __DIR__ . '/../config/config.php';
require_login();
require_roles(['user', 'approver', 'superadmin']);

$current_user = get_logged_in_user();
$user_id = $current_user['id'];
$user_role = $current_user['role'];

$db = new Database($conn);

// Get filter parameters
$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$tab = trim($_GET['tab'] ?? 'all'); // pending | approved | rejected | all
$page = intval($_GET['page'] ?? 1);
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build query
$where = [];
$params = [];

// Scope: 'user' role hanya lihat SIPB miliknya sendiri.
// 'approver' dan 'superadmin' butuh lihat history SEMUA SIPB (bukan cuma yang mereka buat) -
// karena mereka approve/review dokumen orang lain, bukan cuma dokumen sendiri.
if (!in_array($user_role, ['superadmin', 'approver'])) {
    $where[] = "sd.created_by = ?";
    $params[] = $user_id;
}

if (!empty($search)) {
    $where[] = "(doc_number LIKE ? OR remarks LIKE ? OR company LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($category)) {
    // Kategori sekarang disimpan per-item, bukan per-dokumen.
    // Filter dokumen yang punya minimal 1 item dengan kategori terpilih.
    $where[] = "EXISTS (SELECT 1 FROM sipb_items si WHERE si.sipb_id = sd.id AND si.category = ?)";
    $params[] = $category;
}

if ($tab === 'pending') {
    $where[] = "status IN ('Draft', 'Submitted')";
} elseif ($tab === 'approved') {
    $where[] = "status = 'Approved'";
} elseif ($tab === 'rejected') {
    $where[] = "status = 'Rejected'";
}
// tab === 'all' -> tidak ada filter status tambahan

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Stat counts (ikut scope role, terpisah dari filter tab yang aktif)
$is_own_scope_only = !in_array($user_role, ['superadmin', 'approver']);
$stat_scope_where = $is_own_scope_only ? "WHERE created_by = ?" : "";
$stat_scope_params = $is_own_scope_only ? [$user_id] : [];
$stat_total = $db->getScalar("SELECT COUNT(*) FROM sipb_documents $stat_scope_where", $stat_scope_params) ?? 0;
$stat_approved = $db->getScalar(
    "SELECT COUNT(*) FROM sipb_documents " . ($stat_scope_where ? "$stat_scope_where AND status = 'Approved'" : "WHERE status = 'Approved'"),
    $stat_scope_params
) ?? 0;
$stat_pending = $db->getScalar(
    "SELECT COUNT(*) FROM sipb_documents " . ($stat_scope_where ? "$stat_scope_where AND status IN ('Draft','Submitted')" : "WHERE status IN ('Draft','Submitted')"),
    $stat_scope_params
) ?? 0;
$stat_rejected = $db->getScalar(
    "SELECT COUNT(*) FROM sipb_documents " . ($stat_scope_where ? "$stat_scope_where AND status = 'Rejected'" : "WHERE status = 'Rejected'"),
    $stat_scope_params
) ?? 0;

// Count total (perlu alias sd juga di count query karena EXISTS subquery pakai alias sd)
$count_query = "SELECT COUNT(*) as total FROM sipb_documents sd $where_clause";
$total = $db->getScalar($count_query, $params) ?? 0;
$total_pages = ceil($total / $per_page);

// Get list - kategori diambil live dari item (gabungan unique, bukan dari kolom category dokumen yang statis)
$query = "SELECT sd.id, sd.doc_number, sd.remarks, sd.company, sd.status, sd.created_by, sd.created_at, sd.doc_date,
                 sd.approval_spv_status, sd.approval_spv_at, spv_u.name as spv_approver_name,
                 sd.approval_pm_status, sd.approval_pm_at, pm_u.name as pm_approver_name,
                 creator.name as creator_name,
                 (SELECT COUNT(*) FROM sipb_items WHERE sipb_id = sd.id) as item_count,
                 (SELECT GROUP_CONCAT(DISTINCT item_name SEPARATOR ', ') FROM sipb_items WHERE sipb_id = sd.id) as item_names,
                 (SELECT GROUP_CONCAT(DISTINCT category ORDER BY category SEPARATOR ', ') FROM sipb_items WHERE sipb_id = sd.id) as categories,
                 (SELECT GROUP_CONCAT(DISTINCT verified_by_name SEPARATOR ', ') FROM sipb_security_verifications WHERE sipb_id = sd.id) as security_verifiers
          FROM sipb_documents sd
          LEFT JOIN users creator ON sd.created_by = creator.id
          LEFT JOIN users spv_u ON sd.approval_spv_by = spv_u.id
          LEFT JOIN users pm_u ON sd.approval_pm_by = pm_u.id
          $where_clause
          ORDER BY sd.created_at DESC
          LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;

$sipbs = $db->getRows($query, $params) ?? [];

// Get categories list
$categories = [
    'R&D Sample' => 'R&D Sample',
    'Prototipe' => 'Prototipe',
    'Alat' => 'Alat',
    'QC Sample' => 'QC Sample',
    'Lain-lain' => 'Lain-lain'
];
$statuses = ['Draft' => 'Draft', 'Submitted' => 'Submitted', 'Approved' => 'Approved', 'Rejected' => 'Rejected'];

// Status colors
$status_colors = [
    'Draft' => 'warning',
    'Submitted' => 'info',
    'Approved' => 'success',
    'Rejected' => 'danger'
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History SIPB - SIPB-GPI System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo rtrim(getenv("APP_URL") ?: "http://localhost/SIPB-GPI", "/"); ?>/assets/css/theme.css" rel="stylesheet">
    <style>
        .search-form { display: flex; gap: 10px; align-items: center; }
        .item-preview { font-size: 12.5px; color: var(--text-muted); max-width: 220px; }
        .approver-cell { font-size: 12.5px; }
        .approver-cell .name { font-weight: 600; color: var(--text-dark); }
        .approver-cell .date { color: var(--text-muted); }
    </style>
</head>
<body>

<?php $active_page = 'list'; ?>
<div class="app-shell">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-clock-rotate-left"></i> History SIPB Document</h1>
            <form method="GET" class="search-form">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
                <input type="text" name="search" class="page-search" placeholder="Search SIPB..." value="<?php echo htmlspecialchars($search); ?>">
            </form>
        </div>

        <div class="stat-grid">
            <div class="stat-card blue">
                <div class="stat-label"><i class="fas fa-file-lines"></i> Total SIPB</div>
                <div class="stat-number"><?php echo $stat_total; ?></div>
            </div>
            <div class="stat-card green">
                <div class="stat-label"><i class="fas fa-circle-check"></i> Approved</div>
                <div class="stat-number"><?php echo $stat_approved; ?></div>
            </div>
            <div class="stat-card orange">
                <div class="stat-label"><i class="fas fa-clock"></i> Pending</div>
                <div class="stat-number"><?php echo $stat_pending; ?></div>
            </div>
            <div class="stat-card red">
                <div class="stat-label"><i class="fas fa-circle-xmark"></i> Rejected</div>
                <div class="stat-number"><?php echo $stat_rejected; ?></div>
            </div>
        </div>

        <div class="filter-tabs">
            <a href="?tab=pending&search=<?php echo urlencode($search); ?>" class="filter-tab <?php echo $tab === 'pending' ? 'active-yellow' : ''; ?>">
                <i class="fas fa-clock"></i> Pending
            </a>
            <a href="?tab=approved&search=<?php echo urlencode($search); ?>" class="filter-tab <?php echo $tab === 'approved' ? 'active-green' : ''; ?>">
                <i class="fas fa-check"></i> Approved
            </a>
            <a href="?tab=rejected&search=<?php echo urlencode($search); ?>" class="filter-tab <?php echo $tab === 'rejected' ? 'active-red' : ''; ?>">
                <i class="fas fa-times"></i> Rejected
            </a>
            <a href="?tab=all&search=<?php echo urlencode($search); ?>" class="filter-tab <?php echo $tab === 'all' ? 'active-blue' : ''; ?>">
                <i class="fas fa-folder-open"></i> All
            </a>
        </div>

        <div class="content-card">
            <?php if (count($sipbs) > 0): ?>
                <div style="overflow-x: auto;">
                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th>No SIPB</th>
                                <th>Kategori</th>
                                <th>Company</th>
                                <th>Items</th>
                                <th>SPV.GA.HRD</th>
                                <th>Plant Manager</th>
                                <th>Status</th>
                                <th>Security Verified</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sipbs as $sipb): ?>
                                <tr>
                                    <td>
                                        <a href="view.php?id=<?php echo $sipb['id']; ?>" style="font-weight: 700; color: var(--accent-blue); text-decoration: none;">
                                            <?php echo htmlspecialchars($sipb['doc_number']); ?>
                                        </a>
                                        <div style="font-size: 12px; color: var(--text-muted); margin-top: 3px;">
                                            Created By: <?php echo htmlspecialchars($sipb['creator_name'] ?? '-'); ?><br>
                                            <?php echo date('d-m-Y H:i', strtotime($sipb['created_at'])); ?> WIB
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($sipb['categories'])): ?>
                                            <?php foreach (explode(', ', $sipb['categories']) as $cat): ?>
                                                <span class="badge-pill badge-neutral" style="display:block; margin-bottom:3px; width:fit-content;"><?php echo htmlspecialchars($cat); ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($sipb['company'] ?? '-'); ?></td>
                                    <td>
                                        <div class="item-preview"><?php echo htmlspecialchars($sipb['item_names'] ?? '-'); ?></div>
                                        <span class="badge-pill badge-neutral" style="margin-top: 4px; display: inline-block;"><?php echo intval($sipb['item_count']); ?> items</span>
                                    </td>
                                    <td class="approver-cell">
                                        <?php if ($sipb['approval_spv_status'] === 'Approved'): ?>
                                            <span class="badge-pill badge-approved">APPROVED</span>
                                            <div class="name" style="margin-top: 4px;"><?php echo htmlspecialchars($sipb['spv_approver_name'] ?? '-'); ?></div>
                                            <div class="date"><?php echo $sipb['approval_spv_at'] ? date('d-m-Y H:i', strtotime($sipb['approval_spv_at'])) . ' WIB' : ''; ?></div>
                                        <?php elseif ($sipb['approval_spv_status'] === 'Rejected'): ?>
                                            <span class="badge-pill badge-rejected">REJECTED</span>
                                        <?php else: ?>
                                            <span class="badge-pill badge-pending">PENDING</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="approver-cell">
                                        <?php if ($sipb['approval_pm_status'] === 'Approved'): ?>
                                            <span class="badge-pill badge-approved">APPROVED</span>
                                            <div class="name" style="margin-top: 4px;"><?php echo htmlspecialchars($sipb['pm_approver_name'] ?? '-'); ?></div>
                                            <div class="date"><?php echo $sipb['approval_pm_at'] ? date('d-m-Y H:i', strtotime($sipb['approval_pm_at'])) . ' WIB' : ''; ?></div>
                                        <?php elseif ($sipb['approval_pm_status'] === 'Rejected'): ?>
                                            <span class="badge-pill badge-rejected">REJECTED</span>
                                        <?php else: ?>
                                            <span class="badge-pill badge-pending">PENDING</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-pill <?php echo $status_colors[$sipb['status']] === 'success' ? 'badge-approved' : ($status_colors[$sipb['status']] === 'danger' ? 'badge-rejected' : ($status_colors[$sipb['status']] === 'info' ? 'badge-submitted' : 'badge-draft')); ?>">
                                            <?php echo $statuses[$sipb['status']] ?? $sipb['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($sipb['security_verifiers'])): ?>
                                            <span class="badge-pill badge-approved" style="display: block; margin-bottom: 4px;">
                                                <i class="fas fa-shield-halved"></i> ✓ Verified
                                            </span>
                                            <div style="font-size: 11px; color: var(--text-muted);">
                                                By: <?php echo htmlspecialchars($sipb['security_verifiers']); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge-pill badge-pending">
                                                <i class="fas fa-qrcode"></i> Pending
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="view.php?id=<?php echo $sipb['id']; ?>" class="btn-action view" title="View"><i class="fas fa-eye"></i></a>
                                        <a href="print.php?id=<?php echo $sipb['id']; ?>" class="btn-action print" target="_blank" title="Print"><i class="fas fa-print"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation" style="margin-top: 20px;">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&tab=<?php echo urlencode($tab); ?>">Prev</a></li>
                            <?php endif; ?>
                            <li class="page-item active"><span class="page-link">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span></li>
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&tab=<?php echo urlencode($tab); ?>">Next</a></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>

                <p style="color: var(--text-muted); margin-top: 10px; font-size: 13px;">
                    Showing <?php echo count($sipbs); ?> of <?php echo $total; ?> SIPB records
                </p>
            <?php else: ?>
                <div style="text-align: center; padding: 50px 20px; color: var(--text-muted);">
                    <i class="fas fa-inbox" style="font-size: 40px; margin-bottom: 12px; display: block;"></i>
                    <p>Tidak ada SIPB ditemukan.</p>
                    <a href="create.php" class="btn-primary-pill" style="margin-top: 10px;">+ Buat SIPB Baru</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

