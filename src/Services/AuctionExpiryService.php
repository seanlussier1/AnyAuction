<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Sweeps time-expired active auctions, flips them to `closed`, and texts
 * the winning bidder. Idempotent — already-closed auctions are skipped,
 * and the status update uses a WHERE-status='active' guard so concurrent
 * heartbeat ticks can't double-fire.
 *
 * Called from HeartbeatController on every tick. The sweep is cheap when
 * nothing's expired (a single indexed SELECT), so the polling traffic
 * doesn't add real load.
 *
 * Buyout-closed auctions are explicitly skipped here — those went through
 * checkout and the displaced bidder was already texted via
 * NotificationService::notifyAuctionLost at buy time.
 */
final class AuctionExpiryService
{
    /** Cap each sweep to keep latency bounded if a long backlog accumulates. */
    private const SWEEP_BATCH_SIZE = 50;

    public function __construct(
        private readonly PDO $db,
        private readonly NotificationService $notifications
    ) {
    }

    /**
     * Returns the count of auctions newly marked closed and the count
     * actually notified (winners with verified phones who weren't opted out).
     *
     * @return array{processed: int, notified: int}
     */
    public function processExpired(): array
    {
        $stats = ['processed' => 0, 'notified' => 0];

        $candidates = $this->db->prepare(
            "SELECT a.item_id, a.title, a.current_price, a.buy_now_price,
                    (SELECT b.user_id FROM bids b
                       WHERE b.item_id = a.item_id
                       ORDER BY b.bid_amount DESC, b.bid_id DESC
                       LIMIT 1) AS winner_id,
                    (SELECT b.bid_amount FROM bids b
                       WHERE b.item_id = a.item_id
                       ORDER BY b.bid_amount DESC, b.bid_id DESC
                       LIMIT 1) AS winning_bid
             FROM auction_items a
             WHERE a.status = 'active'
               AND a.end_time <= NOW()
             LIMIT " . self::SWEEP_BATCH_SIZE
        );
        $candidates->execute();
        $rows = $candidates->fetchAll();
        if ($rows === []) {
            return $stats;
        }

        // Atomic claim per row so two concurrent sweeps can't both notify.
        $claim = $this->db->prepare(
            "UPDATE auction_items
             SET status = 'closed'
             WHERE item_id = :id AND status = 'active'"
        );

        foreach ($rows as $row) {
            $claim->execute(['id' => (int)$row['item_id']]);
            if ($claim->rowCount() === 0) {
                // Another worker beat us to it.
                continue;
            }
            $stats['processed']++;

            // No bids → no winner, just mark closed and move on.
            if ($row['winner_id'] === null) {
                continue;
            }

            // Buyout already closed this in the bid path — don't double-notify.
            if ($row['buy_now_price'] !== null
                && (float)$row['current_price'] >= (float)$row['buy_now_price']) {
                continue;
            }

            $this->notifications->notifyAuctionWon(
                (int)$row['winner_id'],
                (int)$row['item_id'],
                (float)$row['winning_bid']
            );
            $stats['notified']++;
        }

        return $stats;
    }
}
