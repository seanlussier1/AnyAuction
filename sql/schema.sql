-- AnyAuction — baseline schema (Deliverable 1 tables only; advanced features come later).
-- Target: MariaDB 10.4+ / MySQL 8.0+. Default charset utf8mb4 for full unicode support.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS ratings;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS watchlists;
DROP TABLE IF EXISTS bids;
DROP TABLE IF EXISTS item_images;
DROP TABLE IF EXISTS auction_items;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    user_id          INT AUTO_INCREMENT PRIMARY KEY,
    username         VARCHAR(50)  NOT NULL UNIQUE,
    email            VARCHAR(255) NOT NULL UNIQUE,
    password_hash    VARCHAR(255) NOT NULL,
    first_name       VARCHAR(100) NOT NULL,
    last_name        VARCHAR(100) NOT NULL,
    profile_picture  VARCHAR(500) NULL,
    role             ENUM('buyer','seller','admin') NOT NULL DEFAULT 'buyer',
    is_verified      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    category_id  INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL,
    slug         VARCHAR(100) NOT NULL UNIQUE,
    icon         VARCHAR(50)  NOT NULL DEFAULT 'tag'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auction_items (
    item_id          INT AUTO_INCREMENT PRIMARY KEY,
    seller_id        INT NOT NULL,
    category_id      INT NOT NULL,
    title            VARCHAR(255) NOT NULL,
    description      TEXT         NOT NULL,
    starting_price   DECIMAL(10,2) NOT NULL,
    current_price    DECIMAL(10,2) NOT NULL,
    reserve_price    DECIMAL(10,2) NULL,
    buy_now_price    DECIMAL(10,2) NULL,
    start_time       DATETIME NOT NULL,
    end_time         DATETIME NOT NULL,
    status           ENUM('active','closed','cancelled','pending') NOT NULL DEFAULT 'active',
    `condition`      VARCHAR(50)  NOT NULL DEFAULT 'Used',
    shipping         VARCHAR(100) NOT NULL DEFAULT 'Standard shipping',
    featured         TINYINT(1)   NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_auction_seller
        FOREIGN KEY (seller_id)   REFERENCES users(user_id)         ON DELETE CASCADE,
    CONSTRAINT fk_auction_category
        FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE RESTRICT,
    INDEX idx_auction_status_end (status, end_time),
    INDEX idx_auction_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE item_images (
    image_id       INT AUTO_INCREMENT PRIMARY KEY,
    item_id        INT NOT NULL,
    image_url      VARCHAR(500) NOT NULL,
    is_primary     TINYINT(1)   NOT NULL DEFAULT 0,
    display_order  INT          NOT NULL DEFAULT 0,
    CONSTRAINT fk_image_item
        FOREIGN KEY (item_id) REFERENCES auction_items(item_id) ON DELETE CASCADE,
    INDEX idx_image_item (item_id, is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bids (
    bid_id      INT AUTO_INCREMENT PRIMARY KEY,
    item_id     INT NOT NULL,
    user_id     INT NOT NULL,
    bid_amount  DECIMAL(10,2) NOT NULL,
    bid_time    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_bid_item
        FOREIGN KEY (item_id) REFERENCES auction_items(item_id) ON DELETE CASCADE,
    CONSTRAINT fk_bid_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)         ON DELETE CASCADE,
    INDEX idx_bid_item_time (item_id, bid_time DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE watchlists (
    user_id     INT NOT NULL,
    item_id     INT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, item_id),
    CONSTRAINT fk_watchlist_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)         ON DELETE CASCADE,
    CONSTRAINT fk_watchlist_item
        FOREIGN KEY (item_id) REFERENCES auction_items(item_id) ON DELETE CASCADE,
    INDEX idx_watchlist_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    order_id            INT AUTO_INCREMENT PRIMARY KEY,
    item_id             INT NOT NULL,
    buyer_id            INT NOT NULL,
    amount              DECIMAL(10,2) NOT NULL,
    stripe_session_id   VARCHAR(255) NOT NULL,
    status              ENUM('pending','paid','cancelled','failed') NOT NULL DEFAULT 'pending',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at             DATETIME NULL,
    CONSTRAINT fk_order_item
        FOREIGN KEY (item_id)  REFERENCES auction_items(item_id) ON DELETE CASCADE,
    CONSTRAINT fk_order_buyer
        FOREIGN KEY (buyer_id) REFERENCES users(user_id)         ON DELETE CASCADE,
    UNIQUE KEY uniq_stripe_session (stripe_session_id),
    INDEX idx_order_item_buyer (item_id, buyer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ratings (
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

SET FOREIGN_KEY_CHECKS = 1;
