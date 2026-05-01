<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Watchlist;
use PDO;

/**
 * Stub for the bid/buyout notification fan-out.
 *
 * Today this is a no-op so call sites in the bid/buyout controllers stay
 * stable. When the Twilio integration lands the body of `notifyWatchers`
 * fills in:
 *
 *   1. Pull each watcher's phone + opt-in flag (future
 *      `user_notification_prefs` table).
 *   2. POST to Twilio Programmable Messaging with a per-event template
 *      ("New bid on {title}: ${amount}", "{title} was bought out for
 *      ${amount}", etc.).
 *   3. Record delivery status / errors for retry.
 *
 * The bidder themselves should be excluded from the fan-out — pass their
 * user id via `$context['actor_id']`.
 */
final class NotificationService
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param  string                 $event   "bid", "buyout", "outbid", etc.
     * @param  array<string, mixed>   $context Free-form payload (amount, actor_id, …).
     */
    public function notifyWatchers(int $itemId, string $event, array $context = []): void
    {
        $watchers = (new Watchlist($this->db))->usersWatching($itemId);
        if ($watchers === []) {
            return;
        }
        $actorId = (int)($context['actor_id'] ?? 0);
        $watchers = array_values(array_filter($watchers, static fn ($uid) => $uid !== $actorId));

        // TODO(twilio): dispatch per-watcher SMS using $event + $context.
        // Intentionally a no-op until the Twilio credentials + phone-opt-in
        // table land. Keeping the call here so the bid/buyout flow already
        // hits the right hook.
        unset($watchers, $event);
    }

    /**
     * Fan-out to everyone who has bid on the auction (deduped, actor
     * excluded). Used today by snipe-extension events so prior bidders get
     * a heads-up that the auction was extended and they can counter-bid.
     *
     * @param  string               $event   "snipe_extension", "outbid", etc.
     * @param  array<string, mixed> $context Free-form payload — for snipe events
     *                                       includes amount, actor_id,
     *                                       extension_seconds, new_end_time.
     */
    public function notifyBidders(int $itemId, string $event, array $context = []): void
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT user_id FROM bids WHERE item_id = :id'
        );
        $stmt->execute(['id' => $itemId]);
        $bidders = array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
        if ($bidders === []) {
            return;
        }

        $actorId = (int)($context['actor_id'] ?? 0);
        $bidders = array_values(array_filter($bidders, static fn ($uid) => $uid !== $actorId));
        if ($bidders === []) {
            return;
        }

        // TODO(twilio): dispatch per-bidder SMS using $event + $context.
        // Snipe-extension template (when wired):
        //   "Heads up — someone just bid on {title}. The auction was
        //    extended by {extension_seconds/60} minutes and now ends at
        //    {new_end_time}. Reply STOP to opt out."
        unset($bidders, $event);
    }
}
