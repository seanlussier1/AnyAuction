-- Migration 004: per-phone state machine for SMS-driven re-bidding.
CREATE TABLE IF NOT EXISTS sms_conversations (
    phone_number    VARCHAR(16) PRIMARY KEY,
    user_id         INT NULL,
    state           ENUM('waiting_amount','waiting_confirm') NOT NULL,
    item_id         INT NULL,
    pending_amount  DECIMAL(10,2) NULL,
    expires_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sms_conv_user (user_id),
    INDEX idx_sms_conv_expires (expires_at),
    CONSTRAINT fk_sms_conv_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_sms_conv_item FOREIGN KEY (item_id) REFERENCES auction_items(item_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
