<?php
/**
 * public_html/admin/fg-products/list.php
 *
 * Manage Database FG (Superadmin only)
 * CRUD untuk kode & nama barang FG (dipakai di dropdown Kode Barang saat kategori R&D Sample/QC Sample)
 */

date_default_timezone_set('Asia/Jakarta');

require __DIR__ . '/../../config/config.php';
require_login();
require_roles(['superadmin']);

$current_user = get_logged_in_user();
$db = new Database($conn);

$error = '';
$success = '';

// ============================================
// Handle POST actions
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "❌ CSRF token tidak valid.";
    } else {
        $action = $_POST['action'] ?? '';

        // --- Create new FG product ---
        if ($action === 'create_fg') {
            $code = trim($_POST['code'] ?? '');
            $name = trim($_POST['name'] ?? '');

            if (empty($code) || empty($name)) {
                $error = "❌ Kode dan Nama Barang wajib diisi.";
            } else {
                $existing = $db->getRow("SELECT id FROM fg_products WHERE code = ?", [$code]);
                if ($existing) {
                    $error = "❌ Kode barang \"$code\" sudah ada.";
                } else {
                    $stmt = $conn->prepare("INSERT INTO fg_products (code, name, applicable_categories) VALUES (?, ?, 'R&D Sample,QC Sample')");
                    $stmt->bind_param('ss', $code, $name);
                    if ($stmt->execute()) {
                        $success = "✅ Barang \"$code - $name\" berhasil ditambahkan.";
                        log_audit($conn, 0, 'fg_product_created', "Admin tambah FG product: $code - $name", null, null);
                    } else {
                        $error = "❌ Gagal menambahkan: " . $stmt->error;
                    }
                }
            }
        }

        // --- Update existing FG product ---
        elseif ($action === 'update_fg') {
            $target_id = intval($_POST['fg_id'] ?? 0);
            $code = trim($_POST['code'] ?? '');
            $name = trim($_POST['name'] ?? '');

            if ($target_id === 0 || empty($code) || empty($name)) {
                $error = "❌ Data tidak lengkap.";
            } else {
                $dup = $db->getRow("SELECT id FROM fg_products WHERE code = ? AND id != ?", [$code, $target_id]);
                if ($dup) {
                    $error = "❌ Kode barang \"$code\" sudah dipakai barang lain.";
                } else {
                    $stmt = $conn->prepare("UPDATE fg_products SET code = ?, name = ? WHERE id = ?");
                    $stmt->bind_param('ssi', $code, $name, $target_id);
                    if ($stmt->execute()) {
                        $success = "✅ Barang berhasil diupdate.";
                        log_audit($conn, 0, 'fg_product_updated', "Admin update FG product ID $target_id: $code - $name", null, null);
                    } else {
                        $error = "❌ Gagal update: " . $stmt->error;
                    }
                }
            }
        }

        // --- Delete FG product ---
        elseif ($action === 'delete_fg') {
            $target_id = intval($_POST['fg_id'] ?? 0);
            if ($target_id > 0) {
                // Cek dulu apakah kode barang ini pernah dipakai di SIPB (jangan hapus kalau sudah dipakai, demi integritas riwayat)
                $used = $db->getRow(
                    "SELECT si.id FROM sipb_items si
                     JOIN fg_products fg ON si.item_code = fg.code COLLATE utf8mb4_unicode_ci
                     WHERE fg.id = ? LIMIT 1",
                    [$target_id]
                );
                if ($used) {
                    $error = "❌ Barang ini sudah pernah dipakai di SIPB, tidak bisa dihapus (demi jaga riwayat data). Kalau memang sudah tidak dipakai lagi, edit namanya jadi \"[Discontinued] ...\" saja.";
                } else {
                    $stmt = $conn->prepare("DELETE FROM fg_products WHERE id = ?");
                    $stmt->bind_param('i', $target_id);
                    if ($stmt->execute()) {
                        $success = "✅ Barang berhasil dihapus.";
                        log_audit($conn, 0, 'fg_product_deleted', "Admin hapus FG product ID $target_id", null, null);
                    } else {
                        $error = "❌ Gagal hapus: " . $stmt->error;
                    }
                }
            }
        }

        // --- Bulk import via textarea (format: KODE|NAMA per baris) ---
        elseif ($action === 'bulk_import') {
            $bulk_text = trim($_POST['bulk_text'] ?? '');
            if (empty($bulk_text)) {
                $error = "❌ Data bulk import kosong.";
            } else {
                $lines = explode("\n", $bulk_text);
                $added = 0;
                $skipped = 0;
                $stmt = $conn->prepare("INSERT INTO fg_products (code, name, applicable_categories) VALUES (?, ?, 'R&D Sample,QC Sample')");
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    $parts = explode('|', $line, 2);
                    if (count($parts) !== 2) { $skipped++; continue; }
                    $code = trim($parts[0]);
                    $name = trim($parts[1]);
                    if (empty($code) || empty($name)) { $skipped++; continue; }

                    $existing = $db->getRow("SELECT id FROM fg_products WHERE code = ?", [$code]);
                    if ($existing) { $skipped++; continue; }

                    $stmt->bind_param('ss', $code, $name);
                    if ($stmt->execute()) $added++;
                    else $skipped++;
                }
                $success = "✅ Bulk import selesai: $added barang ditambahkan, $skipped dilewati (duplikat/format salah).";
                log_audit($conn, 0, 'fg_product_bulk_import', "Admin bulk import: $added added, $skipped skipped", null, null);
            }
        }
    }
}

