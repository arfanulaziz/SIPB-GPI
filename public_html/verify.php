<?php
/**
 * public_html/verify.php
 *
 * Halaman verifikasi fisik untuk SECURITY (PUBLIK, tanpa login)
 * Diakses via scan QR code yang tercetak di dokumen SIPB.
 *
 * Menampilkan:
 * - Status approval SIPB (APPROVED / BELUM) - jelas & mencolok
 * - Daftar barang + foto real-time yang di-upload saat submit
 * - Riwayat approval (siapa, kapan)
 * - Form konfirmasi: security isi nama -> tercatat sebagai bukti verifikasi fisik
 */

date_default_timezone_set('Asia/Jakarta');

require __DIR__ . '/config/config.php';
// SENGAJA TIDAK memanggil require_login() - halaman ini publik untuk security tanpa akun.

$db = new Database($conn);

$token = trim($_GET['token'] ?? '');
$error = '';
$success = '';

if (empty($token)) {
    die("❌ Link verifikasi tidak valid (token kosong).");
}

$sipb = $db->getRow("SELECT * FROM sipb_documents WHERE verify_token = ?", [$token]);

if (!$sipb) {
    die("❌ SIPB tidak ditemukan. Link verifikasi mungkin salah atau sudah tidak berlaku.");
}

$sipb_id = $sipb['id'];

