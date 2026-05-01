<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Per-user notification feed. Mirrors the SMS event surface plus a few
 * site-only events. Rendered on the profile Notifications tab and
 * surfaced as an unread badge on the navbar bell icon.
 */
final class Notification
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Insert a notification. Returns the new id.
     */
    public function create(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?int $itemId = null,
        ?string $href = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO notifications (user_id, type, title, body, item_id, href)
             VALUES (:uid, :type, :title, :body, :item, :href)'
        );
        $stmt->execute([
            'uid'   => $userId,
            'type'  => $type,
            'title' => $title,
            'body'  => $body,
            'item'  => $itemId,
            'href'  => $href,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Return only notifications newer than $sinceId. Used by the live-update
     * poller to fetch deltas instead of re-rendering the whole list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sinceForUser(int $userId, int $sinceId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT n.notification_id, n.type, n.title, n.body, n.item_id, n.href,
                    n.is_read, n.created_at,
                    a.title AS item_title
             FROM notifications n
             LEFT JOIN auction_items a ON a.item_id = n.item_id
             WHERE n.user_id = :uid AND n.notification_id > :since
             ORDER BY n.created_at DESC, n.notification_id DESC
             LIMIT :lim'
        );
        $stmt->bindValue('uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue('since', $sinceId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forUser(int $userId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT n.notification_id, n.type, n.title, n.body, n.item_id, n.href,
                    n.is_read, n.created_at,
                    a.title AS item_title
             FROM notifications n
             LEFT JOIN auction_items a ON a.item_id = n.item_id
             WHERE n.user_id = :uid
             ORDER BY n.created_at DESC, n.notification_id DESC
             LIMIT :lim'
        );
        $stmt->bindValue('uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function unreadCountForUser(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0'
        );
        $stmt->execute(['uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    public function markAllReadForUser(int $userId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE notifications SET is_read = 1
             WHERE user_id = :uid AND is_read = 0'
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->rowCount();
    }
}
