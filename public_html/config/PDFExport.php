<?php
/**
 * public_html/config/PDFExport.php
 *
 * PDF export for SIPB documents using TCPDF
 * Also supports browser print (via HTML print stylesheet)
 *
 * Installation:
 * composer require tecnickcom/tcpdf
 */

require_once(__DIR__ . '/../../vendor/autoload.php');

class PDFExport {
    protected $conn;
    protected $tcpdf;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    /**
     * Generate PDF for SIPB document
     *
     * @param int $sipb_id
     * @param string $output 'I' = inline, 'D' = download, 'S' = return string
     * @return void|string
     *
     * @example
     * $pdf = new PDFExport($conn);
     * $pdf->generatePDF(123, 'D'); // Download PDF
     */
    public function generatePDF($sipb_id, $output = 'D') {
        // Get SIPB data
        $sipb = $this->getSIPBData($sipb_id);
        if (!$sipb) {
            die("SIPB document not found");
        }

        // Initialize TCPDF
        $this->tcpdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        // Set document information
        $this->tcpdf->SetCreator('SIPB-GPI System');
        $this->tcpdf->SetAuthor('Salimagro');
        $this->tcpdf->SetTitle('SIPB ' . $sipb['doc_number']);
        $this->tcpdf->SetSubject('Surat Ijin Pengeluaran Barang');

        // Set margins
        $this->tcpdf->SetMargins(10, 10, 10);
        $this->tcpdf->SetHeaderMargin(0);
        $this->tcpdf->SetFooterMargin(0);

        // Set auto page breaks
        $this->tcpdf->SetAutoPageBreak(TRUE, 15);

        // Add page
        $this->tcpdf->AddPage();

        // Set font
        $this->tcpdf->SetFont('helvetica', '', 10);

        // Header
        $html = $this->buildPDFHeader($sipb);

        // Document details
        $html .= $this->buildDocumentDetails($sipb);

        // Items table
        $html .= $this->buildItemsTable($sipb_id);

        // Approval signatures
        $html .= $this->buildApprovalSignatures($sipb);

        // Notes
        if (!empty($sipb['remarks'])) {
            $html .= "<br><h3>Catatan:</h3>";
            $html .= "<p>" . htmlspecialchars($sipb['remarks']) . "</p>";
        }

        // Footer
        $html .= $this->buildPDFFooter($sipb);

        // Write HTML
        $this->tcpdf->writeHTML($html, true, false, true, false, '');

        // Output PDF
        $filename = "SIPB_" . str_replace('.', '_', $sipb['doc_number']) . ".pdf";
        $this->tcpdf->Output($filename, $output);
    }

