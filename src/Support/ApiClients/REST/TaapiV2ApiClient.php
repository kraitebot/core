<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiClients\REST;

use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use SensitiveParameter;

final class TaapiV2ApiClient extends BaseTaapiApiClient
{
    private string $token;

    /**
     * @param  array{url: string, token: string}  $config
     */
    public function __construct(#[SensitiveParameter] array $config)
    {
        $this->apiSystem = ApiSystem::canonical('taapi')->firstOrFail();
        $this->token = $config['token'];

        parent::__construct(
            $config['url'],
            ApiCredentials::make(['token' => $this->token]),
        );
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->token,
            'Content-Type' => 'application/json',
        ];
    }
}
