<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiClients\REST;

use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ValueObjects\ApiCredentials;

final class TaapiApiClient extends BaseTaapiApiClient
{
    /**
     * @param  array{url: string, secret: string}  $config
     */
    public function __construct(array $config)
    {
        $this->apiSystem = ApiSystem::canonical('taapi')->first();

        $credentials = ApiCredentials::make([
            'secret' => $config['secret'],
        ]);

        parent::__construct($config['url'], $credentials);
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
        ];
    }
}
