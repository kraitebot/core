<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiClients\REST;

use Kraite\Core\Abstracts\BaseApiClient;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * KucoinApiClient
 *
 * Low-level HTTP client for KuCoin Futures API.
 * Handles HMAC-SHA256 signature generation and request execution.
 *
 * Authentication Headers (API Key Version 2):
 * - KC-API-KEY: API key
 * - KC-API-SIGN: HMAC-SHA256 signature (base64 encoded)
 * - KC-API-TIMESTAMP: Request timestamp in milliseconds
 * - KC-API-PASSPHRASE: HMAC-SHA256 encrypted passphrase (base64 encoded)
 * - KC-API-KEY-VERSION: "2" (required for encrypted passphrase)
 *
 * Signature Algorithm:
 * 1. Concatenate: timestamp + method + endpoint + body
 * 2. HMAC-SHA256 with API secret
 * 3. Base64 encode the result
 *
 * Passphrase Encryption (v2):
 * 1. HMAC-SHA256 the passphrase with API secret
 * 2. Base64 encode the result
 */
final class KucoinApiClient extends BaseApiClient
{
    public function __construct(array $config)
    {
        $this->apiSystem = ApiSystem::canonical('kucoin')->first();

        $this->exceptionHandler = BaseExceptionHandler::make('kucoin');

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
     * KuCoin Futures uses HMAC-SHA256 signature (API Key Version 2) with:
     * - timestamp (milliseconds)
     * - method (GET, POST, DELETE)
     * - endpoint path with query string
     * - body (JSON for POST/PUT, empty for GET/DELETE)
     */
    public function signRequest(ApiRequest $apiRequest)
    {
        $timestamp = (string) now()->getTimestampMs();
        $method = strtoupper($apiRequest->method);
        $endpoint = $apiRequest->path;

        // Build query string for GET requests or body for POST
        $options = $apiRequest->properties->getOr('options', []);
        $body = '';

        if ($method === 'GET' && ! empty($options)) {
            $queryString = http_build_query($options);
            $endpoint = $endpoint.'?'.$queryString;
        } elseif (in_array($method, ['POST', 'PUT', 'DELETE']) && ! empty($options)) {
            $body = json_encode($options);
            $apiRequest->properties->set('body', $body);
        }

        // KuCoin signature algorithm:
        // 1. Concatenate: timestamp + method + endpoint + body
        $stringToSign = $timestamp.$method.$endpoint.$body;

        // 2. HMAC-SHA256 with API secret
        $secret = $this->credentials->get('api_secret');
        $signature = base64_encode(hash_hmac('sha256', $stringToSign, $secret, true));

        // 3. Encrypt passphrase with HMAC-SHA256 (API Key Version 2)
        $passphrase = $this->credentials->get('passphrase');
        $encryptedPassphrase = base64_encode(hash_hmac('sha256', $passphrase, $secret, true));

        // Add authentication headers
        $apiRequest->properties->set('headers.KC-API-KEY', $this->credentials->get('api_key'));
        $apiRequest->properties->set('headers.KC-API-SIGN', $signature);
        $apiRequest->properties->set('headers.KC-API-TIMESTAMP', $timestamp);
        $apiRequest->properties->set('headers.KC-API-PASSPHRASE', $encryptedPassphrase);
        $apiRequest->properties->set('headers.KC-API-KEY-VERSION', '2');

        // Update path to include query string for GET requests
        if ($method === 'GET' && ! empty($options)) {
            $apiRequest->path = $endpoint;
            $apiRequest->properties->delete('options');
        }

        return $this->processRequest($apiRequest);
    }

    /**
     * Get default headers for all requests.
     */
    public function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function executeHttpRequest(string $method, string $path, array $options): ResponseInterface
    {
        $apiKey = data_get($options, 'headers.KC-API-KEY');

        if (is_string($apiKey) && $apiKey !== '') {
            $timestamp = (string) now()->getTimestampMs();
            $body = data_get($options, 'body');
            $body = is_string($body) ? $body : '';
            $secret = $this->credentials->get('api_secret');

            $options['headers']['KC-API-TIMESTAMP'] = $timestamp;
            $options['headers']['KC-API-SIGN'] = base64_encode(hash_hmac(
                'sha256',
                $timestamp.mb_strtoupper($method).$path.$body,
                $secret,
                true
            ));
        }

        return parent::executeHttpRequest($method, $path, $options);
    }
}
