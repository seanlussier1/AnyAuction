<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Auction;
use App\Models\Order;
use App\Models\Rating;
use App\Services\AuthService;
use App\Services\FlashService;
use App\Services\NotificationService;
use App\Services\StripeService;
use App\Services\Translator;
use App\Services\TwilioService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Throwable;

final class CheckoutController
{
    public function __construct(
        private readonly PDO $db,
        private readonly Twig $view,
        private readonly AuthService $auth,
        private readonly FlashService $flash,
        private readonly StripeService $stripe,
        private readonly TwilioService $twilio,
        private readonly Translator $translator
    ) {
    }

    /**
     * Buyer clicks "Complete Purchase" → we look up the auction, confirm the
     * caller is the winner, create a Stripe Checkout Session, persist a
     * pending order, and 303 the browser to Stripe.
     */
    public function start(Request $request, Response $response, array $args): Response
    {
        if (!$this->auth->isLoggedIn()) {
            $this->flash->error($this->translator->trans('auth.required.checkout'));
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $itemId = (int)($args['id'] ?? 0);

        $body = (array)$request->getParsedBody();
        if (!$this->verifyCsrf((string)($body['_csrf'] ?? ''))) {
            $this->flash->error($this->translator->trans('csrf.expired'));
            return $response->withHeader('Location', '/auction/' . $itemId)->withStatus(302);
        }

        $auction = (new Auction($this->db))->findWithDetails($itemId);
        if (!$auction) {
            return $response->withStatus(404);
        }

        // The user pressing Complete Purchase must be the winning bidder, i.e.
        // their bid equals the auction's current price (and there are bids
        // and a buyout was reached). Mirrors the is_sold/is_winner logic in
        // auction_show.twig.
        $userId  = (int)$_SESSION['user_id'];
        $isSold  = $auction['buy_now_price'] !== null
                && (float)$auction['current_price'] >= (float)$auction['buy_now_price'];
        if (!$isSold) {
            $this->flash->error($this->translator->trans('checkout.error.not_sold'));
            return $response->withHeader('Location', '/auction/' . $itemId)->withStatus(302);
        }
        $check = $this->db->prepare(
            'SELECT 1 FROM bids WHERE item_id = :i AND user_id = :u AND bid_amount = :amt LIMIT 1'
        );
        $check->execute([
            'i'   => $itemId,
            'u'   => $userId,
            'amt' => $auction['current_price'],
        ]);
        if (!$check->fetchColumn()) {
            $this->flash->error($this->translator->trans('checkout.error.not_winner'));
            return $response->withHeader('Location', '/auction/' . $itemId)->withStatus(302);
        }

        $orderModel = new Order($this->db);
        if ($orderModel->isItemPaid($itemId)) {
            $this->flash->success($this->translator->trans('checkout.already_paid'));
            return $response->withHeader('Location', '/auction/' . $itemId)->withStatus(302);
        }

        try {
            $session = $this->stripe->createCheckoutSession(
                itemId:   $itemId,
                title:    (string)$auction['title'],
                amount:   (float)$auction['current_price'],
                imageUrl: $auction['primary_image'] ?? null
            );
        } catch (Throwable $e) {
            $this->flash->error($this->translator->trans('checkout.error.session_expired', [
                'error' => $e->getMessage(),
            ]));
            return $response->withHeader('Location', '/auction/' . $itemId)->withStatus(302);
        }

        $orderModel->create(
            itemId:    $itemId,
            buyerId:   $userId,
            amount:    (float)$auction['current_price'],
            sessionId: (string)$session->id
        );

        return $response->withHeader('Location', (string)$session->url)->withStatus(303);
    }

    /**
     * Stripe redirects here on successful payment with ?session_id=…
     * We retrieve the session, verify payment_status is 'paid', and flip
     * our matching order to paid (idempotently — refreshing the page is
     * safe).
     */
    public function success(Request $request, Response $response): Response
    {
        $sessionId = (string)($request->getQueryParams()['session_id'] ?? '');
        if ($sessionId === '') {
            return $this->view->render($response, 'pages/checkout_result.twig', [
                'state'   => 'error',
                'message' => 'Missing session id.',
            ]);
        }

        try {
            $session = $this->stripe->retrieveSession($sessionId);
        } catch (Throwable $e) {
            return $this->view->render($response, 'pages/checkout_result.twig', [
                'state'   => 'error',
                'message' => 'Could not verify your session: ' . $e->getMessage(),
            ]);
        }

        $orderModel = new Order($this->db);
        $order      = $orderModel->findBySession($sessionId);

        if ($order === null) {
            return $this->view->render($response, 'pages/checkout_result.twig', [
                'state'   => 'error',
                'message' => 'No matching order found.',
            ]);
        }

        if ($session->payment_status === 'paid' && $order['status'] !== 'paid') {
            $orderModel->markPaid((int)$order['order_id']);
            $order['status']  = 'paid';
            $order['paid_at'] = date('Y-m-d H:i:s');
            $this->flash->success($this->translator->trans('checkout.confirmed_msg'));

            // Tell the seller their payout is on its way. Look up the
            // seller from the auction this order paid for.
            $sellerStmt = $this->db->prepare(
                'SELECT seller_id FROM auction_items WHERE item_id = :id'
            );
            $sellerStmt->execute(['id' => (int)$order['item_id']]);
            $sellerId = (int)$sellerStmt->fetchColumn();
            if ($sellerId > 0) {
                (new NotificationService($this->db, $this->twilio, $this->translator))->notifyOrderPaid(
                    $sellerId,
                    (int)$order['item_id'],
                    (float)$order['amount']
                );
            }
        }

        $alreadyRated = false;
        if ($order['status'] === 'paid' && $this->auth->isLoggedIn()) {
            $alreadyRated = (new Rating($this->db))->existsForOrderAndRater(
                (int)$order['order_id'],
                (int)$_SESSION['user_id']
            );
        }
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        }

        return $this->view->render($response, 'pages/checkout_result.twig', [
            'state'   => $session->payment_status === 'paid' ? 'paid' : 'pending',
            'order'   => $order,
            'session' => [
                'id'             => $session->id,
                'payment_status' => $session->payment_status,
            ],
            'already_rated' => $alreadyRated,
            'csrf'          => $_SESSION['_csrf'],
        ]);
    }

    /**
     * Stripe redirects here when the buyer aborts. We mark the pending order
     * cancelled so the buyer can start a fresh session if they change their
     * mind.
     */
    public function cancel(Request $request, Response $response): Response
    {
        $sessionId = (string)($request->getQueryParams()['session_id'] ?? '');
        $itemId    = 0;

        if ($sessionId !== '') {
            $orderModel = new Order($this->db);
            $order      = $orderModel->findBySession($sessionId);
            if ($order !== null) {
                $itemId = (int)$order['item_id'];
                $orderModel->markCancelled((int)$order['order_id']);
            }
        }

        return $this->view->render($response, 'pages/checkout_result.twig', [
            'state'   => 'cancelled',
            'item_id' => $itemId,
        ]);
    }

    private function verifyCsrf(string $submitted): bool
    {
        return isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $submitted);
    }
}
