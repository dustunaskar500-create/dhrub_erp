-- =====================================================================
-- Aether ERP — Stock Tracking + GST Invoicing migration
-- Date: 2026-02
-- Idempotent: safe to run multiple times
-- =====================================================================

-- ---- 1. Extend inventory_items with stock + GST fields ---------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'inventory_items' AND COLUMN_NAME = 'sku') = 0,
  'ALTER TABLE inventory_items
     ADD COLUMN sku VARCHAR(64) NULL AFTER id,
     ADD COLUMN hsn_code VARCHAR(16) NULL AFTER sku,
     ADD COLUMN gst_rate DECIMAL(5,2) NULL DEFAULT 0 AFTER hsn_code,
     ADD COLUMN cost_price DECIMAL(15,2) NULL DEFAULT 0,
     ADD COLUMN sale_price DECIMAL(15,2) NULL DEFAULT 0,
     ADD COLUMN barcode VARCHAR(64) NULL,
     ADD COLUMN image_path VARCHAR(255) NULL,
     ADD COLUMN reorder_qty INT NULL DEFAULT 0,
     ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1,
     ADD UNIQUE KEY uk_inv_sku (sku)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---- 2. Vendors / Suppliers -----------------------------------------
CREATE TABLE IF NOT EXISTS erp_vendors (
  id INT(11) NOT NULL AUTO_INCREMENT,
  vendor_code VARCHAR(32) NOT NULL,
  name VARCHAR(255) NOT NULL,
  gstin VARCHAR(20) NULL,
  pan VARCHAR(20) NULL,
  contact_person VARCHAR(120) NULL,
  email VARCHAR(120) NULL,
  phone VARCHAR(32) NULL,
  address TEXT NULL,
  city VARCHAR(80) NULL,
  state VARCHAR(80) NULL,
  state_code VARCHAR(4) NULL,
  pincode VARCHAR(10) NULL,
  bank_account VARCHAR(40) NULL,
  bank_ifsc VARCHAR(20) NULL,
  payment_terms VARCHAR(80) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  created_by INT(11) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_vendor_code (vendor_code),
  KEY idx_vendor_gstin (gstin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- 3. Purchase Orders ---------------------------------------------
CREATE TABLE IF NOT EXISTS erp_purchase_orders (
  id INT(11) NOT NULL AUTO_INCREMENT,
  po_number VARCHAR(40) NOT NULL,
  vendor_id INT(11) NOT NULL,
  po_date DATE NOT NULL,
  expected_date DATE NULL,
  status ENUM('draft','sent','partial','received','closed','cancelled') NOT NULL DEFAULT 'draft',
  subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  grand_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  place_of_supply VARCHAR(80) NULL,
  notes TEXT NULL,
  created_by INT(11) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_po_number (po_number),
  KEY idx_po_vendor (vendor_id),
  KEY idx_po_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_po_items (
  id INT(11) NOT NULL AUTO_INCREMENT,
  po_id INT(11) NOT NULL,
  item_id INT(11) NULL,
  description VARCHAR(255) NOT NULL,
  hsn_code VARCHAR(16) NULL,
  qty DECIMAL(15,3) NOT NULL DEFAULT 0,
  unit VARCHAR(20) NULL,
  unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
  gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  taxable_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  qty_received DECIMAL(15,3) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_poi_po (po_id),
  KEY idx_poi_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- 4. Goods Receipt Notes (GRN) -----------------------------------
CREATE TABLE IF NOT EXISTS erp_grns (
  id INT(11) NOT NULL AUTO_INCREMENT,
  grn_number VARCHAR(40) NOT NULL,
  po_id INT(11) NULL,
  vendor_id INT(11) NOT NULL,
  received_date DATE NOT NULL,
  received_by INT(11) NULL,
  supplier_invoice_no VARCHAR(80) NULL,
  supplier_invoice_date DATE NULL,
  vehicle_number VARCHAR(40) NULL,
  driver_name VARCHAR(120) NULL,
  gate_pass_no VARCHAR(40) NULL,
  transporter VARCHAR(120) NULL,
  status ENUM('draft','posted','disputed','cancelled') NOT NULL DEFAULT 'draft',
  has_discrepancy TINYINT(1) NOT NULL DEFAULT 0,
  total_qty_received DECIMAL(15,3) NOT NULL DEFAULT 0,
  total_qty_damaged DECIMAL(15,3) NOT NULL DEFAULT 0,
  total_qty_short DECIMAL(15,3) NOT NULL DEFAULT 0,
  total_qty_excess DECIMAL(15,3) NOT NULL DEFAULT 0,
  value_received DECIMAL(15,2) NOT NULL DEFAULT 0,
  value_loss DECIMAL(15,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  posted_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_grn_number (grn_number),
  KEY idx_grn_po (po_id),
  KEY idx_grn_vendor (vendor_id),
  KEY idx_grn_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_grn_items (
  id INT(11) NOT NULL AUTO_INCREMENT,
  grn_id INT(11) NOT NULL,
  po_item_id INT(11) NULL,
  item_id INT(11) NULL,
  description VARCHAR(255) NOT NULL,
  hsn_code VARCHAR(16) NULL,
  unit VARCHAR(20) NULL,
  qty_ordered DECIMAL(15,3) NOT NULL DEFAULT 0,
  qty_received DECIMAL(15,3) NOT NULL DEFAULT 0,
  qty_accepted DECIMAL(15,3) NOT NULL DEFAULT 0,
  qty_damaged DECIMAL(15,3) NOT NULL DEFAULT 0,
  qty_short DECIMAL(15,3) NOT NULL DEFAULT 0,
  qty_excess DECIMAL(15,3) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
  batch_no VARCHAR(60) NULL,
  expiry_date DATE NULL,
  condition_note TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_grni_grn (grn_id),
  KEY idx_grni_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_grn_attachments (
  id INT(11) NOT NULL AUTO_INCREMENT,
  grn_id INT(11) NOT NULL,
  kind ENUM('image','video','document','other') NOT NULL DEFAULT 'document',
  file_path VARCHAR(500) NOT NULL,
  original_name VARCHAR(255) NULL,
  mime_type VARCHAR(120) NULL,
  size_bytes BIGINT NULL,
  caption VARCHAR(255) NULL,
  uploaded_by INT(11) NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_grna_grn (grn_id),
  KEY idx_grna_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- 5. Stock Adjustments (damage / shortage / excess / loss) -------
CREATE TABLE IF NOT EXISTS erp_stock_adjustments (
  id INT(11) NOT NULL AUTO_INCREMENT,
  adj_number VARCHAR(40) NOT NULL,
  item_id INT(11) NOT NULL,
  adj_type ENUM('damage','shortage','excess','wastage','loss','found','theft','return_in','return_out','correction') NOT NULL,
  qty DECIMAL(15,3) NOT NULL,
  unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
  value_impact DECIMAL(15,2) NOT NULL DEFAULT 0,
  direction ENUM('in','out') NOT NULL DEFAULT 'out',
  reason TEXT NULL,
  evidence_path VARCHAR(500) NULL,
  grn_id INT(11) NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  created_by INT(11) NULL,
  approved_by INT(11) NULL,
  approved_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adj_number (adj_number),
  KEY idx_adj_item (item_id),
  KEY idx_adj_type (adj_type),
  KEY idx_adj_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- 6. GST Tax Invoices --------------------------------------------
CREATE TABLE IF NOT EXISTS erp_tax_invoices (
  id INT(11) NOT NULL AUTO_INCREMENT,
  invoice_number VARCHAR(40) NOT NULL,
  invoice_date DATE NOT NULL,
  fy VARCHAR(10) NULL,
  invoice_type ENUM('tax_invoice','bill_of_supply','credit_note','debit_note','proforma') NOT NULL DEFAULT 'tax_invoice',
  buyer_name VARCHAR(255) NOT NULL,
  buyer_gstin VARCHAR(20) NULL,
  buyer_pan VARCHAR(20) NULL,
  buyer_email VARCHAR(120) NULL,
  buyer_phone VARCHAR(32) NULL,
  buyer_address TEXT NULL,
  buyer_state VARCHAR(80) NULL,
  buyer_state_code VARCHAR(4) NULL,
  place_of_supply VARCHAR(80) NULL,
  place_of_supply_code VARCHAR(4) NULL,
  reverse_charge TINYINT(1) NOT NULL DEFAULT 0,
  is_interstate TINYINT(1) NOT NULL DEFAULT 0,
  seller_gstin VARCHAR(20) NULL,
  seller_state VARCHAR(80) NULL,
  seller_state_code VARCHAR(4) NULL,
  subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  taxable_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_cgst DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_sgst DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_igst DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_cess DECIMAL(15,2) NOT NULL DEFAULT 0,
  round_off DECIMAL(15,2) NOT NULL DEFAULT 0,
  grand_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  amount_in_words VARCHAR(500) NULL,
  status ENUM('draft','issued','paid','partial','cancelled','overdue') NOT NULL DEFAULT 'draft',
  payment_status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  due_date DATE NULL,
  notes TEXT NULL,
  terms TEXT NULL,
  generated_pdf_path VARCHAR(500) NULL,
  irn VARCHAR(80) NULL,
  ack_no VARCHAR(80) NULL,
  ack_date DATETIME NULL,
  qr_payload TEXT NULL,
  reference_invoice_id INT(11) NULL,
  created_by INT(11) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_tax_invoice_number (invoice_number),
  KEY idx_inv_date (invoice_date),
  KEY idx_inv_status (status),
  KEY idx_inv_gstin (buyer_gstin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erp_tax_invoice_items (
  id INT(11) NOT NULL AUTO_INCREMENT,
  invoice_id INT(11) NOT NULL,
  item_id INT(11) NULL,
  description VARCHAR(500) NOT NULL,
  hsn_code VARCHAR(16) NULL,
  qty DECIMAL(15,3) NOT NULL DEFAULT 1,
  unit VARCHAR(20) NULL,
  unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
  discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  discount_amt DECIMAL(15,2) NOT NULL DEFAULT 0,
  taxable_value DECIMAL(15,2) NOT NULL DEFAULT 0,
  gst_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  cgst DECIMAL(15,2) NOT NULL DEFAULT 0,
  sgst DECIMAL(15,2) NOT NULL DEFAULT 0,
  igst DECIMAL(15,2) NOT NULL DEFAULT 0,
  cess DECIMAL(15,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_inv_items_invoice (invoice_id),
  KEY idx_inv_items_item (item_id),
  KEY idx_inv_items_hsn (hsn_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- 7. Invoice payments --------------------------------------------
CREATE TABLE IF NOT EXISTS erp_invoice_payments (
  id INT(11) NOT NULL AUTO_INCREMENT,
  invoice_id INT(11) NOT NULL,
  payment_date DATE NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  method ENUM('cash','bank_transfer','upi','cheque','card','other') NOT NULL DEFAULT 'bank_transfer',
  reference_no VARCHAR(120) NULL,
  notes TEXT NULL,
  created_by INT(11) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pay_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- 8. Indian state codes (seed) ----------------------------------
CREATE TABLE IF NOT EXISTS erp_state_codes (
  code VARCHAR(4) NOT NULL,
  name VARCHAR(80) NOT NULL,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO erp_state_codes (code, name) VALUES
('01','Jammu and Kashmir'),('02','Himachal Pradesh'),('03','Punjab'),('04','Chandigarh'),
('05','Uttarakhand'),('06','Haryana'),('07','Delhi'),('08','Rajasthan'),('09','Uttar Pradesh'),
('10','Bihar'),('11','Sikkim'),('12','Arunachal Pradesh'),('13','Nagaland'),('14','Manipur'),
('15','Mizoram'),('16','Tripura'),('17','Meghalaya'),('18','Assam'),('19','West Bengal'),
('20','Jharkhand'),('21','Odisha'),('22','Chhattisgarh'),('23','Madhya Pradesh'),('24','Gujarat'),
('25','Daman and Diu'),('26','Dadra and Nagar Haveli'),('27','Maharashtra'),('28','Andhra Pradesh'),
('29','Karnataka'),('30','Goa'),('31','Lakshadweep'),('32','Kerala'),('33','Tamil Nadu'),
('34','Puducherry'),('35','Andaman and Nicobar Islands'),('36','Telangana'),('37','Andhra Pradesh (New)'),
('38','Ladakh'),('97','Other Territory'),('99','Other Country');

-- ---- 9. Settings additions ------------------------------------------
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('org_state','West Bengal'),
('org_state_code','19'),
('invoice_prefix','INV'),
('invoice_terms','Goods once sold will not be taken back. Subject to local jurisdiction.'),
('grn_prefix','GRN'),
('po_prefix','PO'),
('adj_prefix','ADJ');

-- ---- 10. Document counters ------------------------------------------
CREATE TABLE IF NOT EXISTS erp_doc_counters (
  doc_type VARCHAR(20) NOT NULL,
  fy VARCHAR(10) NOT NULL,
  last_seq INT NOT NULL DEFAULT 0,
  PRIMARY KEY (doc_type, fy)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
