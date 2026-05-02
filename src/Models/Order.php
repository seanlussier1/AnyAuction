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
     * Buyer's order history with the joined auction title + image. For PAID
     * orders we also surface the seller's contact info so the buyer can
     * reach out about shipping etc. Phone is only included when paid_at IS
     * NOT NULL — pending orders don't get contact details.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forBuyer(int $buyerId): array
    {
        $stmt = $this->db->prepare("
            SELECT o.order_id, o.item_id, o.amount, o.status, o.created_at, o.paid_at,
                   a.title, a.seller_id,
                   s.username   AS seller_username,
                   s.first_name AS seller_first_name,
                   s.last_name  AS seller_last_name,
                   CASE WHEN o.status IN ('pending','paid') THEN s.phone ELSE NULL END AS seller_phone,
                   CASE WHEN o.status IN ('pending','paid') THEN s.email ELSE NULL END AS seller_email,
                   (SELECT image_url FROM item_images
                      WHERE item_id = a.item_id
                      ORDER BY is_primary DESC, display_order ASC
                      LIMIT 1) AS primary_image
            FROM orders o
            JOIN auction_items a ON a.item_id = o.item_id
            JOIN users         s ON s.user_id = a.seller_id
            WHERE o.buyer_id = :buyer
            ORDER BY o.created_at DESC");
        $stmt->execute(['buyer' => $buyerId]);
        return $stmt->fetchAll();
    }

    /**
     * Seller's order history with the joined buyer + item details. PAID
     * rows include the buyer's phone/email so the seller can coordinate
     * shipping. Pending rows withhold contact info.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forSeller(int $sellerId): array
    {
        $stmt = $this->db->prepare("
            SELECT o.order_id, o.item_id, o.buyer_id, o.amount, o.status,
                   o.created_at, o.paid_at,
                   a.title,
                   u.username   AS buyer_username,
                   u.first_name AS buyer_first_name,
                   u.last_name  AS buyer_last_name,
                   CASE WHEN o.status IN ('pending','paid') THEN u.phone ELSE NULL END AS buyer_phone,
                   CASE WHEN o.status IN ('pending','paid') THEN u.email ELSE NULL END AS buyer_email,
                   (SELECT image_url FROM item_images
                      WHERE item_id = a.item_id
                      ORDER BY is_primary DESC, display_order ASC
                      LIMIT 1) AS primary_image
            FROM orders o
            JOIN auction_items a ON a.item_id = o.item_id
            JOIN users         u ON u.user_id = o.buyer_id
            WHERE a.seller_id = :seller
            ORDER BY o.created_at DESC");
        $stmt->execute(['seller' => $sellerId]);
        return $stmt->fetchAll();
    }

    /**
     * Paid orders that involve both users in either direction (one as buyer
     * and the other as seller). Used to surface a rate-the-other-party
     * button on a public profile when the viewer has transacted with the
     * profile owner.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forParticipants(int $userA, int $userB): array
    {
        // Each placeholder used once — emulated prepares are off in
        // config/container.php, so re-using :a/:b would fail with HY093.
        $stmt = $this->db->prepare('
            SELECT o.order_id, o.item_id, o.buyer_id, o.amount, o.status,
                   o.created_at, o.paid_at,
                   a.seller_id, a.title,
                   (SELECT image_url FROM item_images
                      WHERE item_id = a.item_id
                      ORDER BY is_primary DESC, display_order ASC
                      LIMIT 1) AS primary_image
            FROM orders o
            JOIN auction_items a ON a.item_id = o.item_id
            WHERE o.status = \'paid\'
              AND ((o.buyer_id = :a1 AND a.seller_id = :b1)
                OR (o.buyer_id = :b2 AND a.seller_id = :a2))
            ORDER BY o.created_at DESC');
        $stmt->execute([
            'a1' => $userA, 'b1' => $userB,
            'a2' => $userA, 'b2' => $userB,
        ]);
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
