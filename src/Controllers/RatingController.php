<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Rating;
use App\Services\AuthService;
use App\Services\FlashService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

final class RatingController
{
    public function __construct(
        private readonly PDO $db,
        private readonly AuthService $auth,
        private readonly FlashService $flash
    ) {
    }

    /**
     * POST /rate/{orderId}. Inline form on the profile/checkout pages.
     * The ratee + direction are derived from the order in the model — we
     * just collect score + comment from the form here.
     */
    public function submit(Request $request, Response $response, array $args): Response
    {
        $orderId = (int)($args['orderId'] ?? 0);
        $referer = $request->getHeaderLine('Referer') ?: '/profile';

        if (!$this->auth->isLoggedIn()) {
            $this->flash->error('Log in to leave a rating.');
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $body = (array)$request->getParsedBody();
        if (!$this->verifyCsrf((string)($body['_csrf'] ?? ''))) {
            $this->flash->error('Your session expired. Please try again.');
            return $response->withHeader('Location', $referer)->withStatus(302);
        }

        $score   = (int)($body['score'] ?? 0);
        $comment = trim((string)($body['comment'] ?? ''));

        try {
            (new Rating($this->db))->create(
                $orderId,
                (int)$_SESSION['user_id'],
                $score,
                $comment === '' ? null : $comment
            );
            $this->flash->success('Thanks — your rating has been submitted.');
        } catch (RuntimeException $e) {
            $this->flash->error($e->getMessage());
        }

        return $response->withHeader('Location', $referer)->withStatus(302);
    }

    private function verifyCsrf(string $submitted): bool
    {
        return isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $submitted);
    }
}
