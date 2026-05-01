<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Auction;
use App\Models\Bid;
use App\Models\Order;
use App\Models\Rating;
use App\Services\AuthService;
use App\Services\FlashService;
use App\Services\NotificationService;
use App\Services\TwilioService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Views\Twig;

final class AuctionController
{
    public function __construct(
        private readonly PDO $db,
        private readonly Twig $view,
        private readonly AuthService $auth,
        private readonly FlashService $flash,
        private readonly TwilioService $twilio
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            return $this->notFound($response);
        }

        $auctionModel = new Auction($this->db);
        $auction = $auctionModel->findWithDetails($id);
        if (!$auction) {
            return $this->notFound($response);
        }

        $images = $auctionModel->imagesFor($id);
        $bids   = (new Bid($this->db))->forAuction($id, 10);

        $minBid = (float)$auction['current_price'] + 1.0;

        // If the current user already paid for this listing, surface the
        // confirmation on the page instead of the Complete Purchase button.
        $orderPaid   = false;
        $orderPaidAt = null;
        if ($this->auth->isLoggedIn()) {
            $existing = (new Order($this->db))->existingForItemAndBuyer($id, (int)$_SESSION['user_id']);
            if ($existing !== null && $existing['status'] === 'paid') {
                $orderPaid   = true;
                $orderPaidAt = $existing['paid_at'];
            }
        }

        $sellerStats = (new Rating($this->db))->statsForUser((int)$auction['seller_id']);

        return $this->view->render($response, 'pages/auction_show.twig', [
            'auction'       => $auction,
            'images'        => $images,
            'bids'          => $bids,
            'min_bid'       => $minBid,
            'csrf'          => $this->ensureCsrfToken(),
            'order_paid'    => $orderPaid,
            'order_paid_at' => $orderPaidAt,
            'seller_stats'  => $sellerStats,
        ]);
    }

    public function buyNow(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);

        if (!$this->auth->isLoggedIn()) {
            $this->flash->error('You need to log in to buy now.');
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $body = (array)$request->getParsedBody();

        if (!$this->verifyCsrf((string)($body['_csrf'] ?? ''))) {
            $this->flash->error('Your session expired. Please try again.');
            return $response->withHeader('Location', '/auction/' . $id)->withStatus(302);
        }

        $auction = (new Auction($this->db))->findWithDetails($id);
        if (!$auction) {
            return $this->notFound($response);
        }
        if ($auction['buy_now_price'] === null) {
            $this->flash->error('This auction does not offer Buy Now.');
            return $response->withHeader('Location', '/auction/' . $id)->withStatus(302);
        }

        try {
            $userId = (int)$_SESSION['user_id'];
            $result = (new Bid($this->db))->place($id, $userId, (float)$auction['buy_now_price']);

            $notifs = new NotificationService($this->db, $this->twilio);
            $notifs->notifyWatchers($id, 'buyout', [
                'amount'   => (float)$auction['buy_now_price'],
                'actor_id' => $userId,
            ]);

            // Buyout displaced the high bidder — text them so they know it's over.
            $prevBidderId = $result['previous_bidder_id'] ?? null;
            if ($prevBidderId !== null && $prevBidderId !== $userId) {
                $notifs->notifyOutbid($id, $prevBidderId, (float)$result['amount']);
            }

            $this->flash->success('Item secured at the buyout price — proceed to checkout.');
        } catch (RuntimeException $e) {
            $this->flash->error($e->getMessage());
        }

        return $response->withHeader('Location', '/auction/' . $id)->withStatus(302);
    }

    public function placeBid(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);

        if (!$this->auth->isLoggedIn()) {
            $this->flash->error('You need to log in before placing a bid.');
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $body = (array)$request->getParsedBody();

        if (!$this->verifyCsrf((string)($body['_csrf'] ?? ''))) {
            $this->flash->error('Your session expired. Please try again.');
            return $response->withHeader('Location', '/auction/' . $id)->withStatus(302);
        }

        $amount = filter_var($body['amount'] ?? '', FILTER_VALIDATE_FLOAT);
        if ($amount === false) {
            $this->flash->error('Enter a valid dollar amount.');
            return $response->withHeader('Location', '/auction/' . $id)->withStatus(302);
        }

        try {
            $userId = (int)$_SESSION['user_id'];
            $result = (new Bid($this->db))->place($id, $userId, (float)$amount);
            $finalAmount = (float)$result['amount'];

            $notifs = new NotificationService($this->db, $this->twilio);
            $notifs->notifyWatchers($id, 'bid', [
                'amount'   => $finalAmount,
                'actor_id' => $userId,
            ]);

            // Tell the previous high bidder they've been outbid (skip if
            // it's the same user raising their own max bid).
            $prevBidderId = $result['previous_bidder_id'] ?? null;
            if ($prevBidderId !== null && $prevBidderId !== $userId) {
                $notifs->notifyOutbid($id, $prevBidderId, $finalAmount);
            }

            if ($result['snipe_extended']) {
                $notifs->notifyBidders($id, 'snipe_extension', [
                    'amount'            => $finalAmount,
                    'actor_id'          => $userId,
                    'extension_seconds' => $result['extension_seconds'],
                    'new_end_time'      => $result['new_end_time'],
                ]);
                $extensionMinutes = (int)round($result['extension_seconds'] / 60);
                $this->flash->success(sprintf(
                    'Bid of $%s placed — last-minute bid, auction extended by %d minutes.',
                    number_format($finalAmount, 2),
                    $extensionMinutes
                ));
            } else {
                $this->flash->success(sprintf('Bid of $%s placed successfully.', number_format($finalAmount, 2)));
            }
        } catch (RuntimeException $e) {
            $this->flash->error($e->getMessage());
        }

        return $response->withHeader('Location', '/auction/' . $id)->withStatus(302);
    }

    private function notFound(Response $response): Response
    {
        return $this->view
            ->render($response->withStatus(404), 'pages/coming_soon.twig', ['page_title' => 'Auction not found'])
            ->withStatus(404);
    }

    private function ensureCsrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['_csrf'];
    }

    private function verifyCsrf(string $submitted): bool
    {
        return isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $submitted);
    }
}
