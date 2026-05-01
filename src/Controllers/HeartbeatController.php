<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuctionExpiryService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * GET /api/heartbeat → JSON snapshot the front-end can poll to detect
 * deploys and listing changes. Cheap (~1 query, <1ms). Cached aggressively
 * by the client so the banner only flips when something actually moved.
 *
 * Doubles as a low-budget auction-expiry sweeper: every heartbeat tick
 * checks for time-expired auctions, marks them closed, and texts the
 * winner. The check is a single indexed SELECT when nothing's expired,
 * so the additional load is negligible. As long as the site has any
 * tab open polling, expiries are processed within ~60s of end_time.
 */
final class HeartbeatController
{
    public function __construct(
        private readonly PDO $db,
        private readonly AuctionExpiryService $expiry
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        // Best-effort sweep — never let it 500 the heartbeat itself.
        try {
            $this->expiry->processExpired();
        } catch (Throwable $e) {
            error_log('[HeartbeatController] expiry sweep failed: ' . $e->getMessage());
        }

        $payload = [
            'version'           => $this->appVersion(),
            'listings_changed'  => $this->listingsTimestamp(),
            'server_time'       => time(),
            'unread_notifications'    => 0,
            'latest_notification_id'  => 0,
        ];

        // Per-user notification snapshot for the live bell badge.
        if (!empty($_SESSION['user_id'])) {
            $stmt = $this->db->prepare(
                'SELECT
                    COALESCE(SUM(is_read = 0), 0) AS unread,
                    COALESCE(MAX(notification_id), 0) AS latest
                 FROM notifications WHERE user_id = :uid'
            );
            $stmt->execute(['uid' => (int)$_SESSION['user_id']]);
            $row = $stmt->fetch();
            if ($row) {
                $payload['unread_notifications']   = (int)$row['unread'];
                $payload['latest_notification_id'] = (int)$row['latest'];
            }
        }

        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function appVersion(): string
    {
        // Written into the image at build time by the Dockerfile.
        $path = __DIR__ . '/../../.version';
        if (is_file($path)) {
            $sha = trim((string)@file_get_contents($path));
            if ($sha !== '') {
                return $sha;
            }
        }
        return 'dev';
    }

    private function listingsTimestamp(): int
    {
        // Reflects the most recent listing or bid activity. Front-end can
        // use it to decide whether to show a "new listings — refresh" hint
        // on browse-style pages.
        $stmt = $this->db->query(
            'SELECT GREATEST(
                COALESCE((SELECT MAX(updated_at) FROM auction_items), 0),
                COALESCE((SELECT MAX(bid_time)   FROM bids), 0)
             ) AS last_activity'
        );
        $value = $stmt->fetchColumn();
        return $value ? (int)strtotime((string)$value) : 0;
    }
}
