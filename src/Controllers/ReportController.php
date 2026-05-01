<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Auction;
use App\Models\Report;
use App\Services\AuthService;
use App\Services\FlashService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * User-facing report flow. The button on the auction detail page links
 * here; the form posts back and creates a `reports` row that the admin
 * panel reviews. Works regardless of auction status (active / closed /
 * paid) — buyers may need to report problems with completed orders too.
 */
final class ReportController
{
    public function __construct(
        private readonly PDO $db,
        private readonly Twig $view,
        private readonly AuthService $auth,
        private readonly FlashService $flash
    ) {
    }

    public function showListing(Request $request, Response $response, array $args): Response
    {
        if (!$this->auth->isLoggedIn()) {
            $this->flash->error('Log in to report a listing.');
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $itemId  = (int)($args['itemId'] ?? 0);
        $auction = (new Auction($this->db))->findWithDetails($itemId);
        if (!$auction) {
            return $response->withStatus(404);
        }

        return $this->view->render($response, 'pages/report_listing.twig', [
            'auction' => $auction,
            'reasons' => Report::LISTING_REASONS,
            'old'     => [],
        ]);
    }

    public function submitListing(Request $request, Response $response, array $args): Response
    {
        if (!$this->auth->isLoggedIn()) {
            $this->flash->error('Log in to report a listing.');
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $itemId = (int)($args['itemId'] ?? 0);
        $body   = (array)$request->getParsedBody();

        if (!$this->verifyCsrf((string)($body['_csrf'] ?? ''))) {
            $this->flash->error('Your session expired. Please try again.');
            return $response->withHeader('Location', '/report/listing/' . $itemId)->withStatus(302);
        }

        $reason  = trim((string)($body['reason']  ?? ''));
        $details = trim((string)($body['details'] ?? ''));

        $auction = (new Auction($this->db))->findWithDetails($itemId);
        if (!$auction) {
            return $response->withStatus(404);
        }

        $errors = [];
        if (!isset(Report::LISTING_REASONS[$reason])) {
            $errors['reason'] = 'Pick a reason from the list.';
        }
        if (mb_strlen($details) < 10) {
            $errors['details'] = 'Please give at least a sentence describing what went wrong.';
        }
        if (mb_strlen($details) > 2000) {
            $errors['details'] = 'Keep it under 2000 characters.';
        }
        if ($errors !== []) {
            return $this->view->render($response, 'pages/report_listing.twig', [
                'auction' => $auction,
                'reasons' => Report::LISTING_REASONS,
                'errors'  => $errors,
                'old'     => compact('reason', 'details'),
            ]);
        }

        (new Report($this->db))->createListingReport(
            (int)$_SESSION['user_id'],
            $itemId,
            $reason,
            $details
        );

        $this->flash->success('Report submitted. An admin will take a look shortly.');
        return $response->withHeader('Location', '/auction/' . $itemId)->withStatus(302);
    }

    public function resolve(Request $request, Response $response, array $args): Response
    {
        return $this->moderate($request, $response, $args, 'resolved');
    }

    public function dismiss(Request $request, Response $response, array $args): Response
    {
        return $this->moderate($request, $response, $args, 'dismissed');
    }

    private function moderate(Request $request, Response $response, array $args, string $status): Response
    {
        if (!$this->auth->isLoggedIn()) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }
        $current = $this->auth->currentUser();
        if (($current['role'] ?? '') !== 'admin') {
            $this->flash->error('Admins only.');
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        $body = (array)$request->getParsedBody();
        if (!$this->verifyCsrf((string)($body['_csrf'] ?? ''))) {
            $this->flash->error('Your session expired. Please try again.');
            return $response->withHeader('Location', '/admin?tab=reports')->withStatus(302);
        }

        $reportId = (int)($args['id'] ?? 0);
        if ((new Report($this->db))->setStatus($reportId, $status)) {
            $this->flash->success(sprintf('Report marked %s.', $status));
        } else {
            $this->flash->error('Could not update that report (already actioned?).');
        }

        return $response->withHeader('Location', '/admin?tab=reports')->withStatus(302);
    }

    private function verifyCsrf(string $submitted): bool
    {
        return isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $submitted);
    }
}
