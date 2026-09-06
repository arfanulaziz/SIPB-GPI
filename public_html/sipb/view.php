<?php
/**
 * public_html/sipb/view.php
 *
 * SIPB View/Detail Page
 * Display SIPB details dengan items, approval history, dan actions
 */

date_default_timezone_set('Asia/Jakarta');

require __DIR__ . '/../config/config.php';
require_login();

$current_user = get_logged_in_user();
$user_id = $current_user['id'];
$user_role = $current_user['role'];

$db = new Database($conn);

// Get SIPB ID
$sipb_id = intval($_GET['id'] ?? 0);
if ($sipb_id === 0) {
    header("Location: list.php");
    exit;
}

// Get SIPB
$sipb = $db->getRow(
    "SELECT * FROM sipb_documents WHERE id = ?",
    [$sipb_id]
);

if (!$sipb) {
    die("❌ SIPB tidak ditemukan.");
}

// Check permission: creator, superadmin, atau approver (butuh lihat detail untuk keperluan approval/riwayat) boleh akses
$is_owner = ($sipb['created_by'] == $user_id);
$is_privileged = in_array($user_role, ['superadmin', 'approver']);
if (!$is_owner && !$is_privileged) {
    die("❌ Anda tidak memiliki akses ke SIPB ini.");
}

// Get items
$items = $db->getRows(
    "SELECT * FROM sipb_items WHERE sipb_id = ? ORDER BY id ASC",
    [$sipb_id]
) ?? [];

// Get approval history
$approval_history = $db->getRows(
    "SELECT ah.*, u.name as approver_name, u.role
     FROM sipb_approval_history ah
     LEFT JOIN users u ON ah.approver_id = u.id
     WHERE ah.sipb_id = ?
     ORDER BY ah.approved_at DESC",
    [$sipb_id]
) ?? [];

// Get security verifications (untuk tracking keluaran barang yang sudah diverifikasi security)
$security_verifications = $db->getRows(
    "SELECT * FROM sipb_security_verifications WHERE sipb_id = ? ORDER BY verified_at DESC",
    [$sipb_id]
) ?? [];

// Get creator info
$creator = $db->getRow("SELECT id, name, email FROM users WHERE id = ?", [$sipb['created_by']]);

// Handle form submission (submit for approval)
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['form_action'])) {
    $form_action = $_POST['form_action'];

    if ($form_action === 'submit' && $sipb['status'] === 'Draft') {
        if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $error = "❌ CSRF token tidak valid.";
        } else {
            $update_query = "UPDATE sipb_documents SET status = 'Submitted' WHERE id = ?";
            if ($db->execute($update_query, [$sipb_id])) {
                log_audit($conn, $sipb_id, 'sipb_submitted', "SIPB submitted for approval", null, null);
                $success = "✅ SIPB berhasil disubmit untuk persetujuan.";
                $sipb['status'] = 'Submitted';
            } else {
                $error = "❌ Error menyimpan perubahan.";
            }
        }
    }
}

// Status info
$status_info = [
    'Draft' => ['label' => 'Draft', 'badge' => 'badge-draft', 'icon' => '📝'],
    'Submitted' => ['label' => 'Menunggu Persetujuan', 'badge' => 'badge-submitted', 'icon' => '⏳'],
    'Approved' => ['label' => 'Disetujui', 'badge' => 'badge-approved', 'icon' => '✅'],
    'Rejected' => ['label' => 'Ditolak', 'badge' => 'badge-rejected', 'icon' => '❌']
];
$current_status = $status_info[$sipb['status']] ?? ['label' => $sipb['status'], 'badge' => 'badge-neutral', 'icon' => '❓'];

$is_fully_approved = ($sipb['status'] === 'Approved');

$active_page = 'list';
$BASE = getenv('APP_URL') ?: 'http://localhost/SIPB-GPI';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($sipb['doc_number']); ?> - SIPB-GPI System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo $BASE; ?>/assets/css/theme.css" rel="stylesheet">
    <style>
        .doc-header { position: relative; overflow: hidden; }
        .doc-title { font-size: 26px; font-weight: bold; color: var(--text-dark); margin-bottom: 4px; }
        .doc-subtitle { color: var(--text-muted); margin: 0 0 16px 0; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px; }
        .info-box { background: #f4f5fb; padding: 14px 16px; border-radius: 8px; border-left: 4px solid var(--accent-blue); }
        .info-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 4px; letter-spacing: 0.4px; }
        .info-value { font-size: 15px; font-weight: 600; color: var(--text-dark); }

        /* Stempel APPROVED - CSS-only, muncul saat status Approved penuh */
        .approved-stamp {
            position: absolute;
            top: 20px;
            right: 30px;
            width: 170px;
            height: 72px;
            border: 4px double #c0392b;
            border-radius: 18px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #c0392b;
            font-weight: 900;
            transform: rotate(-12deg);
            opacity: 0.88;
            pointer-events: none;
            font-family: Arial, sans-serif;
        }
        .approved-stamp .stamp-text { font-size: 22px; letter-spacing: 1px; }
        .approved-stamp .stamp-sub { font-size: 10px; letter-spacing: 2px; margin-top: 2px; }
        @media (max-width: 700px) { .approved-stamp { display: none; } }
    </style>
