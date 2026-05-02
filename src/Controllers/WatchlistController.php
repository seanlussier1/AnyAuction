<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Watchlist;
use App\Services\AuthService;
use App\Services\FlashService;
use App\Services\Translator;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class WatchlistController
{
    public function __construct(
        private readonly PDO $db,
        private readonly Twig $view,
        private readonly AuthService $auth,
        private readonly FlashService $flash,
        private readonly Translator $translator
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        if (!$this->auth->isLoggedIn()) {
            $this->flash->error($this->translator->trans('auth.required.watchlist'));
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $auctions = (new Watchlist($this->db))->forUser((int)$_SESSION['user_id']);

        return $this->view->render($response, 'pages/watchlist.twig', [
            'auctions' => $auctions,
        ]);
    }

    /**
     * Toggle membership. Browser hearts call this via fetch and read the JSON
     * response; a non-AJAX POST (form fallback) gets a redirect to the
     * referer.
     */
    public function toggle(Request $request, Response $response, array $args): Response
    {
        $itemId = (int)($args['id'] ?? 0);
        $wantsJson = str_contains($request->getHeaderLine('Accept'), 'application/json');

        if (!$this->auth->isLoggedIn()) {
            if ($wantsJson) {
                return $this->json($response->withStatus(401), ['error' => 'login_required']);
            }
            $this->flash->error($this->translator->trans('auth.required.watchlist'));
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $body = (array)$request->getParsedBody();
        if (!$this->verifyCsrf((string)($body['_csrf'] ?? ''))) {
            if ($wantsJson) {
                return $this->json($response->withStatus(400), ['error' => 'csrf']);
            }
            $this->flash->error($this->translator->trans('csrf.expired'));
            return $response->withHeader('Location', '/auction/' . $itemId)->withStatus(302);
        }

        if ($itemId <= 0) {
            if ($wantsJson) {
                return $this->json($response->withStatus(404), ['error' => 'not_found']);
            }
            return $response->withHeader('Location', '/watchlist')->withStatus(302);
        }

        $watching = (new Watchlist($this->db))
            ->toggle((int)$_SESSION['user_id'], $itemId);

        if ($wantsJson) {
            return $this->json($response, ['watching' => $watching, 'item_id' => $itemId]);
        }

        $referer = $request->getHeaderLine('Referer') ?: '/watchlist';
        return $response->withHeader('Location', $referer)->withStatus(302);
    }

    private function json(Response $response, array $payload): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function verifyCsrf(string $submitted): bool
    {
        return isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $submitted);
    }
}
