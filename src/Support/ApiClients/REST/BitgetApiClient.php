<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiClients\REST;

use Kraite\Core\Abstracts\BaseApiClient;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiRequest;

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
        $this->apiSystem = ApiSystem::firstWhere('canonical', 'bitget');

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
        $timestamp = (string) round(microtime(true) * 1000);
        $method = strtoupper($apiRequest->method);
        $endpoint = $apiRequest->path;

        // Build query string for GET requests or body for POST
        $options = $apiRequest->properties->getOr('options', []);
        $queryString = '';
        $body = '';

        if ($method === 'GET' && ! empty($options)) {
            $queryString = '?' . http_build_query($options);
        } elseif (in_array($method, ['POST', 'PUT', 'DELETE']) && ! empty($options)) {
            $body = json_encode($options);
            $apiRequest->properties->set('body', $body);
        }

        // BitGet signature algorithm:
        // 1. Concatenate: timestamp + method + endpoint + queryString + body
        $stringToSign = $timestamp . $method . $endpoint . $queryString . $body;

        // 2. HMAC-SHA256 with API secret
        $secret = $this->credentials->get('api_secret');
        $signature = base64_encode(hash_hmac('sha256', $stringToSign, $secret, true));

        // Add authentication headers (passphrase is plain text, NOT encrypted like KuCoin)
        $apiRequest->properties->set('headers.ACCESS-KEY', $this->credentials->get('api_key'));
        $apiRequest->properties->set('headers.ACCESS-SIGN', $signature);
        $apiRequest->properties->set('headers.ACCESS-TIMESTAMP', $timestamp);
        $apiRequest->properties->set('headers.ACCESS-PASSPHRASE', $this->credentials->get('passphrase'));

        // Update path to include query string for GET requests
        if ($method === 'GET' && ! empty($options)) {
            $apiRequest->path = $endpoint . $queryString;
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
}
