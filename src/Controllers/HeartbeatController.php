<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/heartbeat → JSON snapshot the front-end can poll to detect
 * deploys and listing changes. Cheap (~1 query, <1ms). Cached aggressively
 * by the client so the banner only flips when something actually moved.
 */
final class HeartbeatController
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $payload = [
            'version'           => $this->appVersion(),
            'listings_changed'  => $this->listingsTimestamp(),
            'server_time'       => time(),
        ];

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
