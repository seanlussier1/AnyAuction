<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Bid;
use App\Models\SmsConversation;
use App\Services\NotificationService;
use App\Services\TwilioService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Throwable;

/**
 * Inbound SMS handler — wired to Twilio Programmable Messaging.
 *
 * Twilio POSTs application/x-www-form-urlencoded to /api/twilio/sms with
 * From / Body / MessageSid / AccountSid plus an X-Twilio-Signature header.
 * We validate the signature, then drive a tiny state machine over
 * `sms_conversations`:
 *
 *   waiting_amount  → reply with a dollar amount → waiting_confirm
 *   waiting_confirm → reply YES → place the bid → row deleted
 *
 * Keyword overrides (STOP / START / etc.) short-circuit the state machine.
 *
 * Responses are TwiML so Twilio relays them back to the buyer as the same
 * thread. CSRF intentionally NOT enforced — Twilio doesn't send tokens, we
 * authenticate via the signature instead.
 */
final class TwilioWebhookController
{
    /** Short TTL on the confirm step — we want a fresh "YES", not yesterday's. */
    private const CONFIRM_TTL_SECONDS = 300;

    /** STOP / START keyword sets per Twilio carrier-compliance docs. */
    private const STOP_KEYWORDS  = ['stop', 'unsubscribe', 'cancel', 'end', 'quit'];
    private const START_KEYWORDS = ['start', 'unstop', 'subscribe'];
    private const YES_KEYWORDS   = ['y', 'yes', 'confirm', 'ok'];

    public function __construct(
        private readonly PDO $db,
        private readonly TwilioService $twilio
    ) {
    }

    public function sms(Request $request, Response $response): Response
    {
        $params  = (array)$request->getParsedBody();
        $headers = $request->getHeader('X-Twilio-Signature');
        $sigHdr  = $headers[0] ?? '';

        // 1. Signature validation. In dev (no auth_token) we bypass and log
        // — the TwilioService validator returns false unconditionally there
        // and we don't want to lock ourselves out of local testing.
        if ($this->twilio->isConfigured()) {
            $signedUrl = $this->signedUrl($request);
            if (!$this->twilio->validateSignature($signedUrl, $params, $sigHdr)) {
                $response->getBody()->write('invalid signature');
                return $response->withStatus(403)
                    ->withHeader('Content-Type', 'text/plain; charset=UTF-8');
            }
        } else {
            error_log('[TwilioWebhook] signature validation bypassed — TWILIO_AUTH_TOKEN unset.');
        }

        $from = trim((string)($params['From'] ?? ''));
        $body = (string)($params['Body'] ?? '');
        if ($from === '') {
            return $this->twiml($response, "AnyAuction: missing sender — ignored.");
        }

        $bodyTrim  = trim($body);
        $bodyLower = strtolower($bodyTrim);

        $convos = new SmsConversation($this->db);
        $convos->gc();

        // 2. Keyword overrides — STOP / START always take precedence over
        // any in-flight conversation. Compliance + sane UX.
        if (in_array($bodyLower, self::STOP_KEYWORDS, true)) {
            return $this->handleStop($response, $convos, $from);
        }
        if (in_array($bodyLower, self::START_KEYWORDS, true)) {
            return $this->handleStart($response, $from);
        }

        // 3. Tie the inbound number to a verified user.
        $user = $this->findUserByPhone($from);
        if ($user === null) {
            return $this->twiml(
                $response,
                "AnyAuction: we couldn't find an account for this number. Reply START or visit anyauction.org/profile to add one."
            );
        }
        if ((int)$user['sms_opt_out'] === 1) {
            // Silently drop — they opted out. Don't reopen a thread.
            return $this->twiml($response, "AnyAuction: you're unsubscribed. Reply START to resubscribe.");
        }

        // 4. Look up the active conversation. No row = no active outbid.
        $convo = $convos->findByPhone($from);
        if ($convo === null) {
            return $this->twiml(
                $response,
                "AnyAuction: no active outbid waiting. Visit anyauction.org/browse to bid."
            );
        }

        $itemId = (int)$convo['item_id'];
        $title  = $this->lookupTitle($itemId) ?: 'this auction';

        // 5. State machine.
        if ($convo['state'] === 'waiting_amount') {
            return $this->handleWaitingAmount(
                $response,
                $convos,
                $from,
                $title,
                $bodyTrim
            );
        }

        if ($convo['state'] === 'waiting_confirm') {
            return $this->handleWaitingConfirm(
                $response,
                $convos,
                $from,
                $itemId,
                (int)$user['user_id'],
                (float)$convo['pending_amount'],
                $bodyLower
            );
        }

        // Unknown state — defensive; shouldn't happen given the ENUM.
        $convos->delete($from);
        return $this->twiml($response, "AnyAuction: conversation reset. Reply with a bid amount on the next outbid.");
    }

    // --------------------------------------------------------------------
    // Keyword handlers
    // --------------------------------------------------------------------

    private function handleStop(Response $response, SmsConversation $convos, string $phone): Response
    {
        // Mark every account on this number opted-out — same-number-shared
        // family scenarios are rare but we don't want one user's STOP to
        // leave a sibling getting texts.
        $stmt = $this->db->prepare(
            'UPDATE users SET sms_opt_out = 1 WHERE phone = :p'
        );
        $stmt->execute(['p' => $phone]);

        $convos->delete($phone);

        return $this->twiml(
            $response,
            "AnyAuction: you're unsubscribed and will receive no more messages. Reply START to resubscribe."
        );
    }

