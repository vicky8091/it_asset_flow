-- IT Asset Flow — schema upgrade 2026-10-11
-- Run ONCE on an existing installation (fresh installs get all of this from database.sql).
--   mysql -u USER -p DBNAME < upgrade_2026-10-11.sql
-- Adds: managed categories & locations tables, asset financial/warranty fields + photo,
--       due-soon reminder tracking, new settings.

-- Managed categories (icons stay file-based in uploads/icons/, keyed by the category slug)
CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  color VARCHAR(20) NOT NULL DEFAULT '#64748b',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed from categories already in use
INSERT INTO categories (name)
SELECT name FROM (
  SELECT DISTINCT category AS name FROM inventory WHERE category <> ''
) x
WHERE name NOT IN (SELECT name FROM categories);

-- Managed locations (event/office locations)
CREATE TABLE IF NOT EXISTS locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  notes VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO locations (name)
SELECT name FROM (
  SELECT DISTINCT location AS name FROM assigns WHERE location <> ''
) x
WHERE name NOT IN (SELECT name FROM locations);

-- Asset financials, warranty and photo
ALTER TABLE inventory
  ADD COLUMN purchase_date DATE NULL,
  ADD COLUMN cost DECIMAL(12,2) NULL,
  ADD COLUMN vendor VARCHAR(120) NOT NULL DEFAULT '',
  ADD COLUMN invoice_no VARCHAR(80) NOT NULL DEFAULT '',
  ADD COLUMN warranty_expiry DATE NULL,
  ADD COLUMN photo VARCHAR(255) NOT NULL DEFAULT '',
  ADD INDEX idx_inv_warranty (warranty_expiry);

-- One-time "due soon" reminder per assignment (sent by the daily cron)
ALTER TABLE assigns
  ADD COLUMN due_soon_sent_at DATETIME NULL;

-- New settings
INSERT INTO settings (skey, svalue) VALUES
('currency_symbol', '₹'),
('due_soon_days', '3'),
('due_soon_auto', '0'),
('warranty_alert_days', '30')
ON DUPLICATE KEY UPDATE svalue = VALUES(svalue);
