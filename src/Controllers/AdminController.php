<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Auction;
use App\Models\User;
use App\Services\AuthService;
use App\Services\FlashService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class AdminController
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
        // Admin access control is a later-deliverable concern (RBAC + 2FA).
        // For the visual baseline, any signed-in user can reach this page;
        // the UI shows a banner calling that out.
        if (!$this->auth->isLoggedIn()) {
            $this->flash->error('Log in to access the admin dashboard.');
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $currentUser = $this->auth->currentUser();
        $isRealAdmin = ($currentUser['role'] ?? '') === 'admin';

        $auctions = new Auction($this->db);
        $users    = new User($this->db);

        $activeTab   = (string)($request->getQueryParams()['tab']    ?? 'overview');
        $userSearch  = trim((string)($request->getQueryParams()['uq'] ?? ''));

        $stats = [
            'active_auctions'  => $auctions->countActive(),
            'total_users'      => $users->countAll(),
            'pending_reports'  => 2,             // mock — real reports table comes later
            'revenue_30d'      => '$128,450',    // mock — needs orders/payments
        ];

        // Hand-picked mock reports so the Reports tab has something to render.
        $reports = [
            ['id' => 'f1', 'type' => 'listing', 'name' => 'Suspicious Electronics Bundle', 'reason' => 'Possible counterfeit items', 'reported_by' => 'user-44', 'created_at' => '2026-04-05', 'status' => 'pending'],
            ['id' => 'f2', 'type' => 'user',    'name' => 'FastFlip99',                   'reason' => 'Multiple buyer complaints', 'reported_by' => 'user-12', 'created_at' => '2026-04-04', 'status' => 'pending'],
            ['id' => 'f3', 'type' => 'listing', 'name' => 'Designer Bags — Bulk',          'reason' => 'Copyright infringement suspected', 'reported_by' => 'user-07', 'created_at' => '2026-04-03', 'status' => 'resolved'],
        ];

        return $this->view->render($response, 'pages/admin.twig', [
            'is_real_admin'   => $isRealAdmin,
            'stats'           => $stats,
            'category_counts' => $auctions->countByCategory(),
            'users'           => $users->adminAll($userSearch ?: null),
            'listings'        => $auctions->adminAll(),
            'reports'         => $reports,
            'active_tab'      => in_array($activeTab, ['overview', 'users', 'listings', 'reports'], true) ? $activeTab : 'overview',
            'user_search'     => $userSearch,
        ]);
    }
}
