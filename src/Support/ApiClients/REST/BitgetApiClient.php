<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiClients\REST;

use Kraite\Core\Abstracts\BaseApiClient;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\Throttlers\BitgetThrottler;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiRequest;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * BitgetApiClient
 *
 * Low-level HTTP client for BitGet Futures API (V2).
 * Handles HMAC-SHA256 signature generation and request execution.
 *
 * Authentication Headers:
 * - ACCESS-KEY: API key
 * - ACCESS-SIGN: HMAC-SHA256 signature (base64 encoded)
 * - ACCESS-TIMESTAMP: Request timestamp in milliseconds
 * - ACCESS-PASSPHRASE: Passphrase (plain text, NOT encrypted like KuCoin)
 *
 * Signature Algorithm:
 * 1. Concatenate: timestamp + method + endpoint + queryString (GET) or body (POST)
 * 2. HMAC-SHA256 with API secret
 * 3. Base64 encode the result
 */
final class BitgetApiClient extends BaseApiClient
{
    public function __construct(array $config)
    {
        $this->apiSystem = ApiSystem::canonical('bitget')->first();

        $this->exceptionHandler = BaseExceptionHandler::make('bitget');

        $credentials = ApiCredentials::make([
            'api_key' => $config['api_key'],
            'api_secret' => $config['api_secret'],
            'passphrase' => $config['passphrase'],
        ]);

        parent::__construct($config['url'], $credentials);
    }

    /**
     * Execute a public (unauthenticated) request.
     */
    public function publicRequest(ApiRequest $apiRequest)
    {
        return $this->processRequest($apiRequest);
    }

    /**
     * Execute a signed (authenticated) request.
     *
     * BitGet uses HMAC-SHA256 signature with:
     * - timestamp (milliseconds)
     * - method (GET, POST, DELETE)
     * - endpoint path
     * - query string (for GET) or body (for POST/PUT)
     */
    public function signRequest(ApiRequest $apiRequest)
    {
        $method = mb_strtoupper($apiRequest->method);
        $endpoint = $apiRequest->path;

        // Build query string for GET requests or body for POST
        $options = $apiRequest->properties->getOr('options', []);
        $queryString = '';
        $body = '';

        if ($method === 'GET' && ! empty($options)) {
            ksort($options);
            $queryString = '?'.http_build_query($options);
        } elseif (in_array($method, ['POST', 'PUT', 'DELETE']) && ! empty($options)) {
            $body = json_encode($options, JSON_THROW_ON_ERROR);
            $apiRequest->properties->set('body', $body);
        }

        foreach ($this->signatureHeaders($method, $endpoint.$queryString, $body) as $name => $value) {
            $apiRequest->properties->set("headers.{$name}", $value);
        }

        // Update path to include query string for GET requests
        if ($method === 'GET' && ! empty($options)) {
            $apiRequest->path = $endpoint.$queryString;
            $apiRequest->properties->delete('options');
        }

        return $this->processRequest($apiRequest);
    }

    /**
     * Get default headers for all requests.
     * BitGet requires a locale header for proper API authentication.
     */
    public function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'locale' => 'en-US',
        ];
    }

    /**
     * Pace every real attempt, including BaseApiClient's internal retry.
     *
     * ACCESS-KEY is present only on signed calls. Public calls therefore use
     * the per-IP budget while private calls use the per-UID key.
     *
     * @param  array<string, mixed>  $options
     */
    protected function executeHttpRequest(string $method, string $path, array $options): ResponseInterface
    {
        $apiKey = data_get($options, 'headers.ACCESS-KEY');
        $apiKey = is_string($apiKey) && $apiKey !== '' ? $apiKey : null;

        BitgetThrottler::throttleRequest($path, $apiKey);

        if ($apiKey !== null) {
            $body = data_get($options, 'body');
            $headers = data_get($options, 'headers');
            $options['headers'] = array_merge(
                is_array($headers) ? $headers : [],
                $this->signatureHeaders(
                    mb_strtoupper($method),
                    $path,
                    is_string($body) ? $body : ''
                )
            );
        }

        return parent::executeHttpRequest($method, $path, $options);
    }

    /**
     * @return array<string, string>
     */
    private function signatureHeaders(string $method, string $path, string $body): array
    {
        $timestamp = (string) now()->getTimestampMs();
        $signature = base64_encode(hash_hmac(
            'sha256',
            $timestamp.$method.$path.$body,
            $this->credential('api_secret'),
            true
        ));

        return [
            'ACCESS-KEY' => $this->credential('api_key'),
            'ACCESS-SIGN' => $signature,
            'ACCESS-TIMESTAMP' => $timestamp,
            'ACCESS-PASSPHRASE' => $this->credential('passphrase'),
        ];
    }

    private function credential(string $key): string
    {
        $value = $this->credentials?->get($key);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Bitget credential [{$key}] is missing.");
        }

        return $value;
    }
}
