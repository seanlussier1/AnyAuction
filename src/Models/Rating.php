<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use RuntimeException;

final class Rating
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Create a rating for an order. The ratee and direction are derived
     * server-side from the order — clients never get to declare who they're
     * rating. Throws RuntimeException with a user-facing message on any
     * eligibility failure (missing order, not paid, not a participant,
     * already rated, bad score).
     */
    public function create(int $orderId, int $raterId, int $score, ?string $comment): int
    {
        if ($score < 1 || $score > 5) {
            throw new RuntimeException('Score must be between 1 and 5.');
        }

        $stmt = $this->db->prepare('
            SELECT o.order_id, o.status, o.buyer_id, a.seller_id
            FROM orders o
            JOIN auction_items a ON a.item_id = o.item_id
            WHERE o.order_id = :id');
        $stmt->execute(['id' => $orderId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('Order not found.');
        }
        if ($row['status'] !== 'paid') {
            throw new RuntimeException('Order is not paid yet.');
        }

        $buyerId  = (int)$row['buyer_id'];
        $sellerId = (int)$row['seller_id'];

        if ($raterId === $buyerId && $raterId === $sellerId) {
            throw new RuntimeException('Cannot rate yourself.');
        }
        if ($raterId === $buyerId) {
            $rateeId   = $sellerId;
            $direction = 'buyer_to_seller';
        } elseif ($raterId === $sellerId) {
            $rateeId   = $buyerId;
            $direction = 'seller_to_buyer';
        } else {
            throw new RuntimeException('You did not participate in this order.');
        }

        $cleanComment = $comment === null ? null : trim($comment);
        if ($cleanComment === '') {
            $cleanComment = null;
        }
        if ($cleanComment !== null && mb_strlen($cleanComment) > 1000) {
            $cleanComment = mb_substr($cleanComment, 0, 1000);
        }

        try {
            $insert = $this->db->prepare('
                INSERT INTO ratings (order_id, rater_id, ratee_id, direction, score, comment)
                VALUES (:order_id, :rater, :ratee, :direction, :score, :comment)');
            $insert->execute([
                'order_id'  => $orderId,
                'rater'     => $raterId,
                'ratee'     => $rateeId,
                'direction' => $direction,
                'score'     => $score,
                'comment'   => $cleanComment,
            ]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new RuntimeException('You have already rated this order.');
            }
            throw $e;
        }
    }

    /**
     * Reviews this user has received, newest first, with reviewer info and
     * the related auction title.
     *
     * @return array<int, array<string, mixed>>
     */
    public function reviewsForUser(int $userId): array
    {
        $stmt = $this->db->prepare('
            SELECT r.rating_id, r.score, r.comment, r.direction, r.created_at,
                   r.rater_id,
                   u.username        AS reviewer_username,
                   u.first_name      AS reviewer_first_name,
                   u.last_name       AS reviewer_last_name,
                   u.profile_picture AS reviewer_profile_picture,
                   a.item_id,
                   a.title           AS item_title
            FROM ratings r
            JOIN users         u ON u.user_id = r.rater_id
            JOIN orders        o ON o.order_id = r.order_id
            JOIN auction_items a ON a.item_id  = o.item_id
            WHERE r.ratee_id = :uid
            ORDER BY r.created_at DESC');
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Aggregate stats for a rated user.
     *
     * @return array{average: ?float, count: int, distribution: array<int,int>}
     */
    public function statsForUser(int $userId): array
    {
        $agg = $this->db->prepare(
            'SELECT AVG(score) AS avg_score, COUNT(*) AS total
             FROM ratings WHERE ratee_id = :uid'
        );
        $agg->execute(['uid' => $userId]);
        $row = $agg->fetch();

        $count   = (int)($row['total'] ?? 0);
        $average = $count > 0 && $row['avg_score'] !== null ? (float)$row['avg_score'] : null;

        $dist = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0];
        if ($count > 0) {
            $by = $this->db->prepare(
                'SELECT score, COUNT(*) AS n FROM ratings
                 WHERE ratee_id = :uid GROUP BY score'
            );
            $by->execute(['uid' => $userId]);
            foreach ($by->fetchAll() as $bucket) {
                $key = (string)(int)$bucket['score'];
                if (isset($dist[$key])) {
                    $dist[$key] = (int)$bucket['n'];
                }
            }
        }

        return [
            'average'      => $average,
            'count'        => $count,
            'distribution' => [
                1 => $dist['1'],
                2 => $dist['2'],
                3 => $dist['3'],
                4 => $dist['4'],
                5 => $dist['5'],
            ],
        ];
    }

    /**
     * Has this rater already rated this order? Used to decide between
     * "Rate" button and "Rated" badge in the UI.
     */
    public function existsForOrderAndRater(int $orderId, int $raterId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM ratings
             WHERE order_id = :order_id AND rater_id = :rater LIMIT 1'
        );
        $stmt->execute(['order_id' => $orderId, 'rater' => $raterId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Map of order_id => bool for which of the given orders the rater has
     * already rated. Builds the full map (with false defaults) so the UI
     * loop has O(1) lookup.
     *
     * @param int[] $orderIds
     * @return array<int,bool>
     */
    public function ratedMap(array $orderIds, int $raterId): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        $map = array_fill_keys($orderIds, false);
        if (count($orderIds) === 0) {
            return $map;
        }

        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $sql = 'SELECT order_id FROM ratings
                WHERE rater_id = ? AND order_id IN (' . $placeholders . ')';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$raterId], $orderIds));
        foreach ($stmt->fetchAll() as $row) {
            $map[(int)$row['order_id']] = true;
        }
        return $map;
    }
}