// Handle konfirmasi verifikasi security
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "❌ Sesi tidak valid, silakan refresh halaman dan coba lagi.";
    } else {
        $verified_by_name = trim($_POST['verified_by_name'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if (empty($verified_by_name)) {
            $error = "❌ Nama security harus diisi.";
        } elseif ($sipb['status'] !== 'Approved') {
            $error = "❌ SIPB ini belum Approved sepenuhnya, tidak bisa diverifikasi keluar.";
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $stmt = $conn->prepare("INSERT INTO sipb_security_verifications (sipb_id, verified_by_name, notes, ip_address) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('isss', $sipb_id, $verified_by_name, $notes, $ip);
            if ($stmt->execute()) {
                $success = "✅ Verifikasi berhasil dicatat. Barang sah untuk dikeluarkan.";
                log_audit($conn, $sipb_id, 'security_verified', "Diverifikasi security: $verified_by_name" . (!empty($notes) ? " ($notes)" : ""), null, null);
            } else {
                $error = "❌ Gagal mencatat verifikasi: " . $stmt->error;
            }
        }
    }
}

// Get items
$items = $db->getRows("SELECT * FROM sipb_items WHERE sipb_id = ? ORDER BY id ASC", [$sipb_id]) ?? [];

// Get creator
$creator = $db->getRow("SELECT name FROM users WHERE id = ?", [$sipb['created_by']]);

// Get approval history
$approvals = $db->getRows(
    "SELECT ah.*, u.name as approver_name
     FROM sipb_approval_history ah
     LEFT JOIN users u ON ah.approver_id = u.id
     WHERE ah.sipb_id = ?
     ORDER BY ah.approved_at ASC",
    [$sipb_id]
) ?? [];

// Get verification history
$verifications = $db->getRows(
    "SELECT * FROM sipb_security_verifications WHERE sipb_id = ? ORDER BY verified_at DESC",
    [$sipb_id]
) ?? [];

$is_approved = ($sipb['status'] === 'Approved');
$BASE = getenv('APP_URL') ?: 'http://localhost/SIPB-GPI';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Security - <?php echo htmlspecialchars($sipb['doc_number']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f0f2f8;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 16px;
        }
        .wrap { max-width: 640px; margin: 0 auto; }
        .status-banner {
            border-radius: 14px;
            padding: 22px;
            text-align: center;
            color: #fff;
            margin-bottom: 18px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.12);
        }
        .status-banner.approved { background: linear-gradient(135deg, #2ea45c, #237a44); }
        .status-banner.not-approved { background: linear-gradient(135deg, #d8483a, #b13327); }
        .status-banner .icon { font-size: 42px; margin-bottom: 6px; }
        .status-banner .title { font-size: 20px; font-weight: 800; letter-spacing: 0.5px; }
        .status-banner .subtitle { font-size: 13px; opacity: 0.95; margin-top: 4px; }
        .card-box {
            background: #fff;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .card-title { font-weight: 700; font-size: 15px; margin-bottom: 12px; color: #2c3e50; }
        .doc-number { font-family: monospace; font-weight: 700; font-size: 15px; color: #4a5bd6; }
        .info-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f0f2f8; font-size: 13.5px; }
        .info-row:last-child { border-bottom: none; }
        .info-row .label { color: #7f8c8d; }
        .info-row .value { font-weight: 600; color: #2c3e50; text-align: right; }
        .item-card {
            display: flex;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #f0f2f8;
        }
        .item-card:last-child { border-bottom: none; }
        .item-photo {
            width: 72px;
            height: 72px;
            border-radius: 8px;
            object-fit: cover;
            flex-shrink: 0;
            border: 1px solid #e5e7f0;
        }
        .item-photo-placeholder {
            width: 72px;
            height: 72px;
            border-radius: 8px;
            background: #f0f2f8;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #bbb;
            flex-shrink: 0;
            font-size: 22px;
        }
        .item-details { flex: 1; min-width: 0; }
        .item-name { font-weight: 700; font-size: 14px; color: #2c3e50; }
        .item-meta { font-size: 12px; color: #7f8c8d; margin-top: 2px; }
        .badge-pill { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; color: #fff; }
        .badge-approved { background: #2ea45c; }
        .badge-rejected { background: #d8483a; }
        .btn-verify {
            width: 100%;
            background: #2ea45c;
            color: #fff;
            border: none;
            padding: 14px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
        }
        .btn-verify:hover { background: #237a44; }
        .verif-log {
            font-size: 12.5px;
            padding: 8px 0;
            border-bottom: 1px solid #f0f2f8;
        }
        .verif-log:last-child { border-bottom: none; }
        .verif-log .name { font-weight: 700; color: #2c3e50; }
        .verif-log .time { color: #7f8c8d; }
    </style>
</head>
<body>

<div class="wrap">
    <!-- Status Banner -->
    <div class="status-banner <?php echo $is_approved ? 'approved' : 'not-approved'; ?>">
        <div class="icon"><?php echo $is_approved ? '✅' : '⛔'; ?></div>
        <div class="title"><?php echo $is_approved ? 'APPROVED - SAH DIKELUARKAN' : 'BELUM APPROVED'; ?></div>
        <div class="subtitle">
            <?php if ($is_approved): ?>
                Dokumen ini sudah disetujui penuh (SPV & Plant Manager). Barang legal untuk dibawa keluar.
            <?php else: ?>
                Status saat ini: <strong><?php echo htmlspecialchars($sipb['status']); ?></strong>. JANGAN keluarkan barang sebelum status Approved.
            <?php endif; ?>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($error)): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
    <?php if (!empty($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

    <!-- Doc Info -->
    <div class="card-box">
        <div class="card-title"><i class="fas fa-file-lines"></i> Info Dokumen</div>
        <div class="doc-number"><?php echo htmlspecialchars($sipb['doc_number']); ?></div>
        <div style="margin-top: 10px;">
            <div class="info-row"><span class="label">Kategori</span><span class="value"><?php echo htmlspecialchars($sipb['category']); ?></span></div>
            <div class="info-row"><span class="label">Dibuat Oleh</span><span class="value"><?php echo htmlspecialchars($creator['name'] ?? '-'); ?></span></div>
            <div class="info-row"><span class="label">Tanggal</span><span class="value"><?php echo date('d M Y', strtotime($sipb['doc_date'])); ?></span></div>
            <div class="info-row"><span class="label">Penerima</span><span class="value"><?php echo htmlspecialchars($sipb['recipient'] ?? '-'); ?></span></div>
            <?php if (!empty($sipb['company'])): ?>
            <div class="info-row"><span class="label">Perusahaan</span><span class="value"><?php echo htmlspecialchars($sipb['company']); ?></span></div>
            <?php endif; ?>
            <?php if (!empty($sipb['vehicle'])): ?>
            <div class="info-row"><span class="label">Kendaraan</span><span class="value"><?php echo htmlspecialchars($sipb['vehicle']); ?> <?php echo !empty($sipb['license_plate']) ? '(' . htmlspecialchars($sipb['license_plate']) . ')' : ''; ?></span></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Approval History -->
    <?php if (count($approvals) > 0): ?>
    <div class="card-box">
        <div class="card-title"><i class="fas fa-signature"></i> Riwayat Persetujuan</div>
        <?php foreach ($approvals as $a): ?>
            <div class="info-row">
                <span class="label"><?php echo htmlspecialchars($a['position_name'] ?? $a['approval_level']); ?> - <?php echo htmlspecialchars($a['approver_name'] ?? '-'); ?></span>
                <span class="value">
                    <span class="badge-pill <?php echo $a['approval_status'] === 'Approved' ? 'badge-approved' : 'badge-rejected'; ?>"><?php echo htmlspecialchars($a['approval_status']); ?></span>
                    <br><small style="font-weight: 400;"><?php echo date('d M Y H:i', strtotime($a['approved_at'])); ?></small>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Items -->
    <div class="card-box">
        <div class="card-title"><i class="fas fa-box"></i> Daftar Barang (<?php echo count($items); ?>)</div>
        <?php foreach ($items as $item): ?>
            <div class="item-card">
                <?php if (!empty($item['photo_path'])): ?>
                    <img src="<?php echo $BASE . '/' . htmlspecialchars($item['photo_path']); ?>" class="item-photo" alt="Foto barang">
                <?php else: ?>
                    <div class="item-photo-placeholder"><i class="fas fa-image"></i></div>
                <?php endif; ?>
                <div class="item-details">
                    <div class="item-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
                    <div class="item-meta">
                        <?php echo htmlspecialchars($item['item_code']); ?> &middot;
                        Qty: <?php echo rtrim(rtrim(number_format($item['quantity'], 2, '.', ''), '0'), '.'); ?> <?php echo htmlspecialchars($item['unit'] ?? ''); ?>
                    </div>
                    <?php if (!empty($item['notes'])): ?>
                        <div class="item-meta">Ket: <?php echo htmlspecialchars($item['notes']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Verification Form -->
    <?php if ($is_approved): ?>
    <div class="card-box">
        <div class="card-title"><i class="fas fa-shield-halved"></i> Konfirmasi Verifikasi Security</div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="verify">
            <div class="mb-3">
                <label class="form-label" style="font-size: 13px;">Nama Security <span style="color:red;">*</span></label>
                <input type="text" name="verified_by_name" class="form-control" placeholder="Nama lengkap Anda" required>
            </div>
            <div class="mb-3">
                <label class="form-label" style="font-size: 13px;">Catatan (opsional)</label>
                <input type="text" name="notes" class="form-control" placeholder="Contoh: dibawa via mobil box hitam">
            </div>
            <button type="submit" class="btn-verify"><i class="fas fa-check-circle"></i> Konfirmasi Barang Keluar</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Verification History -->
    <?php if (count($verifications) > 0): ?>
    <div class="card-box">
        <div class="card-title"><i class="fas fa-clock-rotate-left"></i> Riwayat Verifikasi Security</div>
        <?php foreach ($verifications as $v): ?>
            <div class="verif-log">
                <span class="name"><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars($v['verified_by_name']); ?></span>
                &middot; <span class="time"><?php echo date('d M Y H:i', strtotime($v['verified_at'])); ?></span>
                <?php if (!empty($v['notes'])): ?><div style="color:#7f8c8d; margin-top:2px;"><?php echo htmlspecialchars($v['notes']); ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <p style="text-align:center; font-size: 11px; color: #bbb; margin-top: 20px;">
        SIPB-GPI Security Verification System &middot; PT Gumindo Perkasa Industri
    </p>
</div>

</body>
</html>

