<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Auction;
use App\Models\Category;
use App\Models\Order;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class HomeController
{
    public function __construct(
        private readonly PDO $db,
        private readonly Twig $view
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $categories  = (new Category($this->db))->all();
        $auctions    = new Auction($this->db);
        $featured    = $auctions->featured(4);
        $endingSoon  = $auctions->endingSoon(4);

        $totalSales  = (new Order($this->db))->totalSales();
        $salesLabel  = $totalSales >= 1_000_000
            ? '$' . number_format($totalSales / 1_000_000, 1) . 'M+'
            : ($totalSales >= 1_000
                ? '$' . number_format($totalSales / 1_000, 1) . 'K+'
                : '$' . number_format($totalSales, 0));

        return $this->view->render($response, 'pages/home.twig', [
            'categories'   => $categories,
            'featured'     => $featured,
            'ending_soon'  => $endingSoon,
            'stats'        => [
                ['label' => 'Active Auctions', 'value' => '12K+'],
                ['label' => 'Happy Buyers',    'value' => '45K+'],
                ['label' => 'Items Sold',      'value' => $salesLabel],
            ],
        ]);
    }
}
