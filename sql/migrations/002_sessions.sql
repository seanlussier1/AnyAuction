-- Migration 002: persistent sessions stored in MariaDB.
-- Idempotent — safe to re-run. Apply once to existing prod DB; schema.sql
-- already includes the same definition for fresh installs.

CREATE TABLE IF NOT EXISTS sessions (
    session_id    VARCHAR(128) PRIMARY KEY,
    user_id       INT NULL,
    payload       MEDIUMBLOB NOT NULL,
    last_activity DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sessions_user_id       (user_id),
    INDEX idx_sessions_last_activity (last_activity),
    CONSTRAINT fk_sessions_user
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
