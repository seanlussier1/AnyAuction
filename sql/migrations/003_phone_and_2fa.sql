-- Migration 003: phone column + auth_codes table for SMS 2FA.
-- Idempotent — safe to re-run.

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'phone'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE users
       ADD COLUMN phone VARCHAR(16) NULL AFTER email,
       ADD COLUMN phone_verified_at DATETIME NULL,
       ADD COLUMN sms_opt_out TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS auth_codes (
    code_id      INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    purpose      ENUM('login','password_reset','phone_verify') NOT NULL,
    code_hash    VARCHAR(255) NOT NULL,
    expires_at   DATETIME NOT NULL,
    used_at      DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auth_codes_user_purpose (user_id, purpose, used_at),
    INDEX idx_auth_codes_expires (expires_at),
    CONSTRAINT fk_auth_codes_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
