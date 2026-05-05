<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Auction;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Rating;
use App\Models\Watchlist;
use App\Services\AuthService;
use App\Services\FlashService;
use App\Services\Translator;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ProfileController
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
            $this->flash->error($this->translator->trans('auth.required.profile'));
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $user     = $this->auth->currentUser();
        $userId   = (int)$user['user_id'];
        $auctions = new Auction($this->db);

        $allListings = $auctions->bySeller($userId);
        $allBids     = $auctions->withBidsFrom($userId);
        $sold        = $auctions->soldBySeller($userId);
        $won         = $auctions->wonBy($userId);
        $watchlist   = (new Watchlist($this->db))->forUser($userId);
        $orderModel  = new Order($this->db);
        $orders      = $orderModel->forBuyer($userId);
        $soldOrders  = $orderModel->forSeller($userId);

        // Items already in sold/won shouldn't double-up in the active tabs.
        $soldIds = array_column($sold, 'item_id');
        $wonIds  = array_column($won,  'item_id');

        $listings = array_values(array_filter(
            $allListings,
            static fn ($l) => !in_array($l['item_id'], $soldIds, true)
        ));
        $bids = array_values(array_filter(
            $allBids,
            static fn ($b) => !in_array($b['item_id'], $wonIds, true)
        ));

        $rating    = new Rating($this->db);
        $statsData = $rating->statsForUser($userId);
        $reviews   = $rating->reviewsForUser($userId);

        $notifModel    = new Notification($this->db);
        $notifications = $notifModel->forUser($userId, 50);
        $unreadNotifs  = $notifModel->unreadCountForUser($userId);

        $orderIds = array_merge(
            array_map('intval', array_column($orders, 'order_id')),
            array_map('intval', array_column($soldOrders, 'order_id'))
        );
        $ratedMap = $rating->ratedMap($orderIds, $userId);

        // Indexed by item_id so the Sold-tab card grid can look up the
        // matching order in O(1) without restructuring the existing layout.
        $soldOrdersByItem = [];
        foreach ($soldOrders as $row) {
            $soldOrdersByItem[(int)$row['item_id']] = $row;
        }

        // Same idea for the Won tab — buyer-side mirror so each won card
        // can render a "Rate seller" button keyed on its real order_id.
        $wonOrdersByItem = [];
        foreach ($orders as $row) {
            $wonOrdersByItem[(int)$row['item_id']] = $row;
        }

        $stats = [
            'active_listings'      => count($listings),
            'total_listings'       => count($allListings),
            'active_bids'          => count($bids),
            'watchlist'            => count($watchlist),
            'rating'               => $statsData['average'] !== null ? number_format($statsData['average'], 1) : '—',
            'reviews'              => $statsData['count'],
            'rating_average_round' => $statsData['average'] !== null ? (int)round($statsData['average']) : 0,
            'rating_distribution'  => $statsData['distribution'],
        ];

        return $this->view->render($response, 'pages/profile.twig', [
            'user'                => $user,
            'listings'            => $listings,
            'sold'                => $sold,
            'bids'                => $bids,
            'won'                 => $won,
            'watchlist'           => $watchlist,
            'orders'              => $orders,
            'reviews'             => $reviews,
            'rated_orders'        => $ratedMap,
            'sold_orders_by_item' => $soldOrdersByItem,
            'won_orders_by_item'  => $wonOrdersByItem,
            'notifications'       => $notifications,
            'unread_notifs'       => $unreadNotifs,
            'csrf'                => $this->ensureCsrfToken(),
            'stats'               => $stats,
        ]);
    }

    public function updateSettings(Request $request, Response $response): Response
    {
        if (!$this->auth->isLoggedIn()) {
            $this->flash->error($this->translator->trans('auth.required.profile'));
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $body = (array)$request->getParsedBody();

        if (!$this->verifyCsrf((string)($body['_csrf'] ?? ''))) {
            $this->flash->error($this->translator->trans('csrf.expired'));
            return $response->withHeader('Location', '/profile#tab-settings')->withStatus(302);
        }

        $user   = $this->auth->currentUser();
        $userId = (int)$user['user_id'];

        // Unchecked checkboxes are not sent in form data, so absence means 0.
        // sms_opt_out is opt-OUT semantics: unchecked label "SMS notifications"
        // means the user wants OUT, which stores as 1.
        $smsOn = isset($body['pref_sms_twilio']);
        $smsOptOut       = $smsOn ? 0 : 1;
        $prefEmailBids   = isset($body['pref_email_bids'])    ? 1 : 0;
        $prefOutbid      = isset($body['pref_outbid'])        ? 1 : 0;
        $prefWeekly      = isset($body['pref_weekly_digest']) ? 1 : 0;
        $prefOrderUpdate = isset($body['pref_order_updates']) ? 1 : 0;

        $stmt = $this->db->prepare(
            'UPDATE users
                SET sms_opt_out        = :sms,
                    pref_email_bids    = :email_bids,
                    pref_outbid        = :outbid,
                    pref_weekly_digest = :weekly,
                    pref_order_updates = :orders
              WHERE user_id = :uid'
        );
        $ok = $stmt->execute([
            'sms'        => $smsOptOut,
            'email_bids' => $prefEmailBids,
            'outbid'     => $prefOutbid,
            'weekly'     => $prefWeekly,
            'orders'     => $prefOrderUpdate,
            'uid'        => $userId,
        ]);

        if ($ok) {
            $this->flash->success($this->translator->trans('profile.settings.saved'));
        } else {
            $this->flash->error($this->translator->trans('profile.settings.save_failed'));
        }

        return $response->withHeader('Location', '/profile#tab-settings')->withStatus(302);
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
