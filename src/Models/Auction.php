<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

final class Auction
{
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
            ORDER BY a.end_time ASC
            LIMIT :limit');
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * All active auctions, optionally filtered by category, with a sort mode.
     * Kept broad for the Browse page; filter/sort is driven by query string.
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

        $where  = "a.status = 'active' AND a.end_time > NOW()";
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
     *
     * @return array<int, array<string, mixed>>
     */
    public function adminAll(): array
    {
        $stmt = $this->db->query('
            SELECT a.item_id, a.title, a.current_price, a.status, a.category_id,
                   a.created_at, a.end_time,
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
            ORDER BY a.created_at DESC');
        return $stmt->fetchAll();
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
}
