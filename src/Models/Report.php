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
     *
     * @return array<int, array<string, mixed>>
     */
    public function adminAll(): array
    {
        $stmt = $this->db->query("
            SELECT r.report_id, r.type, r.reason, r.details, r.status,
                   r.created_at, r.resolved_at,
                   r.item_id, a.title AS item_title,
                   r.reporter_id, ur.username AS reporter_username,
                   r.reported_user_id, uu.username AS reported_username
            FROM reports r
            LEFT JOIN auction_items a ON a.item_id = r.item_id
            LEFT JOIN users         ur ON ur.user_id = r.reporter_id
            LEFT JOIN users         uu ON uu.user_id = r.reported_user_id
            ORDER BY (r.status = 'pending') DESC, r.created_at DESC
            LIMIT 200");
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
