<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Billing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP wrapper for the NOWPayments REST API.
 *
 * Only the endpoints we actually consume are exposed. Auth uses
 * the merchant API key in the `x-api-key` header. All endpoints
 * settle into a single merchant USDT wallet — the gateway handles
 * coin conversion on the user's side.
 *
 * @see https://documenter.getpostman.com/view/7907941/S1a32n38
 */
final class NowPaymentsClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
    ) {}

    public static function fromConfig(): self
    {
        $apiKey = (string) config('services.nowpayments.api_key', '');
        $baseUrl = (string) config('services.nowpayments.base_url', 'https://api.nowpayments.io/v1');

        if ($apiKey === '') {
            throw new RuntimeException(
                'NOWPAYMENTS_API_KEY is not configured. Cannot talk to the gateway.',
            );
        }

        return new self($apiKey, rtrim($baseUrl, '/'));
    }

    /**
     * Create a hosted invoice. Returns the invoice ID + hosted URL
     * the user is redirected to in order to pay.
     *
     * @param  string  $orderId  Our internal reference (kraite-payment-{id}).
     * @return array{id: string, invoice_url: string, raw: array<string, mixed>}
     */
    public function createInvoice(
        float $priceAmount,
        string $orderId,
        string $ipnCallbackUrl,
        ?string $successUrl = null,
        ?string $cancelUrl = null,
        string $priceCurrency = 'usdttrc20',
        ?string $orderDescription = null,
        ?string $customerEmail = null,
        ?string $payCurrency = null,
    ): array {
        $payload = array_filter([
            'price_amount' => $priceAmount,
            'price_currency' => $priceCurrency,
            'pay_currency' => $payCurrency,
            'order_id' => $orderId,
            'ipn_callback_url' => $ipnCallbackUrl,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'order_description' => $orderDescription,
            'customer_email' => $customerEmail,
        ], static fn ($v) => $v !== null);

        $response = $this->client()
            ->post('/invoice', $payload)
            ->throw();

        $data = $response->json();

        if (! is_array($data) || ! isset($data['id'], $data['invoice_url'])) {
            throw new RuntimeException(
                'NOWPayments invoice response did not include id + invoice_url.',
            );
        }

        return [
            'id' => (string) $data['id'],
            'invoice_url' => (string) $data['invoice_url'],
            'raw' => $data,
        ];
    }

    /**
     * Fetch the latest state of a payment. Used as a fallback when
     * we want to reconcile against the gateway directly (admin
     * tooling), not on the webhook hot path.
     *
     * @return array<string, mixed>
     */
    public function getPayment(string $paymentId): array
    {
        $response = $this->client()
            ->get("/payment/{$paymentId}")
            ->throw();

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'Accept' => 'application/json',
            ])
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 200);
    }
}
