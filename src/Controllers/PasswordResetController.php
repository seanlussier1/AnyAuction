<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthCodeService;
use App\Services\AuthService;
use App\Services\FlashService;
use App\Services\Translator;
use App\Services\TwilioService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Forgot-password via SMS.
 *
 * Flow:
 *   GET  /forgot-password   -> showForgot
 *   POST /forgot-password   -> requestReset (sends code if user has verified phone)
 *   GET  /reset-password    -> showReset
 *   POST /reset-password    -> submitReset (verifies code + writes new password)
 *
 * To avoid account enumeration we always flash the same generic message
 * on POST /forgot-password and always redirect to /reset-password,
 * whether or not the email matched a real user.
 */
final class PasswordResetController
{
    private const MIN_PASSWORD_LEN = 8;

    public function __construct(
        private readonly Twig $view,
        private readonly AuthService $auth,
        private readonly FlashService $flash,
        private readonly AuthCodeService $codes,
        private readonly TwilioService $twilio,
        private readonly Translator $translator
    ) {
    }

    public function showForgot(Request $request, Response $response): Response
    {
        if ($this->auth->isLoggedIn()) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        return $this->view->render($response, 'auth/forgot_password.twig', ['old' => []]);
    }

    public function requestReset(Request $request, Response $response): Response
    {
        $body = (array)$request->getParsedBody();

        if (!$this->verifyCsrf((string)($body['_csrf'] ?? ''))) {
            $this->flash->error($this->translator->trans('csrf.expired'));
            return $response->withHeader('Location', '/forgot-password')->withStatus(302);
        }

        $email = trim((string)($body['email'] ?? ''));

        if ($email === '') {
            $this->flash->error($this->translator->trans('auth.forgot.email_required'));
            return $this->view->render($response, 'auth/forgot_password.twig', ['old' => ['email' => $email]]);
        }

        $user = $this->auth->findByEmail($email);

        if ($user !== null && !empty($user['phone_verified_at']) && !empty($user['phone'])) {
            $userId = (int)$user['user_id'];
            $code = $this->codes->issue($userId, 'password_reset');
            $this->twilio->sendSms(
                (string)$user['phone'],
                $this->translator->trans(
                    'sms.code.password_reset',
                    ['code' => $code],
                    (string)($user['locale'] ?? 'en')
                )
            );
            // Dev-mode crutch — see AuthController for context.
            error_log("[dev-2fa] password_reset code for user_id={$userId}: {$code}");

            $_SESSION['pending_reset_user_id'] = $userId;
        } else {
            // Don't set the pending session key — we deliberately fail
            // verification on POST /reset-password rather than leaking
            // whether the email exists.
            unset($_SESSION['pending_reset_user_id']);
        }

        $this->flash->success($this->translator->trans('auth.forgot.generic_sent'));
        return $response->withHeader('Location', '/reset-password')->withStatus(302);
    }

    public function showReset(Request $request, Response $response): Response
    {
        if ($this->auth->isLoggedIn()) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        return $this->view->render($response, 'auth/reset_password.twig', []);
    }

    public function submitReset(Request $request, Response $response): Response
    {
        $body = (array)$request->getParsedBody();

        if (!$this->verifyCsrf((string)($body['_csrf'] ?? ''))) {
            $this->flash->error($this->translator->trans('csrf.expired'));
            return $response->withHeader('Location', '/reset-password')->withStatus(302);
        }

        $code     = trim((string)($body['code']             ?? ''));
        $password = (string)($body['password']               ?? '');
        $confirm  = (string)($body['password_confirm']       ?? '');

        if ($password !== $confirm) {
            $this->flash->error($this->translator->trans('auth.reset.passwords_mismatch'));
            return $this->view->render($response, 'auth/reset_password.twig', []);
        }

        if (strlen($password) < self::MIN_PASSWORD_LEN) {
            $this->flash->error($this->translator->trans('auth.reset.password_too_short'));
            return $this->view->render($response, 'auth/reset_password.twig', []);
        }

        $userId = (int)($_SESSION['pending_reset_user_id'] ?? 0);
        if ($userId <= 0 || !$this->codes->verify($userId, 'password_reset', $code)) {
            $this->flash->error($this->translator->trans('auth.reset.bad_code'));
            return $this->view->render($response, 'auth/reset_password.twig', []);
        }

        $this->auth->updatePassword($userId, $password);
        unset($_SESSION['pending_reset_user_id']);

        $this->flash->success($this->translator->trans('auth.reset.success'));
        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    private function verifyCsrf(string $submitted): bool
    {
        return isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $submitted);
    }
}
