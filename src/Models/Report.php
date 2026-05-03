<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * User-submitted moderation reports against listings (today) and users
 * (schema-ready for later). Pending reports are surfaced on the admin
 * panel; admins can mark them resolved or dismissed.
 */
final class Report
{
    /** Reasons offered in the listing-report form. Keep client + server in sync. */
    public const LISTING_REASONS = [
        'counterfeit'     => 'Counterfeit / fake item',
        'misleading'      => 'Misleading description',
        'prohibited'      => 'Prohibited or illegal item',
        'not_received'    => 'Item not received',
        'not_as_described'=> 'Item not as described',
        'suspicious'      => 'Suspicious behavior',
        'other'           => 'Other',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    public function createListingReport(int $reporterId, int $itemId, string $reason, string $details): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO reports (reporter_id, item_id, type, reason, details)
             VALUES (:reporter, :item, 'listing', :reason, :details)"
        );
        $stmt->execute([
            'reporter' => $reporterId,
            'item'     => $itemId,
            'reason'   => $reason,
            'details'  => $details,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Admin view — most-recent first, joined with reporter + reportee names.
     * Optional filters narrow the result without breaking the existing
     * "show everything" call-site (all params null = no filter).
     *
     * @param ?string $type       'listing' | 'user' (anything else: ignored)
     * @param ?string $status     'pending' | 'resolved' | 'dismissed'
     * @param ?int    $categoryId Filters listing-type reports by listing category
     * @param ?string $search     Substring matched against reporter username,
     *                            reported username, or listing title
     * @return array<int, array<string, mixed>>
     */
    public function adminAll(
        ?string $type = null,
        ?string $status = null,
        ?int $categoryId = null,
        ?string $search = null
    ): array {
        $where  = [];
        $params = [];

        if (in_array($type, ['listing', 'user'], true)) {
            $where[] = 'r.type = :type';
            $params['type'] = $type;
        }
        if (in_array($status, ['pending', 'resolved', 'dismissed'], true)) {
            $where[] = 'r.status = :status';
            $params['status'] = $status;
        }
        if ($categoryId !== null && $categoryId > 0) {
            $where[] = 'a.category_id = :cat';
            $params['cat'] = $categoryId;
        }
        if ($search !== null && $search !== '') {
            $where[] = '(ur.username LIKE :q OR uu.username LIKE :q OR a.title LIKE :q)';
            $params['q'] = '%' . $search . '%';
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT r.report_id, r.type, r.reason, r.details, r.status,
                    r.created_at, r.resolved_at,
                    r.item_id, a.title AS item_title, a.category_id AS item_category_id,
                    c.name AS item_category_name,
                    r.reporter_id, ur.username AS reporter_username,
                    r.reported_user_id, uu.username AS reported_username
             FROM reports r
             LEFT JOIN auction_items a ON a.item_id = r.item_id
             LEFT JOIN categories    c ON c.category_id = a.category_id
             LEFT JOIN users        ur ON ur.user_id = r.reporter_id
             LEFT JOIN users        uu ON uu.user_id = r.reported_user_id
             $whereClause
             ORDER BY (r.status = 'pending') DESC, r.created_at DESC
             LIMIT 200"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function countPending(): int
    {
        return (int)$this->db->query(
            "SELECT COUNT(*) FROM reports WHERE status = 'pending'"
        )->fetchColumn();
    }

    public function setStatus(int $reportId, string $status): bool
    {
        if (!in_array($status, ['resolved', 'dismissed'], true)) {
            return false;
        }
        $stmt = $this->db->prepare(
            'UPDATE reports
             SET status = :s, resolved_at = NOW()
             WHERE report_id = :id AND status = :pending'
        );
        $stmt->execute(['s' => $status, 'id' => $reportId, 'pending' => 'pending']);
        return $stmt->rowCount() > 0;
    }
}
