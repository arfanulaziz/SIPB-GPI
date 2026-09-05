-- ============================================
-- SIPB System - Additional Schema Updates
-- ============================================
-- For advanced features:
-- 1. Email notification system
-- 2. Approval reassign/takeover
-- 3. Document numbering with section
--
-- NOTE: Run this AFTER 001_SIPB_SCHEMA.sql
-- ============================================

-- ============================================
-- 1. UPDATE: sipb_documents (add reassign tracking)
-- ============================================

ALTER TABLE sipb_documents
ADD COLUMN approval_spv_reassigned_from INT COMMENT 'Original approver SPV jika di-reassign' AFTER approval_spv_by,
ADD COLUMN approval_spv_reassigned_at DATETIME COMMENT 'Timestamp reassign SPV' AFTER approval_spv_reassigned_from,
ADD COLUMN approval_pm_reassigned_from INT COMMENT 'Original approver PM jika di-reassign' AFTER approval_pm_by,
ADD COLUMN approval_pm_reassigned_at DATETIME COMMENT 'Timestamp reassign PM' AFTER approval_pm_reassigned_from,
ADD FOREIGN KEY (approval_spv_reassigned_from) REFERENCES users(id) ON DELETE SET NULL,
ADD FOREIGN KEY (approval_pm_reassigned_from) REFERENCES users(id) ON DELETE SET NULL;

-- ============================================
-- 2. NEW TABLE: email_queue (for notification system)
-- ============================================

