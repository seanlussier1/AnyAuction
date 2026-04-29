<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Auction;
use App\Models\Watchlist;
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
        $userId   = (int)$user['user_id'];
        $auctions = new Auction($this->db);

        $allListings = $auctions->bySeller($userId);
        $allBids     = $auctions->withBidsFrom($userId);
        $sold        = $auctions->soldBySeller($userId);
        $won         = $auctions->wonBy($userId);
        $watchlist   = (new Watchlist($this->db))->forUser($userId);

        // Items already in sold/won shouldn't double-up in the active tabs.
        $soldIds = array_column($sold, 'item_id');
        $wonIds  = array_column($won,  'item_id');

        $listings = array_values(array_filter(
            $allListings,
            static fn ($l) => !in_array($l['item_id'], $soldIds, true)
        ));
        $bids = array_values(array_filter(
            $allBids,
            static fn ($b) => !in_array($b['item_id'], $wonIds, true)
        ));

        $stats = [
            'active_listings' => count($listings),
            'total_listings'  => count($allListings),
            'active_bids'     => count($bids),
            'watchlist'       => count($watchlist),
            'rating'          => '—',
            'reviews'         => 0,
        ];

        return $this->view->render($response, 'pages/profile.twig', [
            'user'      => $user,
            'listings'  => $listings,
            'sold'      => $sold,
            'bids'      => $bids,
            'won'       => $won,
            'watchlist' => $watchlist,
            'stats'     => $stats,
        ]);
    }
}
