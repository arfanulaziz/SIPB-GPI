<?php
/**
 * public_html/admin/users/list.php
 *
 * User Management Page (Superadmin only)
 * - Lihat & edit semua user (role, section, posisi approval, status aktif)
 * - Tambah user baru langsung (tanpa perlu registrasi + approve manual)
 * - Kelola daftar Posisi Approval (jabatan) - tambah baru, aktif/nonaktif
 */

date_default_timezone_set('Asia/Jakarta');

require __DIR__ . '/../../config/config.php';
require_login();
require_roles(['superadmin']);

$current_user = get_logged_in_user();
$db = new Database($conn);

$error = '';
$success = '';

// Section list (dipakai untuk nomor dokumen SIPB.GPI.{SECTION}.xxx)
$sections = ['R&D', 'QC', 'Warehouse', 'Production', 'PPIC', 'HR', 'Admin'];

// ============================================
// Handle POST actions
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "❌ CSRF token tidak valid.";
    } else {
        $action = $_POST['action'] ?? '';

        // --- Create new user ---
        if ($action === 'create_user') {
            $nik = trim($_POST['nik'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $section = trim($_POST['section'] ?? '');
            $role = trim($_POST['role'] ?? 'user');
            $position_id = !empty($_POST['position_id']) ? intval($_POST['position_id']) : null;
            $password = $_POST['password'] ?? '';

            if (empty($nik) || empty($name) || empty($email) || empty($section) || empty($password)) {
                $error = "❌ Semua field wajib diisi.";
            } elseif (strlen($password) < 6) {
                $error = "❌ Password minimal 6 karakter.";
            } elseif (!in_array($role, ['user', 'approver', 'superadmin'])) {
                $error = "❌ Role tidak valid.";
            } else {
                $existing = $db->getRow("SELECT id FROM users WHERE nik = ? OR email = ?", [$nik, $email]);
                if ($existing) {
                    $error = "❌ NIK atau email sudah terdaftar.";
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $pos = ($role === 'approver') ? $position_id : null;

                    $stmt = $conn->prepare("INSERT INTO users (nik, name, email, role, section, position_id, is_active, is_approved, password_hash) VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?)");
                    $stmt->bind_param('sssssis', $nik, $name, $email, $role, $section, $pos, $hash);

                    if ($stmt->execute()) {
                        $success = "✅ User \"$name\" berhasil dibuat.";
                        log_audit($conn, 0, 'user_created', "Admin membuat user baru: $name ($nik)", null, null);
                    } else {
                        $error = "❌ Gagal membuat user: " . $stmt->error;
                    }
                }
            }
        }

        // --- Update existing user ---
        elseif ($action === 'update_user') {
            $target_id = intval($_POST['user_id'] ?? 0);
            $nik = trim($_POST['nik'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = trim($_POST['role'] ?? 'user');
            $section = trim($_POST['section'] ?? '');
            $position_id = !empty($_POST['position_id']) ? intval($_POST['position_id']) : null;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $new_password = $_POST['new_password'] ?? '';

            if ($target_id === 0) {
                $error = "❌ User tidak valid.";
            } elseif (empty($nik) || empty($name) || empty($email)) {
                $error = "❌ NIK, Nama, dan Email wajib diisi.";
            } elseif (!in_array($role, ['user', 'approver', 'superadmin'])) {
                $error = "❌ Role tidak valid.";
            } elseif (!empty($new_password) && strlen($new_password) < 6) {
                $error = "❌ Password baru minimal 6 karakter.";
            } else {
                // Cek duplikat NIK/email ke user LAIN (exclude diri sendiri)
                $dup = $db->getRow("SELECT id FROM users WHERE (nik = ? OR email = ?) AND id != ?", [$nik, $email, $target_id]);
                if ($dup) {
                    $error = "❌ NIK atau email sudah dipakai user lain.";
                } else {
                    // Kalau bukan 'approver', posisi harus null (jangan simpan posisi basi)
                    $pos = ($role === 'approver') ? $position_id : null;

                    if (!empty($new_password)) {
                        $hash = password_hash($new_password, PASSWORD_BCRYPT);
                        $stmt = $conn->prepare("UPDATE users SET nik = ?, name = ?, email = ?, role = ?, section = ?, position_id = ?, is_active = ?, password_hash = ? WHERE id = ?");
                        $stmt->bind_param('sssssiisi', $nik, $name, $email, $role, $section, $pos, $is_active, $hash, $target_id);
                    } else {
                        $stmt = $conn->prepare("UPDATE users SET nik = ?, name = ?, email = ?, role = ?, section = ?, position_id = ?, is_active = ? WHERE id = ?");
                        $stmt->bind_param('sssssiii', $nik, $name, $email, $role, $section, $pos, $is_active, $target_id);
                    }

                    if ($stmt->execute()) {
                        $success = "✅ User \"$name\" berhasil diupdate." . (!empty($new_password) ? ' Password juga diperbarui.' : '');
                        log_audit($conn, 0, 'user_updated', "Admin update user ID $target_id: nik=$nik, name=$name, role=$role, section=$section" . (!empty($new_password) ? ' (password direset)' : ''), null, null);
                    } else {
                        $error = "❌ Gagal update user: " . $stmt->error;
                    }
                }
            }
        }

        // --- Create new approval position ---
        elseif ($action === 'create_position') {
            $position_name = trim($_POST['position_name'] ?? '');
            $approval_level = trim($_POST['approval_level'] ?? '');

            if (empty($position_name) || !in_array($approval_level, ['SPV', 'PM'])) {
                $error = "❌ Nama posisi dan level harus diisi dengan benar.";
            } else {
                $stmt = $conn->prepare("INSERT INTO approval_positions (position_name, approval_level) VALUES (?, ?)");
                $stmt->bind_param('ss', $position_name, $approval_level);
                if ($stmt->execute()) {
                    $success = "✅ Posisi \"$position_name\" berhasil dibuat.";
                } else {
                    $error = "❌ Gagal membuat posisi: " . $stmt->error;
                }
            }
        }

        // --- Toggle position active/inactive ---
        elseif ($action === 'toggle_position') {
            $pos_id = intval($_POST['position_id'] ?? 0);
            $conn->query("UPDATE approval_positions SET is_active = 1 - is_active WHERE id = $pos_id");
            $success = "✅ Status posisi diubah.";
        }
    }
}

// ============================================
// Fetch data
// ============================================
$users = $db->getRows(
    "SELECT u.*, p.position_name, p.approval_level
     FROM users u
     LEFT JOIN approval_positions p ON u.position_id = p.id
     ORDER BY u.role DESC, u.name ASC",
    []
) ?? [];

$positions = $db->getRows("SELECT * FROM approval_positions ORDER BY approval_level, position_name", []) ?? [];

$active_page = 'users';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - SIPB-GPI System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo rtrim(getenv("APP_URL") ?: "http://localhost/SIPB-GPI", "/"); ?>/assets/css/theme.css" rel="stylesheet">
    <style>
        .modal-header { background: #2c3468; color: #fff; }
        .modal-header .btn-close { filter: invert(1); }
        .position-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f4f5fb;
            border: 1px solid var(--border-light);
            border-radius: 20px;
            padding: 6px 14px;
            margin: 4px 6px 4px 0;
            font-size: 13px;
        }
        .position-chip.inactive { opacity: 0.5; }
        .position-chip .lvl { font-weight: 700; color: var(--accent-blue); }
    </style>
</head>
<body>

<div class="app-shell">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-users-gear"></i> User Management</h1>
            <button class="btn-primary-pill" data-bs-toggle="modal" data-bs-target="#createUserModal">
                <i class="fas fa-user-plus"></i> Tambah User
            </button>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <!-- Approval Positions Management -->
        <div class="content-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 style="margin:0;"><i class="fas fa-id-badge"></i> Posisi Approval (Jabatan)</h5>
                <button class="btn-secondary-pill" data-bs-toggle="modal" data-bs-target="#createPositionModal">
                    <i class="fas fa-plus"></i> Tambah Posisi
                </button>
            </div>
            <p class="text-muted" style="font-size: 13px;">
                Posisi ini yang menentukan siapa approve di Level SPV (persetujuan pertama) dan Level PM (persetujuan final).
                Assign user ke posisi lewat tombol Edit di tabel user di bawah — kalau suatu saat jabatan berpindah tangan
                (misal R&D Manager gantiin Plant Manager), tinggal ubah assignment-nya di sini, tanpa perlu ubah kode.
            </p>
            <div>
                <?php foreach ($positions as $pos): ?>
                    <span class="position-chip <?php echo $pos['is_active'] ? '' : 'inactive'; ?>">
                        <span class="lvl">Level <?php echo htmlspecialchars($pos['approval_level']); ?></span>
                        — <?php echo htmlspecialchars($pos['position_name']); ?>
                        <?php if (!$pos['is_active']): ?><em>(nonaktif)</em><?php endif; ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="toggle_position">
                            <input type="hidden" name="position_id" value="<?php echo $pos['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-link p-0" style="font-size: 11px; text-decoration: underline;" title="<?php echo $pos['is_active'] ? 'Nonaktifkan' : 'Aktifkan'; ?>">
                                <?php echo $pos['is_active'] ? 'nonaktifkan' : 'aktifkan'; ?>
                            </button>
                        </form>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Users Table -->
        <div class="content-card">
            <h5 style="margin-bottom: 16px;"><i class="fas fa-users"></i> Daftar User</h5>
            <div style="overflow-x: auto;">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>NIK</th>
                            <th>Nama</th>
                            <th>Section</th>
                            <th>Role</th>
                            <th>Posisi Approval</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($u['nik']); ?></td>
                                <td><?php echo htmlspecialchars($u['name']); ?></td>
                                <td><?php echo htmlspecialchars($u['section'] ?? '-'); ?></td>
                                <td>
                                    <span class="badge-pill <?php echo $u['role'] === 'superadmin' ? 'badge-approved' : ($u['role'] === 'approver' ? 'badge-submitted' : 'badge-neutral'); ?>">
                                        <?php echo strtoupper($u['role']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($u['position_name']): ?>
                                        <?php echo htmlspecialchars($u['position_name']); ?>
                                        <small class="text-muted">(<?php echo htmlspecialchars($u['approval_level']); ?>)</small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['is_active']): ?>
                                        <span class="badge-pill badge-approved">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge-pill badge-rejected">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn-action edit" data-bs-toggle="modal" data-bs-target="#editUserModal"
                                        data-id="<?php echo $u['id']; ?>"
                                        data-nik="<?php echo htmlspecialchars($u['nik']); ?>"
                                        data-name="<?php echo htmlspecialchars($u['name']); ?>"
                                        data-email="<?php echo htmlspecialchars($u['email'] ?? ''); ?>"
                                        data-role="<?php echo htmlspecialchars($u['role']); ?>"
                                        data-section="<?php echo htmlspecialchars($u['section'] ?? ''); ?>"
                                        data-position="<?php echo $u['position_id'] ?? ''; ?>"
                                        data-active="<?php echo $u['is_active']; ?>"
                                        title="Edit">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Create User -->
<div class="modal fade" id="createUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="create_user">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah User Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">NIK</label>
                        <input type="text" name="nik" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nama Lengkap</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Section (untuk nomor SIPB)</label>
                        <select name="section" class="form-select" required>
                            <?php foreach ($sections as $s): ?>
                                <option value="<?php echo $s; ?>"><?php echo $s; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select" id="createRoleSelect" onchange="toggleCreatePosition()">
                            <option value="user">User (bisa submit SIPB)</option>
                            <option value="approver">Approver (punya jabatan approval)</option>
                            <option value="superadmin">Superadmin</option>
                        </select>
                    </div>
                    <div class="mb-3" id="createPositionWrapper" style="display:none;">
                        <label class="form-label">Posisi Approval</label>
                        <select name="position_id" class="form-select">
                            <option value="">-- Pilih Posisi --</option>
                            <?php foreach ($positions as $pos): ?>
                                <?php if ($pos['is_active']): ?>
                                <option value="<?php echo $pos['id']; ?>"><?php echo htmlspecialchars($pos['position_name']); ?> (Level <?php echo $pos['approval_level']; ?>)</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" minlength="6" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Buat User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit User -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="editUserId">
                <div class="modal-header">
                    <h5 class="modal-title">Edit User: <span id="editUserName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">NIK</label>
                        <input type="text" name="nik" id="editNik" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nama Lengkap</label>
                        <input type="text" name="name" id="editName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="editEmail" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password Baru <small class="text-muted">(kosongkan kalau tidak ingin ubah)</small></label>
                        <input type="password" name="new_password" id="editPassword" class="form-control" minlength="6" placeholder="Minimal 6 karakter">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Section</label>
                        <select name="section" id="editSection" class="form-select" required>
                            <?php foreach ($sections as $s): ?>
                                <option value="<?php echo $s; ?>"><?php echo $s; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" id="editRole" class="form-select" onchange="toggleEditPosition()">
                            <option value="user">User (bisa submit SIPB)</option>
                            <option value="approver">Approver (punya jabatan approval)</option>
                            <option value="superadmin">Superadmin</option>
                        </select>
                    </div>
                    <div class="mb-3" id="editPositionWrapper" style="display:none;">
                        <label class="form-label">Posisi Approval</label>
                        <select name="position_id" id="editPosition" class="form-select">
                            <option value="">-- Pilih Posisi --</option>
                            <?php foreach ($positions as $pos): ?>
                                <?php if ($pos['is_active']): ?>
                                <option value="<?php echo $pos['id']; ?>"><?php echo htmlspecialchars($pos['position_name']); ?> (Level <?php echo $pos['approval_level']; ?>)</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">💡 Ganti posisi di sini kalau jabatan berpindah tangan (misal R&D Manager gantiin Plant Manager).</div>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_active" id="editActive" class="form-check-input" value="1">
                        <label class="form-check-label" for="editActive">Akun Aktif</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Create Position -->
<div class="modal fade" id="createPositionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="create_position">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Posisi Approval Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nama Posisi/Jabatan</label>
                        <input type="text" name="position_name" class="form-control" placeholder="Contoh: HRGA Manager" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Level Approval</label>
                        <select name="approval_level" class="form-select" required>
                            <option value="SPV">Level SPV (Persetujuan Pertama)</option>
                            <option value="PM">Level PM (Persetujuan Final)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Buat Posisi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleCreatePosition() {
        const role = document.getElementById('createRoleSelect').value;
        document.getElementById('createPositionWrapper').style.display = (role === 'approver') ? 'block' : 'none';
    }

    function toggleEditPosition() {
        const role = document.getElementById('editRole').value;
        document.getElementById('editPositionWrapper').style.display = (role === 'approver') ? 'block' : 'none';
    }

    document.getElementById('editUserModal').addEventListener('show.bs.modal', function(event) {
        const btn = event.relatedTarget;
        document.getElementById('editUserId').value = btn.dataset.id;
        document.getElementById('editUserName').textContent = btn.dataset.name;
        document.getElementById('editNik').value = btn.dataset.nik;
        document.getElementById('editName').value = btn.dataset.name;
        document.getElementById('editEmail').value = btn.dataset.email;
        document.getElementById('editPassword').value = '';
        document.getElementById('editSection').value = btn.dataset.section;
        document.getElementById('editRole').value = btn.dataset.role;
        document.getElementById('editActive').checked = btn.dataset.active === '1';
        toggleEditPosition();
        if (btn.dataset.position) {
            document.getElementById('editPosition').value = btn.dataset.position;
        }
    });
</script>

</body>
</html>

