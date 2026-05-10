<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Billing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Models\Kraite;
use RuntimeException;

/**
 * Thin HTTP wrapper for the NOWPayments REST API.
 *
 * Credentials are stored on the Kraite singleton (encrypted in DB),
 * consistent with all other third-party API keys. The `fromConfig()`
 * factory reads them at runtime.
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
        $engine = Kraite::first();
        $apiKey = (string) ($engine?->nowpayments_api_key ?? '');

        if ($apiKey === '') {
            throw new RuntimeException(
                'NOWPayments API key is not configured on the Kraite singleton.',
            );
        }

        return new self($apiKey, 'https://api.nowpayments.io/v1');
    }

    public static function ipnSecret(): string
    {
        $engine = Kraite::first();

        return (string) ($engine?->nowpayments_ipn_secret ?? '');
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
        bool $isFeePaidByUser = true,
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
            'is_fee_paid_by_user' => $isFeePaidByUser,
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