    /**
     * Generate HTML for browser print
     * (User clicks "Print" button in browser)
     *
     * @param int $sipb_id
     * @return string HTML content
     */
    public function generateHTMLForPrint($sipb_id) {
        $sipb = $this->getSIPBData($sipb_id);
        if (!$sipb) {
            return "<p>SIPB document not found</p>";
        }

        $html = "
        <!DOCTYPE html>
        <html lang='id'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>SIPB " . htmlspecialchars($sipb['doc_number']) . "</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    margin: 20px;
                    color: #333;
                }
                .header {
                    text-align: center;
                    margin-bottom: 20px;
                    border-bottom: 2px solid #000;
                    padding-bottom: 10px;
                }
                .header h1 {
                    margin: 5px 0;
                    font-size: 24px;
                }
                .header p {
                    margin: 2px 0;
                    font-size: 12px;
                }
                .section {
                    margin: 15px 0;
                }
                .section h3 {
                    background: #f0f0f0;
                    padding: 8px;
                    margin: 10px 0 5px 0;
                    font-size: 12px;
                }
                .row {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 20px;
                    margin: 10px 0;
                }
                .field {
                    margin: 5px 0;
                }
                .field label {
                    font-weight: bold;
                    font-size: 11px;
                }
                .field value {
                    display: block;
                    font-size: 11px;
                    margin-top: 2px;
                    padding: 5px;
                    background: #fafafa;
                    border: 1px solid #ddd;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 15px 0;
                    font-size: 10px;
                }
                table th {
                    background: #333;
                    color: white;
                    padding: 8px;
                    text-align: left;
                    font-weight: bold;
                }
                table td {
                    padding: 8px;
                    border: 1px solid #ddd;
                }
                table tr:nth-child(even) {
                    background: #f9f9f9;
                }
                .signatures {
                    margin-top: 30px;
                }
                .signature-block {
                    display: inline-block;
                    width: 23%;
                    margin-right: 2%;
                    text-align: center;
                    font-size: 10px;
                }
                .signature-line {
                    border-top: 1px solid #000;
                    margin-top: 40px;
                    padding-top: 5px;
                }
                .footer {
                    margin-top: 30px;
                    text-align: center;
                    font-size: 9px;
                    color: #666;
                    border-top: 1px solid #ddd;
                    padding-top: 10px;
                }
                .status-badge {
                    display: inline-block;
                    padding: 3px 8px;
                    border-radius: 3px;
                    font-weight: bold;
                    font-size: 11px;
                }
                .status-approved {
                    background: #d4edda;
                    color: #155724;
                }
                .status-rejected {
                    background: #f8d7da;
                    color: #721c24;
                }
                .status-pending {
                    background: #fff3cd;
                    color: #856404;
                }
                @media print {
                    body { margin: 10px; }
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>🏢 SALIMAGRO</h1>
                <p>SURAT IJIN PENGELUARAN BARANG (SIPB)</p>
                <p>PT. Salimagro Indonesia - GPI Division</p>
            </div>

            <div class='section'>
                <div class='row'>
                    <div class='field'>
                        <label>Nomor Dokumen:</label>
                        <value>" . htmlspecialchars($sipb['doc_number']) . "</value>
                    </div>
                    <div class='field'>
                        <label>Tanggal:</label>
                        <value>" . date('d-m-Y', strtotime($sipb['doc_date'])) . "</value>
                    </div>
                </div>

                <div class='row'>
                    <div class='field'>
                        <label>Status:</label>
                        <value>
                            <span class='status-badge status-" . strtolower($sipb['status']) . "'>
                                " . htmlspecialchars($sipb['status']) . "
                            </span>
                        </value>
                    </div>
                    <div class='field'>
                        <label>Kategori:</label>
                        <value>" . htmlspecialchars($sipb['category']) . "</value>
                    </div>
                </div>
            </div>

            <div class='section'>
                <h3>📋 INFORMASI PENGIRIM</h3>
                <div class='row'>
                    <div class='field'>
                        <label>Nama Penerima:</label>
                        <value>" . htmlspecialchars($sipb['recipient_name']) . "</value>
                    </div>
                    <div class='field'>
                        <label>Kontak:</label>
                        <value>" . htmlspecialchars($sipb['recipient_phone'] ?? '-') . "</value>
                    </div>
                </div>
                <div class='field'>
                    <label>Alamat Tujuan:</label>
                    <value>" . htmlspecialchars($sipb['recipient_address'] ?? '-') . "</value>
                </div>
            </div>

            <div class='section'>
                <h3>🏭 INFORMASI CUSTOMER</h3>
                <div class='row'>
                    <div class='field'>
                        <label>Nama Customer:</label>
                        <value>" . htmlspecialchars($sipb['customer_name']) . "</value>
                    </div>
                    <div class='field'>
                        <label>Lokasi:</label>
                        <value>" . htmlspecialchars($sipb['customer_location'] ?? '-') . "</value>
                    </div>
                </div>
            </div>

            <div class='section'>
                <h3>🚗 INFORMASI PENGIRIMAN</h3>
                <div class='row'>
                    <div class='field'>
                        <label>Jenis Kendaraan:</label>
                        <value>" . htmlspecialchars($sipb['vehicle_type'] ?? '-') . "</value>
                    </div>
                    <div class='field'>
                        <label>Plat Nomor:</label>
                        <value>" . htmlspecialchars($sipb['plate_number'] ?? '-') . "</value>
                    </div>
                </div>
            </div>

            <div class='section'>
                <h3>📦 DETAIL BARANG</h3>
                " . $this->buildItemsHTMLTable($sipb_id) . "
            </div>

            <div class='section'>
                <h3>✅ PERSETUJUAN</h3>
                <div class='row'>
                    <div class='field'>
                        <label>SPV.GA/HRD:</label>
                        <value>
                            <strong>" . $this->getApprovalStatus($sipb, 'spv') . "</strong>
                            " . ($sipb['approval_spv_by'] ? $this->getUserName($sipb['approval_spv_by']) : '-') . "
                            " . ($sipb['approval_spv_at'] ? '<br>(' . date('d-m-Y H:i', strtotime($sipb['approval_spv_at'])) . ')' : '') . "
                        </value>
                    </div>
                    <div class='field'>
                        <label>Plant Manager:</label>
                        <value>
                            <strong>" . $this->getApprovalStatus($sipb, 'pm') . "</strong>
                            " . ($sipb['approval_pm_by'] ? $this->getUserName($sipb['approval_pm_by']) : '-') . "
                            " . ($sipb['approval_pm_at'] ? '<br>(' . date('d-m-Y H:i', strtotime($sipb['approval_pm_at'])) . ')' : '') . "
                        </value>
                    </div>
                </div>
            </div>

            <div class='signatures'>
                <div style='text-align: center; margin-bottom: 20px;'>
                    <strong>Tanda Tangan Persetujuan</strong>
                </div>

                <div class='signature-block'>
                    <p><strong>Pembuat</strong></p>
                    <div class='signature-line'>&nbsp;</div>
                    <p>" . htmlspecialchars($this->getUserName($sipb['created_by']) ?? 'N/A') . "</p>
                    <p style='font-size: 9px;'>" . date('d-m-Y', strtotime($sipb['created_at'])) . "</p>
                </div>

                <div class='signature-block'>
                    <p><strong>SPV.GA/HRD</strong></p>
                    <div class='signature-line'>&nbsp;</div>
                    <p>" . htmlspecialchars($this->getUserName($sipb['approval_spv_by']) ?? '---') . "</p>
                    <p style='font-size: 9px;'>" . ($sipb['approval_spv_at'] ? date('d-m-Y', strtotime($sipb['approval_spv_at'])) : '---') . "</p>
                </div>

                <div class='signature-block'>
                    <p><strong>Plant Manager</strong></p>
                    <div class='signature-line'>&nbsp;</div>
                    <p>" . htmlspecialchars($this->getUserName($sipb['approval_pm_by']) ?? '---') . "</p>
                    <p style='font-size: 9px;'>" . ($sipb['approval_pm_at'] ? date('d-m-Y', strtotime($sipb['approval_pm_at'])) : '---') . "</p>
                </div>
            </div>

            <div class='footer'>
                <p>Dokumen ini dicetak secara otomatis oleh SIPB-GPI System @ Salimagro</p>
                <p>Dicetak: " . date('d-m-Y H:i:s') . "</p>
            </div>

            <div class='no-print' style='margin-top: 20px; text-align: center; border-top: 1px solid #ddd; padding-top: 10px;'>
                <button onclick='window.print()' style='padding: 10px 20px; background: #3498db; color: white; border: none; border-radius: 5px; cursor: pointer;'>
                    🖨️ Print / PDF
                </button>
                <button onclick='window.close()' style='padding: 10px 20px; background: #95a5a6; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;'>
                    Tutup
                </button>
            </div>
        </body>
        </html>";

        return $html;
    }

