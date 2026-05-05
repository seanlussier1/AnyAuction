#!/usr/bin/env bash
# Self-contained DB reset for AnyAuction.
# Embeds both schema.sql and seed.sql so it can run on EC2 without any other
# files. Drops every table and reseeds from scratch.
#
# Usage:
#   bash reset-db-standalone.sh
#
# Optional env overrides:
#   DB_CONTAINER (default: db)
#   DB_NAME      (default: anyauction)

set -euo pipefail

DB_CONTAINER="${DB_CONTAINER:-db}"
DB_NAME="${DB_NAME:-anyauction}"

if ! docker inspect "$DB_CONTAINER" >/dev/null 2>&1; then
    echo "error: container '$DB_CONTAINER' is not running. Start it with: docker compose up -d" >&2
    echo "       (or set DB_CONTAINER=<your-name> if it has a different name)" >&2
    exit 1
fi

DB_PW=$(docker inspect "$DB_CONTAINER" \
    --format '{{range .Config.Env}}{{println .}}{{end}}' \
    | grep -E 'MARIADB_ROOT_PASSWORD|MYSQL_ROOT_PASSWORD' \
    | head -1 | cut -d= -f2)

if [ -z "$DB_PW" ]; then
    echo "error: could not read MARIADB_ROOT_PASSWORD/MYSQL_ROOT_PASSWORD from container env" >&2
    exit 1
fi

