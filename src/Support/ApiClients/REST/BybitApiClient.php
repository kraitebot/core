<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiClients\REST;

use Kraite\Core\Abstracts\BaseApiClient;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiRequest;
use Psr\Http\Message\ResponseInterface;

final class BybitApiClient extends BaseApiClient
{
    public function __construct(array $config)
    {
        $this->apiSystem = ApiSystem::firstWhere('canonical', 'bybit');

        $this->exceptionHandler = BaseExceptionHandler::make('bybit');

        $credentials = ApiCredentials::make([
            'api_key' => $config['api_key'],
            'api_secret' => $config['api_secret'],
        ]);

        parent::__construct($config['url'], $credentials);
    }

    public function publicRequest(ApiRequest $apiRequest)
    {
        return $this->processRequest($apiRequest);
    }

    public function signRequest(ApiRequest $apiRequest)
    {
        $timestamp = now()->getTimestampMs();
        $recvWindow = ApiSystem::firstWhere('canonical', 'bybit')->recvwindow_margin;

        // Build query string from options
        $queryString = http_build_query($apiRequest->properties->getOr('options', []));

        // Bybit V5 signature for GET: timestamp + api_key + recv_window + queryString
        $signaturePayload = $timestamp.$this->credentials->get('api_key').$recvWindow.$queryString;

        $signature = hash_hmac(
            'sha256',
            $signaturePayload,
            $this->credentials->get('api_secret')
        );

        // Bybit V5 attaches the per-API-key (UID) rate-limit bucket whenever
        // X-BAPI-API-KEY is present, even on public endpoints. The API key
        // and signature stack live ONLY on signed requests so that public
        // calls (e.g. /v5/market/kline) are billed against the much larger
        // public IP bucket instead of exhausting the per-key quota (10006).
        $apiRequest->properties->set('headers.X-BAPI-API-KEY', $this->credentials->get('api_key'));
        $apiRequest->properties->set('headers.X-BAPI-TIMESTAMP', $timestamp);
        $apiRequest->properties->set('headers.X-BAPI-SIGN', $signature);
        $apiRequest->properties->set('headers.X-BAPI-RECV-WINDOW', $recvWindow);

        return $this->processRequest($apiRequest);
    }

    public function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function executeHttpRequest(string $method, string $path, array $options): ResponseInterface
    {
        $apiKey = data_get($options, 'headers.X-BAPI-API-KEY');

        if (is_string($apiKey) && $apiKey !== '') {
            $timestamp = now()->getTimestampMs();
            $recvWindow = (string) data_get($options, 'headers.X-BAPI-RECV-WINDOW');
            $query = $options['query'] ?? [];
            $queryString = http_build_query(is_array($query) ? $query : []);

            $options['headers']['X-BAPI-TIMESTAMP'] = $timestamp;
            $options['headers']['X-BAPI-SIGN'] = hash_hmac(
                'sha256',
                $timestamp.$apiKey.$recvWindow.$queryString,
                $this->credentials->get('api_secret')
            );
        }

        return parent::executeHttpRequest($method, $path, $options);
    }
}
