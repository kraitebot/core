<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiClients\REST;

use Binance\Util\Url;
use Kraite\Core\Abstracts\BaseApiClient;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiRequest;
use Psr\Http\Message\ResponseInterface;

final class BinanceApiClient extends BaseApiClient
{
    public function __construct(array $config)
    {
        $this->apiSystem = ApiSystem::canonical('binance')->first();

        $this->exceptionHandler = BaseExceptionHandler::make('binance');

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
        // Set the recvwindow
        $apiRequest->properties->set(
            'options.recvWindow',
            ApiSystem::canonical('binance')->first()->recvwindow_margin
        );

        $apiRequest->properties->set(
            'options.timestamp',
            now()->getTimestampMs()
        );

        $query = Url::buildQuery($apiRequest->properties->getOr('options', []));

        $signature = hash_hmac(
            'sha256',
            $query,
            $this->credentials->get('api_secret')
        );

        $apiRequest->properties->set(
            'options.signature',
            $signature
        );

        // For POST/PUT/DELETE, Binance requires params in URL query string
        // Append the full query (including signature) to the path
        if (strtoupper($apiRequest->method) !== 'GET') {
            $fullQuery = Url::buildQuery($apiRequest->properties->getOr('options', []));
            $apiRequest->path = $apiRequest->path.'?'.$fullQuery;
        }

        return $this->processRequest($apiRequest);
    }

    public function getHeaders(): array
    {
        return [
            'X-MBX-APIKEY' => $this->credentials->get('api_key'),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Re-sign immediately before every HTTP attempt. BaseApiClient may retry
     * after a backoff, and reusing the first attempt's timestamp makes the
     * retry expire before Binance receives it.
     *
     * @param  array<string, mixed>  $options
     */
    protected function executeHttpRequest(string $method, string $path, array $options): ResponseInterface
    {
        if (mb_strtoupper($method) === 'GET') {
            $query = $options['query'] ?? [];

            if (is_array($query) && array_key_exists('signature', $query)) {
                unset($query['timestamp'], $query['signature']);
                $query['timestamp'] = now()->getTimestampMs();
                $query['signature'] = $this->signature($query);
                $options['query'] = $query;
            }
        } else {
            [$endpoint, $queryString] = array_pad(explode('?', $path, 2), 2, '');
            parse_str($queryString, $query);

            if (array_key_exists('signature', $query)) {
                unset($query['timestamp'], $query['signature']);
                $query['timestamp'] = now()->getTimestampMs();
                $query['signature'] = $this->signature($query);
                $path = $endpoint.'?'.Url::buildQuery($query);
            }
        }

        return parent::executeHttpRequest($method, $path, $options);
    }

    /** @param array<string, mixed> $query */
    private function signature(array $query): string
    {
        return hash_hmac(
            'sha256',
            Url::buildQuery($query),
            $this->credentials->get('api_secret')
        );
    }
}