echo "→ Dropping all tables and re-applying schema..."
docker exec -i "$DB_CONTAINER" mariadb -uroot -p"$DB_PW" "$DB_NAME" <<'SCHEMA_EOF'
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS reports;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS ratings;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS watchlists;
DROP TABLE IF EXISTS bids;
DROP TABLE IF EXISTS sms_conversations;
DROP TABLE IF EXISTS item_images;
DROP TABLE IF EXISTS auction_items;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS auth_codes;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    user_id            INT AUTO_INCREMENT PRIMARY KEY,
    username           VARCHAR(50)  NOT NULL UNIQUE,
    email              VARCHAR(255) NOT NULL UNIQUE,
    phone              VARCHAR(16)  NULL,
    password_hash      VARCHAR(255) NOT NULL,
    first_name         VARCHAR(100) NOT NULL,
    last_name          VARCHAR(100) NOT NULL,
    profile_picture    VARCHAR(500) NULL,
    role               ENUM('buyer','seller','admin') NOT NULL DEFAULT 'buyer',
    is_verified        TINYINT(1)   NOT NULL DEFAULT 0,
    account_status     ENUM('active','warned','banned') NOT NULL DEFAULT 'active',
    warning_note       VARCHAR(255) NULL,
    banned_at          DATETIME     NULL,
    phone_verified_at  DATETIME     NULL,
    sms_opt_out        TINYINT(1)   NOT NULL DEFAULT 0,
    pref_email_bids    TINYINT(1)   NOT NULL DEFAULT 1,
    pref_outbid        TINYINT(1)   NOT NULL DEFAULT 1,
    pref_weekly_digest TINYINT(1)   NOT NULL DEFAULT 1,
    pref_order_updates TINYINT(1)   NOT NULL DEFAULT 1,
    locale             VARCHAR(5)   NOT NULL DEFAULT 'en',
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_codes (
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

CREATE TABLE sessions (
    session_id    VARCHAR(128) PRIMARY KEY,
    user_id       INT NULL,
    payload       MEDIUMBLOB NOT NULL,
    last_activity DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sessions_user_id       (user_id),
    INDEX idx_sessions_last_activity (last_activity),
    CONSTRAINT fk_sessions_user
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sms_conversations (
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

CREATE TABLE notifications (
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

CREATE TABLE reports (
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

SET FOREIGN_KEY_CHECKS = 1;
SCHEMA_EOF

echo "→ Loading demo seed data..."
docker exec -i "$DB_CONTAINER" mariadb -uroot -p"$DB_PW" "$DB_NAME" <<'SEED_EOF'
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO users (user_id, username, email, password_hash, first_name, last_name, profile_picture, role, is_verified, account_status, warning_note, banned_at) VALUES
    (1,  'demobuyer',   'buyer@anyauction.test',   '$2y$10$aW9pFnpGGTlpNK.HMfHOLOSAD/lKQXzZEs4g4ySWEIGcSmah2zARC', 'Alex',   'Morgan',   'https://i.pravatar.cc/150?img=12', 'buyer',  1, 'active', NULL, NULL),
    (2,  'demoseller',  'seller@anyauction.test',  '$2y$10$Pr6ScO3Tf3zb8R/JV0i8KOga0fzVf1rPD9AsWiZP5gFySNZdbO0QO', 'Jamie',  'Rivera',   'https://i.pravatar.cc/150?img=32', 'seller', 1, 'active', NULL, NULL),
    (3,  'demoadmin',   'admin@anyauction.test',   '$2y$10$S7RjaVqxNpHgGV5r1jWkh.hL20u2gzjRXnQ6h51TsSn1PMwWUsPBC', 'Casey',  'Donovan',  'https://i.pravatar.cc/150?img=48', 'admin',  1, 'active', NULL, NULL),
    (4,  'rileychen',   'riley@anyauction.test',   '$2y$10$aW9pFnpGGTlpNK.HMfHOLOSAD/lKQXzZEs4g4ySWEIGcSmah2zARC', 'Riley',  'Chen',     'https://i.pravatar.cc/150?img=5',  'buyer',  1, 'active', NULL, NULL),
    (5,  'samthompson', 'sam@anyauction.test',     '$2y$10$aW9pFnpGGTlpNK.HMfHOLOSAD/lKQXzZEs4g4ySWEIGcSmah2zARC', 'Sam',    'Thompson', 'https://i.pravatar.cc/150?img=15', 'buyer',  1, 'active', NULL, NULL),
    (6,  'tbrooks',     'taylor@anyauction.test',  '$2y$10$aW9pFnpGGTlpNK.HMfHOLOSAD/lKQXzZEs4g4ySWEIGcSmah2zARC', 'Taylor', 'Brooks',   'https://i.pravatar.cc/150?img=24', 'seller', 1, 'active', NULL, NULL),
    (7,  'mreed',       'morgan@anyauction.test',  '$2y$10$aW9pFnpGGTlpNK.HMfHOLOSAD/lKQXzZEs4g4ySWEIGcSmah2zARC', 'Morgan', 'Reed',     'https://i.pravatar.cc/150?img=60', 'seller', 1, 'active', NULL, NULL),
    (8,  'spammyuser',  'spammy@anyauction.test',  '$2y$10$aW9pFnpGGTlpNK.HMfHOLOSAD/lKQXzZEs4g4ySWEIGcSmah2zARC', 'Pat',    'Vega',     'https://i.pravatar.cc/150?img=68', 'buyer',  0, 'active', NULL, NULL),
    (9,  'warneduser',  'warned@anyauction.test',  '$2y$10$aW9pFnpGGTlpNK.HMfHOLOSAD/lKQXzZEs4g4ySWEIGcSmah2zARC', 'Avery',  'Lin',      'https://i.pravatar.cc/150?img=44', 'buyer',  1, 'warned', 'Repeated late payments — please complete checkout within 24h.', NULL),
    (10, 'banneduser',  'banned@anyauction.test',  '$2y$10$aW9pFnpGGTlpNK.HMfHOLOSAD/lKQXzZEs4g4ySWEIGcSmah2zARC', 'Jordan', 'Cruz',     'https://i.pravatar.cc/150?img=53', 'buyer',  0, 'banned', 'Multiple counterfeit listings reported.', DATE_SUB(NOW(), INTERVAL 5 DAY));

INSERT INTO categories (category_id, name, slug, icon) VALUES
    (1, 'Electronics', 'electronics', 'phone'),
    (2, 'Fashion',     'fashion',     'bag'),
    (3, 'Home',        'home',        'house'),
    (4, 'Art',         'art',         'palette'),
    (5, 'Vehicles',    'vehicles',    'car-front'),
    (6, 'Jewelry',     'jewelry',     'gem'),
    (7, 'Sports',      'sports',      'trophy'),
    (8, 'Music',       'music',       'music-note-beamed');

INSERT INTO auction_items
    (item_id, seller_id, category_id, title, description, starting_price, current_price, reserve_price, buy_now_price,
     start_time, end_time, status, `condition`, shipping, featured)
VALUES
    (1, 2, 1, 'Vintage Leica M3 Rangefinder Camera',
        'A beautifully preserved Leica M3 from 1958. Fully functional, original leatherette, clean optics. Includes leather case and matching 50mm Summicron lens. A collector''s dream.',
        250.00, 460.00, 500.00, 950.00,
        DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL 2 DAY), 'active', 'Used — Excellent', 'Insured shipping worldwide', 1),
    (2, 2, 2, 'Limited Edition Selvedge Denim Jacket — Size M',
        'Small-batch selvedge denim jacket, worn twice. Copper rivets, chain-stitch hem, raw indigo that''ll fade beautifully. Size M, fits true.',
        45.00, 45.00, NULL, 120.00,
        DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 3 DAY), 'active', 'Like New', 'Standard shipping', 0),
    (3, 2, 4, 'Hand-Painted Abstract Canvas (24"x36")',
        'Original acrylic on canvas by local artist. Warm earth tones with gold leaf accents. Signed and dated 2024. Arrives ready to hang.',
        80.00, 150.00, NULL, NULL,
        DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_ADD(NOW(), INTERVAL 1 DAY), 'active', 'New', 'Pickup or local delivery', 1),
    (4, 2, 6, '18k Gold Vintage Signet Ring',
        'Solid 18k yellow gold signet ring, blank face ready for engraving. Hallmarked, weighs 9.2g. Size 9 (resizable).',
        300.00, 560.00, 600.00, 1100.00,
        DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL 4 DAY), 'active', 'Used — Very Good', 'Signature on delivery', 1),
    (5, 2, 7, 'Game-Used Signed NHL Hockey Stick',
        'Professional game-used stick, signed on the shaft. Certificate of authenticity included. Great display piece for any hockey fan.',
        120.00, 120.00, NULL, NULL,
        DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 6 HOUR), 'active', 'Used', 'Standard shipping', 0),
    (6, 2, 8, 'Fender Stratocaster — American Standard, 2012',
        'Sunburst finish, maple neck, recent setup with new 10s. Minor buckle rash on back, otherwise clean. Comes with hardshell case.',
        600.00, 820.00, 900.00, 1400.00,
        DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_ADD(NOW(), INTERVAL 5 DAY), 'active', 'Used — Good', 'Ships in original case', 1),
    (7, 2, 1, 'Sony WH-1000XM5 Wireless Headphones',
        'Top-tier noise cancelling, near-mint condition. Original box, charging cable, and carrying case included. Battery health 98%.',
        180.00, 180.00, NULL, 320.00,
        DATE_SUB(NOW(), INTERVAL 12 HOUR), DATE_ADD(NOW(), INTERVAL 3 DAY), 'active', 'Like New', 'Free shipping', 0),
    (8, 2, 3, 'Mid-Century Modern Walnut Floor Lamp',
        'Authentic 1960s teak and walnut floor lamp, fully rewired with new linen shade. A statement piece for any modern living room.',
        90.00, 105.00, NULL, NULL,
        DATE_SUB(NOW(), INTERVAL 18 HOUR), DATE_ADD(NOW(), INTERVAL 2 DAY), 'active', 'Used — Restored', 'Local pickup preferred', 0),
    (9, 2, 5, '1985 Honda CB350 Motorcycle — Restored',
        'Frame-up restoration completed in 2023. New tires, rebuilt carbs, fresh paint in factory red. ~2,400 km on the rebuild. Title in hand.',
        2200.00, 2200.00, 3000.00, 5800.00,
        DATE_SUB(NOW(), INTERVAL 6 HOUR), DATE_ADD(NOW(), INTERVAL 6 DAY), 'active', 'Used — Restored', 'Pickup only', 1),
    (10, 2, 4, 'Original Banksy-Style Stencil Print, Signed',
        'Limited run street-art stencil print, hand-signed and numbered (12/50). Framed in matte black under museum glass.',
        150.00, 410.00, NULL, 450.00,
        DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), 'closed', 'New', 'Insured shipping', 1),
    (11, 6, 1, 'iPad Pro 12.9" M2 (2022) — 256GB, Wi-Fi',
        'Space gray, AppleCare+ until July 2025. Always in case + screen protector, zero scratches. Includes Magic Keyboard and 2nd-gen Pencil.',
        650.00, 950.00, NULL, 1150.00,
        DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL 4 DAY), 'active', 'Like New', 'Free shipping', 0),
    (12, 6, 2, 'Vintage Levi''s 501 Selvedge Redline — 32x32',
        'Late-80s pre-shrunk Big E redline 501s. Honest fades, no holes, hemmed once. A grail piece for denim heads.',
        90.00, 90.00, NULL, NULL,
        DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 1 DAY), 'active', 'Used — Very Good', 'Standard shipping', 0),
    (13, 6, 3, 'Persian Wool Rug — 5x8, Hand-Knotted',
        'Authentic hand-knotted Persian rug from Tabriz, ~30 years old. Deep reds and indigo with intricate floral medallion. Recently professionally cleaned.',
        250.00, 380.00, NULL, 700.00,
        DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL 3 DAY), 'active', 'Used — Excellent', 'Freight shipping (buyer pays)', 1),
    (14, 6, 4, 'Antique Watercolor Landscape, Framed (c. 1910)',
        'Pastoral watercolor, original gilt frame, signed lower-right (illegible). Acquired from estate sale in Vermont.',
        60.00, 60.00, NULL, NULL,
        DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 5 DAY), 'active', 'Used — Good', 'Standard shipping', 0),
    (15, 6, 6, 'Pearl Drop Earrings — 14k White Gold',
        'Genuine 7mm Akoya pearls on 14k white gold posts and lever-backs. Comes in original jewelry box.',
        120.00, 120.00, NULL, 200.00,
        DATE_SUB(NOW(), INTERVAL 8 HOUR), DATE_ADD(NOW(), INTERVAL 2 DAY), 'active', 'New', 'Free shipping', 0),
    (16, 6, 7, 'Vintage Wilson Pro Staff Tennis Racket',
        '1990s Wilson Pro Staff 6.0, original strings (still playable), 4 3/8 grip. Light cosmetic wear.',
        40.00, 40.00, NULL, NULL,
        DATE_SUB(NOW(), INTERVAL 14 HOUR), DATE_ADD(NOW(), INTERVAL 4 HOUR), 'active', 'Used', 'Standard shipping', 0),
    (17, 6, 8, 'Pink Floyd — Wish You Were Here, OG UK Press',
        '1975 Harvest Records UK first pressing, includes original postcard insert. Vinyl is VG+, sleeve VG (light shelf wear).',
        45.00, 55.00, NULL, NULL,
        DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL 3 DAY), 'active', 'Used — Very Good', 'Standard shipping', 0),
    (18, 6, 1, 'Polaroid SX-70 Land Camera — Tested, Working',
        'Iconic folding Polaroid in chrome and brown leather. Tested with fresh i-Type film, ejects and develops cleanly. Includes original strap.',
        140.00, 140.00, NULL, 220.00,
        DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 2 DAY), 'active', 'Used — Good', 'Standard shipping', 0),
    (19, 7, 5, '2008 Vespa GTS 250 — Low km, One Owner',
        'Garage-kept Vespa GTS 250, 14,200 km, full service history. Recent tires, new battery. Two helmets and topcase included.',
        2800.00, 3950.00, 3500.00, 4500.00,
        DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY), 'closed', 'Used — Excellent', 'Pickup only', 1),
    (20, 7, 1, 'Apple Mac mini M2 (2023) — 8GB / 256GB',
        'Bought new in March 2023, used as a media server only. Like new, original packaging.',
        450.00, 580.00, NULL, 650.00,
        DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), 'closed', 'Like New', 'Free shipping', 0),
    (21, 7, 6, 'Diamond Tennis Bracelet — 14k White Gold, 2.0ct TW',
        'Classic 4-prong tennis bracelet, 2.0ct total weight in round brilliant diamonds (G-H, VS2). 7" length. Independent appraisal included.',
        1800.00, 2150.00, 2000.00, 3200.00,
        DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY), 'closed', 'New', 'Insured overnight', 1),
    (22, 7, 4, 'Eames Lounge Chair & Ottoman — Herman Miller, Walnut',
        'Authentic Herman Miller Eames lounge in walnut/black leather. Bought 2019, kept in a smoke-free home. Cushions in excellent condition.',
        2200.00, 2350.00, NULL, 3500.00,
        DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 6 DAY), 'active', 'Used — Excellent', 'White-glove freight', 1),
    (23, 7, 3, 'Le Creuset Cast Iron 5-Piece Set — Cerise Red',
        '5.5qt round Dutch oven, 3.5qt braiser, 9" skillet, 2qt saucepan, and grill pan. Light use, no chips.',
        220.00, 220.00, NULL, 380.00,
        DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 1 DAY), 'active', 'Used — Very Good', 'Standard shipping', 0),
    (24, 7, 7, 'Specialized Stumpjumper Comp Alloy — Size L (29")',
        '2022 Stumpjumper Comp Alloy 29, Fox Float fork, SRAM NX Eagle 12-speed. Recently serviced, fresh tires.',
        1400.00, 1400.00, NULL, 2100.00,
        DATE_SUB(NOW(), INTERVAL 12 HOUR), DATE_ADD(NOW(), INTERVAL 5 DAY), 'active', 'Used — Good', 'Pickup or freight', 0),
    (25, 7, 2, 'Burberry Cashmere Trench Coat — Size 38',
        'Classic Burberry single-breasted trench in camel cashmere. Listed in error — sold privately. Cancelled.',
        450.00, 450.00, NULL, 800.00,
        DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), 'cancelled', 'Used — Excellent', 'Standard shipping', 0),
    (26, 8, 1, 'BRAND NEW iPhone 99 Pro Max — GENUINE 100%',
        'Latest iPhone, factory sealed, ships from overseas. Best price on the site! No returns.',
        50.00, 50.00, NULL, 199.00,
        DATE_SUB(NOW(), INTERVAL 6 HOUR), DATE_ADD(NOW(), INTERVAL 1 DAY), 'active', 'New', 'International shipping', 0),
    (27, 8, 2, 'AUTHENTIC Designer Handbag — Best Price!!',
        'Real designer bag, 100% authentic, certificate available. DM for details. Ships fast.',
        30.00, 30.00, NULL, 89.00,
        DATE_SUB(NOW(), INTERVAL 12 HOUR), DATE_ADD(NOW(), INTERVAL 2 DAY), 'active', 'New', 'Standard shipping', 0),
    (28, 8, 6, 'Real Gold Rolex Submariner — LAST ONE',
        'Genuine Rolex, fully working, comes with box and papers. Buy now before it''s gone!',
        80.00, 80.00, NULL, 299.00,
        DATE_SUB(NOW(), INTERVAL 4 HOUR), DATE_ADD(NOW(), INTERVAL 3 DAY), 'active', 'Used', 'International shipping', 0);