    private function handleStart(Response $response, string $phone): Response
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET sms_opt_out = 0 WHERE phone = :p'
        );
        $stmt->execute(['p' => $phone]);

        return $this->twiml(
            $response,
            "AnyAuction: you're resubscribed. We'll text you when you're outbid. Reply STOP to opt out."
        );
    }

    // --------------------------------------------------------------------
    // State handlers
    // --------------------------------------------------------------------

    private function handleWaitingAmount(
        Response $response,
        SmsConversation $convos,
        string $phone,
        string $title,
        string $body
    ): Response {
        $amount = $this->parseDollarAmount($body);
        if ($amount === null) {
            return $this->twiml(
                $response,
                "AnyAuction: I couldn't read that as a dollar amount. Reply with a number like '70' or '$70.50', or STOP to opt out."
            );
        }
        if ($amount <= 0) {
            return $this->twiml($response, "AnyAuction: bid must be a positive amount.");
        }

        $convos->setWaitingConfirm($phone, $amount, self::CONFIRM_TTL_SECONDS);

        return $this->twiml(
            $response,
            sprintf(
                "AnyAuction: confirm bid of $%s on '%s'? Reply YES to place, anything else cancels.",
                number_format($amount, 2),
                $title
            )
        );
    }

    private function handleWaitingConfirm(
        Response $response,
        SmsConversation $convos,
        string $phone,
        int $itemId,
        int $userId,
        float $amount,
        string $bodyLower
    ): Response {
        if (!in_array($bodyLower, self::YES_KEYWORDS, true)) {
            $convos->delete($phone);
            return $this->twiml(
                $response,
                sprintf(
                    "AnyAuction: bid cancelled. Visit anyauction.org/auction/%d to bid online.",
                    $itemId
                )
            );
        }

        // Execute through the same model the website uses, so all the
        // validation (auction active, not seller, snipe extension, buyout
        // cap, current_price floor) runs identically here.
        try {
            $result = (new Bid($this->db))->place($itemId, $userId, $amount);
        } catch (RuntimeException $e) {
            $convos->delete($phone);
            return $this->twiml($response, 'AnyAuction: ' . $e->getMessage());
        } catch (Throwable $e) {
            error_log('[TwilioWebhook] bid place failed: ' . $e->getMessage());
            $convos->delete($phone);
            return $this->twiml($response, 'AnyAuction: something went wrong placing that bid. Try again on the site.');
        }

        // Mirror the AuctionController fan-out so the site state stays
        // consistent regardless of which entry point placed the bid.
        $notifs = new NotificationService($this->db, $this->twilio);
        $notifs->notifyWatchers($itemId, 'bid', [
            'amount'   => (float)$result['amount'],
            'actor_id' => $userId,
        ]);

        $prevBidderId = $result['previous_bidder_id'] ?? null;
        if ($prevBidderId !== null && $prevBidderId !== $userId) {
            $notifs->notifyOutbid($itemId, $prevBidderId, (float)$result['amount']);
        }

        if (!empty($result['snipe_extended'])) {
            $notifs->notifyBidders($itemId, 'snipe_extension', [
                'amount'            => (float)$result['amount'],
                'actor_id'          => $userId,
                'extension_seconds' => $result['extension_seconds'],
                'new_end_time'      => $result['new_end_time'],
            ]);
        }

        $convos->delete($phone);

        $msg = sprintf(
            "AnyAuction: bid of $%s placed.",
            number_format((float)$result['amount'], 2)
        );
        if (!empty($result['snipe_extended'])) {
            $extMinutes = (int)round(((int)$result['extension_seconds']) / 60);
            $msg .= sprintf(' Auction extended by %d min.', $extMinutes);
        }
        return $this->twiml($response, $msg);
    }

    // --------------------------------------------------------------------
    // Helpers
    // --------------------------------------------------------------------

    /**
     * Parse "70", "$70", "70$", "70.50", "$70.50", " 70 " into a float.
     * Returns null if unparseable.
     */
    private function parseDollarAmount(string $body): ?float
    {
        $cleaned = trim(str_replace(['$', ',', ' '], '', $body));
        if ($cleaned === '' || !is_numeric($cleaned)) {
            return null;
        }
        return (float)$cleaned;
    }

    private function findUserByPhone(string $phone): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT user_id, phone, sms_opt_out
             FROM users
             WHERE phone = :p AND phone_verified_at IS NOT NULL
             LIMIT 1'
        );
        $stmt->execute(['p' => $phone]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function lookupTitle(int $itemId): ?string
    {
        $stmt = $this->db->prepare('SELECT title FROM auction_items WHERE item_id = :i');
        $stmt->execute(['i' => $itemId]);
        $title = $stmt->fetchColumn();
        return $title === false ? null : (string)$title;
    }

    /**
     * The exact URL Twilio signed — match what they hit, including scheme +
     * host + path. Prefer APP_BASE_URL so reverse-proxy SSL termination
     * doesn't break the signature; fall back to the request URI for dev.
     */
    private function signedUrl(Request $request): string
    {
        $base = (string)($_ENV['APP_BASE_URL'] ?? '');
        if ($base !== '') {
            return rtrim($base, '/') . (string)$request->getUri()->getPath();
        }
        return (string)$request->getUri();
    }

    private function twiml(Response $response, string $body): Response
    {
        $response->getBody()->write(
            '<?xml version="1.0" encoding="UTF-8"?><Response><Message>'
            . htmlspecialchars($body, ENT_XML1, 'UTF-8')
            . '</Message></Response>'
        );
        return $response->withHeader('Content-Type', 'text/xml; charset=UTF-8');
    }
}
