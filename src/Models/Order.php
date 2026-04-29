<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

final class Order
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function create(int $itemId, int $buyerId, float $amount, string $sessionId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO orders (item_id, buyer_id, amount, stripe_session_id, status)
             VALUES (:item, :buyer, :amount, :sid, :status)'
        );
        $stmt->execute([
            'item'   => $itemId,
            'buyer'  => $buyerId,
            'amount' => $amount,
            'sid'    => $sessionId,
            'status' => 'pending',
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function findBySession(string $sessionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM orders WHERE stripe_session_id = :sid'
        );
        $stmt->execute(['sid' => $sessionId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function existingForItemAndBuyer(int $itemId, int $buyerId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM orders
             WHERE item_id = :item AND buyer_id = :buyer
             ORDER BY order_id DESC
             LIMIT 1'
        );
        $stmt->execute(['item' => $itemId, 'buyer' => $buyerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function markPaid(int $orderId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE orders SET status = 'paid', paid_at = NOW() WHERE order_id = :id"
        );
        $stmt->execute(['id' => $orderId]);
    }

    public function markCancelled(int $orderId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE orders SET status = 'cancelled' WHERE order_id = :id AND status = 'pending'"
        );
        $stmt->execute(['id' => $orderId]);
    }

    /**
     * Buyer's order history with the joined auction title + image.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forBuyer(int $buyerId): array
    {
        $stmt = $this->db->prepare('
            SELECT o.order_id, o.item_id, o.amount, o.status, o.created_at, o.paid_at,
                   a.title,
                   (SELECT image_url FROM item_images
                      WHERE item_id = a.item_id
                      ORDER BY is_primary DESC, display_order ASC
                      LIMIT 1) AS primary_image
            FROM orders o
            JOIN auction_items a ON a.item_id = o.item_id
            WHERE o.buyer_id = :buyer
            ORDER BY o.created_at DESC');
        $stmt->execute(['buyer' => $buyerId]);
        return $stmt->fetchAll();
    }

    /**
     * Has this item already been paid for? Used to gate the checkout-start
     * action so the same auction can't be charged twice.
     */
    public function isItemPaid(int $itemId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM orders WHERE item_id = :item AND status = 'paid' LIMIT 1"
        );
        $stmt->execute(['item' => $itemId]);
        return (bool)$stmt->fetchColumn();
    }
}
