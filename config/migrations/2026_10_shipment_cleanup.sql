-- =====================================================================
-- AstraTrack migration 2026_10_shipment_cleanup
-- Target : existing `astratrack` database (MySQL 5.7+ / 8.x, MariaDB 10.3+)
-- Run as : an account with ALTER / CREATE / REFERENCES rights (NOT the app user)
-- Run on : a COPY first. Take a mysqldump before running on live data.
--
--   mysql -u <admin> -p astratrack < 2026_10_shipment_cleanup.sql
--   (do NOT use --force: the script relies on stopping at the first error)
--   phpMyAdmin: select the astratrack database, SQL tab, paste, Go.
--
-- What it does
--   1. Adds shipments.actual_arrival_date (DATE), shipments.remarks (TEXT),
--      deliveries.receiving_contact (VARCHAR 150), and deliveries.scheduled_time (TIME)
--   2. Stops if duplicate B/L numbers exist, then adds UNIQUE (bl_awb_no)
--   3. Creates shipment_stages (8 stages + On Hold + Cancelled), maps old free-text
--      statuses, then adds foreign keys from shipments.current_status and
--      shipment_status_updates.stage to shipment_stages.stage_name
--
-- NOT in this file: the reference prefix change (AST- vs SHP-). Waiting for a decision.
--
-- Safe to re-run: every step checks first. MySQL DDL cannot be rolled back, so the
-- original status values are kept in two *_bak_2026_10 tables (see rollback note).
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- STEP 0. Duplicate B/L guard (nothing has been changed yet)
-- 1) the SELECT lists every duplicated B/L so you can fix them by hand
-- 2) the INSERT then FAILS on the first duplicate ("Duplicate entry '...' for key
--    '...uq_bl'") and stops the script before any change is made.
-- Blank and whitespace-only B/L values count as "no B/L" (NULL), as in step 3.
-- ---------------------------------------------------------------------
SELECT NULLIF(TRIM(bl_awb_no), '')   AS duplicate_bl_awb_no,
       COUNT(*)                      AS shipments_with_it,
       GROUP_CONCAT(reference_no ORDER BY shipment_id SEPARATOR ', ') AS shipment_references
FROM   shipments
WHERE  NULLIF(TRIM(bl_awb_no), '') IS NOT NULL
GROUP  BY NULLIF(TRIM(bl_awb_no), '')
HAVING COUNT(*) > 1;

DROP TEMPORARY TABLE IF EXISTS _bl_guard;
CREATE TEMPORARY TABLE _bl_guard (
    bl VARCHAR(50) NOT NULL,
    UNIQUE KEY uq_bl (bl)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO _bl_guard (bl)
SELECT NULLIF(TRIM(bl_awb_no), '') FROM shipments WHERE NULLIF(TRIM(bl_awb_no), '') IS NOT NULL;

DROP TEMPORARY TABLE _bl_guard;

-- ---------------------------------------------------------------------
-- STEP 1. Missing columns (add if missing; widen/convert if hand-added smaller)
-- ---------------------------------------------------------------------
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipments' AND COLUMN_NAME = 'actual_arrival_date') = 0,
    'ALTER TABLE shipments ADD COLUMN actual_arrival_date DATE NULL AFTER current_status',
    'SELECT ''shipments.actual_arrival_date already OK'' AS info');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deliveries' AND COLUMN_NAME = 'scheduled_time') = 0,
    'ALTER TABLE deliveries ADD COLUMN scheduled_time TIME NULL AFTER scheduled_date',
    'SELECT ''deliveries.scheduled_time already OK'' AS info');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipments' AND COLUMN_NAME = 'remarks') = 0,
    'ALTER TABLE shipments ADD COLUMN remarks TEXT NULL AFTER current_status',
    IF((SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipments' AND COLUMN_NAME = 'remarks') <> 'text',
       'ALTER TABLE shipments MODIFY COLUMN remarks TEXT NULL',
       'SELECT ''shipments.remarks already OK'' AS info'));
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deliveries' AND COLUMN_NAME = 'receiving_contact') = 0,
    'ALTER TABLE deliveries ADD COLUMN receiving_contact VARCHAR(150) NULL AFTER delivery_address',
    IF((SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deliveries' AND COLUMN_NAME = 'receiving_contact') < 150,
       'ALTER TABLE deliveries MODIFY COLUMN receiving_contact VARCHAR(150) NULL',
       'SELECT ''deliveries.receiving_contact already OK'' AS info'));
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- STEP 2. Stage lookup table
-- stage_group is the label shown in the tabs, badges and stepper.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shipment_stages (
    stage_id     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    stage_name   VARCHAR(40)  NOT NULL,
    stage_group  VARCHAR(40)  NOT NULL,
    sort_order   SMALLINT UNSIGNED NOT NULL,
    is_closed    TINYINT(1)   NOT NULL DEFAULT 0,
    UNIQUE KEY uq_stage_name (stage_name),
    KEY idx_stage_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO shipment_stages (stage_name, stage_group, sort_order, is_closed) VALUES
('Documents received', 'Pending Documents',  10, 0),
('Declaration filed',  'Customs Processing', 20, 0),
('Assessment',         'Customs Processing', 30, 0),
('Duties paid',        'Customs Processing', 40, 0),
('Examination',        'Customs Processing', 50, 0),
('Released/cleared',   'Customs Processing', 60, 0),
('Trucking',           'In Transit',         70, 0),
('Delivered',          'Delivered',          80, 1),
('On Hold',            'On Hold',            90, 0),
('Cancelled',          'Cancelled',         100, 1)
ON DUPLICATE KEY UPDATE stage_group = VALUES(stage_group),
                        sort_order  = VALUES(sort_order),
                        is_closed   = VALUES(is_closed);

-- ---------------------------------------------------------------------
-- STEP 3. Back up the old wording, then map it onto the stage names
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shipments_status_bak_2026_10 (
    shipment_id    INT UNSIGNED NOT NULL PRIMARY KEY,
    current_status VARCHAR(40)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO shipments_status_bak_2026_10 (shipment_id, current_status)
SELECT shipment_id, current_status FROM shipments;

CREATE TABLE IF NOT EXISTS shipment_status_updates_stage_bak_2026_10 (
    update_id INT UNSIGNED NOT NULL PRIMARY KEY,
    stage     VARCHAR(40)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO shipment_status_updates_stage_bak_2026_10 (update_id, stage)
SELECT update_id, stage FROM shipment_status_updates;

-- old wording (lower case, trimmed) -> new stage name
-- Review the judgement calls marked (*) before running on live data.
DROP TABLE IF EXISTS _stage_map_2026_10;
CREATE TABLE _stage_map_2026_10 (
    old_value VARCHAR(40) NOT NULL PRIMARY KEY,
    new_stage VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO _stage_map_2026_10 (old_value, new_stage) VALUES
('',                    'Documents received'),   -- (*) blank status
('documents received',  'Documents received'),
('pending documents',   'Documents received'),   -- (*) group name used as a status
('declaration filed',   'Declaration filed'),
('customs processing',  'Declaration filed'),    -- (*) group name used as a status: first customs stage
('assessment',          'Assessment'),
('duties paid',         'Duties paid'),
('examination',         'Examination'),
('released/cleared',    'Released/cleared'),
('released / cleared',  'Released/cleared'),
('released',            'Released/cleared'),
('cleared',             'Released/cleared'),
('in transit',          'Trucking'),             -- (*) the only stage in the In Transit group
('trucking',            'Trucking'),
('delivered',           'Delivered'),
('on hold',             'On Hold'),
('hold',                'On Hold'),
('cancelled',           'Cancelled'),
('canceled',            'Cancelled');

-- Anything listed by these two SELECTs has no mapping. Add it to the INSERT above
-- (or fix the row), then run the script again. Step 4 refuses to run until they are empty.
SELECT 'shipments.current_status' AS column_name, s.current_status AS unmapped_value, COUNT(*) AS rows_affected
FROM shipments s
LEFT JOIN _stage_map_2026_10 m ON m.old_value = LOWER(TRIM(s.current_status)) COLLATE utf8mb4_unicode_ci
WHERE m.old_value IS NULL
GROUP BY s.current_status;

SELECT 'shipment_status_updates.stage' AS column_name, u.stage AS unmapped_value, COUNT(*) AS rows_affected
FROM shipment_status_updates u
LEFT JOIN _stage_map_2026_10 m ON m.old_value = LOWER(TRIM(u.stage)) COLLATE utf8mb4_unicode_ci
WHERE m.old_value IS NULL
GROUP BY u.stage;

UPDATE shipments s
JOIN _stage_map_2026_10 m ON m.old_value = LOWER(TRIM(s.current_status)) COLLATE utf8mb4_unicode_ci
JOIN shipment_stages t    ON t.stage_name = m.new_stage
SET s.current_status = t.stage_name;

UPDATE shipment_status_updates u
JOIN _stage_map_2026_10 m ON m.old_value = LOWER(TRIM(u.stage)) COLLATE utf8mb4_unicode_ci
JOIN shipment_stages t    ON t.stage_name = m.new_stage
SET u.stage = t.stage_name;

DROP TABLE _stage_map_2026_10;

-- ---------------------------------------------------------------------
-- STEP 4. Foreign keys to shipment_stages.stage_name
-- If an unmapped value is left, ADD CONSTRAINT fails here with error 1215/1452
-- and nothing is lost: fix the mapping and re-run.
-- ---------------------------------------------------------------------
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'shipments'
        AND CONSTRAINT_NAME = 'fk_ship_stage' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
    'ALTER TABLE shipments ADD CONSTRAINT fk_ship_stage FOREIGN KEY (current_status)
        REFERENCES shipment_stages (stage_name) ON UPDATE CASCADE ON DELETE RESTRICT',
    'SELECT ''fk_ship_stage already exists'' AS info');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'shipment_status_updates'
        AND CONSTRAINT_NAME = 'fk_status_stage' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
    'ALTER TABLE shipment_status_updates ADD CONSTRAINT fk_status_stage FOREIGN KEY (stage)
        REFERENCES shipment_stages (stage_name) ON UPDATE CASCADE ON DELETE RESTRICT',
    'SELECT ''fk_status_stage already exists'' AS info');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- STEP 5. Unique B/L (the Step 0 guard already proved there are no duplicates)
-- ---------------------------------------------------------------------
UPDATE shipments SET bl_awb_no = NULLIF(TRIM(bl_awb_no), '') WHERE bl_awb_no IS NOT NULL;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipments' AND INDEX_NAME = 'uq_ship_bl') = 0,
    'ALTER TABLE shipments ADD UNIQUE KEY uq_ship_bl (bl_awb_no)',
    'SELECT ''uq_ship_bl already exists'' AS info');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- the old plain index is now redundant
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipments' AND INDEX_NAME = 'idx_ship_bl') > 0,
    'ALTER TABLE shipments DROP INDEX idx_ship_bl',
    'SELECT ''idx_ship_bl already gone'' AS info');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- STEP 6. Result check: every row below should be 0 (or the expected total)
-- ---------------------------------------------------------------------
SELECT
  (SELECT COUNT(*) FROM shipment_stages)                                            AS stages_expected_10,
  (SELECT COUNT(*) FROM shipments s
     LEFT JOIN shipment_stages t ON t.stage_name = s.current_status
     WHERE t.stage_id IS NULL)                                                      AS shipments_without_valid_stage,
  (SELECT COUNT(*) FROM shipment_status_updates u
     LEFT JOIN shipment_stages t ON t.stage_name = u.stage
     WHERE t.stage_id IS NULL)                                                      AS updates_without_valid_stage,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND ((TABLE_NAME = 'shipments'  AND COLUMN_NAME = 'remarks')
         OR (TABLE_NAME = 'deliveries' AND COLUMN_NAME = 'receiving_contact')
         OR (TABLE_NAME = 'deliveries' AND COLUMN_NAME = 'scheduled_time')
         OR (TABLE_NAME = 'shipments' AND COLUMN_NAME = 'actual_arrival_date')))  AS new_columns_expected_4,
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipments'
       AND INDEX_NAME = 'uq_ship_bl' AND NON_UNIQUE = 0)                            AS unique_bl_expected_1;
