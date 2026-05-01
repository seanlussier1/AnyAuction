<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use RuntimeException;

final class Bid
{
    /**
     * Last-minute snipe protection. A bid that lands inside the final
     * SNIPE_WINDOW seconds extends the auction's end_time so other bidders
     * get a chance to react. Tuned to the eBay/Heritage convention: short
     * trigger window, several-minute extension.
     */
    private const SNIPE_WINDOW_SECONDS    = 120;
    private const SNIPE_EXTENSION_SECONDS = 300;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Recent bids on an auction, newest first, joined with the bidder username.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forAuction(int $itemId, int $limit = 10): array
    {
        $stmt = $this->db->prepare('
            SELECT b.bid_id, b.bid_amount, b.bid_time,
                   u.username, u.profile_picture
            FROM bids b
            JOIN users u ON u.user_id = b.user_id
            WHERE b.item_id = :item_id
            ORDER BY b.bid_time DESC, b.bid_id DESC
            LIMIT :limit');
        $stmt->bindValue('item_id', $itemId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Atomically place a bid:
     *   - locks the auction row,
     *   - re-checks status + end_time + minimum amount,
     *   - inserts the bid,
     *   - updates the auction's current_price,
     *   - extends end_time if the bid lands inside the snipe window
     *     (skipped when the bid hits the buyout — the auction is sold).
     *
     * Throws RuntimeException on any validation failure so the controller
     * can surface a specific message.
     *
     * @return array{
     *   bid_id: int,
     *   amount: float,
     *   snipe_extended: bool,
     *   new_end_time: ?string,
     *   extension_seconds: int
     * }
     */
    public function place(int $itemId, int $userId, float $amount): array
    {
        if ($amount <= 0) {
            throw new RuntimeException('Bid amount must be positive.');
        }

        $this->db->beginTransaction();

        try {
            $lock = $this->db->prepare(
                'SELECT current_price, status, end_time, seller_id, buy_now_price
                 FROM auction_items
                 WHERE item_id = :id
                 FOR UPDATE'
            );
            $lock->execute(['id' => $itemId]);
            $auction = $lock->fetch();

            if (!$auction) {
                throw new RuntimeException('Auction not found.');
            }
            if ($auction['status'] !== 'active') {
                throw new RuntimeException('This auction is no longer active.');
            }
            if (strtotime($auction['end_time']) <= time()) {
                throw new RuntimeException('This auction has already ended.');
            }
            if ((int)$auction['seller_id'] === $userId) {
                throw new RuntimeException('You cannot bid on your own auction.');
            }

            // Silently cap at buyout price if set — mirrors the JS cap so a
            // direct POST bypassing the browser still can't exceed it.
            if ($auction['buy_now_price'] !== null && $amount > (float)$auction['buy_now_price']) {
                $amount = (float)$auction['buy_now_price'];
            }

            if ($amount <= (float)$auction['current_price']) {
                throw new RuntimeException(sprintf(
                    'Bid must be higher than the current price of $%s.',
                    number_format((float)$auction['current_price'], 2)
                ));
            }

            $insert = $this->db->prepare(
                'INSERT INTO bids (item_id, user_id, bid_amount)
                 VALUES (:item_id, :user_id, :amount)'
            );
            $insert->execute([
                'item_id' => $itemId,
                'user_id' => $userId,
                'amount'  => $amount,
            ]);
            $bidId = (int)$this->db->lastInsertId();

            $update = $this->db->prepare(
                'UPDATE auction_items SET current_price = :amount WHERE item_id = :id'
            );
            $update->execute(['amount' => $amount, 'id' => $itemId]);

            // Snipe protection: extend end_time if this bid lands in the
            // final SNIPE_WINDOW_SECONDS, but never if the buyout was hit —
            // that auction is sold, no extension applies. GREATEST guards
            // against accidentally shortening if the existing end_time is
            // somehow already further out than NOW + extension.
            $snipeExtended = false;
            $newEndTime    = null;
            $boughtOut     = $auction['buy_now_price'] !== null
                          && $amount >= (float)$auction['buy_now_price'];
            $remaining     = strtotime($auction['end_time']) - time();

            if (!$boughtOut && $remaining > 0 && $remaining <= self::SNIPE_WINDOW_SECONDS) {
                $extend = $this->db->prepare(
                    'UPDATE auction_items
                     SET end_time = GREATEST(end_time, NOW() + INTERVAL :secs SECOND)
                     WHERE item_id = :id'
                );
                $extend->execute([
                    'secs' => self::SNIPE_EXTENSION_SECONDS,
                    'id'   => $itemId,
                ]);

                $read = $this->db->prepare(
                    'SELECT end_time FROM auction_items WHERE item_id = :id'
                );
                $read->execute(['id' => $itemId]);
                $newEndTime    = (string)$read->fetchColumn();
                $snipeExtended = true;
            }

            $this->db->commit();
            return [
                'bid_id'            => $bidId,
                'amount'            => $amount,
                'snipe_extended'    => $snipeExtended,
                'new_end_time'      => $newEndTime,
                'extension_seconds' => self::SNIPE_EXTENSION_SECONDS,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
