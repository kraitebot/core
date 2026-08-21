<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Apis\REST;

use InvalidArgumentException;
use Kraite\Core\Concerns\HasPropertiesValidation;
use Kraite\Core\Contracts\TaapiApiDriver;
use Kraite\Core\Support\ApiClients\REST\TaapiApiClient;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiRequest;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class TaapiLegacyApi implements TaapiApiDriver
{
    use HasPropertiesValidation;

    private TaapiApiClient $client;

    private string $secret;

    public function __construct(ApiCredentials $credentials)
    {
        $secret = $credentials->get('taapi_secret');
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Legacy TAAPI secret is not configured.');
        }

        $this->secret = $secret;
        $this->client = new TaapiApiClient([
            'url' => $this->baseUrl(),
            'secret' => $this->secret,
        ]);
    }

    public function getGroupedIndicatorsValues(ApiProperties $properties): ResponseInterface
    {
        $payload = [
            'secret' => $this->secret,
            'construct' => [
                'exchange' => $properties->get('options.exchange'),
                'symbol' => $properties->get('options.symbol'),
                'interval' => $properties->get('options.interval'),
                'indicators' => $properties->get('options.indicators'),
            ],
            'debug' => $properties->getOr('debug', []),
        ];

        return $this->client->publicRequest(ApiRequest::make(
            'POST',
            '/bulk',
            $properties->mergeIntoNew($payload),
        ));
    }

    public function getBulkIndicatorsValues(ApiProperties $properties): ResponseInterface
    {
        $payload = [
            'secret' => $this->secret,
            'construct' => $properties->get('constructs'),
            'debug' => $properties->getOr('debug', []),
        ];

        return $this->client->publicRequest(ApiRequest::make(
            'POST',
            '/bulk',
            $properties->mergeIntoNew($payload),
        ));
    }

    public function getIndicatorValues(ApiProperties $properties): ResponseInterface
    {
        $endpoint = $properties->get('options.endpoint');
        if (! is_string($endpoint) || $endpoint === '') {
            throw new InvalidArgumentException('Legacy TAAPI indicator endpoint is required.');
        }

        $properties->set('options.secret', $this->secret);

        return $this->client->publicRequest(ApiRequest::make(
            'GET',
            '/'.$endpoint,
            new ApiProperties($properties->toArray()),
        ));
    }

    public function baseUrl(): string
    {
        $url = config('kraite.api.url.taapi.rest', 'https://api.taapi.io');

        return is_string($url) && $url !== '' ? $url : 'https://api.taapi.io';
    }
}
