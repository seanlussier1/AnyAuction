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
    /**
     * Minimum seconds between expired-auction sweeps triggered by /admin
     * page loads. Bursts of admin navigation shouldn't replay the same
     * UPDATE on every request — once a minute is plenty for the badge UI.
     */
    private const SWEEP_THROTTLE_SECONDS = 60;

    public function __construct(
        private readonly PDO $db,
        private readonly Twig $view,
        private readonly AuthService $auth,
        private readonly FlashService $flash
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $blocked = $this->requireAdmin($response);
        if ($blocked !== null) {
            return $blocked;
        }

        $auctions = new Auction($this->db);
        $users    = new User($this->db);
        $reports  = new Report($this->db);

        $lastSweep = (int)($_SESSION['_admin_swept_at'] ?? 0);
        if (time() - $lastSweep > self::SWEEP_THROTTLE_SECONDS) {
            $auctions->closeExpired();
            $_SESSION['_admin_swept_at'] = time();
        }

        $activeTab  = (string)($request->getQueryParams()['tab'] ?? 'overview');
        $userSearch = trim((string)($request->getQueryParams()['uq'] ?? ''));

        $stats = [
            'active_auctions' => $auctions->countActive(),
            'total_users'     => $users->countAll(),
            'pending_reports' => $reports->countPending(),
            'revenue_30d'     => '$128,450',
        ];

        return $this->view->render($response, 'pages/admin.twig', [
            'is_real_admin'   => true,
            'stats'           => $stats,
            'category_counts' => $auctions->countByCategory(),
            'users'           => $users->adminAll($userSearch ?: null),
            'listings'        => $auctions->adminAll(),
            'reports'         => $reports->adminAll(),
            'active_tab'      => in_array($activeTab, ['overview', 'users', 'listings', 'reports'], true) ? $activeTab : 'overview',
            'user_search'     => $userSearch,
        ]);
    }

    public function removeListing(Request $request, Response $response, array $args): Response
    {
        $blocked = $this->requireAdmin($response);
        if ($blocked !== null) {
            return $blocked;
        }

        if (!$this->validCsrf($request)) {
            $this->flash->error('Your session expired. Please try again.');
            return $response->withHeader('Location', '/admin?tab=listings')->withStatus(302);
        }

        $itemId = (int)($args['id'] ?? 0);

        if ((new Auction($this->db))->adminRemove($itemId)) {
            $this->flash->success('Listing removed from the marketplace.');
        } else {
            $this->flash->error('Could not remove that listing.');
        }

        return $response->withHeader('Location', '/admin?tab=listings')->withStatus(302);
    }

    public function warnUser(Request $request, Response $response, array $args): Response
    {
        return $this->moderateUser($request, $response, $args, 'warn');
    }

    public function banUser(Request $request, Response $response, array $args): Response
    {
        return $this->moderateUser($request, $response, $args, 'ban');
    }

    public function unbanUser(Request $request, Response $response, array $args): Response
    {
        return $this->moderateUser($request, $response, $args, 'unban');
    }

    private function moderateUser(Request $request, Response $response, array $args, string $action): Response
    {
        $blocked = $this->requireAdmin($response);
        if ($blocked !== null) {
            return $blocked;
        }

        if (!$this->validCsrf($request)) {
            $this->flash->error('Your session expired. Please try again.');
            return $response->withHeader('Location', '/admin?tab=users')->withStatus(302);
        }

        $userId = (int)($args['id'] ?? 0);
        $users  = new User($this->db);
        $target = $users->findById($userId);

        if (!$target) {
            $this->flash->error('User not found.');
            return $response->withHeader('Location', '/admin?tab=users')->withStatus(302);
        }

        if (($target['role'] ?? '') === 'admin') {
            $this->flash->error('Admin accounts cannot be moderated here.');
            return $response->withHeader('Location', '/admin?tab=users')->withStatus(302);
        }

        $body = (array)$request->getParsedBody();

        $ok = match ($action) {
            'warn'  => $users->warn($userId, (string)($body['warning_note'] ?? 'Flagged by admin')),
            'ban'   => $users->ban($userId),
            'unban' => $users->unban($userId),
            default => false,
        };

        if ($ok) {
            $this->flash->success('User account updated.');
        } else {
            $this->flash->error('Could not update that user.');
        }

        return $response->withHeader('Location', '/admin?tab=users')->withStatus(302);
    }

    private function requireAdmin(Response $response): ?Response
    {
        if (!$this->auth->isLoggedIn()) {
            $this->flash->error('Log in to access the admin dashboard.');
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $currentUser = $this->auth->currentUser();

        if (($currentUser['role'] ?? '') !== 'admin') {
            $this->flash->error('Admins only.');
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        return null;
    }

    private function validCsrf(Request $request): bool
    {
        $body = (array)$request->getParsedBody();
        $submitted = (string)($body['_csrf'] ?? '');

        return isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $submitted);
    }
}
