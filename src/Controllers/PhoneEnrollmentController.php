<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthCodeService;
use App\Services\AuthService;
use App\Services\FlashService;
use App\Services\PhoneNormalizer;
use App\Services\TwilioService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Two-step enrollment for phone numbers:
 *   step=phone  -> collect & normalize the number, send a verify code
 *   step=code   -> validate the 6-digit code, mark phone_verified_at
 *
 * Reached via:
 *   - newly registered users (forced)
 *   - legacy users with no verified phone on next login
 *
 * Driven by $_SESSION['pending_enrollment_user_id'] and $_SESSION['_enroll_step'].
 */
final class PhoneEnrollmentController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuthService $auth,
        private readonly FlashService $flash,
        private readonly AuthCodeService $codes,
        private readonly TwilioService $twilio
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $userId = (int)($_SESSION['pending_enrollment_user_id'] ?? 0);
        if ($userId <= 0) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $params = $request->getQueryParams();
        $stepParam = (string)($params['step'] ?? '');
        $sessionStep = (string)($_SESSION['_enroll_step'] ?? 'phone');

        // Query string wins if explicit, otherwise fall back to session.
        $step = $stepParam === 'code' ? 'code'
              : ($stepParam === 'phone' ? 'phone' : $sessionStep);

        $_SESSION['_enroll_step'] = $step;

        $user      = $this->auth->findById($userId);
        $phoneMask = $this->maskPhone((string)($user['phone'] ?? ''));

        return $this->view->render($response, 'auth/enroll_phone.twig', [
            'step'       => $step,
            'phone_mask' => $phoneMask,
        ]);
    }

    public function submit(Request $request, Response $response): Response
    {
        $body = (array)$request->getParsedBody();

        if (!$this->verifyCsrf((string)($body['_csrf'] ?? ''))) {
            $this->flash->error('Your session expired. Please try again.');
            return $response->withHeader('Location', '/enroll-phone')->withStatus(302);
        }

        $userId = (int)($_SESSION['pending_enrollment_user_id'] ?? 0);
        if ($userId <= 0) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $step = (string)($body['step'] ?? $_SESSION['_enroll_step'] ?? 'phone');

        if ($step === 'code') {
            return $this->submitCode($request, $response, $userId, $body);
        }

        return $this->submitPhone($request, $response, $userId, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function submitPhone(Request $request, Response $response, int $userId, array $body): Response
    {
        $phoneRaw = trim((string)($body['phone'] ?? ''));
        $phoneE164 = PhoneNormalizer::normalize($phoneRaw);

        if ($phoneE164 === null) {
            $this->flash->error('Please enter a valid US or Canada phone number.');
            $_SESSION['_enroll_step'] = 'phone';
            return $this->view->render($response, 'auth/enroll_phone.twig', [
                'step'       => 'phone',
                'phone_mask' => '',
                'old'        => ['phone' => $phoneRaw],
            ]);
        }

        if ($this->auth->isPhoneLinkedToOtherUser($phoneE164, $userId)) {
            $this->flash->error('This phone is already linked to another account.');
            $_SESSION['_enroll_step'] = 'phone';
            return $this->view->render($response, 'auth/enroll_phone.twig', [
                'step'       => 'phone',
                'phone_mask' => '',
                'old'        => ['phone' => $phoneRaw],
            ]);
        }

        $this->auth->updatePhone($userId, $phoneE164);

        $code = $this->codes->issue($userId, 'phone_verify');
        $this->twilio->sendSms(
            $phoneE164,
            "Verify your AnyAuction phone with code {$code}. Expires in 10 minutes."
        );
        // Dev-mode crutch — see AuthController for context.
        error_log("[dev-2fa] phone_verify code for user_id={$userId}: {$code}");

        $_SESSION['_enroll_step'] = 'code';
        $this->flash->success('We sent a code to ' . $this->maskPhone($phoneE164) . '.');
        return $response->withHeader('Location', '/enroll-phone?step=code')->withStatus(302);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function submitCode(Request $request, Response $response, int $userId, array $body): Response
    {
        $code = trim((string)($body['code'] ?? ''));

        if (!$this->codes->verify($userId, 'phone_verify', $code)) {
            $this->flash->error('That code is invalid or expired.');
            $user = $this->auth->findById($userId);
            return $this->view->render($response, 'auth/enroll_phone.twig', [
                'step'       => 'code',
                'phone_mask' => $this->maskPhone((string)($user['phone'] ?? '')),
            ]);
        }

        $this->auth->markPhoneVerified($userId);

        // Promote to a real session and tear down enrollment state.
        unset($_SESSION['pending_enrollment_user_id'], $_SESSION['_enroll_step']);
        $this->auth->completeLogin($userId);

        $this->flash->success('Phone verified. Welcome!');
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    /**
     * Display-only mask for an E.164 number, e.g. "+1XXX-XXX-4567".
     * Returns "" for empty/short input.
     */
    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 4) {
            return '';
        }
        $last4 = substr($digits, -4);
        return '+1 (XXX) XXX-' . $last4;
    }

    private function verifyCsrf(string $submitted): bool
    {
        return isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $submitted);
    }
}
