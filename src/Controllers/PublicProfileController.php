<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Auction;
use App\Models\Rating;
use App\Models\User;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Public-facing profile page at /users/{id}. Shows the user's reviews,
 * average rating, and active listings — no email, orders, watchlist, or
 * settings. Anyone (logged in or not) can view it.
 */
final class PublicProfileController
{
    public function __construct(
        private readonly PDO $db,
        private readonly Twig $view
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

        return $this->view->render($response, 'pages/public_profile.twig', [
            'profile_user' => $user,
            'stats'        => $stats,
            'reviews'      => $reviews,
            'listings'     => $listings,
        ]);
    }

    private function notFound(Response $response): Response
    {
        return $this->view
            ->render($response->withStatus(404), 'pages/coming_soon.twig', ['page_title' => 'User not found'])
            ->withStatus(404);
    }
}
