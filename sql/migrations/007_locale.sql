-- Migration 007: per-user locale preference for i18n.
-- Idempotent — INFORMATION_SCHEMA guard so it's safe to re-run.

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'locale'
);
SET @sql := IF(@col_exists = 0,
    "ALTER TABLE users ADD COLUMN locale VARCHAR(5) NOT NULL DEFAULT 'en' AFTER sms_opt_out",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