INSERT INTO item_images (item_id, image_url, is_primary, display_order) VALUES
    (1,  'https://images.unsplash.com/photo-1516035069371-29a1b244cc32?w=800&q=80', 1, 0),
    (2,  'https://images.unsplash.com/photo-1544022613-e87ca75a784a?w=800&q=80',    1, 0),
    (3,  'https://images.unsplash.com/photo-1536924430914-91f9e2041b83?w=800&q=80', 1, 0),
    (4,  'https://images.unsplash.com/photo-1603561591411-07134e71a2a9?w=800&q=80', 1, 0),
    (5,  'https://images.unsplash.com/photo-1515703407324-5f51c8aea9fa?w=800&q=80', 1, 0),
    (6,  'https://images.unsplash.com/photo-1510915361894-db8b60106cb1?w=800&q=80', 1, 0),
    (7,  'https://images.unsplash.com/photo-1583394838336-acd977736f90?w=800&q=80', 1, 0),
    (8,  'https://images.unsplash.com/photo-1513506003901-1e6a229e2d15?w=800&q=80', 1, 0),
    (9,  'https://images.unsplash.com/photo-1558981403-c5f9899a28bc?w=800&q=80',    1, 0),
    (10, 'https://images.unsplash.com/photo-1549887534-1541e9326642?w=800&q=80',    1, 0),
    (11, 'https://images.unsplash.com/photo-1561154464-82e9adf32764?w=800&q=80',    1, 0),
    (12, 'https://images.unsplash.com/photo-1542272604-787c3835535d?w=800&q=80',    1, 0),
    (13, 'https://images.unsplash.com/photo-1600166898405-da9535204843?w=800&q=80', 1, 0),
    (14, 'https://images.unsplash.com/photo-1578926375605-eaf7559b1458?w=800&q=80', 1, 0),
    (15, 'https://images.unsplash.com/photo-1535632787350-4e68ef0ac584?w=800&q=80', 1, 0),
    (16, 'https://images.unsplash.com/photo-1622279457486-62dcc4a431d6?w=800&q=80', 1, 0),
    (17, 'https://images.unsplash.com/photo-1483478550801-ceba5fe50e8e?w=800&q=80', 1, 0),
    (18, 'https://images.unsplash.com/photo-1495121605193-b116b5b9c5fe?w=800&q=80', 1, 0),
    (19, 'https://images.unsplash.com/photo-1568772585407-9361f9bf3a87?w=800&q=80', 1, 0),
    (20, 'https://images.unsplash.com/photo-1527443224154-c4a3942d3acf?w=800&q=80', 1, 0),
    (21, 'https://images.unsplash.com/photo-1611591437281-460bfbe1220a?w=800&q=80', 1, 0),
    (22, 'https://images.unsplash.com/photo-1567538096630-e0c55bd6374c?w=800&q=80', 1, 0),
    (23, 'https://images.unsplash.com/photo-1556909114-f6e7ad7d3136?w=800&q=80',    1, 0),
    (24, 'https://images.unsplash.com/photo-1485965120184-e220f721d03e?w=800&q=80', 1, 0),
    (25, 'https://images.unsplash.com/photo-1539109136881-3be0616acf4b?w=800&q=80', 1, 0),
    (26, 'https://images.unsplash.com/photo-1592899677977-9c10ca588bbd?w=800&q=80', 1, 0),
    (27, 'https://images.unsplash.com/photo-1584917865442-de89df76afd3?w=800&q=80', 1, 0),
    (28, 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=800&q=80', 1, 0);

INSERT INTO bids (item_id, user_id, bid_amount, bid_time) VALUES
    (1, 1, 300.00, DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (1, 4, 350.00, DATE_SUB(NOW(), INTERVAL 20 HOUR)),
    (1, 1, 420.00, DATE_SUB(NOW(), INTERVAL 6 HOUR)),
    (1, 5, 460.00, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
    (3, 1, 120.00, DATE_SUB(NOW(), INTERVAL 18 HOUR)),
    (3, 4, 150.00, DATE_SUB(NOW(), INTERVAL 4 HOUR)),
    (4, 1, 400.00, DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (4, 4, 510.00, DATE_SUB(NOW(), INTERVAL 8 HOUR)),
    (4, 5, 560.00, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
    (6, 1, 780.00, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
    (6, 5, 820.00, DATE_SUB(NOW(), INTERVAL 30 MINUTE)),
    (8, 4, 90.00,  DATE_SUB(NOW(), INTERVAL 12 HOUR)),
    (8, 5, 105.00, DATE_SUB(NOW(), INTERVAL 4 HOUR)),
    (11, 1, 750.00, DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (11, 5, 820.00, DATE_SUB(NOW(), INTERVAL 18 HOUR)),
    (11, 4, 880.00, DATE_SUB(NOW(), INTERVAL 8 HOUR)),
    (11, 1, 950.00, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
    (13, 4, 320.00, DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (13, 5, 380.00, DATE_SUB(NOW(), INTERVAL 6 HOUR)),
    (17, 1, 55.00, DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (22, 5, 2300.00, DATE_SUB(NOW(), INTERVAL 18 HOUR)),
    (22, 1, 2350.00, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
    (10, 1, 200.00, DATE_SUB(NOW(), INTERVAL 4 DAY)),
    (10, 4, 280.00, DATE_SUB(NOW(), INTERVAL 3 DAY)),
    (10, 1, 350.00, DATE_SUB(NOW(), INTERVAL 2 DAY)),
    (10, 4, 380.00, DATE_SUB(NOW(), INTERVAL 36 HOUR)),
    (10, 1, 410.00, DATE_SUB(NOW(), INTERVAL 26 HOUR)),
    (19, 4, 3000.00, DATE_SUB(NOW(), INTERVAL 6 DAY)),
    (19, 5, 3500.00, DATE_SUB(NOW(), INTERVAL 4 DAY)),
    (19, 4, 3800.00, DATE_SUB(NOW(), INTERVAL 3 DAY)),
    (19, 5, 3950.00, DATE_SUB(NOW(), INTERVAL 50 HOUR)),
    (20, 1, 480.00, DATE_SUB(NOW(), INTERVAL 7 DAY)),
    (20, 4, 580.00, DATE_SUB(NOW(), INTERVAL 4 DAY)),
    (21, 1, 1900.00, DATE_SUB(NOW(), INTERVAL 9 DAY)),
    (21, 5, 2050.00, DATE_SUB(NOW(), INTERVAL 7 DAY)),
    (21, 1, 2100.00, DATE_SUB(NOW(), INTERVAL 6 DAY)),
    (21, 5, 2150.00, DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 1 HOUR);

INSERT INTO watchlists (user_id, item_id, created_at) VALUES
    (1, 9,  DATE_SUB(NOW(), INTERVAL 5 HOUR)),
    (1, 22, DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (1, 24, DATE_SUB(NOW(), INTERVAL 6 HOUR)),
    (1, 28, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
    (4, 1,  DATE_SUB(NOW(), INTERVAL 22 HOUR)),
    (4, 6,  DATE_SUB(NOW(), INTERVAL 18 HOUR)),
    (4, 11, DATE_SUB(NOW(), INTERVAL 9 HOUR)),
    (4, 22, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
    (5, 4,  DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (5, 13, DATE_SUB(NOW(), INTERVAL 7 HOUR)),
    (5, 22, DATE_SUB(NOW(), INTERVAL 1 HOUR));

INSERT INTO orders (item_id, buyer_id, amount, stripe_session_id, status, created_at, paid_at) VALUES
    (10, 1, 410.00,  'cs_test_seed_001_banksy',   'paid', DATE_SUB(NOW(), INTERVAL 26 HOUR), DATE_SUB(NOW(), INTERVAL 25 HOUR)),
    (19, 5, 3950.00, 'cs_test_seed_002_vespa',    'paid', DATE_SUB(NOW(), INTERVAL 47 HOUR), DATE_SUB(NOW(), INTERVAL 46 HOUR)),
    (20, 4, 580.00,  'cs_test_seed_003_macmini',  'paid', DATE_SUB(NOW(), INTERVAL 71 HOUR), DATE_SUB(NOW(), INTERVAL 70 HOUR)),
    (21, 5, 2150.00, 'cs_test_seed_004_bracelet', 'paid', DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 2 HOUR, DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 3 HOUR);

INSERT INTO ratings (order_id, rater_id, ratee_id, direction, score, comment, created_at) VALUES
    (1, 1, 2, 'buyer_to_seller', 5, 'Smooth transaction, packaging was perfect. Would buy again.', DATE_SUB(NOW(), INTERVAL 20 HOUR)),
    (1, 2, 1, 'seller_to_buyer', 5, 'Fast payment, great communication.',                            DATE_SUB(NOW(), INTERVAL 18 HOUR)),
    (2, 5, 7, 'buyer_to_seller', 5, 'Bike was exactly as described. Morgan even helped load it.', DATE_SUB(NOW(), INTERVAL 36 HOUR)),
    (2, 7, 5, 'seller_to_buyer', 5, 'Easy pickup, thanks!',                                        DATE_SUB(NOW(), INTERVAL 30 HOUR)),
    (3, 4, 7, 'buyer_to_seller', 4, 'Took a couple of days longer to ship than expected, but item is mint.', DATE_SUB(NOW(), INTERVAL 2 DAY)),
    (3, 7, 4, 'seller_to_buyer', 5, 'Paid right away, good buyer.',                                            DATE_SUB(NOW(), INTERVAL 47 HOUR)),
    (4, 5, 7, 'buyer_to_seller', 5, 'Stunning piece, appraisal matched. Highly recommend.', DATE_SUB(NOW(), INTERVAL 4 DAY)),
    (4, 7, 5, 'seller_to_buyer', 5, 'Repeat customer, always a pleasure.',                  DATE_SUB(NOW(), INTERVAL 4 DAY) + INTERVAL 2 HOUR);

INSERT INTO reports (reporter_id, item_id, reported_user_id, type, reason, details, status, created_at, resolved_at) VALUES
    (1, 26, NULL, 'listing', 'counterfeit',
        'Listing claims to be a "brand new iPhone 99 Pro Max" — Apple does not make this model. Almost certainly a scam.',
        'pending', DATE_SUB(NOW(), INTERVAL 3 HOUR), NULL),
    (4, 27, NULL, 'listing', 'counterfeit',
        'Generic stock-photo "designer handbag" with no brand named, asking for DMs. Classic counterfeit pattern.',
        'pending', DATE_SUB(NOW(), INTERVAL 6 HOUR), NULL),
    (5, 28, NULL, 'listing', 'counterfeit',
        '$80 starting price on a "real Rolex Submariner"? No serial in description, no box photos. Please remove.',
        'pending', DATE_SUB(NOW(), INTERVAL 2 HOUR), NULL),
    (1, NULL, 8, 'user', 'suspicious',
        'Same seller, three obvious counterfeit listings inside 24 hours. Recommend ban + listing removal.',
        'pending', DATE_SUB(NOW(), INTERVAL 1 HOUR), NULL),
    (4, 25, NULL, 'listing', 'misleading',
        'Seller marked the listing as cancelled after agreeing to sell privately, leaving bidders hanging.',
        'resolved', DATE_SUB(NOW(), INTERVAL 30 HOUR), DATE_SUB(NOW(), INTERVAL 24 HOUR)),
    (5, 7, NULL, 'listing', 'not_as_described',
        'Listing says "battery health 98%" but I think it might be lower.',
        'dismissed', DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 36 HOUR));

INSERT INTO notifications (user_id, type, title, body, item_id, href, is_read, created_at) VALUES
    (1, 'outbid',     'You were outbid on Vintage Leica M3 Rangefinder Camera',
        'sam@anyauction.test placed a higher bid of $460.00.', 1, '/auction/1', 0, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
    (1, 'outbid',     'You were outbid on 18k Gold Vintage Signet Ring',
        'A new bid of $560.00 has been placed.',                4, '/auction/4', 0, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
    (1, 'won',        'You won: Original Banksy-Style Stencil Print',
        'Final price $410.00. Complete checkout to receive your item.', 10, '/auction/10', 0, DATE_SUB(NOW(), INTERVAL 25 HOUR)),
    (1, 'order_paid', 'Payment received for Original Banksy-Style Stencil Print',
        'Your order is paid. The seller will arrange shipping.', 10, '/profile#tab-orders', 1, DATE_SUB(NOW(), INTERVAL 24 HOUR)),
    (2, 'sold',       'Your auction ended: Original Banksy-Style Stencil Print',
        'Final price $410.00. Buyer: Alex Morgan.', 10, '/profile#tab-sold', 0, DATE_SUB(NOW(), INTERVAL 25 HOUR)),
    (2, 'order_paid', 'Buyer paid for: Original Banksy-Style Stencil Print',
        'Funds confirmed. Please ship to the buyer.', 10, '/profile#tab-sold', 1, DATE_SUB(NOW(), INTERVAL 24 HOUR)),
    (7, 'sold',       'Your auction ended: 2008 Vespa GTS 250',
        'Final price $3,950.00. Buyer: Sam Thompson.', 19, '/profile#tab-sold', 1, DATE_SUB(NOW(), INTERVAL 47 HOUR)),
    (7, 'sold',       'Your auction ended: Apple Mac mini M2',
        'Final price $580.00. Buyer: Riley Chen.',     20, '/profile#tab-sold', 1, DATE_SUB(NOW(), INTERVAL 71 HOUR)),
    (7, 'sold',       'Your auction ended: Diamond Tennis Bracelet',
        'Final price $2,150.00. Buyer: Sam Thompson.', 21, '/profile#tab-sold', 0, DATE_SUB(NOW(), INTERVAL 5 DAY) + INTERVAL 1 HOUR),
    (3, 'admin_alert', 'New listing reports pending review',
        '4 reports are awaiting moderation. Visit the admin dashboard to triage.',
        NULL, '/admin', 0, DATE_SUB(NOW(), INTERVAL 1 HOUR));

SET FOREIGN_KEY_CHECKS = 1;
SEED_EOF

echo ""
echo "✓ Database reset. Demo logins:"
echo "    buyer@anyauction.test    / password123    (active buyer)"
echo "    seller@anyauction.test   / seller123      (active seller)"
echo "    admin@anyauction.test    / admin123       (admin)"
echo "    riley@anyauction.test    / password123    (second buyer)"
echo "    sam@anyauction.test      / password123    (buyer with rating history)"
echo "    taylor@anyauction.test   / password123    (second seller)"
echo "    morgan@anyauction.test   / password123    (seller with rating history)"
echo "    spammy@anyauction.test   / password123    (subject of pending reports)"
echo "    warned@anyauction.test   / password123    (admin warning on file)"
echo "    banned@anyauction.test   / password123    (banned — login should be blocked)"
