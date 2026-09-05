<?php
/**
 * public_html/sipb/export-pdf.php
 *
 * SIPB PDF Export
 * Using TCPDF class
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

// Get creator
$creator = $db->getRow("SELECT name, email FROM users WHERE id = ?", [$sipb['created_by']]);

// Try to use PDFExport class if available
$use_tcpdf = false;
if (class_exists('PDFExport')) {
    try {
        require_once __DIR__ . '/../config/PDFExport.php';
        $pdfExport = new PDFExport();
        $pdfExport->generatePDF($sipb_id, 'D'); // D = download
        exit;
    } catch (Exception $e) {
        // Fallback to manual TCPDF
    }
}

// Fallback: Manual TCPDF if available
if (class_exists('TCPDF')) {
    try {
        require_once 'vendor/autoload.php';

        $pdf = new TCPDF();
        $pdf->SetCreator('SIPB-GPI System');
        $pdf->SetAuthor('PT. Salimagro Indonesia');
        $pdf->SetTitle('SIPB - ' . $sipb['doc_number']);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);

        // Header
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 10, 'SURAT IJIN PENGELUARAN BARANG', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 5, $sipb['doc_number'], 0, 1, 'C');
        $pdf->Ln(10);

        // Company info
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 5, 'PT. Salimagro Indonesia - GPI Division', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Ln(5);

        // Info table
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetFont('helvetica', 'B', 10);

        $pdf->Cell(40, 6, 'Section:', 0, 0, 'L', true);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, $sipb['section'], 0, 1, 'L');

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(40, 6, 'Tujuan:', 0, 0, 'L', true);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 6, $sipb['purpose'], 0, 'L');

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(40, 6, 'Dibuat Oleh:', 0, 0, 'L', true);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, $creator['name'] ?? 'N/A', 0, 1);

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(40, 6, 'Tanggal:', 0, 0, 'L', true);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, date('d M Y', strtotime($sipb['created_at'])), 0, 1);

        $pdf->Ln(5);

        // Items table
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(100, 150, 220);
        $pdf->SetTextColor(255, 255, 255);

        $pdf->Cell(15, 7, 'No', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Kode', 1, 0, 'C', true);
        $pdf->Cell(60, 7, 'Nama Barang', 1, 0, 'L', true);
        $pdf->Cell(15, 7, 'Qty', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Lot', 1, 0, 'C', true);
        $pdf->Cell(50, 7, 'Keterangan', 1, 1, 'L', true);

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 9);

        $total_qty = 0;
        foreach ($items as $idx => $item) {
            $total_qty += intval($item['quantity']);
            $pdf->Cell(15, 6, $idx + 1, 1, 0, 'C');
            $pdf->Cell(30, 6, substr($item['item_code'], 0, 10), 1, 0, 'L');
            $pdf->Cell(60, 6, substr($item['item_name'], 0, 25), 1, 0, 'L');
            $pdf->Cell(15, 6, $item['quantity'], 1, 0, 'C');
            $pdf->Cell(30, 6, substr($item['lot'] ?? '-', 0, 15), 1, 0, 'L');
            $pdf->Cell(50, 6, substr($item['remarks'] ?? '-', 0, 20), 1, 1, 'L');
        }

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(120, 6, 'TOTAL', 1, 0, 'R');
        $pdf->Cell(15, 6, $total_qty, 1, 0, 'C');
        $pdf->Cell(80, 6, '', 1, 1);

        $pdf->Ln(5);

        // Notes if any
        if (!empty($sipb['notes'])) {
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 6, 'CATATAN:', 0, 1);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->MultiCell(0, 5, $sipb['notes'], 0, 'L');
        }

        // Output
        $filename = $sipb['doc_number'] . '.pdf';
        $pdf->Output($filename, 'D');
        exit;

    } catch (Exception $e) {
        // Fallback to HTML print
    }
}

// Fallback: Redirect to print.php
header("Location: print.php?id=$sipb_id");
exit;