</head>
<body>

<div class="app-shell">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="main-content">
        <!-- Actions -->
        <div class="content-card">
            <h5 style="margin-bottom: 16px;"><i class="fas fa-gear"></i> Actions</h5>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <?php if ($sipb['status'] === 'Draft'): ?>
                    <form method="POST" style="display: inline;" id="submitForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="form_action" value="submit">
                        <button type="submit" class="btn-primary-pill" id="submitBtn"><i class="fas fa-paper-plane"></i> Submit untuk Persetujuan</button>
                    </form>
                <?php endif; ?>
                <a href="<?php echo $BASE; ?>/index.php" class="btn-secondary-pill"><i class="fas fa-house"></i> Home</a>
                <a href="print.php?id=<?php echo $sipb_id; ?>" class="btn-primary-pill" target="_blank"><i class="fas fa-print"></i> Print</a>
                <a href="export-pdf.php?id=<?php echo $sipb_id; ?>" class="btn-primary-pill"><i class="fas fa-file-pdf"></i> Export PDF</a>
                <a href="list.php" class="btn-secondary-pill"><i class="fas fa-arrow-left"></i> Back to List</a>
            </div>
        </div>

        <!-- Header -->
        <div class="content-card doc-header">
            <?php if ($is_fully_approved): ?>
                <div class="approved-stamp">
                    <div class="stamp-text">APPROVED</div>
                    <div class="stamp-sub">SIPB-GPI</div>
                </div>
            <?php endif; ?>

            <div class="doc-title"><?php echo $current_status['icon']; ?> <?php echo htmlspecialchars($sipb['doc_number']); ?></div>
            <p class="doc-subtitle">Surat Ijin Pengeluaran Barang</p>

            <div class="info-grid">
                <div class="info-box">
                    <div class="info-label">Status</div>
                    <div class="info-value"><span class="badge-pill <?php echo $current_status['badge']; ?>"><?php echo $current_status['label']; ?></span></div>
                </div>
                <div class="info-box">
                    <div class="info-label">Kategori</div>
                    <div class="info-value"><?php echo htmlspecialchars($sipb['category']); ?></div>
                </div>
                <div class="info-box">
                    <div class="info-label">Created By</div>
                    <div class="info-value"><?php echo htmlspecialchars($creator['name'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-box">
                    <div class="info-label">Created At</div>
                    <div class="info-value"><?php echo date('d M Y H:i', strtotime($sipb['created_at'])); ?></div>
                </div>
                <div class="info-box">
                    <div class="info-label">Items</div>
                    <div class="info-value"><?php echo count($items); ?> barang</div>
                </div>
            </div>
        </div>

        <?php if (!empty($error)): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
        <?php if (!empty($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

        <!-- Items Section -->
        <div class="content-card">
            <h5 style="margin-bottom: 16px;"><i class="fas fa-box"></i> Item-Item Barang</h5>
            <?php if (count($items) > 0): ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px;">
                    <?php foreach ($items as $idx => $item): ?>
                        <div style="border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; background: #fafafa;">
                            <!-- Photo Display -->
                            <?php if (!empty($item['photo_path'])): ?>
                                <img src="<?php echo $BASE; ?>/<?php echo htmlspecialchars($item['photo_path']); ?>"
                                     style="width: 100%; height: 200px; object-fit: cover; border-bottom: 1px solid #e0e0e0;">
                            <?php else: ?>
                                <div style="width: 100%; height: 200px; background: #e0e0e0; display: flex; align-items: center; justify-content: center; color: #999; border-bottom: 1px solid #e0e0e0;">
                                    <i class="fas fa-image" style="font-size: 48px;"></i>
                                </div>
                            <?php endif; ?>

                            <!-- Item Details -->
                            <div style="padding: 14px;">
                                <div style="margin-bottom: 8px;">
                                    <span class="badge-pill badge-neutral" style="font-size: 11px;"><?php echo htmlspecialchars($item['category'] ?? '-'); ?></span>
                                </div>
                                <div style="font-weight: 700; color: #2c3e50; margin-bottom: 4px;">
                                    <?php echo htmlspecialchars($item['item_name']); ?>
                                </div>
                                <div style="font-size: 12px; color: #7f8c8d; margin-bottom: 8px;">
                                    Kode: <strong><?php echo htmlspecialchars($item['item_code']); ?></strong>
                                </div>
                                <div style="background: white; padding: 10px; border-radius: 6px; font-size: 13px; margin-bottom: 8px;">
                                    <div style="margin-bottom: 3px;"><strong>Qty:</strong> <?php echo rtrim(rtrim(number_format($item['quantity'], 2, '.', ''), '0'), '.'); ?> <?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?></div>
                                    <?php if (!empty($item['customer'])): ?><div style="margin-bottom: 3px;"><strong>Customer:</strong> <?php echo htmlspecialchars($item['customer']); ?></div><?php endif; ?>
                                    <?php if (!empty($item['application'])): ?><div style="margin-bottom: 3px;"><strong>Aplikasi:</strong> <?php echo htmlspecialchars($item['application']); ?></div><?php endif; ?>
                                    <?php if (!empty($item['notes'])): ?><div><strong>Ket:</strong> <?php echo htmlspecialchars($item['notes']); ?></div><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted text-center" style="padding: 30px 0;">Tidak ada item dalam SIPB ini.</p>
            <?php endif; ?>
        </div>

        <?php if (!empty($sipb['remarks'])): ?>
        <div class="content-card">
            <h5 style="margin-bottom: 12px;"><i class="fas fa-note-sticky"></i> Catatan Tambahan</h5>
            <p style="margin: 0;"><?php echo htmlspecialchars($sipb['remarks']); ?></p>
        </div>
        <?php endif; ?>

        <!-- Approval History -->
        <div class="content-card">
            <h5 style="margin-bottom: 16px;"><i class="fas fa-signature"></i> Riwayat Persetujuan</h5>
            <?php if (count($approval_history) > 0): ?>
                <div style="overflow-x: auto;">
                    <table class="styled-table">
                        <thead>
                            <tr><th>Approver</th><th>Posisi</th><th>Level</th><th>Status</th><th>Catatan</th><th>Date</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($approval_history as $ah): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($ah['approver_name'] ?? 'System'); ?></td>
                                    <td><small><?php echo htmlspecialchars($ah['position_name'] ?? $ah['role'] ?? '-'); ?></small></td>
                                    <td><?php echo htmlspecialchars($ah['approval_level']); ?></td>
                                    <td><span class="badge-pill <?php echo $ah['approval_status'] === 'Approved' ? 'badge-approved' : 'badge-rejected'; ?>"><?php echo htmlspecialchars($ah['approval_status']); ?></span></td>
                                    <td><?php echo htmlspecialchars($ah['approval_note'] ?? '-'); ?></td>
                                    <td><small><?php echo date('d M Y H:i', strtotime($ah['approved_at'])); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted text-center" style="padding: 30px 0;">Belum ada riwayat persetujuan.</p>
            <?php endif; ?>
        </div>

        <!-- Security Verifications -->
        <div class="content-card">
            <h5 style="margin-bottom: 16px;"><i class="fas fa-shield-halved"></i> Riwayat Verifikasi Security</h5>
            <?php if (count($security_verifications) > 0): ?>
                <div style="overflow-x: auto;">
                    <table class="styled-table">
                        <thead>
                            <tr><th>Nama Security</th><th>Catatan</th><th>IP Address</th><th>Waktu Verifikasi</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($security_verifications as $sv): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($sv['verified_by_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($sv['notes'] ?? '-'); ?></td>
                                    <td><small><?php echo htmlspecialchars($sv['ip_address'] ?? '-'); ?></small></td>
                                    <td><small><?php echo date('d M Y H:i:s', strtotime($sv['verified_at'])); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted text-center" style="padding: 30px 0;">
                    <?php if ($is_fully_approved): ?>
                        <i class="fas fa-qrcode" style="font-size: 24px; color: #ddd; margin-bottom: 10px; display: block;"></i>
                        Belum ada verifikasi security. Security perlu scan QR di print dokumen untuk verifikasi.
                    <?php else: ?>
                        <i class="fas fa-lock" style="font-size: 24px; color: #ddd; margin-bottom: 10px; display: block;"></i>
                        SIPB belum approved. Security bisa verifikasi setelah dokumen fully approved.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.addEventListener('click', function(e) {
            if (!confirm('Yakin ingin submit SIPB ini untuk persetujuan?')) {
                e.preventDefault();
            }
        });
    }
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