DROP TABLE IF EXISTS email_queue;
CREATE TABLE email_queue (
  id INT PRIMARY KEY AUTO_INCREMENT,
  sipb_id INT NOT NULL,

  /* Email details */
  recipient_email VARCHAR(100) NOT NULL,
  recipient_name VARCHAR(150),
  subject VARCHAR(255) NOT NULL,
  body_html LONGTEXT NOT NULL,

  /* Email type */
  email_type ENUM('approval_request','approval_approved','approval_rejected','reassign_notification') NOT NULL,

  /* Status */
  status ENUM('Pending','Sent','Failed') DEFAULT 'Pending',
  attempt_count INT DEFAULT 0,
  last_error TEXT,

  /* Metadata */
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME,

  FOREIGN KEY (sipb_id) REFERENCES sipb_documents(id) ON DELETE CASCADE,
  INDEX idx_status (status),
  INDEX idx_email_type (email_type),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. NEW TABLE: approval_delegation (for takeover/reassign)
-- ============================================

DROP TABLE IF EXISTS approval_delegation;
CREATE TABLE approval_delegation (
  id INT PRIMARY KEY AUTO_INCREMENT,

  /* Original & Delegated User */
  original_user_id INT NOT NULL COMMENT 'User yang seharusnya approve',
  delegated_to_user_id INT NOT NULL COMMENT 'User yang mengambil alih',

  /* Delegation Scope */
  approval_level ENUM('SPV','PM') NOT NULL,
  delegation_type ENUM('reassign','takeover') NOT NULL COMMENT 'reassign=permanent, takeover=temporary',

  /* Time Range */
  valid_from DATETIME NOT NULL,
  valid_until DATETIME COMMENT 'NULL = permanent reassign',

  /* Status & Notes */
  reason TEXT,
  is_active BOOLEAN DEFAULT 1,

  /* Metadata */
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (original_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (delegated_to_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id),

  INDEX idx_original_user (original_user_id),
  INDEX idx_delegated_to (delegated_to_user_id),
  INDEX idx_approval_level (approval_level),
  INDEX idx_valid_period (valid_from, valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. NEW TABLE: document_numbering (for auto-increment per section/month)
-- ============================================

DROP TABLE IF EXISTS document_numbering;
CREATE TABLE document_numbering (
  id INT PRIMARY KEY AUTO_INCREMENT,

  /* Period & Section */
  year INT NOT NULL,
  month INT NOT NULL,
  section VARCHAR(20) NOT NULL COMMENT 'R&D|QAQC|HRGA|WH',

  /* Counter */
  next_sequence INT DEFAULT 1,

  /* Metadata */
  last_generated_at DATETIME,

  UNIQUE KEY uk_year_month_section (year, month, section),
  INDEX idx_section (section)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. NEW TABLE: email_configuration (SMTP settings)
-- ============================================

DROP TABLE IF EXISTS email_configuration;
CREATE TABLE email_configuration (
  id INT PRIMARY KEY AUTO_INCREMENT,

  /* SMTP Configuration */
  smtp_host VARCHAR(100) NOT NULL DEFAULT 'smtp.gmail.com',
  smtp_port INT NOT NULL DEFAULT 587,
  smtp_secure VARCHAR(20) NOT NULL DEFAULT 'tls' COMMENT 'tls|ssl',
  smtp_username VARCHAR(100) NOT NULL,
  smtp_password VARCHAR(255) NOT NULL,

  /* From Address */
  from_email VARCHAR(100) NOT NULL,
  from_name VARCHAR(150) NOT NULL,

  /* Settings */
  reply_to_email VARCHAR(100),
  max_retry INT DEFAULT 3,
  is_enabled BOOLEAN DEFAULT 1,

  /* Metadata */
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by INT,

  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default SMTP config (to be filled by admin)
INSERT INTO email_configuration (smtp_username, from_email, from_name)
VALUES ('noreply@salimagro.com', 'noreply@salimagro.com', 'SIPB-GPI System');

-- ============================================
-- 6. UPDATE: users (add section enum with new values)
-- ============================================

-- Note: section ENUM already exists, just documenting values:
-- Allowed sections: R&D, QAQC, HRGA (GA/HRD), WH (Warehouse), Admin, Production, Maintenance, QSHE

-- ============================================
-- 7. VIEW: Get pending approvals (with delegation handling)
-- ============================================

DROP VIEW IF EXISTS v_pending_approvals_with_delegation;
CREATE VIEW v_pending_approvals_with_delegation AS
SELECT
  d.id,
  d.doc_number,
  d.doc_date,
  d.category,
  d.customer_name,
  d.status,
  d.created_by,
  u_creator.name as created_by_name,
  u_creator.section as created_by_section,

  /* Original Approver Info */
  d.approval_spv_by as original_spv_id,
  u_spv.name as original_spv_name,

  d.approval_pm_by as original_pm_id,
  u_pm.name as original_pm_name,

  /* Delegated/Takeover Approver (if any) */
  COALESCE(del_spv.delegated_to_user_id, d.approval_spv_by) as current_spv_approver_id,
  COALESCE(u_del_spv.name, u_spv.name) as current_spv_approver_name,

  COALESCE(del_pm.delegated_to_user_id, d.approval_pm_by) as current_pm_approver_id,
  COALESCE(u_del_pm.name, u_pm.name) as current_pm_approver_name,

  /* Status */
  d.approval_spv_status,
  d.approval_pm_status,

  (SELECT COUNT(*) FROM sipb_items WHERE sipb_id = d.id) as total_items,
  d.created_at

FROM sipb_documents d
JOIN users u_creator ON d.created_by = u_creator.id
LEFT JOIN users u_spv ON d.approval_spv_by = u_spv.id
LEFT JOIN users u_pm ON d.approval_pm_by = u_pm.id

/* Handle SPV reassignment/delegation */
LEFT JOIN approval_delegation del_spv ON
  d.approval_spv_by = del_spv.original_user_id
  AND del_spv.approval_level = 'SPV'
  AND del_spv.is_active = 1
  AND NOW() BETWEEN del_spv.valid_from AND COALESCE(del_spv.valid_until, NOW())

LEFT JOIN users u_del_spv ON del_spv.delegated_to_user_id = u_del_spv.id

/* Handle PM reassignment/delegation */
LEFT JOIN approval_delegation del_pm ON
  d.approval_pm_by = del_pm.original_user_id
  AND del_pm.approval_level = 'PM'
  AND del_pm.is_active = 1
  AND NOW() BETWEEN del_pm.valid_from AND COALESCE(del_pm.valid_until, NOW())

LEFT JOIN users u_del_pm ON del_pm.delegated_to_user_id = u_del_pm.id

WHERE d.status IN ('Submitted', 'Waiting Plant Manager')
ORDER BY d.doc_date DESC;

-- ============================================
-- SEED DATA: Section mapping for doc numbering
-- ============================================

INSERT INTO document_numbering (year, month, section, next_sequence, last_generated_at)
VALUES
  (YEAR(CURDATE()), MONTH(CURDATE()), 'R&D', 1, NULL),
  (YEAR(CURDATE()), MONTH(CURDATE()), 'QAQC', 1, NULL),
  (YEAR(CURDATE()), MONTH(CURDATE()), 'HRGA', 1, NULL),
  (YEAR(CURDATE()), MONTH(CURDATE()), 'WH', 1, NULL);

-- ============================================
-- END OF UPDATES
-- ============================================
-- Next:
-- 1. Implement email notification system (Mailer.php)
-- 2. Implement approval reassign/takeover logic (Approval.php)
-- 3. Implement document number generation (DocumentNumber.php)
-- ============================================
