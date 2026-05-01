-- Migration 005: per-user notification feed surfaced on the profile page
-- and via the navbar bell badge. Mirrors the events that fire SMS today
-- plus a few in-site-only events (bid received on your listing, payout,
-- etc.) where SMS would be too noisy.
-- Idempotent — safe to re-run.

CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    type            VARCHAR(50) NOT NULL,
    title           VARCHAR(255) NOT NULL,
    body            TEXT NULL,
    item_id         INT NULL,
    href            VARCHAR(500) NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user_unread (user_id, is_read, created_at),
    INDEX idx_notifications_user_recent (user_id, created_at DESC),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_item FOREIGN KEY (item_id) REFERENCES auction_items(item_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
