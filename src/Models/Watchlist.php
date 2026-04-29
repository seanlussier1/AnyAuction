<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

final class Watchlist
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function add(int $userId, int $itemId): void
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO watchlists (user_id, item_id) VALUES (:u, :i)'
        );
        $stmt->execute(['u' => $userId, 'i' => $itemId]);
    }

    public function remove(int $userId, int $itemId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM watchlists WHERE user_id = :u AND item_id = :i'
        );
        $stmt->execute(['u' => $userId, 'i' => $itemId]);
    }

    /**
     * Toggle membership. Returns true if the item is now watched, false if it
     * was just removed.
     */
    public function toggle(int $userId, int $itemId): bool
    {
        if ($this->isWatched($userId, $itemId)) {
            $this->remove($userId, $itemId);
            return false;
        }
        $this->add($userId, $itemId);
        return true;
    }

    public function isWatched(int $userId, int $itemId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM watchlists WHERE user_id = :u AND item_id = :i'
        );
        $stmt->execute(['u' => $userId, 'i' => $itemId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Item ids the user is watching — small payload for filling heart icons
     * across listing pages without an extra query per card.
     *
     * @return array<int, int>
     */
    public function idsForUser(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT item_id FROM watchlists WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Full auction-card rows for the user's watchlist, newest-saved first.
     * Sold items are kept (the user wanted to watch them; final outcome is
     * still useful information).
     *
     * @return array<int, array<string, mixed>>
     */
    public function forUser(int $userId): array
    {
        $stmt = $this->db->prepare('
            SELECT a.item_id, a.title, a.current_price, a.starting_price, a.buy_now_price,
                   a.reserve_price, a.end_time, a.featured, a.category_id,
                   (SELECT image_url FROM item_images
                      WHERE item_id = a.item_id
                      ORDER BY is_primary DESC, display_order ASC
                      LIMIT 1) AS primary_image,
                   (SELECT COUNT(*) FROM bids WHERE item_id = a.item_id) AS total_bids
            FROM auction_items a
            JOIN watchlists w ON w.item_id = a.item_id
            WHERE w.user_id = :u
            ORDER BY w.created_at DESC');
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * User ids watching the given item. Hook used by NotificationService to
     * fan out bid/buyout SMS once Twilio is wired.
     *
     * @return array<int, int>
     */
    public function usersWatching(int $itemId): array
    {
        $stmt = $this->db->prepare('SELECT user_id FROM watchlists WHERE item_id = :i');
        $stmt->execute(['i' => $itemId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
