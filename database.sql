-- IT Asset Flow — database schema
-- Import via cPanel phpMyAdmin (or: mysql -u USER -p DBNAME < database.sql)

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(100) NOT NULL DEFAULT '',
  email VARCHAR(150) NOT NULL DEFAULT '',
  department VARCHAR(100) NOT NULL DEFAULT '',
  employee_id VARCHAR(50) NOT NULL DEFAULT '',
  must_change_pwd TINYINT(1) NOT NULL DEFAULT 0,
  role ENUM('admin','user') NOT NULL DEFAULT 'admin',
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  profile_pic VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE inventory (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_number VARCHAR(50) NOT NULL UNIQUE,
  asset_name VARCHAR(150) NOT NULL,
  category VARCHAR(100) NOT NULL DEFAULT '',
  brand VARCHAR(100) NOT NULL DEFAULT '',
  model VARCHAR(100) NOT NULL DEFAULT '',
  serial_number VARCHAR(100) NOT NULL DEFAULT '',
  status ENUM('Available','Issued','Damaged','Missing','Under Repair','Retired') NOT NULL DEFAULT 'Available',
  condition_note VARCHAR(255) NOT NULL DEFAULT '',
  remarks VARCHAR(255) NOT NULL DEFAULT '',
  purchase_date DATE NULL,
  cost DECIMAL(12,2) NULL,
  vendor VARCHAR(120) NOT NULL DEFAULT '',
  invoice_no VARCHAR(80) NOT NULL DEFAULT '',
  warranty_expiry DATE NULL,
  photo VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_inv_status (status),
  INDEX idx_inv_serial (serial_number),
  INDEX idx_inv_name (asset_name),
  INDEX idx_inv_warranty (warranty_expiry)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Managed categories — assets reference the name in inventory.category.
-- Per-category icons stay file-based in uploads/icons/<slug>.<ext>.
CREATE TABLE categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  color VARCHAR(20) NOT NULL DEFAULT '#64748b',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Managed locations — assigns.location stores the name (event/office venues).
CREATE TABLE locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  notes VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  employee_id VARCHAR(50) NOT NULL DEFAULT '',
  department VARCHAR(100) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '',
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE assigns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  assign_no VARCHAR(20) NOT NULL UNIQUE,
  user_name VARCHAR(100) NOT NULL,
  user_email VARCHAR(150) NOT NULL,
  employee_id VARCHAR(50) NOT NULL DEFAULT '',
  project_code VARCHAR(50) NOT NULL,
  event_name VARCHAR(150) NOT NULL DEFAULT '',
  location VARCHAR(100) NOT NULL DEFAULT '',
  purpose ENUM('Office','Event') NOT NULL DEFAULT 'Event',
  collected_by VARCHAR(100) NOT NULL DEFAULT '',
  assign_date DATE NOT NULL,
  expected_return DATE NOT NULL,
  status ENUM('Issued','Partial','Returned') NOT NULL DEFAULT 'Issued',
  assign_remarks VARCHAR(500) NOT NULL DEFAULT '',
  ack_token CHAR(32) NULL,
  ack_expires DATETIME NULL,
  ack_status ENUM('Not Sent','Sent','Acknowledged') NOT NULL DEFAULT 'Not Sent',
  ack_at DATETIME NULL,
  ack_ip VARCHAR(45) NULL,
  reminders_sent INT NOT NULL DEFAULT 0,
  last_reminder_at DATETIME NULL,
  due_soon_sent_at DATETIME NULL,
  created_by VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_asn_email (user_email),
  INDEX idx_asn_project (project_code),
  INDEX idx_asn_status (status),
  INDEX idx_asn_return (expected_return),
  INDEX idx_asn_ack (ack_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE assign_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  assign_id INT NOT NULL,
  inventory_id INT NOT NULL,
  item_name VARCHAR(150) NOT NULL,
  asset_number VARCHAR(50) NOT NULL,
  serial_number VARCHAR(100) NOT NULL DEFAULT '',
  assign_condition VARCHAR(255) NOT NULL DEFAULT 'Good',
  return_status ENUM('Pending','Returned','Damaged','Missing') NOT NULL DEFAULT 'Pending',
  return_date DATE NULL,
  return_condition VARCHAR(255) NOT NULL DEFAULT '',
  return_remarks VARCHAR(500) NOT NULL DEFAULT '',
  FOREIGN KEY (assign_id) REFERENCES assigns(id) ON DELETE CASCADE,
  FOREIGN KEY (inventory_id) REFERENCES inventory(id),
  INDEX idx_it_inv (inventory_id),
  INDEX idx_it_return (return_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
  skey VARCHAR(50) PRIMARY KEY,
  svalue TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin VARCHAR(100) NOT NULL,
  action VARCHAR(50) NOT NULL,
  details VARCHAR(500) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_log_action (action),
  INDEX idx_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default admin: username "admin", password "Fervent@1234" — changing it is optional
-- (My Profile → Change Password). Admins can create further accounts (role admin or user)
-- on the User Management page.
INSERT INTO admins (username, password_hash, name, must_change_pwd, role, profile_pic) VALUES
('admin', '$2y$10$8Nh3rI/UpPzzdoHCu4dVwOhJwLfsJ/9tQFcRd1fEZArBFzQ0loIcC', 'IT Administrator', 0, 'admin', '');

INSERT INTO settings (skey, svalue) VALUES
('company_name', 'Event Management Company'),
('it_support_email', ''),
('mail_host', ''),
('mail_port', '587'),
('mail_username', ''),
('mail_password', ''),
('mail_encryption', 'tls'),
('from_name', 'IT Support'),
('from_email', ''),
('ack_token_days', '30'),
('auto_reminder', '0'),
('reminder_max', '3'),
('currency_symbol', '₹'),
('due_soon_days', '3'),
('due_soon_auto', '0'),
('warranty_alert_days', '30');

SET FOREIGN_KEY_CHECKS = 1;
