<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Issues and verifies short-lived 6-digit codes used for SMS-based 2FA,
 * phone enrollment verification, and password reset.
 *
 * Codes are stored as password_hash() so they can't be read back from
 * the DB. Each code is single-use (used_at gets stamped on verify) and
 * expires after 10 minutes.
 */
final class AuthCodeService
{
    private const TTL_MINUTES = 10;

    /** @var array<int, true> Valid purpose values matching the auth_codes ENUM. */
    private const PURPOSES = [
        'login'          => true,
        'password_reset' => true,
        'phone_verify'   => true,
    ];

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Issue a new code for (user, purpose). Invalidates any prior unused
     * codes for that pair. Returns the plaintext 6-digit code so the
     * caller can hand it to the SMS sender.
     */
    public function issue(int $userId, string $purpose): string
    {
        $this->assertPurpose($purpose);
        $this->gc();

        // Burn any outstanding unused codes for this (user, purpose) pair so
        // a stale earlier code can't race the new one.
        $invalidate = $this->db->prepare(
            'UPDATE auth_codes
                SET used_at = NOW()
              WHERE user_id = :uid
                AND purpose = :purpose
                AND used_at IS NULL'
        );
        $invalidate->execute(['uid' => $userId, 'purpose' => $purpose]);

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = password_hash($code, PASSWORD_DEFAULT);

        $insert = $this->db->prepare(
            'INSERT INTO auth_codes (user_id, purpose, code_hash, expires_at)
             VALUES (:uid, :purpose, :hash, DATE_ADD(NOW(), INTERVAL :ttl MINUTE))'
        );
        $insert->execute([
            'uid'     => $userId,
            'purpose' => $purpose,
            'hash'    => $hash,
            'ttl'     => self::TTL_MINUTES,
        ]);

        return $code;
    }

    /**
     * Verify a plaintext code against the latest unused, unexpired row
     * for (user, purpose). On a match, stamp used_at and return true.
     */
    public function verify(int $userId, string $purpose, string $code): bool
    {
        $this->assertPurpose($purpose);

        $code = trim($code);
        if ($code === '' || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT code_id, code_hash
               FROM auth_codes
              WHERE user_id = :uid
                AND purpose = :purpose
                AND used_at IS NULL
                AND expires_at > NOW()
              ORDER BY code_id DESC
              LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'purpose' => $purpose]);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        if (!password_verify($code, (string)$row['code_hash'])) {
            return false;
        }

        $consume = $this->db->prepare(
            'UPDATE auth_codes SET used_at = NOW() WHERE code_id = :cid'
        );
        $consume->execute(['cid' => (int)$row['code_id']]);

        return true;
    }

    /**
     * Best-effort cleanup of rows older than 1 day. Called from issue()
     * so we don't accumulate dead rows. Errors are swallowed; this is
     * housekeeping, not a hard requirement.
     */
    public function gc(): void
    {
        try {
            $this->db->exec(
                'DELETE FROM auth_codes WHERE expires_at < NOW() - INTERVAL 1 DAY'
            );
        } catch (\Throwable) {
            // Non-fatal — auth_codes is allowed to drift slightly.
        }
    }

    private function assertPurpose(string $purpose): void
    {
        if (!isset(self::PURPOSES[$purpose])) {
            throw new \InvalidArgumentException("Unknown auth code purpose: {$purpose}");
        }
    }
}
