<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Tiny state-machine store for inbound SMS bidding conversations.
 *
 * One row per phone number. Lifecycle:
 *   1. NotificationService::notifyOutbid creates/replaces the row in
 *      `waiting_amount` state when a buyer is outbid.
 *   2. Buyer texts back a dollar amount → row flips to `waiting_confirm`
 *      with `pending_amount` set.
 *   3. Buyer texts YES → bid placed via Bid::place, row deleted.
 *
 * Rows expire (`expires_at`) and the webhook calls `gc()` on every hit so
 * we don't accumulate stale state.
 */
final class SmsConversation
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Returns the conversation row for $phone if it exists AND is unexpired.
     * Expired rows are reported as missing — callers should treat them like a
     * cold start. The `gc()` sweep cleans them up out-of-band.
     *
     * @return array<string, mixed>|null
     */
    public function findByPhone(string $phone): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT phone_number, user_id, state, item_id, pending_amount,
                    expires_at, updated_at
             FROM sms_conversations
             WHERE phone_number = :p
               AND expires_at > NOW()'
        );
        $stmt->execute(['p' => $phone]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Open (or replace) a conversation in `waiting_amount` state. Used when a
     * buyer has just been outbid — we prompt them for a counter-bid amount.
     */
    public function upsertWaitingAmount(string $phone, int $userId, int $itemId, int $ttlSeconds): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO sms_conversations
                (phone_number, user_id, state, item_id, pending_amount, expires_at)
             VALUES
                (:p, :u, 'waiting_amount', :i, NULL, DATE_ADD(NOW(), INTERVAL :ttl SECOND))
             ON DUPLICATE KEY UPDATE
                user_id        = VALUES(user_id),
                state          = 'waiting_amount',
                item_id        = VALUES(item_id),
                pending_amount = NULL,
                expires_at     = VALUES(expires_at)"
        );
        $stmt->execute([
            'p'   => $phone,
            'u'   => $userId,
            'i'   => $itemId,
            'ttl' => $ttlSeconds,
        ]);
    }

    /**
     * Move an existing row into `waiting_confirm` with the parsed dollar
     * amount stashed. TTL is short here — we want a fresh confirm, not one
     * the buyer forgot about.
     */
    public function setWaitingConfirm(string $phone, float $amount, int $ttlSeconds): void
    {
        $stmt = $this->db->prepare(
            "UPDATE sms_conversations
             SET state          = 'waiting_confirm',
                 pending_amount = :amt,
                 expires_at     = DATE_ADD(NOW(), INTERVAL :ttl SECOND)
             WHERE phone_number = :p"
        );
        $stmt->execute([
            'p'   => $phone,
            'amt' => $amount,
            'ttl' => $ttlSeconds,
        ]);
    }

    public function delete(string $phone): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM sms_conversations WHERE phone_number = :p'
        );
        $stmt->execute(['p' => $phone]);
    }

    /**
     * Sweep expired rows. Cheap; safe to call from the webhook entry point.
     */
    public function gc(): void
    {
        $this->db->exec('DELETE FROM sms_conversations WHERE expires_at < NOW()');
    }
}
