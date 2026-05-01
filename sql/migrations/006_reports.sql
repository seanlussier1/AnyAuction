-- Migration 006: real reports table — replaces the mock array in
-- AdminController. Listing reports today; the schema also has a slot
-- for user-reports so they don't need a follow-up migration.
-- Idempotent — safe to re-run.

CREATE TABLE IF NOT EXISTS reports (
    report_id        INT AUTO_INCREMENT PRIMARY KEY,
    reporter_id      INT NOT NULL,
    item_id          INT NULL,
    reported_user_id INT NULL,
    type             ENUM('listing','user') NOT NULL,
    reason           VARCHAR(50) NOT NULL,
    details          TEXT NOT NULL,
    status           ENUM('pending','resolved','dismissed') NOT NULL DEFAULT 'pending',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at      DATETIME NULL,
    INDEX idx_reports_status_created (status, created_at DESC),
    INDEX idx_reports_item           (item_id),
    INDEX idx_reports_reported_user  (reported_user_id),
    CONSTRAINT fk_reports_reporter FOREIGN KEY (reporter_id)      REFERENCES users(user_id)        ON DELETE CASCADE,
    CONSTRAINT fk_reports_item     FOREIGN KEY (item_id)          REFERENCES auction_items(item_id) ON DELETE CASCADE,
    CONSTRAINT fk_reports_user     FOREIGN KEY (reported_user_id) REFERENCES users(user_id)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
