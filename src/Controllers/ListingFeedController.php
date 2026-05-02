<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Auction;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Tiny JSON endpoint the live-update poller hits to fetch listings that
 * appeared after the page was loaded. Filters by category if the page
 * specifies one (matches the /browse?category=X surface). Time-left is
 * formatted server-side so the client doesn't need its own clock-math
 * to mirror the {{ end_time | aa_timeleft }} filter.
 */
final class ListingFeedController
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function recent(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $since = (int)($q['since'] ?? 0);
        $cat   = isset($q['category']) && $q['category'] !== '' ? (int)$q['category'] : null;

        $rows = (new Auction($this->db))->sinceForFeed($since, $cat);

        // Render the small subset the front-end needs to build a card.
        $payload = array_map(static function (array $r): array {
            return [
                'item_id'        => (int)$r['item_id'],
                'title'          => (string)$r['title'],
                'current_price'  => (float)$r['current_price'],
                'buy_now_price'  => $r['buy_now_price'] !== null ? (float)$r['buy_now_price'] : null,
                'reserve_price'  => $r['reserve_price'] !== null ? (float)$r['reserve_price'] : null,
                'end_time'       => (string)$r['end_time'],
                'time_left'      => self::timeLeft((string)$r['end_time']),
                'featured'       => (int)$r['featured'] === 1,
                'primary_image'  => $r['primary_image'] !== null ? (string)$r['primary_image'] : null,
                'total_bids'     => (int)$r['total_bids'],
                'category_id'    => (int)$r['category_id'],
            ];
        }, $rows);

        $response->getBody()->write(json_encode(['listings' => $payload], JSON_THROW_ON_ERROR));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Mirror the aa_timeleft Twig filter so client-rendered cards use the
     * same human format ("2d 4h", "23m", "Ended").
     */
    private static function timeLeft(string $endTime): string
    {
        $diff = strtotime($endTime) - time();
        if ($diff <= 0) {
            return 'Ended';
        }
        $days    = intdiv($diff, 86400);
        $hours   = intdiv($diff % 86400, 3600);
        $minutes = intdiv($diff % 3600, 60);
        if ($days > 0) {
            return $days . 'd ' . $hours . 'h';
        }
        if ($hours > 0) {
            return $hours . 'h ' . $minutes . 'm';
        }
        return $minutes . 'm';
    }
}
