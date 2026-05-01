<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Auction;
use App\Models\Order;
use App\Models\Rating;
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
        $orderModel  = new Order($this->db);
        $orders      = $orderModel->forBuyer($userId);
        $soldOrders  = $orderModel->forSeller($userId);

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

        $rating    = new Rating($this->db);
        $statsData = $rating->statsForUser($userId);
        $reviews   = $rating->reviewsForUser($userId);

        $orderIds = array_merge(
            array_map('intval', array_column($orders, 'order_id')),
            array_map('intval', array_column($soldOrders, 'order_id'))
        );
        $ratedMap = $rating->ratedMap($orderIds, $userId);

        // Indexed by item_id so the Sold-tab card grid can look up the
        // matching order in O(1) without restructuring the existing layout.
        $soldOrdersByItem = [];
        foreach ($soldOrders as $row) {
            $soldOrdersByItem[(int)$row['item_id']] = $row;
        }

        // Same idea for the Won tab — buyer-side mirror so each won card
        // can render a "Rate seller" button keyed on its real order_id.
        $wonOrdersByItem = [];
        foreach ($orders as $row) {
            $wonOrdersByItem[(int)$row['item_id']] = $row;
        }

        $stats = [
            'active_listings'      => count($listings),
            'total_listings'       => count($allListings),
            'active_bids'          => count($bids),
            'watchlist'            => count($watchlist),
            'rating'               => $statsData['average'] !== null ? number_format($statsData['average'], 1) : '—',
            'reviews'              => $statsData['count'],
            'rating_average_round' => $statsData['average'] !== null ? (int)round($statsData['average']) : 0,
            'rating_distribution'  => $statsData['distribution'],
        ];

        return $this->view->render($response, 'pages/profile.twig', [
            'user'                => $user,
            'listings'            => $listings,
            'sold'                => $sold,
            'bids'                => $bids,
            'won'                 => $won,
            'watchlist'           => $watchlist,
            'orders'              => $orders,
            'reviews'             => $reviews,
            'rated_orders'        => $ratedMap,
            'sold_orders_by_item' => $soldOrdersByItem,
            'won_orders_by_item'  => $wonOrdersByItem,
            'csrf'                => $this->ensureCsrfToken(),
            'stats'               => $stats,
        ]);
    }

    private function ensureCsrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['_csrf'];
    }
}
