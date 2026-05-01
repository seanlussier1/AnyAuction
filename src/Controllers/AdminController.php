<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Auction;
use App\Models\Report;
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
        $reports  = new Report($this->db);

        $activeTab   = (string)($request->getQueryParams()['tab']    ?? 'overview');
        $userSearch  = trim((string)($request->getQueryParams()['uq'] ?? ''));

        $stats = [
            'active_auctions'  => $auctions->countActive(),
            'total_users'      => $users->countAll(),
            'pending_reports'  => $reports->countPending(),
            'revenue_30d'      => '$128,450',    // mock — needs orders/payments
        ];

        return $this->view->render($response, 'pages/admin.twig', [
            'is_real_admin'   => $isRealAdmin,
            'stats'           => $stats,
            'category_counts' => $auctions->countByCategory(),
            'users'           => $users->adminAll($userSearch ?: null),
            'listings'        => $auctions->adminAll(),
            'reports'         => $reports->adminAll(),
            'active_tab'      => in_array($activeTab, ['overview', 'users', 'listings', 'reports'], true) ? $activeTab : 'overview',
            'user_search'     => $userSearch,
        ]);
    }
}
