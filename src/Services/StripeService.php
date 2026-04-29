<?php

declare(strict_types=1);

namespace App\Services;

use Stripe\Checkout\Session;
use Stripe\StripeClient;

final class StripeService
{
    private readonly StripeClient $client;

    public function __construct(
        private readonly string $secretKey,
        private readonly string $baseUrl
    ) {
        $this->client = new StripeClient($secretKey);
    }

    /**
     * Create a hosted Checkout Session for a single auction line-item.
     * Returns the Session — caller persists session->id and 303s the buyer
     * to session->url.
     */
    public function createCheckoutSession(int $itemId, string $title, float $amount, ?string $imageUrl = null): Session
    {
        $line = [
            'price_data' => [
                'currency'     => 'usd',
                'product_data' => [
                    'name' => mb_substr($title, 0, 250),
                ],
                // Stripe wants amounts in the smallest currency unit (cents).
                'unit_amount'  => (int)round($amount * 100),
            ],
            'quantity' => 1,
        ];

        // Stripe rejects non-https URLs in product images; only send if it's
        // an https URL we already have (Unsplash seed data, etc.).
        if ($imageUrl !== null && str_starts_with($imageUrl, 'https://')) {
            $line['price_data']['product_data']['images'] = [$imageUrl];
        }

        return $this->client->checkout->sessions->create([
            'mode'                 => 'payment',
            'line_items'           => [$line],
            'success_url'          => $this->baseUrl . '/checkout/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'           => $this->baseUrl . '/checkout/cancel?session_id={CHECKOUT_SESSION_ID}',
            'client_reference_id'  => (string)$itemId,
        ]);
    }

    public function retrieveSession(string $sessionId): Session
    {
        return $this->client->checkout->sessions->retrieve($sessionId);
    }
}
