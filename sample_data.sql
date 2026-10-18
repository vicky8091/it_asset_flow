-- IT Asset Flow — optional DEMO data. Import AFTER database.sql.
-- Demo credentials stay the same: admin / Fervent@1234
-- Dates are fixed around 2026-10-06 so the demo shows one active, one overdue and one returned assignment.

INSERT INTO inventory (id, asset_number, asset_name, category, brand, model, serial_number, status,
                       purchase_date, cost, vendor, invoice_no, warranty_expiry) VALUES
(1, 'IT-LAP-001', 'Laptop', 'Dell Laptop', 'Dell', 'Latitude 5420', 'ABC123456', 'Issued', '2025-08-12', 54999.00, 'Dell India', 'INV-2025-0114', '2028-08-12'),
(2, 'IT-LAP-002', 'Laptop', 'Dell Laptop', 'Dell', 'Latitude 5420', 'ABC123457', 'Issued', '2025-08-12', 54999.00, 'Dell India', 'INV-2025-0114', '2028-08-12'),
(3, 'IT-REM-001', 'PPT Remote', 'Presentation Remote', 'Logitech', 'R400', 'SN001', 'Issued', '2024-03-02', 2499.00, 'Amazon Business', 'INV-2024-0031', NULL),
(4, 'IT-PD-001', 'Pen Drive', 'Storage', 'Sandisk', '64GB', 'SN002', 'Issued', '2024-11-18', 799.00, 'Amazon Business', 'INV-2024-0220', NULL),
(5, 'IT-CBL-012', 'HDMI Cable', 'Cable', 'Generic', '2m HDMI', '', 'Available', '2024-06-05', 349.00, 'Local Market', '', NULL),
(6, 'IT-HUB-001', 'USB Hub', 'Accessory', 'Generic', '4-port USB 3.0', '', 'Available', '2024-06-05', 1299.00, 'Local Market', '', NULL),
(7, 'IT-SSD-001', 'SSD', 'Storage', 'Samsung', 'T7 1TB', 'SNSSD778', 'Available', '2025-02-20', 8999.00, 'Samsung Store', 'INV-2025-0042', '2027-02-20'),
(8, 'IT-MOB-001', 'Mobile Phone', 'Phone', 'Samsung', 'Galaxy S23', 'SNMOB112', 'Available', '2025-10-01', 74999.00, 'Samsung Store', 'INV-2025-0301', '2026-10-01'),
(9, 'IT-RTR-001', 'Wi-Fi Router', 'Network', 'TP-Link', 'Archer C6', 'SNRTR909', 'Available', '2023-12-10', 3299.00, 'Amazon Business', 'INV-2023-0089', '2026-12-10'),
(10, 'IT-KBD-001', 'Keyboard', 'Peripheral', 'Logitech', 'K120', '', 'Available', '2024-01-25', 595.00, 'Local Market', '', NULL);

INSERT INTO categories (name, color) VALUES
('Dell Laptop', '#2563eb'), ('Presentation Remote', '#7c3aed'), ('Storage', '#0d9488'),
('Cable', '#b45309'), ('Accessory', '#475569'), ('Phone', '#db2777'),
('Network', '#0369a1'), ('Peripheral', '#16a34a');

INSERT INTO locations (name, notes) VALUES
('Mumbai', 'Bandra Kurla Complex office'), ('Delhi', 'Corporate Summit venue'), ('Pune', 'Dealer Meet hall');

INSERT INTO users (name, email, employee_id, department, phone) VALUES
('Rahul Sharma', 'rahul@example.com', 'EMP-231', 'Operations', '+91 98765 43210'),
('Priya Patel', 'priya@example.com', 'EMP-118', 'Marketing', '+91 87654 32109'),
('Arjun Mehta', 'arjun@example.com', 'EMP-077', 'Sales', '+91 76543 21098'),
('Neha Singh', 'neha@example.com', 'EMP-142', 'Design', '+91 65432 10987'),
('Vikash Singh', 'vikash@example.com', 'EMP-201', 'IT Support', '+91 54321 09876');

INSERT INTO assigns (id, assign_no, user_name, user_email, employee_id, project_code, event_name, location,
                    purpose, collected_by, assign_date, expected_return, status, ack_token, ack_expires, ack_status, ack_at, ack_ip, created_by) VALUES
(1, 'ISS-2026-00001', 'Rahul Sharma', 'rahul@example.com', 'EMP-231', 'EMC-2026-101', 'Product Launch', 'Mumbai',
    'Event', 'Rahul Sharma', '2026-10-02', '2026-10-15', 'Issued', 'demo0001acktoken0000000000000001', '2026-11-05 00:00:00', 'Sent', NULL, NULL, 'admin'),
(2, 'ISS-2026-00002', 'Priya Patel', 'priya@example.com', 'EMP-118', 'EMC-2026-102', 'Corporate Summit', 'Delhi',
    'Event', 'Priya Patel', '2026-09-20', '2026-10-01', 'Issued', 'demo0002acktoken0000000000000002', '2026-10-31 00:00:00', 'Acknowledged', '2026-09-20 11:24:00', '127.0.0.1', 'admin'),
(3, 'ISS-2026-00003', 'Arjun Mehta', 'arjun@example.com', 'EMP-077', 'EMC-2026-098', 'Dealer Meet', 'Pune',
    'Event', 'Arjun Mehta', '2026-09-01', '2026-09-10', 'Returned', 'demo0003acktoken0000000000000003', '2026-10-01 00:00:00', 'Acknowledged', '2026-09-01 09:40:00', '127.0.0.1', 'admin');

INSERT INTO assign_items (assign_id, inventory_id, item_name, asset_number, serial_number, assign_condition, return_status, return_date, return_condition, return_remarks) VALUES
(1, 1, 'Laptop', 'IT-LAP-001', 'ABC123456', 'Good', 'Pending', NULL, '', ''),
(1, 3, 'PPT Remote', 'IT-REM-001', 'SN001', 'Good', 'Pending', NULL, '', ''),
(2, 2, 'Laptop', 'IT-LAP-002', 'ABC123457', 'Good', 'Pending', NULL, '', ''),
(2, 4, 'Pen Drive', 'IT-PD-001', 'SN002', 'Good', 'Pending', NULL, '', ''),
(3, 5, 'HDMI Cable', 'IT-CBL-012', '', 'Good', 'Returned', '2026-09-10', 'Good', ''),
(3, 6, 'USB Hub', 'IT-HUB-001', '', 'Good', 'Returned', '2026-09-10', 'Good', ''),
(3, 7, 'SSD', 'IT-SSD-001', 'SNSSD778', 'Good', 'Returned', '2026-09-10', 'Good', '');

INSERT INTO activity_log (admin, action, details, ip) VALUES
('system', 'demo_imported', 'Demo data loaded (see sample_data.sql)', '127.0.0.1');
