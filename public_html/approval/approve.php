<?php
/**
 * public_html/approval/approve.php
 *
 * Approval Action Page
 * Handle approve/reject dengan notes
 *
 * Schema notes (sipb_documents):
 *   status: enum('Draft','Submitted','Approved','Rejected')
 *   approval_spv_status / approval_pm_status: enum('Pending','Approved','Rejected')
 */

// NOTE: session_start() sengaja tidak dipanggil di sini - lihat config.php
date_default_timezone_set('Asia/Jakarta');

require __DIR__ . '/../config/config.php';
require_login();

// Only approvers can access
require_roles(['approver', 'superadmin']);

$current_user = get_logged_in_user();
$user_id = $current_user['id'];
$user_role = $current_user['role'];
$user_approval_level = $current_user['approval_level']; // 'SPV', 'PM', atau null - berdasarkan posisi user

$db = new Database($conn);

// Get SIPB ID
$sipb_id = intval($_GET['id'] ?? 0);
if ($sipb_id === 0) {
    header("Location: pending.php");
    exit;
}

// Get SIPB
$sipb = $db->getRow(
    "SELECT * FROM sipb_documents WHERE id = ?",
    [$sipb_id]
);

if (!$sipb || $sipb['status'] !== 'Submitted') {
    die("❌ SIPB tidak ditemukan atau tidak dalam status Submitted.");
}

// Get items
$items = $db->getRows(
    "SELECT * FROM sipb_items WHERE sipb_id = ? ORDER BY id ASC",
    [$sipb_id]
) ?? [];

// Get creator info
$creator = $db->getRow("SELECT id, name, email FROM users WHERE id = ?", [$sipb['created_by']]);

// Determine approval level based on POSISI user (bukan role) & current SIPB approval state
if ($user_role === 'superadmin') {
    // Superadmin approves whichever level is still pending
    $approval_level = ($sipb['approval_spv_status'] === 'Approved') ? 'PM' : 'SPV';
} elseif ($user_approval_level === 'SPV' || $user_approval_level === 'PM') {
    $approval_level = $user_approval_level;
} else {
    die("❌ Akun Anda belum di-assign ke posisi approval manapun. Hubungi admin untuk set jabatan approval di User Management.");
}

// Guard: level must actually be pending
$level_field = 'approval_' . strtolower($approval_level) . '_status';
if ($sipb[$level_field] !== 'Pending') {
    die("❌ SIPB ini tidak sedang menunggu persetujuan Anda di level $approval_level.");
}

// Guard: PM level can only approve after SPV approved
if ($approval_level === 'PM' && $sipb['approval_spv_status'] !== 'Approved') {
    die("❌ SIPB ini belum disetujui di level SPV.");
}

