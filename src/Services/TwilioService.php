<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;
use Twilio\Rest\Client;
use Twilio\Security\RequestValidator;

final class TwilioService
{
    private ?Client $client = null;

    public function __construct(
        private readonly string $accountSid,
        private readonly string $authToken,
        private readonly string $fromNumber
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->accountSid !== '' && $this->authToken !== '' && $this->fromNumber !== '';
    }

    /**
     * Send an SMS. Returns true on success, false on any failure (logs the
     * error). Silently no-ops in dev when creds are unset so flows don't
     * 500 — callers can check isConfigured() if they need to gate.
     */
    public function sendSms(string $to, string $body): bool
    {
        if (!$this->isConfigured()) {
            error_log('[TwilioService] sendSms skipped — creds not configured.');
            return false;
        }
        try {
            $this->client()->messages->create($to, [
                'from' => $this->fromNumber,
                'body' => $body,
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('[TwilioService] sendSms failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate the X-Twilio-Signature header on an incoming webhook. $params
     * must be the FORM-ENCODED params Twilio sent (not query string).
     */
    public function validateSignature(string $url, array $params, string $signature): bool
    {
        if ($this->authToken === '') {
            return false;
        }
        return (new RequestValidator($this->authToken))->validate($signature, $url, $params);
    }

    private function client(): Client
    {
        return $this->client ??= new Client($this->accountSid, $this->authToken);
    }
}
