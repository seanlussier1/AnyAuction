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
use App\Services\Translator;
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
        private readonly TwilioService $twilio,
        private readonly Translator $translator
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
            $this->flash->error($this->translator->trans('auth.required.buy'));
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $body = (array)$request->getParsedBody();

        if (!$this->verifyCsrf((string)($body['_csrf'] ?? ''))) {
            $this->flash->error($this->translator->trans('csrf.expired'));
            return $response->withHeader('Location', '/auction/' . $id)->withStatus(302);
        }

        $auction = (new Auction($this->db))->findWithDetails($id);
        if (!$auction) {
            return $this->notFound($response);
        }
        if ($auction['buy_now_price'] === null) {
            $this->flash->error($this->translator->trans('auction.buyout.no_offer'));
            return $response->withHeader('Location', '/auction/' . $id)->withStatus(302);
        }

        try {
            $userId = (int)$_SESSION['user_id'];
            $result = (new Bid($this->db))->place($id, $userId, (float)$auction['buy_now_price']);

            $notifs = new NotificationService($this->db, $this->twilio, $this->translator);
            $notifs->notifyWatchers($id, 'buyout', [
                'amount'   => (float)$auction['buy_now_price'],
                'actor_id' => $userId,
            ]);

            // Buyout displaced the high bidder — they can't counter-bid (auction
            // is sold) so send the auction-lost message, not the rebid prompt.
            $prevBidderId = $result['previous_bidder_id'] ?? null;
            if ($prevBidderId !== null && $prevBidderId !== $userId) {
                $notifs->notifyAuctionLost($id, $prevBidderId, (float)$result['amount']);
            }

            // Tell the seller their item just sold.
            $sellerId = (int)($result['seller_id'] ?? 0);
            if ($sellerId > 0 && $sellerId !== $userId) {
                $notifs->notifyItemSold($sellerId, $id, (float)$result['amount']);
            }

            $this->flash->success($this->translator->trans('auction.buyout.success'));
        } catch (RuntimeException $e) {
            $this->flash->error($e->getMessage());
        }

        return $response->withHeader('Location', '/auction/' . $id)->withStatus(302);
    }

    public function placeBid(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);

        if (!$this->auth->isLoggedIn()) {
            $this->flash->error($this->translator->trans('auth.required.bid'));
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $body = (array)$request->getParsedBody();

        if (!$this->verifyCsrf((string)($body['_csrf'] ?? ''))) {
            $this->flash->error($this->translator->trans('csrf.expired'));
            return $response->withHeader('Location', '/auction/' . $id)->withStatus(302);
        }

        $amount = filter_var($body['amount'] ?? '', FILTER_VALIDATE_FLOAT);
        if ($amount === false) {
            $this->flash->error($this->translator->trans('auction.bid.invalid_amount'));
            return $response->withHeader('Location', '/auction/' . $id)->withStatus(302);
        }

        try {
            $userId = (int)$_SESSION['user_id'];
            $result = (new Bid($this->db))->place($id, $userId, (float)$amount);
            $finalAmount = (float)$result['amount'];

            $notifs = new NotificationService($this->db, $this->twilio, $this->translator);
            $notifs->notifyWatchers($id, 'bid', [
                'amount'   => $finalAmount,
                'actor_id' => $userId,
            ]);

            // Tell the previous high bidder. If the bid hit the buyout the
            // auction is now sold and they can't reply with a counter-bid,
            // so use the auction-lost message instead.
            $prevBidderId = $result['previous_bidder_id'] ?? null;
            if ($prevBidderId !== null && $prevBidderId !== $userId) {
                if (!empty($result['bought_out'])) {
                    $notifs->notifyAuctionLost($id, $prevBidderId, $finalAmount);
                } else {
                    $notifs->notifyOutbid($id, $prevBidderId, $finalAmount);
                }
            }

            // In-site notifications for the seller side.
            $sellerId = (int)($result['seller_id'] ?? 0);
            if ($sellerId > 0 && $sellerId !== $userId) {
                $notifs->notifyBidReceived($sellerId, $id, $userId, $finalAmount);
                if (!empty($result['bought_out'])) {
                    $notifs->notifyItemSold($sellerId, $id, $finalAmount);
                }
            }

            if ($result['snipe_extended']) {
                $notifs->notifyBidders($id, 'snipe_extension', [
                    'amount'            => $finalAmount,
                    'actor_id'          => $userId,
                    'extension_seconds' => $result['extension_seconds'],
                    'new_end_time'      => $result['new_end_time'],
                ]);
                $extensionMinutes = (int)round($result['extension_seconds'] / 60);
                $this->flash->success($this->translator->trans('auction.bid.placed_snipe', [
                    'amount'  => number_format($finalAmount, 2),
                    'minutes' => $extensionMinutes,
                ]));
            } else {
                $this->flash->success($this->translator->trans('auction.bid.placed', [
                    'amount' => number_format($finalAmount, 2),
                ]));
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
