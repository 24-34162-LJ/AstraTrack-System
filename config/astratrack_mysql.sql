-- =====================================================================
-- AstraTrack: Customs Brokerage Shipment & Transaction Monitoring System
-- MySQL schema (MySQL 5.7+ / 8.x, also works on MariaDB 10.3+)
-- Run:  mysql -u root -p < astratrack_mysql.sql
-- =====================================================================

DROP DATABASE IF EXISTS astratrack;
CREATE DATABASE astratrack
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE astratrack;

-- ---------------------------------------------------------------------
-- 1. clients
-- ---------------------------------------------------------------------
CREATE TABLE clients (
    client_id       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_name     VARCHAR(150) NOT NULL,
    contact_person  VARCHAR(100),
    email           VARCHAR(120),
    phone           VARCHAR(30),
    address         VARCHAR(255),
    tin             VARCHAR(30),
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. users (authentication and access control)
-- ---------------------------------------------------------------------
CREATE TABLE users (
    user_id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(100) NOT NULL,
    email           VARCHAR(120) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('client','staff','admin') NOT NULL,
    client_id       INT UNSIGNED NULL,                      -- client users only
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_client FOREIGN KEY (client_id)
        REFERENCES clients(client_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. shipments
-- ---------------------------------------------------------------------
CREATE TABLE shipments (
    shipment_id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reference_no        VARCHAR(30) NOT NULL UNIQUE,        -- e.g. AST-2026-0001
    client_id           INT UNSIGNED NOT NULL,
    assigned_staff_id   INT UNSIGNED NULL,
    transaction_type    ENUM('import','export') NOT NULL,
    mode                ENUM('sea','air','land') NOT NULL,
    bl_awb_no           VARCHAR(50),
    shipper_name        VARCHAR(150),
    consignee_name      VARCHAR(150),
    vessel_or_flight    VARCHAR(100),
    port_of_loading     VARCHAR(100),
    port_of_discharge   VARCHAR(100),
    hs_code             VARCHAR(20),
    total_packages      INT,                                -- total units
    total_gross_weight  DECIMAL(12,3),                      -- kg
    measurement_cbm     DECIMAL(12,3),
    incoterm            VARCHAR(10),
    invoice_no          VARCHAR(50),
    currency            CHAR(3) DEFAULT 'USD',
    invoice_value       DECIMAL(15,2),                      -- invoice total
    freight_amount      DECIMAL(15,2),                      -- ocean freight
    fob_value           DECIMAL(15,2),
    current_status      VARCHAR(40) NOT NULL DEFAULT 'Documents received',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ship_client FOREIGN KEY (client_id)
        REFERENCES clients(client_id) ON UPDATE CASCADE,
    CONSTRAINT fk_ship_staff FOREIGN KEY (assigned_staff_id)
        REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_ship_client (client_id),
    INDEX idx_ship_bl     (bl_awb_no),
    INDEX idx_ship_status (current_status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. containers
-- ---------------------------------------------------------------------
CREATE TABLE containers (
    container_id    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shipment_id     INT UNSIGNED NOT NULL,
    container_no    VARCHAR(20) NOT NULL,
    seal_no         VARCHAR(30),
    size_type       VARCHAR(20),                            -- e.g. 40HQ
    CONSTRAINT fk_cont_ship FOREIGN KEY (shipment_id)
        REFERENCES shipments(shipment_id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_cont_no (container_no)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. customs_processing (manually entered by staff, no BOC integration)
-- updated_at refreshes automatically on UPDATE (replaces the PG trigger)
-- ---------------------------------------------------------------------
CREATE TABLE customs_processing (
    customs_id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shipment_id         INT UNSIGNED NOT NULL,
    entry_no            VARCHAR(50),
    declaration_type    VARCHAR(40),
    lodgement_date      DATE,
    customs_value       DECIMAL(15,2),
    duties_taxes_amount DECIMAL(15,2),
    duties_paid_date    DATE,
    assessment_status   ENUM('pending','assessed','paid') NOT NULL DEFAULT 'pending',
    examination_lane    ENUM('green','yellow','red') NULL,
    clearance_date      DATE,
    customs_remarks     TEXT,
    updated_by          INT UNSIGNED NULL,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_customs_ship FOREIGN KEY (shipment_id)
        REFERENCES shipments(shipment_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_customs_user FOREIGN KEY (updated_by)
        REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_customs_ship (shipment_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. shipment_status_updates
-- stage values: Documents received, Declaration filed, Assessment,
--               Duties paid, Examination, Released/cleared, Trucking, Delivered
-- ---------------------------------------------------------------------
CREATE TABLE shipment_status_updates (
    update_id       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shipment_id     INT UNSIGNED NOT NULL,
    stage           VARCHAR(40) NOT NULL,
    status          VARCHAR(40) NOT NULL,
    remarks         VARCHAR(255),
    updated_by      INT UNSIGNED NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_status_ship FOREIGN KEY (shipment_id)
        REFERENCES shipments(shipment_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_status_user FOREIGN KEY (updated_by)
        REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_status_ship (shipment_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. deliveries (trucking and delivery stage)
-- ---------------------------------------------------------------------
CREATE TABLE deliveries (
    delivery_id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shipment_id         INT UNSIGNED NOT NULL,
    trucker             VARCHAR(100),
    plate_no            VARCHAR(20),
    driver_name         VARCHAR(100),
    delivery_address    VARCHAR(255),
    scheduled_date      DATE,
    delivered_date      DATE,
    status              ENUM('scheduled','in_transit','delivered','cancelled')
                        NOT NULL DEFAULT 'scheduled',
    CONSTRAINT fk_deliv_ship FOREIGN KEY (shipment_id)
        REFERENCES shipments(shipment_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. shipment_documents
-- doc_type values: B/L or AWB, Commercial invoice, Packing list,
--   Insurance certificate, Import entry, Assessment notice, Permit,
--   Duties receipt, Other
-- ---------------------------------------------------------------------
CREATE TABLE shipment_documents (
    document_id     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shipment_id     INT UNSIGNED NOT NULL,
    doc_type        VARCHAR(40) NOT NULL,
    file_path       VARCHAR(255) NOT NULL,
    uploaded_by     INT UNSIGNED NULL,
    uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_doc_ship FOREIGN KEY (shipment_id)
        REFERENCES shipments(shipment_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_doc_user FOREIGN KEY (uploaded_by)
        REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 9. billings
-- ---------------------------------------------------------------------
CREATE TABLE billings (
    billing_id      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    billing_no      VARCHAR(30) NOT NULL UNIQUE,
    client_id       INT UNSIGNED NOT NULL,
    shipment_id     INT UNSIGNED NOT NULL,
    billing_date    DATE NOT NULL,
    due_date        DATE,
    total_amount    DECIMAL(15,2) NOT NULL DEFAULT 0.00,    -- PHP
    payment_status  ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_bill_client FOREIGN KEY (client_id)
        REFERENCES clients(client_id) ON UPDATE CASCADE,
    CONSTRAINT fk_bill_ship FOREIGN KEY (shipment_id)
        REFERENCES shipments(shipment_id) ON UPDATE CASCADE,
    CONSTRAINT fk_bill_user FOREIGN KEY (created_by)
        REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_bill_status (payment_status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 10. billing_items
-- charge_type values: Service fee, Customs-related charge, Other expense
-- ---------------------------------------------------------------------
CREATE TABLE billing_items (
    item_id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    billing_id      INT UNSIGNED NOT NULL,
    charge_type     VARCHAR(40) NOT NULL,
    description     VARCHAR(255),
    amount          DECIMAL(15,2) NOT NULL,
    CONSTRAINT fk_item_bill FOREIGN KEY (billing_id)
        REFERENCES billings(billing_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 11. payments (processed outside the system, verified by staff)
-- ---------------------------------------------------------------------
CREATE TABLE payments (
    payment_id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    billing_id          INT UNSIGNED NOT NULL,
    amount              DECIMAL(15,2) NOT NULL,
    payment_date        DATE NOT NULL,
    payment_method      VARCHAR(30),                        -- cash, bank transfer, check, e-wallet
    reference_no        VARCHAR(60),
    proof_file_path     VARCHAR(255),
    verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    verified_by         INT UNSIGNED NULL,
    recorded_by         INT UNSIGNED NULL,
    CONSTRAINT fk_pay_bill FOREIGN KEY (billing_id)
        REFERENCES billings(billing_id) ON UPDATE CASCADE,
    CONSTRAINT fk_pay_verifier FOREIGN KEY (verified_by)
        REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_pay_recorder FOREIGN KEY (recorded_by)
        REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_pay_bill (billing_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 12. transactions
-- ---------------------------------------------------------------------
CREATE TABLE transactions (
    transaction_id  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NOT NULL,
    shipment_id     INT UNSIGNED NOT NULL,
    billing_id      INT UNSIGNED NULL,
    status          ENUM('pending','ongoing','completed') NOT NULL DEFAULT 'pending',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME NULL,
    CONSTRAINT fk_trx_client FOREIGN KEY (client_id)
        REFERENCES clients(client_id) ON UPDATE CASCADE,
    CONSTRAINT fk_trx_ship FOREIGN KEY (shipment_id)
        REFERENCES shipments(shipment_id) ON UPDATE CASCADE,
    CONSTRAINT fk_trx_bill FOREIGN KEY (billing_id)
        REFERENCES billings(billing_id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_trx_status (status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 13. transaction_history
-- ---------------------------------------------------------------------
CREATE TABLE transaction_history (
    history_id      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    transaction_id  INT UNSIGNED NOT NULL,
    event_type      VARCHAR(40) NOT NULL,    -- shipment update, billing created, payment recorded...
    description     VARCHAR(255),
    performed_by    INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_hist_trx FOREIGN KEY (transaction_id)
        REFERENCES transactions(transaction_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_hist_user FOREIGN KEY (performed_by)
        REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- 14. activity_log
-- ---------------------------------------------------------------------
-- Run once in phpMyAdmin (SQL tab) with the `astratrack` database selected.
-- Stores every important change so the dashboard's Recent Activity can show it.
CREATE TABLE IF NOT EXISTS activity_log (
    activity_id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    activity_type      VARCHAR(40)  NOT NULL,           -- 'User Account', 'Shipment Update', 'Billing Change', ...
    description        VARCHAR(255) NOT NULL,
    performed_by       INT UNSIGNED NULL,
    performed_by_name  VARCHAR(100) NULL,               -- saved as text so it still shows after a user is deleted
    performed_by_role  VARCHAR(20)  NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_user FOREIGN KEY (performed_by)
        REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_activity_created (created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- View: outstanding balance per billing (computed from verified payments)
-- ---------------------------------------------------------------------
CREATE VIEW v_billing_balance AS
SELECT  b.billing_id,
        b.billing_no,
        b.client_id,
        b.shipment_id,
        b.total_amount,
        COALESCE(SUM(CASE WHEN p.verification_status = 'verified' THEN p.amount END), 0) AS amount_paid,
        b.total_amount
          - COALESCE(SUM(CASE WHEN p.verification_status = 'verified' THEN p.amount END), 0) AS balance,
        b.payment_status
FROM billings b
LEFT JOIN payments p ON p.billing_id = b.billing_id
GROUP BY b.billing_id, b.billing_no, b.client_id, b.shipment_id, b.total_amount, b.payment_status;

-- =====================================================================
-- SAMPLE DATA (from the Kubota sea shipment documents)
-- NOTE: password_hash values are placeholders. Generate real hashes in
-- your app (e.g. PHP password_hash) before using real accounts.
-- =====================================================================
INSERT INTO clients (client_name, address)
VALUES ('Kubota Philippines Inc.',
        '232 Quirino Highway, Baesa, Quezon City 1106, Metro Manila');

INSERT INTO users (full_name, email, password_hash, role, client_id) VALUES
('System Administrator', 'admin@astratrack.local',  'REPLACE_WITH_HASH', 'admin',  NULL),
('Sample Staff',         'staff@astratrack.local',  'REPLACE_WITH_HASH', 'staff',  NULL),
('Sample Client User',   'client@astratrack.local', 'REPLACE_WITH_HASH', 'client', 1);

INSERT INTO shipments (
    reference_no, client_id, assigned_staff_id, transaction_type, mode,
    bl_awb_no, shipper_name, consignee_name, vessel_or_flight,
    port_of_loading, port_of_discharge, hs_code,
    total_packages, total_gross_weight, measurement_cbm, incoterm,
    invoice_no, currency, invoice_value, freight_amount,
    fob_value, current_status
) VALUES (
    'AST-2026-0001', 1, 2, 'import', 'sea',
    'ONEYBKKGW8554308', 'Siam Kubota Corporation Co., Ltd.',
    'Kubota Philippines Inc.', 'ACX PEARL 0292N',
    'Laem Chabang, Thailand', 'Manila North Harbor', '8433.51.00',
    12, 36360.000, 286.320, 'CIP',
    'S037100369', 'USD', 201622.20, 2320.00,
    199244.54, 'In transit'
);

INSERT INTO containers (shipment_id, container_no, seal_no, size_type) VALUES
(1, 'KKFU8014457', 'THBT43147', '40HQ'),
(1, 'ONEU4122390', 'THBT37514', '40HQ'),
(1, 'TCNU5925729', 'THBT45715', '40HQ'),
(1, 'TCNU6861779', 'THBT43027', '40HQ');

INSERT INTO shipment_status_updates (shipment_id, stage, status, remarks, updated_by)
VALUES (1, 'Documents received', 'Completed',
        'B/L, invoice, packing list, insurance certificate received', 2);

INSERT INTO shipment_documents (shipment_id, doc_type, file_path, uploaded_by) VALUES
(1, 'B/L or AWB', 'uploads/AST-2026-0001/ONEYBKKGW8554308_MLA.pdf', 2);

INSERT INTO transactions (client_id, shipment_id, status) VALUES (1, 1, 'ongoing');