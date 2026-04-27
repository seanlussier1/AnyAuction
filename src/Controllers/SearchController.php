<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Auction;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SearchController
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function suggest(Request $request, Response $response): Response
    {
        $q = trim((string)($request->getQueryParams()['q'] ?? ''));

        $results = [];
        if (mb_strlen($q) >= 2) {
            $rows = (new Auction($this->db))->search($q, 8);
            foreach ($rows as $row) {
                $results[] = [
                    'item_id'       => (int)$row['item_id'],
                    'title'         => (string)$row['title'],
                    'current_price' => (float)$row['current_price'],
                    'end_time'      => (string)$row['end_time'],
                    'primary_image' => $row['primary_image'] ?? null,
                    'url'           => '/auction/' . (int)$row['item_id'],
                ];
            }
        }

        $payload = json_encode(['query' => $q, 'results' => $results], JSON_THROW_ON_ERROR);
        $response->getBody()->write($payload);
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
