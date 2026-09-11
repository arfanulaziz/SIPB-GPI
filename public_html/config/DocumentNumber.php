<?php
/**
 * public_html/config/DocumentNumber.php
 *
 * Auto-generate SIPB document numbers
 * Format: SIPB.GPI.{SECTION}.{YYYY.MM.DD}.{SEQ}
 *
 * Examples:
 * - SIPB.GPI.R&D.2026.08.27.0001
 * - SIPB.GPI.QAQC.2026.08.27.0001
 * - SIPB.GPI.HRGA.2026.08.27.0001
 * - SIPB.GPI.WH.2026.08.27.0001
 *
 * Sections reset counter per month
 */

class DocumentNumber {
    protected $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    /**
     * Get section code from user
     *
     * @param int $user_id
     * @return string Section: R&D, QAQC, HRGA, WH, or unknown section
     */
    public function getSectionFromUser($user_id) {
        $query = "SELECT section FROM users WHERE id = ? LIMIT 1";
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return 'UNKNOWN';
        }

        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            return $this->mapSectionCode($user['section']);
        }

        return 'UNKNOWN';
    }

    /**
     * Map database section to document code
     * R&D → R&D
     * QC → QAQC
     * HR → HRGA
     * Warehouse → WH
     *
     * @param string $db_section
     * @return string Code
     */
    private function mapSectionCode($db_section) {
        $mapping = [
            'R&D' => 'R&D',
            'QC' => 'QAQC',
            'HR' => 'HRGA',
            'Warehouse' => 'WH',
            'QAQC' => 'QAQC',
            'HRGA' => 'HRGA',
            'WH' => 'WH',
        ];

        return $mapping[$db_section] ?? 'UNKNOWN';
    }

    /**
     * Generate next document number
     *
     * @param string $section R&D|QAQC|HRGA|WH
     * @return string Document number or false on error
     *
     * @example
     * $doc_number = $doc_gen->generateDocumentNumber('R&D');
     * // Returns: SIPB.GPI.R&D.2026.08.27.0001
     */
    public function generateDocumentNumber($section) {
        $today = date('Y-m-d');
        $year = date('Y');
        $month = date('m');
        $day = date('d');

        // Normalize section
        $section = $this->normalizeSection($section);
        if ($section === false) {
            return false;
        }

        try {
            // Begin transaction
            $this->conn->begin_transaction();

            // Get or create counter for this month/section
            $query = "SELECT id, next_sequence FROM document_numbering
                      WHERE year = ? AND month = ? AND section = ?
                      LIMIT 1 FOR UPDATE";

            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare error: " . $this->conn->error);
            }

            $stmt->bind_param('iis', $year, $month, $section);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                // Counter exists, increment it
                $row = $result->fetch_assoc();
                $next_seq = $row['next_sequence'];
                $num_id = $row['id'];
            } else {
                // Create new counter for this month
                $insert_query = "INSERT INTO document_numbering (year, month, section, next_sequence)
                                 VALUES (?, ?, ?, 1)";
                $insert_stmt = $this->conn->prepare($insert_query);
                if (!$insert_stmt) {
                    throw new Exception("Insert error: " . $this->conn->error);
                }

                $insert_stmt->bind_param('iis', $year, $month, $section);
                if (!$insert_stmt->execute()) {
                    throw new Exception("Insert execution error: " . $insert_stmt->error);
                }

                $next_seq = 1;
                $num_id = $this->conn->insert_id;
            }

            // Generate formatted sequence (4 digits)
            $seq_formatted = str_pad($next_seq, 4, '0', STR_PAD_LEFT);

            // Format: SIPB.GPI.{SECTION}.{YYYY.MM.DD}.{SEQ}
            $doc_number = "SIPB.GPI.$section.$year.$month.$day.$seq_formatted";

            // Update sequence counter
            $next_sequence = $next_seq + 1;
            $update_query = "UPDATE document_numbering
                             SET next_sequence = ?, last_generated_at = NOW()
                             WHERE id = ?";
            $update_stmt = $this->conn->prepare($update_query);
            if (!$update_stmt) {
                throw new Exception("Update error: " . $this->conn->error);
            }

            $update_stmt->bind_param('ii', $next_sequence, $num_id);
            if (!$update_stmt->execute()) {
                throw new Exception("Update execution error: " . $update_stmt->error);
            }

            // Commit transaction
            $this->conn->commit();

            return $doc_number;
        } catch (Exception $e) {
            // Rollback on error
            $this->conn->rollback();
            return false;
        }
    }

    /**
     * Validate & normalize section code
     *
     * @param string $section
     * @return string|false Normalized section or false if invalid
     */
    private function normalizeSection($section) {
        $valid_sections = ['R&D', 'QAQC', 'HRGA', 'WH'];

        // Trim & convert to uppercase
        $section = strtoupper(trim($section));

        // Check if valid
        if (!in_array($section, $valid_sections)) {
            return false;
        }

        return $section;
    }

    /**
     * Get next sequence number for given section/month
     * (For preview/display purposes only)
     *
     * @param string $section
     * @return int|null
     */
    public function getNextSequence($section) {
        $section = $this->normalizeSection($section);
        if ($section === false) {
            return null;
        }

        $year = date('Y');
        $month = date('m');

        $query = "SELECT next_sequence FROM document_numbering
                  WHERE year = ? AND month = ? AND section = ?
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('iis', $year, $month, $section);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['next_sequence'];
        }

        return 1; // First document of the month
    }

    /**
     * Get preview of next document number (without incrementing)
     *
     * @param string $section
     * @return string|null Preview of next doc number
     */
    public function previewNextDocumentNumber($section) {
        $section = $this->normalizeSection($section);
        if ($section === false) {
            return null;
        }

        $next_seq = $this->getNextSequence($section);
        if ($next_seq === null) {
            return null;
        }

        $year = date('Y');
        $month = date('m');
        $day = date('d');
        $seq_formatted = str_pad($next_seq, 4, '0', STR_PAD_LEFT);

        return "SIPB.GPI.$section.$year.$month.$day.$seq_formatted";
    }

    /**
     * Reset counter for a specific month/section (admin function)
     * USE WITH CAUTION - only if previous numbers need to be voided
     *
     * @param string $section
     * @param int $year
     * @param int $month
     * @param int $new_sequence
     * @return bool
     */
    public function resetSequence($section, $year, $month, $new_sequence) {
        $section = $this->normalizeSection($section);
        if ($section === false) {
            return false;
        }

        $query = "UPDATE document_numbering
                  SET next_sequence = ?
                  WHERE year = ? AND month = ? AND section = ?";

        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('iiis', $new_sequence, $year, $month, $section);
        return $stmt->execute();
    }
}


