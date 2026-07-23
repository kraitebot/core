<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiClients\REST;

use Kraite\Core\Abstracts\BaseApiClient;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiRequest;

final class TaapiApiClient extends BaseApiClient
{
    public function __construct(array $config)
    {
        $this->apiSystem = ApiSystem::canonical('taapi')->first();

        $credentials = ApiCredentials::make([
            'secret' => $config['secret'],
        ]);

        parent::__construct($config['url'], $credentials);
    }

    public function publicRequest(ApiRequest $apiRequest)
    {
        return $this->processRequest($apiRequest, true);
    }

    public function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
        ];
    }
}