    /**
     * Build PDF header
     */
    private function buildPDFHeader($sipb) {
        return "
        <div style='text-align: center; margin-bottom: 20px;'>
            <h1 style='margin: 5px 0;'>SALIMAGRO</h1>
            <h2 style='margin: 5px 0; font-size: 14px;'>SURAT IJIN PENGELUARAN BARANG (SIPB)</h2>
            <p style='margin: 2px 0; font-size: 11px;'>PT. Salimagro Indonesia - GPI Division</p>
            <hr>
        </div>";
    }

    /**
     * Build document details section
     */
    private function buildDocumentDetails($sipb) {
        return "
        <table cellpadding='5' border='1'>
            <tr>
                <td width='25%'><b>Nomor Dokumen</b></td>
                <td width='75%'>" . htmlspecialchars($sipb['doc_number']) . "</td>
            </tr>
            <tr>
                <td><b>Tanggal</b></td>
                <td>" . date('d-m-Y', strtotime($sipb['doc_date'])) . "</td>
            </tr>
            <tr>
                <td><b>Status</b></td>
                <td>" . htmlspecialchars($sipb['status']) . "</td>
            </tr>
            <tr>
                <td><b>Kategori</b></td>
                <td>" . htmlspecialchars($sipb['category']) . "</td>
            </tr>
            <tr>
                <td><b>Penerima</b></td>
                <td>" . htmlspecialchars($sipb['recipient_name']) . "</td>
            </tr>
            <tr>
                <td><b>Customer</b></td>
                <td>" . htmlspecialchars($sipb['customer_name']) . "</td>
            </tr>
        </table>";
    }

    /**
     * Build items table for PDF
     */
    private function buildItemsTable($sipb_id) {
        $items = $this->getItemsData($sipb_id);

        $html = "
        <h3>Detail Barang</h3>
        <table cellpadding='5' border='1'>
            <tr style='background-color: #333; color: white;'>
                <th width='5%'>No</th>
                <th width='35%'>Nama Barang</th>
                <th width='15%'>Lot Number</th>
                <th width='10%'>Qty</th>
                <th width='10%'>Satuan</th>
                <th width='15%'>Exp. Date</th>
            </tr>";

        $no = 1;
        foreach ($items as $item) {
            $html .= "
            <tr>
                <td>" . $no++ . "</td>
                <td>" . htmlspecialchars($item['item_name']) . "</td>
                <td>" . htmlspecialchars($item['lot_number'] ?? '-') . "</td>
                <td>" . $item['quantity'] . "</td>
                <td>" . htmlspecialchars($item['unit']) . "</td>
                <td>" . ($item['expiry_date'] ? date('d-m-Y', strtotime($item['expiry_date'])) : '-') . "</td>
            </tr>";
        }

        $html .= "</table>";
        return $html;
    }

