<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Notification;
use App\Services\AuthService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Tiny endpoint pair for the Notifications tab. Read happens server-side
 * in ProfileController; this is just for marking-as-read when the user
 * actually views the tab (so the bell badge clears at the right time
 * rather than on every profile page load).
 */
final class NotificationController
{
    public function __construct(
        private readonly PDO $db,
        private readonly AuthService $auth
    ) {
    }

    public function markAllRead(Request $request, Response $response): Response
    {
        if (!$this->auth->isLoggedIn()) {
            return $this->json($response->withStatus(401), ['error' => 'login_required']);
        }
        $count = (new Notification($this->db))->markAllReadForUser((int)$_SESSION['user_id']);
        return $this->json($response, ['marked' => $count]);
    }

    private function json(Response $response, array $payload): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
