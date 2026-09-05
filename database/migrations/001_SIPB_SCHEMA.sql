-- ============================================
-- SIPB System - Database Schema (MariaDB 11.8)
-- ============================================
-- Created: 2026-08-27
-- Version: 1.0
--
-- Stack: PHP 7.4+ | MySQLi | Bootstrap 5
-- Stack: XAMPP → VSCode → localhost
--
-- NOTE:
-- - Use prepared statements for ALL queries
-- - Security baseline: SQL injection prevention, CSRF token, input validation
-- - Audit trail via sipb_audit_log table
-- ============================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET COLLATION_CONNECTION=utf8mb4_unicode_ci;
SET time_zone = '+07:00';

-- ============================================
-- 1. TABLE: users
-- ============================================
-- Master user dengan role-based access control (RBAC)

DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  nik VARCHAR(20) UNIQUE NOT NULL COMMENT 'Employee ID',
  name VARCHAR(100) NOT NULL,
  email VARCHAR(100) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL COMMENT 'bcrypt hash',
  role ENUM('superadmin','approver_spv','approver_pm','user') NOT NULL DEFAULT 'user',
  section ENUM('R&D','QC','Warehouse','Production','PPIC','HR','Admin') NOT NULL,
  whatsapp VARCHAR(20) COMMENT 'Phone number format: +62...',
  is_active BOOLEAN DEFAULT 1 COMMENT 'Soft delete flag',
  is_approved BOOLEAN DEFAULT 0 COMMENT 'Admin approval status',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT COMMENT 'Superadmin yang create user ini',

  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_role_active (role, is_active),
  INDEX idx_section (section),
  INDEX idx_nik (nik),
  CONSTRAINT chk_whatsapp CHECK (whatsapp IS NULL OR whatsapp LIKE '+62%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. TABLE: sipb_documents
-- ============================================
-- Header dokumen SIPB dengan dual-level approval workflow

DROP TABLE IF EXISTS sipb_documents;
CREATE TABLE sipb_documents (
  id INT PRIMARY KEY AUTO_INCREMENT,
  doc_number VARCHAR(100) UNIQUE NOT NULL COMMENT 'Format: SIPB.GPI.{Section}.{YYYY.MM.DD}.{seq}',
  doc_date DATE NOT NULL,
  category ENUM('R&D Sample','Prototipe','Alat','QC Sample','Lain-lain') NOT NULL DEFAULT 'Lain-lain',

  /* Penerima */
  recipient_name VARCHAR(150) NOT NULL COMMENT 'Nama orang yang menerima barang',
  recipient_phone VARCHAR(20) COMMENT 'Contact person phone',
  recipient_address TEXT COMMENT 'Alamat tujuan pengiriman',

  /* Customer / Tujuan */
  customer_name VARCHAR(200) NOT NULL COMMENT 'Nama customer/perusahaan tujuan',
  customer_location VARCHAR(200) COMMENT 'Lokasi customer',

  /* Pengiriman */
  vehicle_type VARCHAR(100) COMMENT 'Jenis kendaraan (Xpander, Via JNE, etc)',
  plate_number VARCHAR(50),

  /* Status Dokumen */
  status ENUM('Draft','Submitted','Approved','Rejected') DEFAULT 'Draft',
  remarks TEXT COMMENT 'Catatan umum dokumen',

  /* Approval Level 1 (SPV.GA.HRD) */
  approval_spv_status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
  approval_spv_by INT COMMENT 'User ID approver SPV',
  approval_spv_at DATETIME COMMENT 'Timestamp approval SPV',
  approval_spv_note TEXT COMMENT 'Catatan approval/reject SPV',

  /* Approval Level 2 (Plant Manager) */
  approval_pm_status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
  approval_pm_by INT COMMENT 'User ID approver PM',
  approval_pm_at DATETIME COMMENT 'Timestamp approval PM',
  approval_pm_note TEXT COMMENT 'Catatan approval/reject PM',

  /* Metadata */
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (approval_spv_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (approval_pm_by) REFERENCES users(id) ON DELETE SET NULL,

  INDEX idx_status (status),
  INDEX idx_doc_date (doc_date),
  INDEX idx_doc_number (doc_number),
  INDEX idx_created_by (created_by),
  INDEX idx_approval_spv (approval_spv_status),
  INDEX idx_approval_pm (approval_pm_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. TABLE: sipb_items
-- ============================================
-- Detail item/barang dalam setiap SIPB dengan lot tracking

DROP TABLE IF EXISTS sipb_items;
CREATE TABLE sipb_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  sipb_id INT NOT NULL,

  /* Barang */
  item_name VARCHAR(255) NOT NULL,
  item_code VARCHAR(50) COMMENT 'Product code (future inventory integration)',
  quantity DECIMAL(12,2) NOT NULL,
  unit VARCHAR(20) NOT NULL COMMENT 'satuan: g, pcs, L, ml, etc',

  /* Lot & Expiry Tracking */
  lot_number VARCHAR(100) COMMENT 'Batch/Lot number',
  manufacture_date DATE COMMENT 'Tanggal produksi',
  expiry_date DATE COMMENT 'Tanggal kadaluarsa',

  /* Notes */
  notes TEXT,

  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (sipb_id) REFERENCES sipb_documents(id) ON DELETE CASCADE,
  INDEX idx_sipb_id (sipb_id),
  INDEX idx_lot_number (lot_number),
  INDEX idx_expiry_date (expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. TABLE: sipb_audit_log
-- ============================================
-- Audit trail lengkap setiap transaksi (compliance & digital signature trail)

DROP TABLE IF EXISTS sipb_audit_log;
CREATE TABLE sipb_audit_log (
  id INT PRIMARY KEY AUTO_INCREMENT,
  sipb_id INT NOT NULL,

  /* Action Detail */
  action VARCHAR(50) NOT NULL COMMENT 'create|submit|approve|reject|edit|delete',
  action_by INT NOT NULL,
  action_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  /* Snapshot */
  old_value JSON COMMENT 'Data sebelum perubahan',
  new_value JSON COMMENT 'Data sesudah perubahan',
  notes TEXT COMMENT 'Catatan tambahan dari action',
  ip_address VARCHAR(45) COMMENT 'IP address user',
  user_agent VARCHAR(255) COMMENT 'Browser user agent',

  FOREIGN KEY (sipb_id) REFERENCES sipb_documents(id) ON DELETE CASCADE,
  FOREIGN KEY (action_by) REFERENCES users(id) ON DELETE RESTRICT,

  INDEX idx_sipb_id (sipb_id),
  INDEX idx_action_at (action_at),
  INDEX idx_action (action),
  INDEX idx_action_by (action_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. TABLE: sipb_approval_history (Optional)
-- ============================================
-- Approval history log terpisah (untuk performance query approval)

DROP TABLE IF EXISTS sipb_approval_history;
CREATE TABLE sipb_approval_history (
  id INT PRIMARY KEY AUTO_INCREMENT,
  sipb_id INT NOT NULL,
  approval_level ENUM('SPV','PM') NOT NULL,
  approval_status ENUM('Approved','Rejected') NOT NULL,
  approver_id INT NOT NULL,
  approval_note TEXT,
  approved_at DATETIME DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (sipb_id) REFERENCES sipb_documents(id) ON DELETE CASCADE,
  FOREIGN KEY (approver_id) REFERENCES users(id) ON DELETE RESTRICT,

  INDEX idx_sipb_id (sipb_id),
  INDEX idx_approver_id (approver_id),
  INDEX idx_approval_level (approval_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- SEEDS: Default Admin User
-- ============================================

INSERT INTO users (nik, name, email, password_hash, role, section, whatsapp, is_active, is_approved, created_by)
VALUES
  (
    '99001',
    'System Admin',
    'admin@gpi.local',
    '$2y$10$MU3i0q4HYWTm8.xVURm0SOADqvqTqyuQM7M6JKEvpdOQdgADEtSni', -- password: admin123
    'superadmin',
    'Admin',
    '+6281234567890',
    1,
    1,
    NULL
  );

-- ============================================
-- VIEWS (Optional - untuk common reporting queries)
-- ============================================

-- View: Pending approvals untuk SPV
DROP VIEW IF EXISTS v_pending_spv_approvals;
CREATE VIEW v_pending_spv_approvals AS
SELECT
  d.id,
  d.doc_number,
  d.doc_date,
  d.category,
  d.customer_name,
  d.created_by,
  u.name as created_by_name,
  (SELECT COUNT(*) FROM sipb_items WHERE sipb_id = d.id) as total_items
FROM sipb_documents d
JOIN users u ON d.created_by = u.id
WHERE d.approval_spv_status = 'Pending'
  AND d.status = 'Submitted'
ORDER BY d.doc_date DESC;

-- View: Pending approvals untuk PM
DROP VIEW IF EXISTS v_pending_pm_approvals;
CREATE VIEW v_pending_pm_approvals AS
SELECT
  d.id,
  d.doc_number,
  d.doc_date,
  d.category,
  d.customer_name,
  d.created_by,
  u.name as created_by_name,
  d.approval_spv_by,
  u_spv.name as approved_by_spv,
  d.approval_spv_at,
  (SELECT COUNT(*) FROM sipb_items WHERE sipb_id = d.id) as total_items
FROM sipb_documents d
JOIN users u ON d.created_by = u.id
LEFT JOIN users u_spv ON d.approval_spv_by = u_spv.id
WHERE d.approval_pm_status = 'Pending'
  AND d.approval_spv_status = 'Approved'
ORDER BY d.approval_spv_at DESC;

-- ============================================
-- END OF SCHEMA
-- ============================================
-- Next steps:
-- 1. Create config/config.php & .env
-- 2. Implement Security.php & Database.php helper classes
-- 3. Build login & auth flow
-- 4. Build SIPB CRUD pages
-- ============================================