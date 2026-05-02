-- Migration 008: admin moderation controls for user warnings/bans.

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'account_status'
);

SET @sql := IF(@col_exists = 0,
    "ALTER TABLE users ADD COLUMN account_status ENUM('active','warned','banned') NOT NULL DEFAULT 'active' AFTER is_verified",
    "SELECT 1"
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'warning_note'
);

SET @sql := IF(@col_exists = 0,
    "ALTER TABLE users ADD COLUMN warning_note VARCHAR(255) NULL AFTER account_status",
    "SELECT 1"
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'banned_at'
);

SET @sql := IF(@col_exists = 0,
    "ALTER TABLE users ADD COLUMN banned_at DATETIME NULL AFTER warning_note",
    "SELECT 1"
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