    /**
     * Build items table for HTML
     */
    private function buildItemsHTMLTable($sipb_id) {
        $items = $this->getItemsData($sipb_id);

        $html = "<table>";
        $html .= "
            <tr>
                <th>No</th>
                <th>Nama Barang</th>
                <th>Lot Number</th>
                <th>Qty</th>
                <th>Satuan</th>
                <th>Exp. Date</th>
            </tr>";

        $no = 1;
        foreach ($items as $item) {
            $html .= "
            <tr>
                <td>" . $no++ . "</td>
                <td>" . htmlspecialchars($item['item_name']) . "</td>
                <td>" . htmlspecialchars($item['lot_number'] ?? '-') . "</td>
                <td>" . $item['quantity'] . "</td>
                <td>" . htmlspecialchars($item['unit']) . "</td>
                <td>" . ($item['expiry_date'] ? date('d-m-Y', strtotime($item['expiry_date'])) : '-') . "</td>
            </tr>";
        }

        $html .= "</table>";
        return $html;
    }

    /**
     * Build approval signatures section
     */
    private function buildApprovalSignatures($sipb) {
        return "
        <h3 style='margin-top: 30px;'>TANDA TANGAN PERSETUJUAN</h3>
        <table width='100%'>
            <tr>
                <td width='25%' align='center'><b>Pembuat</b></td>
                <td width='25%' align='center'><b>SPV.GA/HRD</b></td>
                <td width='25%' align='center'><b>Plant Manager</b></td>
            </tr>
            <tr height='50'>
                <td align='center'>&nbsp;</td>
                <td align='center'>&nbsp;</td>
                <td align='center'>&nbsp;</td>
            </tr>
            <tr>
                <td align='center' style='font-size: 9px;'>" . htmlspecialchars($this->getUserName($sipb['created_by']) ?? 'N/A') . "</td>
                <td align='center' style='font-size: 9px;'>" . htmlspecialchars($this->getUserName($sipb['approval_spv_by']) ?? '---') . "</td>
                <td align='center' style='font-size: 9px;'>" . htmlspecialchars($this->getUserName($sipb['approval_pm_by']) ?? '---') . "</td>
            </tr>
        </table>";
    }

    /**
     * Build PDF footer
     */
    private function buildPDFFooter($sipb) {
        return "
        <hr>
        <p align='center' style='font-size: 8px;'>Dokumen ini dicetak otomatis oleh SIPB-GPI System | Dicetak: " . date('d-m-Y H:i:s') . "</p>";
    }

    /**
     * Get SIPB data
     */
    protected function getSIPBData($sipb_id) {
        $query = "SELECT s.*, u.name as created_by_name
                  FROM sipb_documents s
                  LEFT JOIN users u ON s.created_by = u.id
                  WHERE s.id = ? LIMIT 1";

        $stmt = $this->conn->prepare($query);
        if (!$stmt) return null;

        $stmt->bind_param('i', $sipb_id);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result->num_rows > 0 ? $result->fetch_assoc() : null;
    }

    /**
     * Get items for SIPB
     */
    protected function getItemsData($sipb_id) {
        $query = "SELECT * FROM sipb_items WHERE sipb_id = ? ORDER BY id ASC";

        $stmt = $this->conn->prepare($query);
        if (!$stmt) return [];

        $stmt->bind_param('i', $sipb_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        return $items;
    }

    /**
     * Get user name
     */
    protected function getUserName($user_id) {
        $query = "SELECT name FROM users WHERE id = ? LIMIT 1";
        $stmt = $this->conn->prepare($query);
        if (!$stmt) return null;

        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['name'];
        }
        return null;
    }

    /**
     * Get approval status label
     */
    protected function getApprovalStatus($sipb, $level) {
        $field = "approval_" . $level . "_status";
        $status = $sipb[$field] ?? 'Pending';

        switch ($status) {
            case 'Approved':
                return "✅ " . $status;
            case 'Rejected':
                return "❌ " . $status;
            default:
                return "⏳ " . $status;
        }
    }
}

?>
