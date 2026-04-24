-- AnyAuction — baseline seed data.
-- Demo credentials (email / password):
--   buyer@anyauction.test  / password123
--   seller@anyauction.test / seller123
--   admin@anyauction.test  / admin123
-- Password hashes below are bcrypt (PASSWORD_DEFAULT) so password_verify() will succeed.

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- Users
-- -----------------------------------------------------------------------------
INSERT INTO users (username, email, password_hash, first_name, last_name, profile_picture, role, is_verified) VALUES
    ('demobuyer',  'buyer@anyauction.test',  '$2y$10$aW9pFnpGGTlpNK.HMfHOLOSAD/lKQXzZEs4g4ySWEIGcSmah2zARC', 'Alex',  'Morgan',  'https://i.pravatar.cc/150?img=12', 'buyer',  1),
    ('demoseller', 'seller@anyauction.test', '$2y$10$Pr6ScO3Tf3zb8R/JV0i8KOga0fzVf1rPD9AsWiZP5gFySNZdbO0QO', 'Jamie', 'Rivera',  'https://i.pravatar.cc/150?img=32', 'seller', 1),
    ('demoadmin',  'admin@anyauction.test',  '$2y$10$S7RjaVqxNpHgGV5r1jWkh.hL20u2gzjRXnQ6h51TsSn1PMwWUsPBC', 'Casey', 'Donovan', 'https://i.pravatar.cc/150?img=48', 'admin',  1);

-- -----------------------------------------------------------------------------
-- Categories (icon names match Bootstrap Icons so templates can render them directly)
-- -----------------------------------------------------------------------------
INSERT INTO categories (name, slug, icon) VALUES
    ('Electronics', 'electronics', 'phone'),
    ('Fashion',     'fashion',     'bag'),
    ('Home',        'home',        'house'),
    ('Art',         'art',         'palette'),
    ('Vehicles',    'vehicles',    'car-front'),
    ('Jewelry',     'jewelry',     'gem'),
    ('Sports',      'sports',      'trophy'),
    ('Music',       'music',       'music-note-beamed');

-- -----------------------------------------------------------------------------
-- Auctions
-- start_time/end_time use NOW()-style offsets so the seeded auctions are always "ending soon".
-- -----------------------------------------------------------------------------
INSERT INTO auction_items
    (seller_id, category_id, title, description, starting_price, current_price, reserve_price, buy_now_price,
     start_time, end_time, status, `condition`, shipping, featured)
VALUES
    (2, 1, 'Vintage Leica M3 Rangefinder Camera',
        'A beautifully preserved Leica M3 from 1958. Fully functional, original leatherette, clean optics. Includes leather case and matching 50mm Summicron lens. A collector''s dream.',
        250.00, 420.00, 500.00, 950.00,
        DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL 2 DAY), 'active', 'Used — Excellent', 'Insured shipping worldwide', 1),

    (2, 2, 'Limited Edition Denim Jacket — Size M',
        'Small-batch selvedge denim jacket, worn twice. Copper rivets, chain-stitch hem, raw indigo that''ll fade beautifully. Size M, fits true.',
        45.00, 68.00, NULL, 120.00,
        DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 3 DAY), 'active', 'Like New', 'Standard shipping', 0),

    (2, 4, 'Hand-Painted Abstract Canvas (24"x36")',
        'Original acrylic on canvas by local artist. Warm earth tones with gold leaf accents. Signed and dated 2024. Arrives ready to hang.',
        80.00, 150.00, NULL, NULL,
        DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_ADD(NOW(), INTERVAL 1 DAY), 'active', 'New', 'Pickup or local delivery', 1),

    (2, 6, '18k Gold Vintage Signet Ring',
        'Solid 18k yellow gold signet ring, blank face ready for engraving. Hallmarked, weighs 9.2g. Size 9 (resizable).',
        300.00, 510.00, 600.00, 1100.00,
        DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL 4 DAY), 'active', 'Used — Very Good', 'Signature required on delivery', 1),

    (2, 7, 'Signed NHL Hockey Stick',
        'Professional game-used stick, signed on the shaft. Certificate of authenticity included. Great display piece for any hockey fan.',
        120.00, 175.00, NULL, NULL,
        DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 6 HOUR), 'active', 'Used', 'Standard shipping', 0),

    (2, 8, 'Fender Stratocaster — American Standard, 2012',
        'Sunburst finish, maple neck, recent setup with new 10s. Minor buckle rash on back, otherwise clean. Comes with hardshell case.',
        600.00, 780.00, 900.00, 1400.00,
        DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_ADD(NOW(), INTERVAL 5 DAY), 'active', 'Used — Good', 'Ships in original case', 1);

-- -----------------------------------------------------------------------------
-- Item images (Unsplash URLs — replace with your own once Cloudinary is wired up)
-- -----------------------------------------------------------------------------
INSERT INTO item_images (item_id, image_url, is_primary, display_order) VALUES
    (1, 'https://images.unsplash.com/photo-1516035069371-29a1b244cc32?w=800&q=80', 1, 0),
    (2, 'https://images.unsplash.com/photo-1544022613-e87ca75a784a?w=800&q=80',    1, 0),
    (3, 'https://images.unsplash.com/photo-1536924430914-91f9e2041b83?w=800&q=80', 1, 0),
    (4, 'https://images.unsplash.com/photo-1603561591411-07134e71a2a9?w=800&q=80', 1, 0),
    (5, 'https://images.unsplash.com/photo-1515703407324-5f51c8aea9fa?w=800&q=80', 1, 0),
    (6, 'https://images.unsplash.com/photo-1510915361894-db8b60106cb1?w=800&q=80', 1, 0);

-- -----------------------------------------------------------------------------
-- A few bids so the detail page has history from the start
-- -----------------------------------------------------------------------------
INSERT INTO bids (item_id, user_id, bid_amount, bid_time) VALUES
    (1, 1, 300.00, DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (1, 1, 420.00, DATE_SUB(NOW(), INTERVAL 6 HOUR)),
    (4, 1, 400.00, DATE_SUB(NOW(), INTERVAL 12 HOUR)),
    (4, 1, 510.00, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
    (6, 1, 780.00, DATE_SUB(NOW(), INTERVAL 3 HOUR));
