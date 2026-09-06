<?php
/**
 * public_html/sipb/create.php
 *
 * SIPB Create Page (v3)
 * Changes v3:
 * - Kode Barang dropdown di-populate instant via embedded JS array (fix kosong)
 * - Nomor SIPB: SIPB.GPI.{SECTION}.{YYYY.MM.DD}.{SEQ 2-digit}
 * - Kolom "Perusahaan" tidak lagi optional-labeled
 * - Kategori dipindah ke level item (bukan dokumen)
 * - Kolom item mengikuti struktur: Kategori, Kode Barang, Nama Barang, Qty, Unit, Customer, Aplikasi, Keterangan
 */

// NOTE: session_start() sengaja tidak dipanggil di sini - config.php yang mengatur
// ini_set('session.*', ...) sebelum session_start() supaya settingan (httponly, gc_maxlifetime) berlaku.
date_default_timezone_set('Asia/Jakarta');

require __DIR__ . '/../config/config.php';
require_login();
require_roles(['user', 'approver', 'superadmin']);

$current_user = get_logged_in_user();
$user_id = $current_user['id'];
$user_section = $current_user['section'];

$db = new Database($conn);

/**
 * Compress image if file size > 300KB
 * Keeps resolution but reduces quality to achieve target size
 */
function compressImageIfNeeded($file_path, $max_size_kb = 300) {
    $max_bytes = $max_size_kb * 1024;
    if (filesize($file_path) <= $max_bytes) {
        return true; // No compression needed
    }

    // Get image info
    $image_info = getimagesize($file_path);
    if (!$image_info) return false;

    $width = $image_info[0];
    $height = $image_info[1];
    $mime = $image_info['mime'];

    // Load image based on type
    if ($mime === 'image/jpeg') {
        $image = imagecreatefromjpeg($file_path);
        $format = 'jpeg';
    } elseif ($mime === 'image/png') {
        $image = imagecreatefrompng($file_path);
        $format = 'png';
    } elseif ($mime === 'image/webp') {
        $image = imagecreatefromwebp($file_path);
        $format = 'webp';
    } else {
        return false;
    }

    if (!$image) return false;

    // Try different quality levels to achieve target file size
    $quality = 85;
    for ($q = 85; $q >= 30; $q -= 5) {
        ob_start();
        if ($format === 'jpeg') {
            imagejpeg($image, null, $q);
        } elseif ($format === 'png') {
            imagepng($image, null, max(0, 9 - intval($q / 10)));
        } elseif ($format === 'webp') {
            imagewebp($image, null, $q);
        }
        $output = ob_get_clean();

        if (strlen($output) <= $max_bytes) {
            file_put_contents($file_path, $output);
            imagedestroy($image);
            return true;
        }
    }

    // If all attempts fail, save with lowest quality
    ob_start();
    if ($format === 'jpeg') {
        imagejpeg($image, null, 30);
    } elseif ($format === 'png') {
        imagepng($image, null, 9);
    } elseif ($format === 'webp') {
        imagewebp($image, null, 30);
    }
    $output = ob_get_clean();
    file_put_contents($file_path, $output);
    imagedestroy($image);

    return true;
}

// Kategori (sekarang per item)
$categories = [
    'R&D Sample' => 'R&D Sample',
    'Prototipe' => 'Prototipe',
    'Alat' => 'Alat',
    'QC Sample' => 'QC Sample',
    'Lain-lain' => 'Lain-lain'
];

// Kategori yang pakai FG product list (sesuai data 112 produk)
$fg_categories = ['R&D Sample', 'QC Sample'];

// Unit list
$units = ['g' => 'g', 'kg' => 'kg', 'L' => 'L', 'pcs' => 'pcs', 'Unit' => 'Unit'];

// Aplikasi list
$applications = ['Dairy' => 'Dairy', 'Jelly' => 'Jelly', 'Pudding' => 'Pudding', 'Meat' => 'Meat', 'Gummy' => 'Gummy', 'Lain-lain' => 'Lain-lain'];

/**
 * Generate nomor SIPB dengan sequence 2-digit
 * Format: SIPB.GPI.{SECTION}.{YYYY.MM.DD}.{SEQ}
 */