// Handle form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "❌ CSRF token tidak valid.";
    } else {
        $decision = trim($_POST['decision'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if (!in_array($decision, ['Approved', 'Rejected'])) {
            $error = "❌ Keputusan tidak valid.";
        } elseif ($decision === 'Rejected' && empty($notes)) {
            $error = "❌ Alasan penolakan harus diisi.";
        } else {
            $field_prefix = 'approval_' . strtolower($approval_level);

            try {
                $conn->begin_transaction();

                if ($decision === 'Approved') {
                    $doc_status = ($approval_level === 'PM') ? 'Approved' : 'Submitted';

                    $query = "UPDATE sipb_documents SET
                              {$field_prefix}_status = 'Approved',
                              {$field_prefix}_by = ?,
                              {$field_prefix}_at = NOW(),
                              {$field_prefix}_note = ?,
                              status = ?
                              WHERE id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param('issi', $user_id, $notes, $doc_status, $sipb_id);
                    $stmt->execute();
                } else {
                    $query = "UPDATE sipb_documents SET
                              {$field_prefix}_status = 'Rejected',
                              {$field_prefix}_by = ?,
                              {$field_prefix}_at = NOW(),
                              {$field_prefix}_note = ?,
                              status = 'Rejected'
                              WHERE id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param('isi', $user_id, $notes, $sipb_id);
                    $stmt->execute();
                }

                // Log to approval history (snapshot nama posisi saat itu - superadmin approve pakai label khusus)
                $approver_position_name = ($user_role === 'superadmin')
                    ? 'Superadmin (Override)'
                    : ($current_user['position_name'] ?? $approval_level);

                $history_query = "INSERT INTO sipb_approval_history
                                  (sipb_id, approval_level, approval_status, approver_id, position_name, approval_note)
                                  VALUES (?, ?, ?, ?, ?, ?)";
                $hstmt = $conn->prepare($history_query);
                $hstmt->bind_param('ississ', $sipb_id, $approval_level, $decision, $user_id, $approver_position_name, $notes);
                $hstmt->execute();

                $conn->commit();

                log_audit($conn, $sipb_id, 'sipb_' . strtolower($decision), "SIPB $decision at $approval_level level", null, null);

                if ($decision === 'Approved') {
                    $success = "✅ SIPB berhasil disetujui di level $approval_level.";
                    if ($approval_level === 'PM') {
                        $success .= " Semua persetujuan selesai, SIPB disetujui!";
                    }
                } else {
                    $success = "✅ SIPB berhasil ditolak.";
                }

                header("Refresh: 2; url=pending.php");
            } catch (Exception $e) {
                $conn->rollback();
                $error = "❌ Error: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review SIPB - SIPB-GPI System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo rtrim(getenv("APP_URL") ?: "http://localhost/SIPB-GPI", "/"); ?>/assets/css/theme.css" rel="stylesheet">
    <style>
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px; }
        .info-box { background: #f4f5fb; padding: 14px 16px; border-radius: 8px; border-left: 4px solid var(--accent-blue); }
        .info-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 4px; }
        .info-value { font-size: 15px; font-weight: 600; color: var(--text-dark); }
        .form-section { margin-top: 20px; padding: 20px; background: #f4f5fb; border-radius: 10px; border-left: 4px solid var(--accent-blue); }
        .form-section-title { font-size: 16px; font-weight: bold; margin-bottom: 15px; color: var(--text-dark); }
    </style>
</head>
<body>

<?php $active_page = 'pending'; ?>
<div class="app-shell">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="content-card">
            <h1 class="page-title" style="font-size: 22px; margin-bottom: 4px;"><i class="fas fa-file-signature"></i> Review SIPB</h1>
            <p style="color: var(--text-muted); margin: 0 0 16px 0;"><?php echo htmlspecialchars($sipb['doc_number']); ?></p>

            <div class="info-grid">
                <div class="info-box">
                    <div class="info-label">Kategori</div>
                    <div class="info-value"><?php echo htmlspecialchars($sipb['category']); ?></div>
                </div>
                <div class="info-box">
                    <div class="info-label">Level Persetujuan</div>
                    <div class="info-value"><?php echo htmlspecialchars($current_user['position_name'] ?? $approval_level); ?> <small class="text-muted">(Level <?php echo htmlspecialchars($approval_level); ?>)</small></div>
                </div>
                <div class="info-box">
                    <div class="info-label">Dibuat Oleh</div>
                    <div class="info-value"><?php echo htmlspecialchars($creator['name'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-box">
                    <div class="info-label">Items</div>
                    <div class="info-value"><?php echo count($items); ?> barang</div>
                </div>
            </div>
        </div>

        <?php if (!empty($error)): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
        <?php if (!empty($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

        <div class="content-card">
            <h5 style="margin-bottom: 16px;"><i class="fas fa-box"></i> Item-Item Barang</h5>
            <div style="overflow-x: auto;">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>Kategori</th><th>Kode</th><th>Nama Barang</th><th>Qty</th>
                            <th>Unit</th><th>Customer</th><th>Aplikasi</th><th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><span class="badge-pill badge-neutral"><?php echo htmlspecialchars($item['category'] ?? '-'); ?></span></td>
                                <td><strong><?php echo htmlspecialchars($item['item_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                <td><?php echo rtrim(rtrim(number_format($item['quantity'], 2, '.', ''), '0'), '.'); ?></td>
                                <td><?php echo htmlspecialchars($item['unit'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($item['customer'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($item['application'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($item['notes'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (empty($success)): ?>
        <div class="content-card">
            <form method="POST" id="approvalForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-signature"></i> Keputusan Persetujuan (Level <?php echo htmlspecialchars($approval_level); ?>)</div>
                    <div class="mb-3">
                        <label class="form-label">Keputusan <span style="color: red;">*</span></label>
                        <div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="decision" id="approve" value="Approved" required>
                                <label class="form-check-label" for="approve">✅ Setujui SIPB ini</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="decision" id="reject" value="Rejected" required>
                                <label class="form-check-label" for="reject">❌ Tolak SIPB ini</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="notes" class="form-label">Catatan / Alasan <span id="requiredLabel" style="color: red; display: none;">*</span></label>
                        <textarea class="form-control" id="notes" name="notes" rows="4" placeholder="Masukkan catatan persetujuan atau alasan penolakan..."></textarea>
                        <small class="text-muted">Untuk penolakan, alasan wajib diisi.</small>
                    </div>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <button type="submit" class="btn-primary-pill" id="submitBtn" disabled><i class="fas fa-check"></i> Kirim Keputusan</button>
                        <a href="pending.php" class="btn-secondary-pill"><i class="fas fa-arrow-left"></i> Kembali</a>
                    </div>
                </div>
            </form>
        </div>
        <?php else: ?>
        <div class="content-card">
            <a href="pending.php" class="btn-secondary-pill"><i class="fas fa-arrow-left"></i> Kembali ke Approval SIPB</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    const approveRadio = document.getElementById('approve');
    const rejectRadio = document.getElementById('reject');
    const notesField = document.getElementById('notes');
    const submitBtn = document.getElementById('submitBtn');
    const requiredLabel = document.getElementById('requiredLabel');
    const form = document.getElementById('approvalForm');

    function updateFormState() {
        const selected = document.querySelector('input[name="decision"]:checked');
        if (selected) {
            submitBtn.disabled = false;
            if (rejectRadio.checked) {
                requiredLabel.style.display = 'inline';
                notesField.required = true;
            } else {
                requiredLabel.style.display = 'none';
                notesField.required = false;
            }
        } else {
            submitBtn.disabled = true;
        }
    }

    if (approveRadio && rejectRadio) {
        approveRadio.addEventListener('change', updateFormState);
        rejectRadio.addEventListener('change', updateFormState);
    }

    if (form) {
        form.addEventListener('submit', function(e) {
            if (rejectRadio.checked && !notesField.value.trim()) {
                e.preventDefault();
                alert('❌ Alasan penolakan harus diisi!');
                notesField.focus();
            }
        });
    }
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

