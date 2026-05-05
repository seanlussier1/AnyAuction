-- Migration 009: per-user notification preference columns.
-- Companion to the existing sms_opt_out column; these four track the
-- four other toggles surfaced in /profile -> Settings.

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'pref_email_bids'
);

SET @sql := IF(@col_exists = 0,
    "ALTER TABLE users ADD COLUMN pref_email_bids TINYINT(1) NOT NULL DEFAULT 1 AFTER sms_opt_out",
    "SELECT 1"
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'pref_outbid'
);

SET @sql := IF(@col_exists = 0,
    "ALTER TABLE users ADD COLUMN pref_outbid TINYINT(1) NOT NULL DEFAULT 1 AFTER pref_email_bids",
    "SELECT 1"
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'pref_weekly_digest'
);

SET @sql := IF(@col_exists = 0,
    "ALTER TABLE users ADD COLUMN pref_weekly_digest TINYINT(1) NOT NULL DEFAULT 1 AFTER pref_outbid",
    "SELECT 1"
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'pref_order_updates'
);

SET @sql := IF(@col_exists = 0,
    "ALTER TABLE users ADD COLUMN pref_order_updates TINYINT(1) NOT NULL DEFAULT 1 AFTER pref_weekly_digest",
    "SELECT 1"
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
