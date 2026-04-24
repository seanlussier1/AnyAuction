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

final class ProfileController
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
            $this->flash->error('Log in to view your profile.');
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user     = $this->auth->currentUser();
        $auctions = new Auction($this->db);

        $listings = $auctions->bySeller((int)$user['user_id']);
        $bids     = $auctions->withBidsFrom((int)$user['user_id']);

        $stats = [
            'active_listings' => count(array_filter($listings, static fn ($l) => $l['total_bids'] !== null)),
            'total_listings'  => count($listings),
            'active_bids'     => count($bids),
            'watchlist'       => 0,
            'rating'          => '—',
            'reviews'         => 0,
        ];

        return $this->view->render($response, 'pages/profile.twig', [
            'user'     => $user,
            'listings' => $listings,
            'bids'     => $bids,
            'stats'    => $stats,
        ]);
    }
}
