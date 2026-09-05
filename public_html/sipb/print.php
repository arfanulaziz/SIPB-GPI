<?php
/**
 * public_html/sipb/print.php
 *
 * SIPB Print Page
 * Optimized for browser printing
 */

// NOTE: session_start() sengaja tidak dipanggil di sini - lihat config.php
date_default_timezone_set('Asia/Jakarta');

require __DIR__ . '/../config/config.php';
require_login();

$db = new Database($conn);

// Get SIPB ID
$sipb_id = intval($_GET['id'] ?? 0);
if ($sipb_id === 0) {
    die("❌ SIPB tidak ditemukan.");
}

$sipb = $db->getRow("SELECT * FROM sipb_documents WHERE id = ?", [$sipb_id]);
if (!$sipb) {
    die("❌ SIPB tidak ditemukan.");
}

// Get items
$items = $db->getRows(
    "SELECT * FROM sipb_items WHERE sipb_id = ? ORDER BY id ASC",
    [$sipb_id]
) ?? [];

// Get approvals
$approvals = $db->getRows(
    "SELECT ah.*, u.name as approver_name
     FROM sipb_approval_history ah
     LEFT JOIN users u ON ah.approver_id = u.id
     WHERE ah.sipb_id = ?
     ORDER BY ah.approval_level ASC",
    [$sipb_id]
) ?? [];

// Get creator
$creator = $db->getRow("SELECT name, email FROM users WHERE id = ?", [$sipb['created_by']]);

