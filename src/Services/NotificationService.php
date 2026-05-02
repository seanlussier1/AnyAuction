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
 *
 * Locale handling: every user-facing string is keyed through Translator
 * with the RECIPIENT's locale (looked up from users.locale), not the
 * request's. A French buyer outbid by an English seller still gets a
 * French SMS / DB notification.
 */
final class NotificationService
{
    /** Outbid conversations live for 30 minutes — long enough to glance at
     *  a phone and reply, short enough that a stale row from yesterday
     *  doesn't get matched against an unrelated text. */
    private const OUTBID_CONVERSATION_TTL_SECONDS = 1800;

    public function __construct(
        private readonly PDO $db,
        private readonly TwilioService $twilio,
        private readonly Translator $translator
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
        $amountFmt = number_format($amount, 2);

        $titleKey = match ($event) {
            'bid'    => 'notif.title.watchlist_bid',
            'buyout' => 'notif.title.watchlist_buyout',
            default  => null,
        };
        $bodyKey = match ($event) {
            'bid'    => 'notif.body.watchlist_bid',
            'buyout' => 'notif.body.watchlist_buyout',
            default  => null,
        };
        if ($titleKey === null || $bodyKey === null) {
            return; // unknown event — skip rather than emit a half-formed row
        }

        $ntype = $event === 'bid' ? 'watchlist_bid' : 'watchlist_buyout';
        $href  = '/auction/' . $itemId;

        // Batch-load locales for all watchers in a single query rather than
        // N+1 lookups. Keeps the fan-out cheap.
        $locales = $this->lookupLocales($watchers);

        $notif = new Notification($this->db);
        foreach ($watchers as $uid) {
            $uid = (int)$uid;
            $locale = $locales[$uid] ?? 'en';
            $heading = $this->translator->trans($titleKey, [], $locale);
            $body    = $this->translator->trans($bodyKey, [
                'amount' => $amountFmt,
                'title'  => $title,
            ], $locale);
            $notif->create($uid, $ntype, $heading, $body, $itemId, $href);
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
            $ntype  = 'snipe_extended';
            $href   = '/auction/' . $itemId;

            $locales = $this->lookupLocales($bidders);

            $notif = new Notification($this->db);
            foreach ($bidders as $uid) {
                $uid = (int)$uid;
                $locale = $locales[$uid] ?? 'en';
                $heading = $this->translator->trans('notif.title.snipe_extended', [], $locale);
                $body    = $this->translator->trans('notif.body.snipe_extended', [
                    'title'   => $title,
                    'minutes' => $extMin,
                ], $locale);
                $notif->create($uid, $ntype, $heading, $body, $itemId, $href);
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

        $locale    = $this->normalizeLocale((string)($user['locale'] ?? 'en'));
        $amountFmt = number_format($newAmount, 2);

        // Always record the in-site notification, regardless of SMS prefs.
        (new Notification($this->db))->create(
            $previousBidderId,
            'outbid',
            $this->translator->trans('notif.title.outbid', [], $locale),
            $this->translator->trans('notif.body.outbid', [
                'amount' => $amountFmt,
                'title'  => $title,
            ], $locale),
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
            $this->translator->trans('sms.outbid', [
                'title'  => $title,
                'amount' => $amountFmt,
            ], $locale)
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

        $locale    = $this->normalizeLocale((string)($user['locale'] ?? 'en'));
        $amountFmt = number_format($finalAmount, 2);

        (new Notification($this->db))->create(
            $previousBidderId,
            'auction_lost',
            $this->translator->trans('notif.title.auction_lost', [], $locale),
            $this->translator->trans('notif.body.auction_lost', [
                'title'  => $title,
                'amount' => $amountFmt,
            ], $locale),
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
            $this->translator->trans('sms.auction_lost', [
                'title'  => $title,
                'amount' => $amountFmt,
            ], $locale)
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

        $locale    = $this->normalizeLocale((string)($user['locale'] ?? 'en'));
        $amountFmt = number_format($winningBid, 2);

        (new Notification($this->db))->create(
            $winnerId,
            'auction_won',
            $this->translator->trans('notif.title.auction_won', [], $locale),
            $this->translator->trans('notif.body.auction_won', [
                'title'  => $title,
                'amount' => $amountFmt,
            ], $locale),
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
            $this->translator->trans('sms.auction_won', [
                'title'  => $title,
                'amount' => $amountFmt,
            ], $locale)
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

        $locale = $this->lookupLocale($sellerId);

        (new Notification($this->db))->create(
            $sellerId,
            'bid_received',
            $this->translator->trans('notif.title.bid_received', [], $locale),
            $this->translator->trans('notif.body.bid_received', [
                'amount' => number_format($amount, 2),
                'title'  => $title,
            ], $locale),
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

        $locale = $this->lookupLocale($sellerId);

        (new Notification($this->db))->create(
            $sellerId,
            'item_sold',
            $this->translator->trans('notif.title.item_sold', [], $locale),
            $this->translator->trans('notif.body.item_sold', [
                'title'  => $title,
                'amount' => number_format($finalAmount, 2),
            ], $locale),
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

        $locale = $this->lookupLocale($sellerId);

        (new Notification($this->db))->create(
            $sellerId,
            'order_paid',
            $this->translator->trans('notif.title.order_paid', [], $locale),
            $this->translator->trans('notif.body.order_paid', [
                'amount' => number_format($amount, 2),
                'title'  => $title,
            ], $locale),
            $itemId,
            '/profile'
        );
    }

    /**
     * Site-only: friendly welcome on signup.
     */
    public function notifyWelcome(int $userId, string $firstName): void
    {
        $locale = $this->lookupLocale($userId);

        (new Notification($this->db))->create(
            $userId,
            'welcome',
            $this->translator->trans('notif.title.welcome', [], $locale),
            $this->translator->trans('notif.body.welcome', [
                'first_name' => $firstName,
            ], $locale),
            null,
            '/browse'
        );
    }

    private function lookupUser(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT phone, phone_verified_at, sms_opt_out, locale
             FROM users
             WHERE user_id = :id'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Single-user locale lookup — one row, one column. Used by the
     * site-only seller-side notifications where we don't need phone/opt-out.
     */
    private function lookupLocale(int $userId): string
    {
        $stmt = $this->db->prepare('SELECT locale FROM users WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        $code = (string)$stmt->fetchColumn();
        return $this->normalizeLocale($code);
    }

    /**
     * Batch locale lookup keyed by user_id. Avoids N+1 queries when fanning
     * out to a list of watchers / bidders.
     *
     * @param  array<int, int|string> $userIds
     * @return array<int, string>
     */
    private function lookupLocales(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT user_id, locale FROM users WHERE user_id IN ($placeholders)"
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int)$row['user_id']] = $this->normalizeLocale((string)$row['locale']);
        }
        return $out;
    }

    private function normalizeLocale(string $code): string
    {
        $code = $code === '' ? 'en' : $code;
        return $this->translator->normalize($code);
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
