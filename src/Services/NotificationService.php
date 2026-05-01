<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SmsConversation;
use App\Models\Watchlist;
use PDO;

/**
 * Bid/buyout notification fan-out.
 *
 * Today the watchlist + bidder fan-outs are still no-ops (those go through a
 * future opt-in/throttle table). The outbid path IS live — when a buyer is
 * displaced as the high bidder we send them an SMS that opens an inbound
 * conversation in `sms_conversations`, so they can text back a counter-bid.
 *
 * The bidder themselves should be excluded from the fan-out — pass their
 * user id via `$context['actor_id']`.
 */
final class NotificationService
{
    /** Outbid conversations live for 30 minutes — long enough to glance at
     *  a phone and reply, short enough that a stale row from yesterday
     *  doesn't get matched against an unrelated text. */
    private const OUTBID_CONVERSATION_TTL_SECONDS = 1800;

    public function __construct(
        private readonly PDO $db,
        private readonly TwilioService $twilio
    ) {
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
        // Intentionally a no-op until the per-user opt-in/throttle table
        // lands. Keeping the call here so the bid/buyout flow already hits
        // the right hook.
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

    /**
     * Tell the displaced high bidder they've been outbid. We:
     *   1. Look up their phone + opt-in. Skip silently if unverified or opted out.
     *   2. Open (or replace) an `sms_conversations` row in `waiting_amount` state
     *      so a follow-up reply with a dollar amount routes through the
     *      Twilio webhook back into Bid::place.
     *   3. Send the SMS via TwilioService (which is itself a silent no-op when
     *      Twilio creds are unset, so dev environments don't 500).
     *
     * Caller is responsible for not invoking when previous_bidder_id matches
     * the new bidder (e.g. raising your own max bid shouldn't text yourself).
     */
    public function notifyOutbid(int $itemId, int $previousBidderId, float $newAmount): void
    {
        $userStmt = $this->db->prepare(
            'SELECT phone, phone_verified_at, sms_opt_out
             FROM users
             WHERE user_id = :id'
        );
        $userStmt->execute(['id' => $previousBidderId]);
        $user = $userStmt->fetch();
        if (!$user) {
            return;
        }

        $phone = (string)($user['phone'] ?? '');
        if ($phone === '' || $user['phone_verified_at'] === null) {
            return;
        }
        if ((int)$user['sms_opt_out'] === 1) {
            return;
        }

        $titleStmt = $this->db->prepare(
            'SELECT title FROM auction_items WHERE item_id = :id'
        );
        $titleStmt->execute(['id' => $itemId]);
        $title = (string)$titleStmt->fetchColumn();
        if ($title === '') {
            return;
        }

        // Open the conversation BEFORE sending the SMS so the buyer's reply
        // never races a missing row. INSERT … ON DUPLICATE KEY UPDATE means
        // a fresh outbid resets any half-completed prior conversation.
        (new SmsConversation($this->db))->upsertWaitingAmount(
            $phone,
            $previousBidderId,
            $itemId,
            self::OUTBID_CONVERSATION_TTL_SECONDS
        );

        $body = sprintf(
            "AnyAuction: a buyer outbid you on '%s' at $%s. Reply with a new bid amount or STOP to opt out.",
            $title,
            number_format($newAmount, 2)
        );

        $this->twilio->sendSms($phone, $body);
    }

    /**
     * Tell the displaced high bidder that the auction was bought out — game
     * over, no follow-up bid possible. Like notifyOutbid but does NOT open
     * an `sms_conversations` row (there's nothing to reply to) and uses a
     * "sold" message instead of the "rebid" prompt.
     *
     * Caller is responsible for not invoking when the displaced bidder
     * matches the buyer (raising your own max bid into the buyout).
     */
    public function notifyAuctionLost(int $itemId, int $previousBidderId, float $finalAmount): void
    {
        $userStmt = $this->db->prepare(
            'SELECT phone, phone_verified_at, sms_opt_out
             FROM users
             WHERE user_id = :id'
        );
        $userStmt->execute(['id' => $previousBidderId]);
        $user = $userStmt->fetch();
        if (!$user) {
            return;
        }

        $phone = (string)($user['phone'] ?? '');
        if ($phone === '' || $user['phone_verified_at'] === null) {
            return;
        }
        if ((int)$user['sms_opt_out'] === 1) {
            return;
        }

        $titleStmt = $this->db->prepare(
            'SELECT title FROM auction_items WHERE item_id = :id'
        );
        $titleStmt->execute(['id' => $itemId]);
        $title = (string)$titleStmt->fetchColumn();
        if ($title === '') {
            return;
        }

        // No conversation row — auction is sold, no further bidding possible.
        // If a stale waiting_* row exists for this phone (from an earlier
        // outbid), clear it so a future text doesn't try to bid on this
        // closed auction.
        (new \App\Models\SmsConversation($this->db))->delete($phone);

        $body = sprintf(
            "AnyAuction: '%s' was bought out for $%s. The auction is closed — better luck next time! Reply STOP to opt out.",
            $title,
            number_format($finalAmount, 2)
        );

        $this->twilio->sendSms($phone, $body);
    }
}