// URL verifikasi security (di-encode ke QR) - pakai verify_token, bukan ID biasa, biar gak gampang ditebak
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
// FIX: Use local IP address instead of localhost so mobile devices can scan QR code
// Default fallback ke HTTP_HOST jika tidak ada IP override, tapi saat ini pakai IP lokal
$host = '192.168.150.25';  // IP address lokal di WiFi kantor
$app_url = rtrim(getenv('APP_URL') ?: 'http://localhost/SIPB-GPI', '/');
$verify_url = $sipb['verify_token']
    ? "$protocol://$host$app_url/verify.php?token=" . $sipb['verify_token']
    : null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print - <?php echo htmlspecialchars($sipb['doc_number']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            color: #333;
            line-height: 1.6;
            padding: 20px;
            background: white;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            padding: 40px;
        }
        .header {
            display: grid;
            grid-template-columns: 120px 1fr 120px;
            align-items: center;
            gap: 15px;
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
        }
        .header-logo {
            width: 110px;
            height: 110px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .header-logo img { max-width: 100%; max-height: 100%; }
        .header-logo.placeholder {
            border: 1px dashed #bbb;
            border-radius: 6px;
            color: #bbb;
            font-size: 9px;
            text-align: center;
            line-height: 1.3;
        }
        .company-name {
            font-size: 17px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .document-title {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
            margin: 15px 0 8px;
            line-height: 1.3;
        }
        .doc-number {
            font-size: 16px;
            color: #2c3e50;
            font-family: monospace;
            font-weight: 700;
        }
        .qr-section {
            display: flex;
            align-items: center;
            gap: 16px;
            border: 1px dashed #bbb;
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 25px;
            background: #fafbfe;
        }
        .qr-section #qrcode {
            flex-shrink: 0;
            width: 100px;
            height: 100px;
        }
        .qr-caption-title { font-weight: bold; font-size: 13px; color: #2c3e50; margin-bottom: 4px; }
        .qr-caption-text { font-size: 11px; color: #7f8c8d; line-height: 1.4; }
        @media print {
            .qr-section { border-color: #999; background: #fff; }
        }
        .info-table {
            width: 100%;
            margin-bottom: 25px;
            font-size: 13px;
        }
        .info-table td {
            padding: 6px 15px;
            border-bottom: 1px solid #ddd;
        }
        .info-label {
            font-weight: bold;
            width: 30%;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
            font-size: 12px;
        }
        .items-table th {
            background: #f0f0f0;
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
            font-weight: bold;
            font-size: 11px;
        }
        .items-table td {
            padding: 7px;
            border: 1px solid #ddd;
        }
        .section {
            margin-bottom: 30px;
        }
        .section-title {
            font-size: 13px;
            font-weight: bold;
            border-bottom: 1px solid #ddd;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .signature-area {
            margin-top: 35px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            font-size: 11px;
        }
        .signature-box {
            position: relative;
            text-align: center;
            padding-top: 45px;
            min-height: 70px;
        }
        .signature-name {
            font-weight: bold;
            margin-top: 6px;
            font-size: 11px;
        }
        .submitted-tag {
            display: inline-block;
            margin-top: 8px;
            padding: 3px 12px;
            border: 1.5px solid #2c3e50;
            border-radius: 4px;
            color: #2c3e50;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 1px;
        }
        /* Stempel APPROVED - CSS-only, dipakai di kolom approver saat status Approved */
        .approve-stamp {
            position: absolute;
            top: -6px;
            left: 50%;
            transform: translateX(-50%) rotate(-10deg);
            width: 130px;
            height: 56px;
            border: 3px double #c0392b;
            border-radius: 14px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #c0392b;
            font-weight: 900;
            opacity: 0.88;
        }
        .approve-stamp .stamp-text { font-size: 13px; letter-spacing: 0.5px; }
        .approve-stamp .stamp-sub { font-size: 7px; letter-spacing: 1px; margin-top: 1px; }
        @media print {
            body {
                padding: 0;
            }
            .container {
                padding: 20px;
            }
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>

<div class="no-print" style="margin-bottom: 20px; text-align: center;">
    <button onclick="window.print()" style="padding: 10px 20px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;">
        🖨️ Print atau Simpan as PDF
    </button>
</div>

<div class="container">
    <!-- Header -->
    <div class="header">
        <div class="header-logo"><img src="<?php echo rtrim(getenv("APP_URL") ?: "http://localhost/SIPB-GPI", "/"); ?>/assets/img/Logo GPI.png" alt="IndoGum"></div>
        <div>
            <div class="company-name">PT Gumindo Perkasa Industri - Site 3 (SAC)</div>
            <div class="document-title">SURAT IJIN PENGELUARAN BARANG (SIPB)</div>
            <div class="doc-number"><?php echo htmlspecialchars($sipb['doc_number']); ?></div>
        </div>
        <div class="header-logo"><img src="<?php echo rtrim(getenv("APP_URL") ?: "http://localhost/SIPB-GPI", "/"); ?>/assets/img/Salim Group Logo.png" alt="Salim Group"></div>
    </div>

    <!-- QR Verifikasi Security -->
    <?php if ($verify_url): ?>
    <div class="qr-section">
        <div id="qrcode"></div>
        <div class="qr-caption">
            <div class="qr-caption-title">🔒 Scan untuk Verifikasi Security</div>
            <div class="qr-caption-text">Security wajib scan QR ini sebelum barang dikeluarkan, untuk cek status approval & cocokkan foto barang.</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Info -->
    <table class="info-table">
        <tr>
            <td class="info-label">Tanggal</td>
            <td><?php echo date('d M Y', strtotime($sipb['created_at'])); ?></td>
            <td class="info-label">Penerima</td>
            <td><?php echo htmlspecialchars($sipb['recipient'] ?? '-'); ?></td>
        </tr>
        <tr>
            <td class="info-label">Perusahaan</td>
            <td><?php echo htmlspecialchars($sipb['company'] ?? '-'); ?></td>
            <td class="info-label">Kendaraan</td>
            <td><?php echo htmlspecialchars($sipb['vehicle'] ?? '-'); ?></td>
        </tr>
        <tr>
            <td class="info-label">Nomor Polisi</td>
            <td colspan="3"><?php echo htmlspecialchars($sipb['license_plate'] ?? '-'); ?></td>
        </tr>
        <tr>
            <td class="info-label">Catatan</td>
            <td colspan="3"><?php echo htmlspecialchars($sipb['remarks'] ?? '-'); ?></td>
        </tr>
    </table>

    <!-- Items Section -->
    <div class="section">
        <div class="section-title">DAFTAR BARANG</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Kategori</th>
                    <th>Kode Barang</th>
                    <th>Nama Barang</th>
                    <th>Qty</th>
                    <th>Unit</th>
                    <th>Customer</th>
                    <th>Aplikasi</th>
                    <th>Keterangan</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $totals_by_unit = [];
                foreach ($items as $item) {
                    $u = $item['unit'] ?? 'pcs';
                    $totals_by_unit[$u] = ($totals_by_unit[$u] ?? 0) + floatval($item['quantity']);
                }
                ?>
                <?php foreach ($items as $idx => $item): ?>
                    <tr>
                        <td><?php echo $idx + 1; ?></td>
                        <td><?php echo htmlspecialchars($item['category'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($item['item_code']); ?></td>
                        <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td><?php echo rtrim(rtrim(number_format($item['quantity'], 2, '.', ''), '0'), '.'); ?></td>
                        <td><?php echo htmlspecialchars($item['unit'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($item['customer'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($item['application'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($item['notes'] ?? '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="background: #f0f0f0; font-weight: bold;">
                    <td colspan="4">TOTAL</td>
                    <td colspan="5">
                        <?php
                        $total_parts = [];
                        foreach ($totals_by_unit as $u => $qty) {
                            $total_parts[] = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') . ' ' . $u;
                        }
                        echo htmlspecialchars(implode(', ', $total_parts));
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <?php
    // Cari approval SPV dan PM dari riwayat (untuk tau nama approver + apakah approved)
    $spv_approval = null;
    $pm_approval = null;
    foreach ($approvals as $a) {
        if ($a['approval_level'] === 'SPV') $spv_approval = $a;
        if ($a['approval_level'] === 'PM') $pm_approval = $a;
    }
    ?>
    <!-- Signatures -->
    <div class="section">
        <div class="section-title">TANDA TANGAN</div>
        <div class="signature-area">
            <div class="signature-box">
                <div>Dibuat Oleh</div>
                <div class="signature-name"><?php echo htmlspecialchars($creator['name'] ?? 'N/A'); ?></div>
                <div class="submitted-tag">✓ SUBMITTED</div>
            </div>
            <div class="signature-box">
                <?php if ($spv_approval && $spv_approval['approval_status'] === 'Approved'): ?>
                    <div class="approve-stamp"><div class="stamp-text">APPROVED</div><div class="stamp-sub">SIPB-GPI</div></div>
                <?php endif; ?>
                <div>Disetujui</div>
                <div class="signature-name"><?php echo htmlspecialchars($spv_approval['position_name'] ?? 'SPV HRGA / R&D Manager'); ?></div>
                <?php if ($spv_approval): ?><div style="font-size: 11px; color: #666;"><?php echo htmlspecialchars($spv_approval['approver_name'] ?? ''); ?></div><?php endif; ?>
            </div>
            <div class="signature-box">
                <?php if ($pm_approval && $pm_approval['approval_status'] === 'Approved'): ?>
                    <div class="approve-stamp"><div class="stamp-text">APPROVED</div><div class="stamp-sub">SIPB-GPI</div></div>
                <?php endif; ?>
                <div>Disetujui</div>
                <div class="signature-name"><?php echo htmlspecialchars($pm_approval['position_name'] ?? 'Plant Manager'); ?></div>
                <?php if ($pm_approval): ?><div style="font-size: 11px; color: #666;"><?php echo htmlspecialchars($pm_approval['approver_name'] ?? ''); ?></div><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div style="text-align: center; margin-top: 50px; color: #999; font-size: 12px;">
        <p>Dokumen ini dicetak dari SIPB-GPI System</p>
        <p><?php echo date('d M Y H:i'); ?></p>
    </div>
</div>

<?php if ($verify_url): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
    // Simple QR code without logo (more reliable/clearer)
    new QRCode(document.getElementById("qrcode"), {
        text: <?php echo json_encode($verify_url); ?>,
        width: 100,
        height: 100,
        correctLevel: QRCode.CorrectLevel.M
    });
</script>
<?php endif; ?>

</body>
</html>

