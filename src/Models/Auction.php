<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

final class Auction
{
    /**
     * Predicate excluding bought-out auctions: a buyout was set, the current
     * price has reached or passed it, and at least one bid exists. Without
     * the bid check, listings created with starting_price == buy_now_price
     * (and no bids yet) would be falsely flagged as sold.
     */
    private const NOT_SOLD = '(a.buy_now_price IS NULL
        OR a.current_price < a.buy_now_price
        OR NOT EXISTS (SELECT 1 FROM bids b_ns WHERE b_ns.item_id = a.item_id))';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Active auctions flagged as featured, joined with primary image.
     *
     * @return array<int, array<string, mixed>>
     */
    public function featured(int $limit = 4): array
    {
        $stmt = $this->db->prepare($this->listingSelect() . '
            WHERE a.status = \'active\' AND a.featured = 1 AND a.end_time > NOW()
              AND ' . self::NOT_SOLD . '
            ORDER BY a.end_time ASC
            LIMIT :limit');
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Active auctions ordered by soonest-ending.
     *
     * @return array<int, array<string, mixed>>
     */
    public function endingSoon(int $limit = 4): array
    {
        $stmt = $this->db->prepare($this->listingSelect() . '
            WHERE a.status = \'active\' AND a.end_time > NOW()
              AND ' . self::NOT_SOLD . '
            ORDER BY a.end_time ASC
            LIMIT :limit');
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * All active auctions, optionally filtered by category, with a sort mode.
     * Kept broad for the Browse page; filter/sort is driven by query string.
     * Excludes bought-out items — those live in the buyer's "won" or seller's
     * "sold" profile tabs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function browse(?int $categoryId = null, string $sort = 'ending'): array
    {
        $orderBy = match ($sort) {
            'price-low'  => 'a.current_price ASC',
            'price-high' => 'a.current_price DESC',
            'newest'     => 'a.created_at DESC',
            default      => 'a.end_time ASC',
        };

        $where  = "a.status = 'active' AND a.end_time > NOW() AND " . self::NOT_SOLD;
        $params = [];
        if ($categoryId !== null) {
            $where       .= ' AND a.category_id = :cat';
            $params['cat'] = $categoryId;
        }

        $stmt = $this->db->prepare($this->listingSelect() . ' WHERE ' . $where . ' ORDER BY ' . $orderBy);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Listings the seller has sold via buyout. Mirrors the template's
     * is_sold rule: buy-now set, current_price >= buy_now_price, has bids.
     *
     * @return array<int, array<string, mixed>>
     */
    public function soldBySeller(int $sellerId): array
    {
        $stmt = $this->db->prepare($this->listingSelect() . '
            WHERE a.seller_id = :sid
              AND a.buy_now_price IS NOT NULL
              AND a.current_price >= a.buy_now_price
              AND EXISTS (SELECT 1 FROM bids b WHERE b.item_id = a.item_id)
            ORDER BY a.updated_at DESC');
        $stmt->execute(['sid' => $sellerId]);
        return $stmt->fetchAll();
    }

    /**
     * Auctions the user won — sold AND their bid equals the final price.
     * The `Bid::place` lock guarantees only one user can hold a bid equal
     * to current_price = buy_now_price.
     *
     * @return array<int, array<string, mixed>>
     */
    public function wonBy(int $userId): array
    {
        $stmt = $this->db->prepare($this->listingSelect() . '
            WHERE a.buy_now_price IS NOT NULL
              AND a.current_price >= a.buy_now_price
              AND EXISTS (
                SELECT 1 FROM bids b
                WHERE b.item_id = a.item_id
                  AND b.user_id = :uid
                  AND b.bid_amount = a.current_price
              )
            ORDER BY a.updated_at DESC');
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * All auctions for a given seller, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function bySeller(int $sellerId): array
    {
        $stmt = $this->db->prepare($this->listingSelect() . '
            WHERE a.seller_id = :sid
            ORDER BY a.created_at DESC');
        $stmt->execute(['sid' => $sellerId]);
        return $stmt->fetchAll();
    }

    /**
     * All auctions the given user has placed at least one bid on (de-duped).
     *
     * @return array<int, array<string, mixed>>
     */
    public function withBidsFrom(int $userId): array
    {
        $stmt = $this->db->prepare($this->listingSelect() . '
            WHERE a.item_id IN (SELECT DISTINCT item_id FROM bids WHERE user_id = :uid)
            ORDER BY a.end_time ASC');
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Admin listings table view — every auction with minimal fields + seller name.
     * Includes a derived `display_status` so the UI can render closed/expired
     * auctions as "inactive" without relying on the row's actual status.
     *
     * The $statusFilter narrows the rows by lifecycle state:
     *   - "active":    open auctions whose end_time is still in the future (default)
     *   - "inactive":  closed listings or active listings whose end_time has passed
     *   - "cancelled": admin-removed listings
     *   - "all" (or anything else): no filter
     *
     * @return array<int, array<string, mixed>>
     */
    public function adminAll(string $statusFilter = 'active'): array
    {
        $where = match ($statusFilter) {
            'active'    => "WHERE a.status = 'active' AND a.end_time > NOW()",
            'inactive'  => "WHERE a.status = 'closed' OR (a.status = 'active' AND a.end_time <= NOW())",
            'cancelled' => "WHERE a.status = 'cancelled'",
            default     => '',
        };

        $stmt = $this->db->query("
            SELECT a.item_id, a.title, a.current_price, a.status, a.category_id,
                   a.created_at, a.end_time,
                   CASE
                       WHEN a.status = 'active' AND a.end_time <= NOW() THEN 'inactive'
                       WHEN a.status = 'closed' THEN 'inactive'
                       ELSE a.status
                   END AS display_status,
                   (SELECT COUNT(*) FROM bids WHERE item_id = a.item_id) AS total_bids,
                   u.username AS seller_username,
                   c.name     AS category_name,
                   (SELECT image_url FROM item_images
                      WHERE item_id = a.item_id
                      ORDER BY is_primary DESC, display_order ASC
                      LIMIT 1) AS primary_image
            FROM auction_items a
            JOIN users      u ON u.user_id     = a.seller_id
            JOIN categories c ON c.category_id = a.category_id
            $where
            ORDER BY a.created_at DESC
        ");

        return $stmt->fetchAll();
    }

    /**
     * Flip every active auction whose end_time has passed to status='closed'.
     * Returns the number of rows updated. Idempotent — safe to call repeatedly.
     */
    public function closeExpired(): int
    {
        $stmt = $this->db->prepare(
            "UPDATE auction_items
                SET status = 'closed'
              WHERE status = 'active' AND end_time <= NOW()"
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Admin moderation: cancel a listing so it disappears from the marketplace.
     * Returns true if a row was actually flipped (false if already cancelled).
     */
    public function adminRemove(int $itemId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE auction_items
                SET status = 'cancelled'
              WHERE item_id = :id AND status <> 'cancelled'"
        );
        $stmt->execute(['id' => $itemId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Count active auctions (for Admin overview).
     */
    public function countActive(): int
    {
        return (int)$this->db->query(
            "SELECT COUNT(*) FROM auction_items WHERE status = 'active' AND end_time > NOW()"
        )->fetchColumn();
    }

    /**
     * Listings-per-category for Admin overview.
     *
     * @return array<int, array{category_id: int, name: string, count: int}>
     */
    public function countByCategory(): array
    {
        $stmt = $this->db->query('
            SELECT c.category_id, c.name, COUNT(a.item_id) AS count
            FROM categories c
            LEFT JOIN auction_items a ON a.category_id = c.category_id
            GROUP BY c.category_id, c.name
            ORDER BY c.name');
        return $stmt->fetchAll();
    }

    /**
     * Full detail view for an auction — seller info, category, primary image.
     *
     * @return array<string, mixed>|null
     */
    public function findWithDetails(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT a.*,
                   u.username          AS seller_username,
                   u.first_name        AS seller_first_name,
                   u.last_name         AS seller_last_name,
                   u.profile_picture   AS seller_profile_picture,
                   u.is_verified       AS seller_is_verified,
                   u.created_at        AS seller_created_at,
                   c.name              AS category_name,
                   c.slug              AS category_slug,
                   (SELECT image_url FROM item_images
                      WHERE item_id = a.item_id
                      ORDER BY is_primary DESC, display_order ASC
                      LIMIT 1)          AS primary_image,
                   (SELECT COUNT(*) FROM bids WHERE item_id = a.item_id) AS total_bids
            FROM auction_items a
            JOIN users       u ON u.user_id     = a.seller_id
            JOIN categories  c ON c.category_id = a.category_id
            WHERE a.item_id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Active listings whose item_id is greater than $sinceId — used by the
     * live-update poller to prepend brand-new listings into the grid
     * without a full reload. Optionally filtered by category to match the
     * page the user is viewing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sinceForFeed(int $sinceId, ?int $categoryId = null, int $limit = 24): array
    {
        $where  = "a.status = 'active' AND a.end_time > NOW() AND a.item_id > :since AND " . self::NOT_SOLD;
        $params = ['since' => $sinceId];
        if ($categoryId !== null) {
            $where .= ' AND a.category_id = :cat';
            $params['cat'] = $categoryId;
        }

        $stmt = $this->db->prepare($this->listingSelect()
            . ' WHERE ' . $where
            . ' ORDER BY a.item_id DESC LIMIT :lim');
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, PDO::PARAM_INT);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Live-search active auctions by title prefix/contains. LIKE wildcards in
     * the user's query are escaped so a literal % or _ is treated as text.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $q, int $limit = 8): array
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);

        $stmt = $this->db->prepare(
            "SELECT a.item_id, a.title, a.current_price, a.end_time,
                    (SELECT image_url FROM item_images
                       WHERE item_id = a.item_id
                       ORDER BY is_primary DESC, display_order ASC
                       LIMIT 1) AS primary_image
             FROM auction_items a
             WHERE a.status = 'active'
               AND a.end_time > NOW()
               AND a.title LIKE :needle
               AND " . self::NOT_SOLD . "
             ORDER BY a.end_time ASC
             LIMIT :lim"
        );
        $stmt->bindValue(':needle', '%' . $escaped . '%');
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * All images for an auction, primary first then by display order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function imagesFor(int $itemId): array
    {
        $stmt = $this->db->prepare(
            'SELECT image_id, image_url, is_primary, display_order
             FROM item_images
             WHERE item_id = :id
             ORDER BY is_primary DESC, display_order ASC, image_id ASC'
        );
        $stmt->execute(['id' => $itemId]);
        return $stmt->fetchAll();
    }

    public function updateCurrentPrice(int $itemId, float $amount): void
    {
        $stmt = $this->db->prepare(
            'UPDATE auction_items SET current_price = :amount WHERE item_id = :id'
        );
        $stmt->execute(['amount' => $amount, 'id' => $itemId]);
    }

     /**
     * Shared SELECT for card-listing queries (home + ending-soon).
     */
    private function listingSelect(): string
    {
        return 'SELECT a.item_id, a.title, a.current_price, a.starting_price, a.buy_now_price,
                       a.reserve_price, a.end_time, a.featured, a.category_id,
                       (SELECT image_url FROM item_images
                          WHERE item_id = a.item_id
                          ORDER BY is_primary DESC, display_order ASC
                          LIMIT 1) AS primary_image,
                       (SELECT COUNT(*) FROM bids WHERE item_id = a.item_id) AS total_bids
                FROM auction_items a';
    }

    public function create(int $sellerId, array $data): int
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare('
                INSERT INTO auction_items (
                    seller_id, category_id, title, description, starting_price, current_price,
                    reserve_price, buy_now_price, start_time, end_time, status, `condition`, shipping
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY), \'active\', ?, ?)
            ');

            $stmt->execute([
                $sellerId,
                $data['category_id'],
                trim($data['title']),
                trim($data['description']),
                $data['starting_price'],
                $data['starting_price'], // current_price starts as starting_price
                $data['reserve_price'] ?? null,
                $data['buy_now_price'] ?? null,
                $data['duration'],
                $data['condition'] ?? null,
                $data['shipping'] ?? null,
            ]);

            $auctionId = (int)$this->db->lastInsertId();

            $this->db->commit();
            return $auctionId;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw new \RuntimeException('Failed to create auction: ' . $e->getMessage());
        }
    }
}
