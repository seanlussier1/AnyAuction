<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Auction;
use App\Models\Order;
use App\Models\Rating;
use App\Models\User;
use App\Services\AuthService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Public-facing profile page at /users/{id}. Shows the user's reviews,
 * average rating, and active listings — no email, orders, watchlist, or
 * settings. Anyone (logged in or not) can view it.
 *
 * If the viewer is logged in and has paid orders involving this profile
 * owner that haven't been rated yet, a "Leave a rating" section is
 * surfaced so they can leave the review from here.
 */
final class PublicProfileController
{
    public function __construct(
        private readonly PDO $db,
        private readonly Twig $view,
        private readonly AuthService $auth
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            return $this->notFound($response);
        }

        $user = (new User($this->db))->findById($id);
        if (!$user) {
            return $this->notFound($response);
        }

        $rating       = new Rating($this->db);
        $statsData    = $rating->statsForUser($id);
        $reviews      = $rating->reviewsForUser($id);
        $listings     = (new Auction($this->db))->bySeller($id);

        $stats = [
            'rating'                => $statsData['average'] !== null ? number_format($statsData['average'], 1) : '—',
            'reviews'               => $statsData['count'],
            'rating_average_round'  => $statsData['average'] !== null ? (int)round($statsData['average']) : 0,
            'rating_distribution'   => $statsData['distribution'],
        ];

        // Surface rate-able orders to the logged-in viewer so they can leave
        // a rating directly from this profile (works for both directions —
        // viewer-as-buyer or viewer-as-seller). Only paid + not-yet-rated
        // orders show up. Skipped when viewing your own profile.
        $rateable = [];
        $csrf     = '';
        $viewerId = 0;
        if ($this->auth->isLoggedIn()) {
            $viewerId = (int)$_SESSION['user_id'];
            if ($viewerId !== $id) {
                $shared   = (new Order($this->db))->forParticipants($viewerId, $id);
                $ratedMap = $rating->ratedMap(array_column($shared, 'order_id'), $viewerId);
                foreach ($shared as $row) {
                    $orderId = (int)$row['order_id'];
                    if (empty($ratedMap[$orderId])) {
                        $row['rater_role'] = ((int)$row['buyer_id'] === $viewerId) ? 'buyer' : 'seller';
                        $rateable[] = $row;
                    }
                }
                if (!empty($rateable)) {
                    if (empty($_SESSION['_csrf'])) {
                        $_SESSION['_csrf'] = bin2hex(random_bytes(16));
                    }
                    $csrf = $_SESSION['_csrf'];
                }
            }
        }

        return $this->view->render($response, 'pages/public_profile.twig', [
            'profile_user'    => $user,
            'stats'           => $stats,
            'reviews'         => $reviews,
            'listings'        => $listings,
            'rateable_orders' => $rateable,
            'csrf'            => $csrf,
            'viewer_id'       => $viewerId,
        ]);
    }

    private function notFound(Response $response): Response
    {
        return $this->view
            ->render($response->withStatus(404), 'pages/coming_soon.twig', ['page_title' => 'User not found'])
            ->withStatus(404);
    }
}
