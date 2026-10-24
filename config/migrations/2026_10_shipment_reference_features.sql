-- AstraTrack shipment lifecycle, key dates, and in-app notifications.
-- Safe to rerun on MySQL 5.7+/MariaDB 10.3+. Back up before applying to production.
-- phpMyAdmin: select astratrack, open SQL, paste/run this file.

SET NAMES utf8mb4;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shipments' AND COLUMN_NAME='eta_date')=0,
  'ALTER TABLE shipments ADD COLUMN eta_date DATE NULL AFTER actual_arrival_date', 'SELECT ''eta_date already exists''');
PREPARE ast_stmt FROM @ddl; EXECUTE ast_stmt; DEALLOCATE PREPARE ast_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shipments' AND COLUMN_NAME='first_free_day')=0,
  'ALTER TABLE shipments ADD COLUMN first_free_day DATE NULL AFTER eta_date', 'SELECT ''first_free_day already exists''');
PREPARE ast_stmt FROM @ddl; EXECUTE ast_stmt; DEALLOCATE PREPARE ast_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shipments' AND COLUMN_NAME='delivery_due_date')=0,
  'ALTER TABLE shipments ADD COLUMN delivery_due_date DATE NULL AFTER first_free_day', 'SELECT ''delivery_due_date already exists''');
PREPARE ast_stmt FROM @ddl; EXECUTE ast_stmt; DEALLOCATE PREPARE ast_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shipments' AND COLUMN_NAME='archived_at')=0,
  'ALTER TABLE shipments ADD COLUMN archived_at DATETIME NULL AFTER delivery_due_date', 'SELECT ''archived_at already exists''');
PREPARE ast_stmt FROM @ddl; EXECUTE ast_stmt; DEALLOCATE PREPARE ast_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shipments' AND COLUMN_NAME='archived_by')=0,
  'ALTER TABLE shipments ADD COLUMN archived_by INT UNSIGNED NULL AFTER archived_at', 'SELECT ''archived_by already exists''');
PREPARE ast_stmt FROM @ddl; EXECUTE ast_stmt; DEALLOCATE PREPARE ast_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shipments' AND COLUMN_NAME='archive_reason')=0,
  'ALTER TABLE shipments ADD COLUMN archive_reason VARCHAR(255) NULL AFTER archived_by', 'SELECT ''archive_reason already exists''');
PREPARE ast_stmt FROM @ddl; EXECUTE ast_stmt; DEALLOCATE PREPARE ast_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shipments' AND INDEX_NAME='idx_ship_archived_at')=0,
  'ALTER TABLE shipments ADD INDEX idx_ship_archived_at (archived_at)', 'SELECT ''idx_ship_archived_at already exists''');
PREPARE ast_stmt FROM @ddl; EXECUTE ast_stmt; DEALLOCATE PREPARE ast_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shipments' AND INDEX_NAME='idx_ship_eta')=0,
  'ALTER TABLE shipments ADD INDEX idx_ship_eta (eta_date)', 'SELECT ''idx_ship_eta already exists''');
PREPARE ast_stmt FROM @ddl; EXECUTE ast_stmt; DEALLOCATE PREPARE ast_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shipments' AND INDEX_NAME='idx_ship_first_free_day')=0,
  'ALTER TABLE shipments ADD INDEX idx_ship_first_free_day (first_free_day)', 'SELECT ''idx_ship_first_free_day already exists''');
PREPARE ast_stmt FROM @ddl; EXECUTE ast_stmt; DEALLOCATE PREPARE ast_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shipments' AND INDEX_NAME='idx_ship_delivery_due')=0,
  'ALTER TABLE shipments ADD INDEX idx_ship_delivery_due (delivery_due_date)', 'SELECT ''idx_ship_delivery_due already exists''');
PREPARE ast_stmt FROM @ddl; EXECUTE ast_stmt; DEALLOCATE PREPARE ast_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='shipments' AND CONSTRAINT_NAME='fk_ship_archived_by')=0,
  'ALTER TABLE shipments ADD CONSTRAINT fk_ship_archived_by FOREIGN KEY (archived_by) REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT ''fk_ship_archived_by already exists''');
PREPARE ast_stmt FROM @ddl; EXECUTE ast_stmt; DEALLOCATE PREPARE ast_stmt;

CREATE TABLE IF NOT EXISTS notifications (
    notification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    shipment_id     INT UNSIGNED NULL,
    event_type      VARCHAR(40) NOT NULL,
    title           VARCHAR(120) NOT NULL,
    message         VARCHAR(255) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at         DATETIME NULL,
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_notification_ship FOREIGN KEY (shipment_id)
        REFERENCES shipments(shipment_id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_notifications_user_created (user_id, created_at),
    INDEX idx_notifications_user_read (user_id, read_at)
) ENGINE=InnoDB;

SELECT 'Shipment reference features migration complete.' AS result;
