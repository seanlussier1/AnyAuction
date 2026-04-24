<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Auction;
use App\Services\AuthService;
use App\Services\FlashService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class WatchlistController
{
    public function __construct(
        private readonly PDO $db,
        private readonly Twig $view,
        private readonly AuthService $auth,
        private readonly FlashService $flash
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        if (!$this->auth->isLoggedIn()) {
            $this->flash->error('Log in to see your watchlist.');
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        // Watchlist persistence comes in a later deliverable. For the visual
        // baseline we show "Ending Soon" as a pretend watch-set so the page
        // doesn't look empty on first view.
        $auctions = (new Auction($this->db))->endingSoon(3);

        return $this->view->render($response, 'pages/watchlist.twig', [
            'auctions' => $auctions,
        ]);
    }
}
