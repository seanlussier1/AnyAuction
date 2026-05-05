<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthCodeService;
use App\Services\AuthService;
use App\Services\FlashService;
use App\Services\NotificationService;
use App\Services\PhoneNormalizer;
use App\Services\Translator;
use App\Services\TwilioService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class AuthController
{
    /**
     * Seeded demo accounts that bypass phone enrollment + SMS 2FA so the
     * team can log in for testing without burning a Twilio number per
     * person. Real signups still go through the full flow.
     */
    private const DEMO_EMAILS = [
        'buyer@anyauction.test',
        'seller@anyauction.test',
        'admin@anyauction.test',
        'riley@anyauction.test',
        'sam@anyauction.test',
        'taylor@anyauction.test',
        'morgan@anyauction.test',
        'spammy@anyauction.test',
        'warned@anyauction.test',
        'banned@anyauction.test',
    ];

    public function __construct(
        private readonly Twig $view,
        private readonly AuthService $auth,
        private readonly FlashService $flash,
        private readonly AuthCodeService $codes,
        private readonly TwilioService $twilio,
        private readonly PDO $db,
        private readonly Translator $translator
    ) {
    }

    public function showRegister(Request $request, Response $response): Response
    {
        if ($this->auth->isLoggedIn()) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        return $this->view->render($response, 'auth/register.twig', ['old' => []]);
    }

    public function register(Request $request, Response $response): Response
    {
        $body = (array)$request->getParsedBody();

        if (!$this->verifyCsrf((string)($body['_csrf'] ?? ''))) {
            $this->flash->error($this->translator->trans('csrf.expired'));
            return $response->withHeader('Location', '/register')->withStatus(302);
        }

        $email     = trim((string)($body['email']      ?? ''));
        $username  = trim((string)($body['username']   ?? ''));
        $firstName = trim((string)($body['first_name'] ?? ''));
        $lastName  = trim((string)($body['last_name']  ?? ''));
        $phoneRaw  = trim((string)($body['phone']      ?? ''));
        $password  = (string)($body['password']         ?? '');
        $confirm   = (string)($body['password_confirm'] ?? '');

        $old = compact('email', 'username', 'firstName', 'lastName') + ['phone' => $phoneRaw];

        if ($password !== $confirm) {
            $this->flash->error($this->translator->trans('auth.register.passwords_mismatch'));
            return $this->view->render($response, 'auth/register.twig', ['old' => $old]);
        }

        $phoneE164 = PhoneNormalizer::normalize($phoneRaw);
        if ($phoneE164 === null) {
            $this->flash->error($this->translator->trans('auth.register.bad_phone'));
            return $this->view->render($response, 'auth/register.twig', ['old' => $old]);
        }

        $newId = $this->auth->register($email, $username, $password, $firstName, $lastName, $phoneE164);

        if ($newId === null) {
            return $this->view->render($response, 'auth/register.twig', ['old' => $old]);
        }

        // Drop a welcome notification so the new user has something on the
        // bell from day one. Always site-only — no SMS for this event.
        (new NotificationService($this->db, $this->twilio, $this->translator))->notifyWelcome($newId, $firstName);

        // Force the new user through phone verification before logging them
        // in. The phone is on the row but phone_verified_at is NULL, so the
        // enrollment flow will move straight to the "enter code" step.
        // Brand-new accounts haven't picked a language yet; default to 'en'.
        $code = $this->codes->issue($newId, 'phone_verify');
        $this->twilio->sendSms(
            $phoneE164,
            $this->translator->trans('sms.code.phone_verify', ['code' => $code], 'en')
        );
        // Dev-mode crutch: surface the plaintext code in php logs so devs
        // without Twilio creds can complete the flow. Remove or gate behind
        // APP_ENV=dev if/when Twilio is wired up everywhere.
        error_log("[dev-2fa] phone_verify code for user_id={$newId}: {$code}");

        $_SESSION['pending_enrollment_user_id'] = $newId;
        $_SESSION['_enroll_step']               = 'code';

        $this->flash->success($this->translator->trans('auth.register.created'));
        return $response->withHeader('Location', '/enroll-phone?step=code')->withStatus(302);
    }

    public function showLogin(Request $request, Response $response): Response
    {
        if ($this->auth->isLoggedIn()) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        return $this->view->render($response, 'auth/login.twig', ['old' => []]);
    }

    public function login(Request $request, Response $response): Response
    {
        $body     = (array)$request->getParsedBody();

        if (!$this->verifyCsrf((string)($body['_csrf'] ?? ''))) {
            $this->flash->error($this->translator->trans('csrf.expired'));
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $email    = trim((string)($body['email']    ?? ''));
        $password = (string)($body['password']       ?? '');

        if ($email === '' || $password === '') {
            $this->flash->error($this->translator->trans('auth.login.both_required'));
            return $this->view->render($response, 'auth/login.twig', ['old' => ['email' => $email]]);
        }

        $user = $this->auth->verifyPassword($email, $password);
        if ($user === null) {
            return $this->view->render($response, 'auth/login.twig', ['old' => ['email' => $email]]);
        }

        // Banned account: render the form with an inline TOS notice instead
        // of just bouncing back with a generic flash. Done before any other
        // post-auth path (demo bypass, enrollment, 2FA) so a banned user
        // never gets further than the login screen.
        if (($user['account_status'] ?? 'active') === 'banned') {
            return $this->view->render($response, 'auth/login.twig', [
                'old'    => ['email' => $email],
                'errors' => ['banned' => true],
            ]);
        }

        $userId = (int)$user['user_id'];

        // Demo accounts skip 2FA + enrollment entirely so the team can
        // test without three real phones.
        if (in_array(strtolower($email), self::DEMO_EMAILS, true)) {
            unset(
                $_SESSION['pending_enrollment_user_id'],
                $_SESSION['_enroll_step'],
                $_SESSION['pending_2fa_user_id'],
                $_SESSION['pending_2fa_at']
            );
            $this->auth->completeLogin($userId);
            $this->flash->success($this->translator->trans('auth.login.welcome_back'));
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        // Legacy users with no verified phone get pushed into enrollment
        // before they're logged in. Their session_id stays unchanged
        // until they finish the flow.
        if (empty($user['phone_verified_at'])) {
            unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_at']);
            $_SESSION['pending_enrollment_user_id'] = $userId;

            // If they already have a phone on file (set during register but
            // never verified, or backfilled by an admin), jump straight to
            // the code step. Otherwise prompt for the number.
            if (!empty($user['phone'])) {
                $code = $this->codes->issue($userId, 'phone_verify');
                $this->twilio->sendSms(
                    (string)$user['phone'],
                    $this->translator->trans(
                        'sms.code.phone_verify',
                        ['code' => $code],
                        (string)($user['locale'] ?? 'en')
                    )
                );
                error_log("[dev-2fa] phone_verify code for user_id={$userId}: {$code}");
                $_SESSION['_enroll_step'] = 'code';
                return $response->withHeader('Location', '/enroll-phone?step=code')->withStatus(302);
            }

            $_SESSION['_enroll_step'] = 'phone';
            return $response->withHeader('Location', '/enroll-phone')->withStatus(302);
        }

        // Standard 2FA path: issue login code, redirect to verify form.
        unset($_SESSION['pending_enrollment_user_id'], $_SESSION['_enroll_step']);
        $_SESSION['pending_2fa_user_id'] = $userId;
        $_SESSION['pending_2fa_at']      = time();
        $_SESSION['_2fa_attempts']       = 0;
        unset($_SESSION['_2fa_resent_at']);

        $code = $this->codes->issue($userId, 'login');
        $this->twilio->sendSms(
            (string)$user['phone'],
            $this->translator->trans(
                'sms.code.login',
                ['code' => $code],
                (string)($user['locale'] ?? 'en')
            )
        );
        error_log("[dev-2fa] login code for user_id={$userId}: {$code}");

        $this->flash->success($this->translator->trans('auth.2fa.code_sent'));
        return $response->withHeader('Location', '/verify-2fa')->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout();
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    private function verifyCsrf(string $submitted): bool
    {
        return isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $submitted);
    }
}
