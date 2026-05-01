<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthCodeService;
use App\Services\AuthService;
use App\Services\FlashService;
use App\Services\NotificationService;
use App\Services\PhoneNormalizer;
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
    ];

    public function __construct(
        private readonly Twig $view,
        private readonly AuthService $auth,
        private readonly FlashService $flash,
        private readonly AuthCodeService $codes,
        private readonly TwilioService $twilio,
        private readonly PDO $db
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
            $this->flash->error('Your session expired. Please try again.');
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
            $this->flash->error('Passwords do not match.');
            return $this->view->render($response, 'auth/register.twig', ['old' => $old]);
        }

        $phoneE164 = PhoneNormalizer::normalize($phoneRaw);
        if ($phoneE164 === null) {
            $this->flash->error('Please enter a valid US or Canada phone number.');
            return $this->view->render($response, 'auth/register.twig', ['old' => $old]);
        }

        $newId = $this->auth->register($email, $username, $password, $firstName, $lastName, $phoneE164);

        if ($newId === null) {
            return $this->view->render($response, 'auth/register.twig', ['old' => $old]);
        }

        // Drop a welcome notification so the new user has something on the
        // bell from day one. Always site-only — no SMS for this event.
        (new NotificationService($this->db, $this->twilio))->notifyWelcome($newId, $firstName);

        // Force the new user through phone verification before logging them
        // in. The phone is on the row but phone_verified_at is NULL, so the
        // enrollment flow will move straight to the "enter code" step.
        $code = $this->codes->issue($newId, 'phone_verify');
        $this->twilio->sendSms(
            $phoneE164,
            "Verify your AnyAuction phone with code {$code}. Expires in 10 minutes."
        );
        // Dev-mode crutch: surface the plaintext code in php logs so devs
        // without Twilio creds can complete the flow. Remove or gate behind
        // APP_ENV=dev if/when Twilio is wired up everywhere.
        error_log("[dev-2fa] phone_verify code for user_id={$newId}: {$code}");

        $_SESSION['pending_enrollment_user_id'] = $newId;
        $_SESSION['_enroll_step']               = 'code';

        $this->flash->success('Account created. Enter the 6-digit code we just texted you.');
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
            $this->flash->error('Your session expired. Please try again.');
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $email    = trim((string)($body['email']    ?? ''));
        $password = (string)($body['password']       ?? '');

        if ($email === '' || $password === '') {
            $this->flash->error('Please enter both your email and password.');
            return $this->view->render($response, 'auth/login.twig', ['old' => ['email' => $email]]);
        }

        $user = $this->auth->verifyPassword($email, $password);
        if ($user === null) {
            return $this->view->render($response, 'auth/login.twig', ['old' => ['email' => $email]]);
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
            $this->flash->success('Welcome back!');
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
                    "Verify your AnyAuction phone with code {$code}. Expires in 10 minutes."
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
            "Your AnyAuction code is {$code}. Expires in 10 minutes."
        );
        error_log("[dev-2fa] login code for user_id={$userId}: {$code}");

        $this->flash->success('We sent a 6-digit code to your phone.');
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
