-- Migration 001: bidirectional buyer↔seller ratings.
-- Idempotent — safe to re-run. Apply to existing prod DB once; schema.sql
-- already includes the same definition for fresh installs.

CREATE TABLE IF NOT EXISTS ratings (
    rating_id     INT AUTO_INCREMENT PRIMARY KEY,
    order_id      INT NOT NULL,
    rater_id      INT NOT NULL,
    ratee_id      INT NOT NULL,
    direction     ENUM('buyer_to_seller','seller_to_buyer') NOT NULL,
    score         TINYINT NOT NULL,
    comment       VARCHAR(1000) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rating_order
        FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
    CONSTRAINT fk_rating_rater
        FOREIGN KEY (rater_id) REFERENCES users(user_id)   ON DELETE CASCADE,
    CONSTRAINT fk_rating_ratee
        FOREIGN KEY (ratee_id) REFERENCES users(user_id)   ON DELETE CASCADE,
    CONSTRAINT chk_rating_score CHECK (score BETWEEN 1 AND 5),
    UNIQUE KEY uniq_order_rater (order_id, rater_id),
    INDEX idx_rating_ratee (ratee_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