// ============================================
// Fetch data
// ============================================
$search = trim($_GET['search'] ?? '');
$page = intval($_GET['page'] ?? 1);
$per_page = 25;
$offset = ($page - 1) * $per_page;

$where = '';
$params = [];
if (!empty($search)) {
    $where = "WHERE code LIKE ? OR name LIKE ?";
    $params = ["%$search%", "%$search%"];
}

$total = $db->getScalar("SELECT COUNT(*) FROM fg_products $where", $params) ?? 0;
$total_pages = max(1, ceil($total / $per_page));

$list_params = array_merge($params, [$per_page, $offset]);
$fg_products = $db->getRows(
    "SELECT * FROM fg_products $where ORDER BY code ASC LIMIT ? OFFSET ?",
    $list_params
) ?? [];

$active_page = 'fg-products';
$BASE = getenv('APP_URL') ?: 'http://localhost/SIPB-GPI';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Database FG - SIPB-GPI System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo $BASE; ?>/assets/css/theme.css" rel="stylesheet">
    <style>
        .modal-header { background: #2c3468; color: #fff; }
        .modal-header .btn-close { filter: invert(1); }
    </style>
</head>
<body>

<div class="app-shell">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-database"></i> Manage Database FG</h1>
            <div style="display: flex; gap: 10px;">
                <button class="btn-secondary-pill" data-bs-toggle="modal" data-bs-target="#bulkImportModal">
                    <i class="fas fa-file-import"></i> Bulk Import
                </button>
                <button class="btn-primary-pill" data-bs-toggle="modal" data-bs-target="#createFgModal">
                    <i class="fas fa-plus"></i> Tambah Barang
                </button>
            </div>
        </div>
        <p class="welcome-text" style="color: var(--text-muted); margin-bottom: 20px; font-size: 14px;">
            Kelola master data kode & nama barang FG yang muncul di dropdown "Kode Barang" saat user pilih kategori R&D Sample / QC Sample. Total: <strong><?php echo $total; ?></strong> barang.
        </p>

        <?php if (!empty($error)): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
        <?php if (!empty($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

        <div class="content-card">
            <form method="GET" style="margin-bottom: 20px;">
                <input type="text" name="search" class="page-search" style="width: 320px;" placeholder="Cari kode atau nama barang..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn-secondary-pill" style="margin-left: 8px;"><i class="fas fa-search"></i> Cari</button>
                <?php if (!empty($search)): ?>
                    <a href="list.php" class="btn-secondary-pill"><i class="fas fa-times"></i> Reset</a>
                <?php endif; ?>
            </form>

            <?php if (count($fg_products) > 0): ?>
                <div style="overflow-x: auto;">
                    <table class="styled-table">
                        <thead>
                            <tr><th>Kode Barang</th><th>Nama Barang</th><th>Ditambahkan</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fg_products as $fg): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($fg['code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($fg['name']); ?></td>
                                    <td><small class="text-muted"><?php echo date('d M Y', strtotime($fg['created_at'])); ?></small></td>
                                    <td>
                                        <button class="btn-action edit" data-bs-toggle="modal" data-bs-target="#editFgModal"
                                            data-id="<?php echo $fg['id']; ?>"
                                            data-code="<?php echo htmlspecialchars($fg['code']); ?>"
                                            data-name="<?php echo htmlspecialchars($fg['name']); ?>"
                                            title="Edit"><i class="fas fa-pen"></i></button>
                                        <button class="btn-action delete" onclick="confirmDelete(<?php echo $fg['id']; ?>, '<?php echo htmlspecialchars(addslashes($fg['code'])); ?>')" title="Hapus"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <nav style="margin-top: 20px;">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>">Prev</a></li>
                            <?php endif; ?>
                            <li class="page-item active"><span class="page-link">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span></li>
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>">Next</a></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php else: ?>
                <div style="text-align: center; padding: 50px 20px; color: var(--text-muted);">
                    <i class="fas fa-box-open" style="font-size: 40px; margin-bottom: 12px; display: block;"></i>
                    <p>Tidak ada barang ditemukan.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal: Create FG Product -->
<div class="modal fade" id="createFgModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="create_fg">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Barang FG</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Kode Barang</label>
                        <input type="text" name="code" class="form-control" placeholder="Contoh: FG00113" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nama Barang</label>
                        <input type="text" name="name" class="form-control" placeholder="Contoh: Indogel SGP-F30" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Tambah</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit FG Product -->
<div class="modal fade" id="editFgModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_fg">
                <input type="hidden" name="fg_id" id="editFgId">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Barang FG</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Kode Barang</label>
                        <input type="text" name="code" id="editFgCode" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nama Barang</label>
                        <input type="text" name="name" id="editFgName" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Bulk Import -->
<div class="modal fade" id="bulkImportModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="bulk_import">
                <div class="modal-header">
                    <h5 class="modal-title">Bulk Import Barang FG</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p style="font-size: 13px; color: #666;">
                        Paste banyak barang sekaligus, <strong>1 baris = 1 barang</strong>, format: <code>KODE|NAMA BARANG</code><br>
                        Contoh:<br>
                        <code>FG00113|Indogel SGP-F30</code><br>
                        <code>FG00114|Indogel SGP-F31</code>
                    </p>
                    <textarea name="bulk_text" class="form-control" rows="10" placeholder="FG00113|Indogel SGP-F30&#10;FG00114|Indogel SGP-F31" required></textarea>
                    <div class="form-text">Kode yang sudah ada akan dilewati otomatis (tidak akan duplikat).</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Import</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden delete form -->
<form method="POST" id="deleteFgForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="action" value="delete_fg">
    <input type="hidden" name="fg_id" id="deleteFgId">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.getElementById('editFgModal').addEventListener('show.bs.modal', function(event) {
        const btn = event.relatedTarget;
        document.getElementById('editFgId').value = btn.dataset.id;
        document.getElementById('editFgCode').value = btn.dataset.code;
        document.getElementById('editFgName').value = btn.dataset.name;
    });

    function confirmDelete(id, code) {
        if (confirm('Yakin mau hapus barang "' + code + '"?\n\n(Kalau barang ini pernah dipakai di SIPB, penghapusan akan ditolak demi menjaga integritas riwayat data.)')) {
            document.getElementById('deleteFgId').value = id;
            document.getElementById('deleteFgForm').submit();
        }
    }
</script>

</body>
</html>

