-- Add optional before/after field details for shipment edit audit entries.
-- Safe to rerun. Existing activity_log rows remain valid with NULL details.
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'details_json') = 0,
    'ALTER TABLE activity_log ADD COLUMN details_json LONGTEXT NULL AFTER performed_by_role',
    'SELECT ''activity_log.details_json already OK'' AS info');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) AS details_json_column_expected_1
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'details_json';
