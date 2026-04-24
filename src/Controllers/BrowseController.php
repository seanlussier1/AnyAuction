<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Auction;
use App\Models\Category;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class BrowseController
{
    public function __construct(
        private readonly PDO $db,
        private readonly Twig $view
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $categoryId = isset($params['category']) && $params['category'] !== ''
            ? (int)$params['category']
            : null;
        $sort  = (string)($params['sort'] ?? 'ending');
        $query = trim((string)($params['q'] ?? ''));

        $auctions  = new Auction($this->db);
        $results   = $auctions->browse($categoryId, $sort);

        if ($query !== '') {
            $needle  = mb_strtolower($query);
            $results = array_values(array_filter($results, static fn ($a) =>
                str_contains(mb_strtolower($a['title']), $needle)
            ));
        }

        $categories    = (new Category($this->db))->all();
        $activeCategory = $categoryId !== null
            ? (new Category($this->db))->find($categoryId)
            : null;

        return $this->view->render($response, 'pages/browse.twig', [
            'auctions'        => $results,
            'categories'      => $categories,
            'active_category' => $activeCategory,
            'active_sort'     => $sort,
            'query'           => $query,
            'result_count'    => count($results),
        ]);
    }
}
