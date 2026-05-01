<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthCodeService;
use App\Services\AuthService;
use App\Services\FlashService;
use App\Services\TwilioService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Step 2 of login: verify the SMS code we sent after a valid password.
 *
 * Pending state lives in session keys set by AuthController::login():
 *   - $_SESSION['pending_2fa_user_id']   user awaiting verification
 *   - $_SESSION['pending_2fa_at']        unix ts of when we sent the code
 *   - $_SESSION['_2fa_attempts']         failed-attempt counter (cap 5)
 *   - $_SESSION['_2fa_resent_at']        unix ts of last resend (rate limit)
 */
final class Auth2FAController
{
    private const MAX_ATTEMPTS    = 5;
    private const RESEND_COOLDOWN = 60;

    public function __construct(
        private readonly Twig $view,
        private readonly AuthService $auth,
        private readonly FlashService $flash,
        private readonly AuthCodeService $codes,
        private readonly TwilioService $twilio
    ) {
    }

    public function showVerify(Request $request, Response $response): Response
    {
        if (empty($_SESSION['pending_2fa_user_id'])) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }
        return $this->view->render($response, 'auth/verify_2fa.twig', []);
    }

    public function verify(Request $request, Response $response): Response
    {
        $body = (array)$request->getParsedBody();

        if (!$this->verifyCsrf((string)($body['_csrf'] ?? ''))) {
            $this->flash->error('Your session expired. Please try again.');
            return $response->withHeader('Location', '/verify-2fa')->withStatus(302);
        }

        $userId = (int)($_SESSION['pending_2fa_user_id'] ?? 0);
        if ($userId <= 0) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $code = trim((string)($body['code'] ?? ''));

        if (!$this->codes->verify($userId, 'login', $code)) {
            $_SESSION['_2fa_attempts'] = (int)($_SESSION['_2fa_attempts'] ?? 0) + 1;

            if ($_SESSION['_2fa_attempts'] >= self::MAX_ATTEMPTS) {
                unset(
                    $_SESSION['pending_2fa_user_id'],
                    $_SESSION['pending_2fa_at'],
                    $_SESSION['_2fa_attempts'],
                    $_SESSION['_2fa_resent_at']
                );
                $this->flash->error('Too many incorrect codes. Please log in again.');
                return $response->withHeader('Location', '/login')->withStatus(302);
            }

            $this->flash->error('That code is invalid or expired.');
            return $this->view->render($response, 'auth/verify_2fa.twig', []);
        }

        // Code accepted. Clear pending state and finalize login.
        unset(
            $_SESSION['pending_2fa_user_id'],
            $_SESSION['pending_2fa_at'],
            $_SESSION['_2fa_attempts'],
            $_SESSION['_2fa_resent_at']
        );
        $this->auth->completeLogin($userId);

        $this->flash->success('Welcome back!');
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    public function resend(Request $request, Response $response): Response
    {
        $body = (array)$request->getParsedBody();

        if (!$this->verifyCsrf((string)($body['_csrf'] ?? ''))) {
            $this->flash->error('Your session expired. Please try again.');
            return $response->withHeader('Location', '/verify-2fa')->withStatus(302);
        }

        $userId = (int)($_SESSION['pending_2fa_user_id'] ?? 0);
        if ($userId <= 0) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $lastSent = (int)($_SESSION['_2fa_resent_at'] ?? 0);
        if ($lastSent > 0 && (time() - $lastSent) < self::RESEND_COOLDOWN) {
            $this->flash->error('Please wait a moment before requesting another code.');
            return $response->withHeader('Location', '/verify-2fa')->withStatus(302);
        }

        $user = $this->auth->findById($userId);
        if ($user === null || empty($user['phone'])) {
            unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_at']);
            $this->flash->error('We could not find a phone on file for your account. Please log in again.');
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $code = $this->codes->issue($userId, 'login');
        $this->twilio->sendSms(
            (string)$user['phone'],
            "Your AnyAuction code is {$code}. Expires in 10 minutes."
        );
        // Dev-mode crutch — see AuthController for context.
        error_log("[dev-2fa] login code (resent) for user_id={$userId}: {$code}");

        $_SESSION['_2fa_resent_at'] = time();

        $this->flash->add('info', 'A new code is on the way.');
        return $response->withHeader('Location', '/verify-2fa')->withStatus(302);
    }

    private function verifyCsrf(string $submitted): bool
    {
        return isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $submitted);
    }
}
