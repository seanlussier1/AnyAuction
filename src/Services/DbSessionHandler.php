<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use SessionHandlerInterface;
use SessionIdInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Stores PHP sessions in MariaDB so they survive container redeploys.
 * The default file-based handler keeps sessions in /tmp inside the
 * container — every Watchtower image swap wipes them and logs everyone
 * out. Persisting to the shared DB keeps users logged in across deploys.
 */
final class DbSessionHandler implements
    SessionHandlerInterface,
    SessionIdInterface,
    SessionUpdateTimestampHandlerInterface
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        $stmt = $this->db->prepare(
            'SELECT payload FROM sessions WHERE session_id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? (string)$row['payload'] : '';
    }

    public function write(string $id, string $data): bool
    {
        // user_id sniffed from the session payload so we can target a
        // user's sessions if we ever need to (admin force-logout, etc.).
        $userId = null;
        if ($data !== '' && preg_match('/user_id\|i:(\d+);/', $data, $m)) {
            $userId = (int)$m[1];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO sessions (session_id, user_id, payload, last_activity)
             VALUES (:id, :uid, :data, NOW())
             ON DUPLICATE KEY UPDATE
               user_id       = VALUES(user_id),
               payload       = VALUES(payload),
               last_activity = NOW()'
        );
        return $stmt->execute([
            'id'   => $id,
            'uid'  => $userId,
            'data' => $data,
        ]);
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE session_id = :id');
        return $stmt->execute(['id' => $id]);
    }

    /**
     * @return int|false Number of rows deleted, or false on failure.
     */
    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->db->prepare(
            'DELETE FROM sessions
             WHERE last_activity < (NOW() - INTERVAL :ttl SECOND)'
        );
        if (!$stmt->execute(['ttl' => $max_lifetime])) {
            return false;
        }
        return $stmt->rowCount();
    }

    public function create_sid(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function validateId(string $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM sessions WHERE session_id = :id');
        $stmt->execute(['id' => $id]);
        return (bool)$stmt->fetchColumn();
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE sessions SET last_activity = NOW() WHERE session_id = :id'
        );
        return $stmt->execute(['id' => $id]);
    }
}