function generateDocNumber($conn, $section) {
    $date_part = date('Y.m.d');
    $prefix = 'SIPB.GPI.' . $section . '.' . $date_part . '.';

    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM sipb_documents WHERE doc_number LIKE ?");
    $like_pattern = $prefix . '%';
    $stmt->bind_param('s', $like_pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $next_seq = intval($row['cnt']) + 1;

    return $prefix . str_pad($next_seq, 2, '0', STR_PAD_LEFT);
}

// Preview nomor SIPB (best-effort, final akan di-generate ulang saat submit untuk avoid race condition)
$doc_number_preview = generateDocNumber($conn, $user_section);

// Handle form submission
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "❌ CSRF token tidak valid.";
    } else {
        $purpose = trim($_POST['purpose'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $recipient = trim($_POST['recipient'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $vehicle = trim($_POST['vehicle'] ?? '');
        $license_plate = trim($_POST['license_plate'] ?? '');
        $items = $_POST['items'] ?? [];

        // Validate
        if (empty($purpose)) {
            $error = "❌ Tujuan pengeluaran harus diisi.";
        } elseif (empty($recipient)) {
            $error = "❌ Penerima harus diisi.";
        } elseif (count($items) === 0) {
            $error = "❌ Minimal 1 item harus ditambahkan.";
        } else {
            // Validate items
            $valid_items = true;
            foreach ($items as $idx => $item) {
                if (empty($item['category']) || empty($item['code']) || empty($item['name']) || empty($item['qty']) || $item['qty'] <= 0) {
                    $error = "❌ Item " . ($idx + 1) . " tidak lengkap atau qty harus > 0.";
                    $valid_items = false;
                    break;
                }
            }

            if ($valid_items) {
                // Determine document category: kalau semua item sama, pakai itu; kalau campuran, gabungkan unique
                $item_categories = array_unique(array_column($items, 'category'));
                $doc_category = implode(', ', $item_categories);

                $max_retry = 5;
                $attempt = 0;
                $inserted = false;

                while (!$inserted && $attempt < $max_retry) {
                    $attempt++;
                    $doc_number = generateDocNumber($conn, $user_section);

                    try {
                        $conn->begin_transaction();

                        $status = 'Draft';
                        $verify_token = bin2hex(random_bytes(20)); // Token unik buat QR verifikasi security (bukan ID biasa)
                        $insert_doc = "INSERT INTO sipb_documents
                                       (doc_number, doc_date, category, purpose, remarks, recipient, company, vehicle, license_plate,
                                        created_by, status, verify_token, created_at)
                                       VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

                        $stmt = $conn->prepare($insert_doc);
                        if (!$stmt) {
                            throw new Exception("Prepare error: " . $conn->error);
                        }

                        $stmt->bind_param('ssssssssiss', $doc_number, $doc_category, $purpose, $remarks, $recipient, $company, $vehicle, $license_plate, $user_id, $status, $verify_token);

                        if (!$stmt->execute()) {
                            // Duplicate doc_number (race condition) -> retry dengan seq berikutnya
                            if ($conn->errno === 1062) {
                                $conn->rollback();
                                continue;
                            }
                            throw new Exception("Error insert dokumen: " . $stmt->error);
                        }

                        $sipb_id = $stmt->insert_id;

                        // Insert items
                        $insert_items = "INSERT INTO sipb_items
                                         (sipb_id, category, item_code, item_name, quantity, unit, customer, application, notes, photo_path)
                                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                        $item_stmt = $conn->prepare($insert_items);
                        if (!$item_stmt) {
                            throw new Exception("Prepare error: " . $conn->error);
                        }

                        $upload_dir = __DIR__ . '/../uploads/sipb_items/';

                        foreach ($items as $idx => $item) {
                            $item_category = $item['category'];
                            $code = $item['code'];
                            $name = $item['name'];
                            $qty = floatval($item['qty']);
                            $unit = $item['unit'] ?? 'pcs';
                            $customer = trim($item['customer'] ?? '');
                            $application = trim($item['application'] ?? '');
                            $notes = trim($item['notes'] ?? '');
                            $photo_path = null;

                            // Handle upload foto barang (opsional, per item)
                            if (isset($_FILES['items']['tmp_name'][$idx]['photo']) && $_FILES['items']['error'][$idx]['photo'] === UPLOAD_ERR_OK) {
                                $tmp_name = $_FILES['items']['tmp_name'][$idx]['photo'];
                                $orig_name = $_FILES['items']['name'][$idx]['photo'];
                                $file_size = $_FILES['items']['size'][$idx]['photo'];

                                $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                                $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];

                                if (in_array($ext, $allowed_ext) && $file_size <= 8 * 1024 * 1024) {
                                    $safe_filename = 'item_' . date('YmdHis') . '_' . $idx . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                                    $full_path = $upload_dir . $safe_filename;

                                    if (move_uploaded_file($tmp_name, $full_path)) {
                                        // Auto-compress if file size > 300KB
                                        compressImageIfNeeded($full_path, 300);
                                        $photo_path = 'uploads/sipb_items/' . $safe_filename;
                                    }
                                }
                            }

                            $item_stmt->bind_param('isssdsssss', $sipb_id, $item_category, $code, $name, $qty, $unit, $customer, $application, $notes, $photo_path);
                            if (!$item_stmt->execute()) {
                                throw new Exception("Error insert item: " . $item_stmt->error);
                            }
                        }

                        $conn->commit();
                        $inserted = true;

                        log_audit($conn, $sipb_id, 'sipb_created', "SIPB dibuat: $doc_number (Items: " . count($items) . ")", null, null);

                        // Send approval notification emails to all active approvers
                        require_once __DIR__ . '/../config/send_approval_notification.php';
                        $notification_result = send_approval_notification($conn, $sipb_id, $doc_number, $current_user['name']);
                        // Note: Notification errors are logged but don't block SIPB creation

                        header("Location: view.php?id=$sipb_id&success=1");
                        exit;

                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = "❌ Error: " . $e->getMessage();
                        break;
                    }
                }

                if (!$inserted && empty($error)) {
                    $error = "❌ Gagal generate nomor SIPB unik setelah beberapa percobaan. Silakan coba lagi.";
                }
            }
        }
    }
}

// FG products untuk embed sebagai JS array (dipakai untuk R&D Sample & QC Sample)
$fg_products = $db->getRows("SELECT code, name FROM fg_products ORDER BY code ASC", []) ?? [];

// Suggested recipients dan companies dari history
$suggested_recipients = [];
$suggested_companies = [];

$recipients = $db->getRows(
    // GROUP BY + MAX() instead of DISTINCT + ORDER BY created_at: under
    // ONLY_FULL_GROUP_BY (default on MySQL 8+) ordering by a column outside the
    // SELECT list is rejected when DISTINCT is used. Same result: unique
    // recipients, most recently used first.
    "SELECT recipient FROM sipb_documents WHERE recipient IS NOT NULL AND recipient != '' GROUP BY recipient ORDER BY MAX(created_at) DESC LIMIT 10",
    []
);
foreach ($recipients as $r) {
    $suggested_recipients[] = $r['recipient'];
}

$companies = $db->getRows(
    "SELECT company FROM sipb_documents WHERE company IS NOT NULL AND company != '' GROUP BY company ORDER BY MAX(created_at) DESC LIMIT 10",
    []
);
foreach ($companies as $c) {
    $suggested_companies[] = $c['company'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buat SIPB Baru - SIPB-GPI System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo rtrim(getenv("APP_URL") ?: "http://localhost/SIPB-GPI", "/"); ?>/assets/css/theme.css" rel="stylesheet">
    <style>
        .form-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            padding: 30px;
        }
        .form-title {
            font-size: 28px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .form-subtitle {
            color: #7f8c8d;
            font-size: 14px;
            margin-bottom: 30px;
        }
        .section-header {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
            border-bottom: 2px solid #667eea;
            padding-bottom: 12px;
            margin-top: 30px;
            margin-bottom: 20px;
        }
        .form-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .form-label .required {
            color: #e74c3c;
        }
        .form-control, .form-select {
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 10px 15px;
            font-size: 14px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .form-text {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
            font-style: italic;
        }
        .item-row {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .btn-add-item {
            background: #27ae60;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            margin-bottom: 20px;
        }
        .btn-add-item:hover {
            background: #229954;
        }
        .btn-remove-item {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            margin-top: 10px;
        }
        .btn-remove-item:hover {
            background: #c0392b;
        }
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        .btn-submit {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            flex: 1;
            min-width: 150px;
        }
        .btn-submit:hover {
            background: #5568d3;
        }
        .btn-back {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-back:hover {
            background: #5a6268;
            color: white;
        }
        .alert {
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .doc-number-display {
            background: #f8f9fa;
            padding: 12px 15px;
            border-radius: 6px;
            font-family: monospace;
            font-weight: bold;
            color: #667eea;
            font-size: 16px;
            border: 1px dashed #667eea;
        }
        .row-item label {
            font-size: 12px;
        }
    </style>
</head>
<body>

<?php $active_page = 'create'; ?>
<div class="app-shell">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="main-content">
    <div class="form-container">
    <div class="form-title">📝 Buat SIPB Baru</div>
    <div class="form-subtitle">Surat Ijin Pengeluaran Barang</div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <form method="POST" id="sipbForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

        <!-- Info SIPB -->
        <div class="section-header">📋 Informasi SIPB</div>

        <div class="row mb-4">
            <div class="col-md-6">
                <label class="form-label">No SIPB</label>
                <div class="doc-number-display"><?php echo htmlspecialchars($doc_number_preview); ?></div>
                <div class="form-text">✓ Auto-generated berdasarkan section Anda (<?php echo htmlspecialchars($user_section); ?>) dan nomor urut hari ini</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Tujuan Pengeluaran <span class="required">*</span></label>
                <input type="text" name="purpose" class="form-control" placeholder="Contoh: Testing, Repair, Assembly" value="<?php echo htmlspecialchars($_POST['purpose'] ?? ''); ?>" required>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-12">
                <label class="form-label">Catatan Tambahan</label>
                <input type="text" name="remarks" class="form-control" placeholder="Informasi tambahan jika diperlukan..." value="<?php echo htmlspecialchars($_POST['remarks'] ?? ''); ?>">
            </div>
        </div>

        <!-- Penerima & Transport -->
        <div class="section-header">🚗 Penerima & Transport</div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Penerima <span class="required">*</span></label>
                <input type="text" name="recipient" class="form-control" placeholder="Nama penerima barang" value="<?php echo htmlspecialchars($_POST['recipient'] ?? ''); ?>" required list="recipientList">
                <datalist id="recipientList">
                    <?php foreach ($suggested_recipients as $r): ?>
                        <option value="<?php echo htmlspecialchars($r); ?>">
                    <?php endforeach; ?>
                </datalist>
                <div class="form-text">💡 Sesuaikan nama yang sudah pernah di-submit</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Perusahaan</label>
                <input type="text" name="company" class="form-control" placeholder="Nama perusahaan/kurir" value="<?php echo htmlspecialchars($_POST['company'] ?? ''); ?>" list="companyList">
                <datalist id="companyList">
                    <?php foreach ($suggested_companies as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>">
                    <?php endforeach; ?>
                </datalist>
                <div class="form-text">💡 Sesuaikan dengan perusahaan yang sudah pernah di-submit</div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-6">
                <label class="form-label">Kendaraan (Optional)</label>
                <input type="text" name="vehicle" class="form-control" placeholder="Jenis kendaraan" value="<?php echo htmlspecialchars($_POST['vehicle'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">No Polisi (Optional)</label>
                <input type="text" name="license_plate" class="form-control" placeholder="Nomor polisi kendaraan" value="<?php echo htmlspecialchars($_POST['license_plate'] ?? ''); ?>">
            </div>
        </div>

        <!-- Item-Item Barang -->
        <div class="section-header">📦 Item-Item Barang</div>

        <div id="itemsContainer"></div>

        <button type="button" class="btn-add-item" id="addItemBtn">+ Tambah Item</button>

        <!-- Buttons -->
        <div class="button-group">
            <button type="submit" class="btn-submit">💾 Buat SIPB</button>
            <a href="list.php" class="btn-back">← Kembali</a>
        </div>
    </form>
    </div>
    </div>
</div>

<script>
// Embed data statis untuk client-side (fix dropdown kosong - instant populate tanpa reload)
const FG_PRODUCTS = <?php echo json_encode($fg_products, JSON_UNESCAPED_UNICODE); ?>;
const CATEGORIES = <?php echo json_encode($categories, JSON_UNESCAPED_UNICODE); ?>;
const FG_CATEGORIES = <?php echo json_encode($fg_categories); ?>; // Kategori yang pakai FG list
const UNITS = <?php echo json_encode($units, JSON_UNESCAPED_UNICODE); ?>;
const APPLICATIONS = <?php echo json_encode($applications, JSON_UNESCAPED_UNICODE); ?>;

let itemCount = 0;

function buildCategoryOptions(selected) {
    let html = '<option value="">-- Pilih Kategori --</option>';
    for (const [key, label] of Object.entries(CATEGORIES)) {
        html += `<option value="${key}" ${key === selected ? 'selected' : ''}>${label}</option>`;
    }
    return html;
}

function buildUnitOptions() {
    let html = '';
    for (const [key, label] of Object.entries(UNITS)) {
        html += `<option value="${key}" ${key === 'pcs' ? 'selected' : ''}>${label}</option>`;
    }
    return html;
}

function buildApplicationOptions() {
    let html = '<option value="">-- Pilih --</option>';
    for (const [key, label] of Object.entries(APPLICATIONS)) {
        html += `<option value="${key}">${label}</option>`;
    }
    return html;
}

function buildFGCodeOptions() {
    let html = '<option value="">-- Pilih Kode --</option>';
    FG_PRODUCTS.forEach(p => {
        html += `<option value="${p.code}" data-name="${p.name.replace(/"/g, '&quot;')}">${p.code}</option>`;
    });
    return html;
}

function buildFGNameOptions() {
    let html = '<option value="">-- Pilih Nama Barang --</option>';
    // Urutkan alfabetis biar gampang dicari (orang lebih hafal nama drpd kode)
    const sorted = [...FG_PRODUCTS].sort((a, b) => a.name.localeCompare(b.name));
    sorted.forEach(p => {
        html += `<option value="${p.name.replace(/"/g, '&quot;')}" data-code="${p.code}">${p.name}</option>`;
    });
    return html;
}

function addItem() {
    const idx = itemCount;
    const container = document.getElementById('itemsContainer');

    const div = document.createElement('div');
    div.className = 'item-row';
    div.id = `item-${idx}`;

    div.innerHTML = `
        <div class="row row-item">
            <div class="col-md-2">
                <label class="form-label">Kategori <span class="required">*</span></label>
                <select name="items[${idx}][category]" class="form-select item-category-select" required onchange="onCategoryChange(${idx})">
                    ${buildCategoryOptions('')}
                </select>
            </div>
            <div class="col-md-2 item-code-wrapper">
                <label class="form-label">Kode Barang <span class="required">*</span></label>
                <select name="items[${idx}][code]" class="form-select item-code-select" required onchange="updateItemName(${idx})">
                    <option value="">-- Pilih Kategori dulu --</option>
                </select>
            </div>
            <div class="col-md-2 item-name-wrapper">
                <label class="form-label">Nama Barang <span class="required">*</span></label>
                <input type="text" name="items[${idx}][name]" class="form-control item-name-input" placeholder="Auto/Manual" required>
            </div>
            <div class="col-md-1">
                <label class="form-label">Qty <span class="required">*</span></label>
                <input type="number" name="items[${idx}][qty]" class="form-control" min="0.01" step="0.01" placeholder="0" required>
            </div>
            <div class="col-md-1">
                <label class="form-label">Unit</label>
                <select name="items[${idx}][unit]" class="form-select">
                    ${buildUnitOptions()}
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">Customer</label>
                <input type="text" name="items[${idx}][customer]" class="form-control" placeholder="Customer">
            </div>
            <div class="col-md-1">
                <label class="form-label">Aplikasi</label>
                <select name="items[${idx}][application]" class="form-select">
                    ${buildApplicationOptions()}
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Keterangan</label>
                <input type="text" name="items[${idx}][notes]" class="form-control" placeholder="Catatan item">
            </div>
        </div>
        <div class="row row-item" style="margin-top: 10px;">
            <div class="col-md-4">
                <label class="form-label"><i class="fas fa-camera"></i> Foto Barang (untuk verifikasi security)</label>
                <input type="file" name="items[${idx}][photo]" class="form-control item-photo-input" accept="image/jpeg,image/png,image/webp" onchange="previewItemPhoto(${idx})">
                <div class="form-text">Foto barang aktual yang mau dibawa keluar. Max 8MB, JPG/PNG/WEBP.</div>
            </div>
            <div class="col-md-2">
                <img id="photoPreview-${idx}" src="" style="display:none; max-width:100%; max-height:80px; border-radius:6px; border:1px solid #dee2e6; margin-top: 22px;">
            </div>
        </div>
        <button type="button" class="btn-remove-item" onclick="removeItem(${idx})">🗑️ Hapus</button>
    `;

    container.appendChild(div);
    itemCount++;
}

function onCategoryChange(idx) {
    const categorySelect = document.querySelector(`#item-${idx} .item-category-select`);
    const codeWrapper = document.querySelector(`#item-${idx} .item-code-wrapper`);
    const category = categorySelect.value;

    const nameWrapper = document.querySelector(`#item-${idx} .item-name-wrapper`);

    if (FG_CATEGORIES.includes(category)) {
        // Kode Barang: dropdown, pilih kode -> nama auto-terisi
        codeWrapper.innerHTML = `
            <label class="form-label">Kode Barang <span class="required">*</span></label>
            <select name="items[${idx}][code]" class="form-select item-code-select" required onchange="updateItemName(${idx})">
                ${buildFGCodeOptions()}
            </select>
        `;
        // Nama Barang: JUGA dropdown (searchable via ketik huruf awal) - pilih nama -> kode auto-terisi.
        // Dua dropdown ini saling sinkron dua arah.
        nameWrapper.innerHTML = `
            <label class="form-label">Nama Barang <span class="required">*</span></label>
            <select name="items[${idx}][name]" class="form-select item-name-input" required onchange="updateItemCode(${idx})">
                ${buildFGNameOptions()}
            </select>
        `;
    } else if (category === '') {
        codeWrapper.innerHTML = `
            <label class="form-label">Kode Barang <span class="required">*</span></label>
            <select name="items[${idx}][code]" class="form-select item-code-select" required>
                <option value="">-- Pilih Kategori dulu --</option>
            </select>
        `;
        nameWrapper.innerHTML = `
            <label class="form-label">Nama Barang <span class="required">*</span></label>
            <input type="text" name="items[${idx}][name]" class="form-control item-name-input" placeholder="Auto/Manual" required>
        `;
    } else {
        // Kategori lain (Prototipe, Alat, Lain-lain) -> input manual, bebas ketik
        codeWrapper.innerHTML = `
            <label class="form-label">Kode Barang <span class="required">*</span></label>
            <input type="text" name="items[${idx}][code]" class="form-control item-code-select" placeholder="Kode barang" required>
        `;
        nameWrapper.innerHTML = `
            <label class="form-label">Nama Barang <span class="required">*</span></label>
            <input type="text" name="items[${idx}][name]" class="form-control item-name-input" placeholder="Nama barang" required>
        `;
    }
}

function previewItemPhoto(idx) {
    const input = document.querySelector(`#item-${idx} .item-photo-input`);
    const preview = document.getElementById(`photoPreview-${idx}`);
    if (!input || !input.files || !input.files[0]) {
        preview.style.display = 'none';
        return;
    }
    const reader = new FileReader();
    reader.onload = function(e) {
        preview.src = e.target.result;
        preview.style.display = 'block';
    };
    reader.readAsDataURL(input.files[0]);
}

// Kode Barang dipilih -> set Nama Barang yang sesuai
function updateItemName(idx) {
    const codeSelect = document.querySelector(`#item-${idx} .item-code-select`);
    const nameField = document.querySelector(`#item-${idx} .item-name-input`);
    if (!codeSelect || !nameField) return;

    const selected = codeSelect.options[codeSelect.selectedIndex];
    const name = selected ? (selected.dataset.name || '') : '';

    if (nameField.tagName === 'SELECT') {
        nameField.value = name;
    } else {
        nameField.value = name;
    }
}

// Nama Barang dipilih -> set Kode Barang yang sesuai (arah sebaliknya)
function updateItemCode(idx) {
    const nameSelect = document.querySelector(`#item-${idx} .item-name-input`);
    const codeField = document.querySelector(`#item-${idx} .item-code-select`);
    if (!nameSelect || !codeField) return;

    const selected = nameSelect.options[nameSelect.selectedIndex];
    const code = selected ? (selected.dataset.code || '') : '';

    if (codeField.tagName === 'SELECT') {
        codeField.value = code;
    } else {
        codeField.value = code;
    }
}

function removeItem(idx) {
    const item = document.getElementById(`item-${idx}`);
    if (item) item.remove();
}

document.getElementById('addItemBtn').addEventListener('click', addItem);

// Tambah 1 item default saat page load
addItem();

document.getElementById('sipbForm').addEventListener('submit', function(e) {
    const items = document.querySelectorAll('.item-row');
    if (items.length === 0) {
        e.preventDefault();
        alert('❌ Minimal 1 item harus ditambahkan!');
        return;
    }

    let valid = true;
    items.forEach((row, idx) => {
        const qtyInput = row.querySelector('input[name*="[qty]"]');
        const catSelect = row.querySelector('select[name*="[category]"]');
        if (qtyInput && (!qtyInput.value || parseFloat(qtyInput.value) <= 0)) {
            alert('❌ Item ' + (idx + 1) + ': Qty harus > 0');
            valid = false;
        }
        if (catSelect && !catSelect.value) {
            alert('❌ Item ' + (idx + 1) + ': Kategori harus dipilih');
            valid = false;
        }
    });

    if (!valid) {
        e.preventDefault();
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

