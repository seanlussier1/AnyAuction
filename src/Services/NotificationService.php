<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Notification;
use App\Models\SmsConversation;
use App\Models\Watchlist;
use PDO;

/**
 * Bid/buyout notification fan-out.
 *
 * Two channels:
 *   - SMS (Twilio) — only fires when the user has a verified phone and
 *     hasn't opted out via STOP.
 *   - In-site feed — always fires, persisted in the `notifications` table,
 *     surfaced on the profile Notifications tab + bell badge.
 *
 * The bidder/buyer themselves should be excluded from the fan-out — pass
 * their user id via `$context['actor_id']`.
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
        if ($watchers === []) {
            return;
        }

        $title = $this->lookupTitle($itemId);
        if ($title === '') {
            return;
        }

        $amount = isset($context['amount']) ? (float)$context['amount'] : 0.0;
        [$ntype, $heading, $body] = match ($event) {
            'bid'    => ['watchlist_bid',    'New bid on a watched item',    sprintf("Someone bid \$%s on '%s'.", number_format($amount, 2), $title)],
            'buyout' => ['watchlist_buyout', 'Watched item was bought out',  sprintf("'%s' was bought out for \$%s.", $title, number_format($amount, 2))],
            default  => ['watchlist_event',  'Watched item update',          sprintf("There's an update on '%s'.", $title)],
        };
        $href = '/auction/' . $itemId;

        $notif = new Notification($this->db);
        foreach ($watchers as $uid) {
            $notif->create((int)$uid, $ntype, $heading, $body, $itemId, $href);
        }

        // SMS path is intentionally still a no-op for watchers — too noisy.
    }

    /**
     * Fan-out to everyone who has bid on the auction (deduped, actor
     * excluded). Used by snipe-extension events so prior bidders get
     * a heads-up that the auction was extended and they can counter-bid.
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

        $title = $this->lookupTitle($itemId);
        if ($title === '') {
            return;
        }

        if ($event === 'snipe_extension') {
            $extMin = (int)round(((int)($context['extension_seconds'] ?? 300)) / 60);
            $heading = 'Auction extended';
            $body    = sprintf(
                "A last-minute bid extended '%s' by %d more minutes. Get back in the action.",
                $title,
                $extMin
            );
            $ntype = 'snipe_extended';
            $href  = '/auction/' . $itemId;

            $notif = new Notification($this->db);
            foreach ($bidders as $uid) {
                $notif->create((int)$uid, $ntype, $heading, $body, $itemId, $href);
            }
        }

        // SMS path: still a TODO — too noisy without per-user opt-in.
    }

    /**
     * Tell the displaced high bidder they've been outbid (DB + SMS). The
     * SMS opens an inbound conversation so the user can text back a
     * counter-bid via the Twilio webhook.
     */
    public function notifyOutbid(int $itemId, int $previousBidderId, float $newAmount): void
    {
        $user  = $this->lookupUser($previousBidderId);
        $title = $this->lookupTitle($itemId);
        if ($user === null || $title === '') {
            return;
        }

        // Always record the in-site notification, regardless of SMS prefs.
        (new Notification($this->db))->create(
            $previousBidderId,
            'outbid',
            'You were outbid',
            sprintf("Someone bid \$%s on '%s'. You can place a higher bid to take the lead.", number_format($newAmount, 2), $title),
            $itemId,
            '/auction/' . $itemId
        );

        if (!$this->canSms($user)) {
            return;
        }
        $phone = (string)$user['phone'];

        // Open the conversation BEFORE sending the SMS so the buyer's reply
        // never races a missing row. INSERT … ON DUPLICATE KEY UPDATE means
        // a fresh outbid resets any half-completed prior conversation.
        (new SmsConversation($this->db))->upsertWaitingAmount(
            $phone,
            $previousBidderId,
            $itemId,
            self::OUTBID_CONVERSATION_TTL_SECONDS
        );

        $this->twilio->sendSms(
            $phone,
            sprintf(
                "AnyAuction: a buyer outbid you on '%s' at $%s. Reply with a new bid amount or STOP to opt out.",
                $title,
                number_format($newAmount, 2)
            )
        );
    }

    /**
     * Tell the displaced high bidder that the auction was bought out —
     * game over, no follow-up bid possible.
     */
    public function notifyAuctionLost(int $itemId, int $previousBidderId, float $finalAmount): void
    {
        $user  = $this->lookupUser($previousBidderId);
        $title = $this->lookupTitle($itemId);
        if ($user === null || $title === '') {
            return;
        }

        (new Notification($this->db))->create(
            $previousBidderId,
            'auction_lost',
            'Auction bought out',
            sprintf("'%s' was bought out for \$%s. The auction is now closed.", $title, number_format($finalAmount, 2)),
            $itemId,
            '/auction/' . $itemId
        );

        if (!$this->canSms($user)) {
            return;
        }
        $phone = (string)$user['phone'];

        // Clear any stale waiting_* row so future texts don't try to bid on
        // this closed auction.
        (new SmsConversation($this->db))->delete($phone);

        $this->twilio->sendSms(
            $phone,
            sprintf(
                "AnyAuction: '%s' was bought out for $%s. The auction is closed — better luck next time! Reply STOP to opt out.",
                $title,
                number_format($finalAmount, 2)
            )
        );
    }

    /**
     * Tell the winning bidder that a time-expired auction closed in their
     * favour. One-shot terminal message — no follow-up SMS bidding.
     */
    public function notifyAuctionWon(int $winnerId, int $itemId, float $winningBid): void
    {
        $user  = $this->lookupUser($winnerId);
        $title = $this->lookupTitle($itemId);
        if ($user === null || $title === '') {
            return;
        }

        (new Notification($this->db))->create(
            $winnerId,
            'auction_won',
            'You won an auction!',
            sprintf("Congrats — '%s' is yours at \$%s. Check your profile for next steps.", $title, number_format($winningBid, 2)),
            $itemId,
            '/auction/' . $itemId
        );

        if (!$this->canSms($user)) {
            return;
        }
        $phone = (string)$user['phone'];

        (new SmsConversation($this->db))->delete($phone);

        $this->twilio->sendSms(
            $phone,
            sprintf(
                "AnyAuction: you won '%s' at $%s! Check your profile for next steps. Reply STOP to opt out.",
                $title,
                number_format($winningBid, 2)
            )
        );
    }

    /**
     * Site-only: tell the seller someone placed a bid on their listing.
     * No SMS — sellers get the digest in the Notifications tab.
     */
    public function notifyBidReceived(int $sellerId, int $itemId, int $bidderId, float $amount): void
    {
        if ($sellerId === $bidderId) {
            return;
        }
        $title = $this->lookupTitle($itemId);
        if ($title === '') {
            return;
        }

        (new Notification($this->db))->create(
            $sellerId,
            'bid_received',
            'New bid on your listing',
            sprintf("A buyer bid \$%s on '%s'.", number_format($amount, 2), $title),
            $itemId,
            '/auction/' . $itemId
        );
    }

    /**
     * Site-only: tell the seller their item was bought out.
     */
    public function notifyItemSold(int $sellerId, int $itemId, float $finalAmount): void
    {
        $title = $this->lookupTitle($itemId);
        if ($title === '') {
            return;
        }

        (new Notification($this->db))->create(
            $sellerId,
            'item_sold',
            'Your item sold',
            sprintf("'%s' was bought out for \$%s. Watch your dashboard for the payout.", $title, number_format($finalAmount, 2)),
            $itemId,
            '/auction/' . $itemId
        );
    }

    /**
     * Site-only: tell the seller a buyer's payment cleared on Stripe.
     */
    public function notifyOrderPaid(int $sellerId, int $itemId, float $amount): void
    {
        $title = $this->lookupTitle($itemId);
        if ($title === '') {
            return;
        }

        (new Notification($this->db))->create(
            $sellerId,
            'order_paid',
            'Payment received',
            sprintf("Buyer paid \$%s for '%s'. Time to ship!", number_format($amount, 2), $title),
            $itemId,
            '/profile'
        );
    }

    /**
     * Site-only: friendly welcome on signup.
     */
    public function notifyWelcome(int $userId, string $firstName): void
    {
        (new Notification($this->db))->create(
            $userId,
            'welcome',
            'Welcome to AnyAuction!',
            sprintf("Hey %s — your account is ready. Add items to your watchlist or start a listing whenever you're ready.", $firstName),
            null,
            '/browse'
        );
    }

    private function lookupUser(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT phone, phone_verified_at, sms_opt_out
             FROM users
             WHERE user_id = :id'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function lookupTitle(int $itemId): string
    {
        $stmt = $this->db->prepare('SELECT title FROM auction_items WHERE item_id = :id');
        $stmt->execute(['id' => $itemId]);
        return (string)$stmt->fetchColumn();
    }

    private function canSms(array $user): bool
    {
        $phone = (string)($user['phone'] ?? '');
        return $phone !== ''
            && $user['phone_verified_at'] !== null
            && (int)$user['sms_opt_out'] !== 1;
    }
}
