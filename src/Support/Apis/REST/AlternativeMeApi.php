<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Apis\REST;

use Kraite\Core\Support\ApiClients\REST\AlternativeMeApiClient;
use Kraite\Core\Support\ValueObjects\ApiRequest;

final class AlternativeMeApi
{
    private $client;

    public function __construct()
    {
        $this->client = new AlternativeMeApiClient([
            'url' => config('kraite.api.url.alternativeme.rest'),
        ]);
    }

    public function getFearAndGreedIndex()
    {
        $apiRequest = ApiRequest::make(
            'GET',
            '/fng',
        );

        return $this->client->publicRequest($apiRequest);
    }
}
