<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiClients\REST;

use Kraite\Core\Abstracts\BaseApiClient;
use Kraite\Core\Support\Throttlers\TaapiThrottler;
use Kraite\Core\Support\ValueObjects\ApiRequest;
use Psr\Http\Message\ResponseInterface;
use UnexpectedValueException;

abstract class BaseTaapiApiClient extends BaseApiClient
{
    final public function publicRequest(ApiRequest $apiRequest): ResponseInterface
    {
        return $this->processRequest($apiRequest, true);
    }

    /**
     * Keep model context available to API logging without serializing it into
     * TAAPI's strict request bodies.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    protected function prepareRequestOptions(ApiRequest $apiRequest, bool $sendAsJson, array $headers): array
    {
        $options = parent::prepareRequestOptions($apiRequest, $sendAsJson, $headers);
        $preparedOptions = [];

        foreach ($options as $key => $value) {
            if (! is_string($key)) {
                throw new UnexpectedValueException('TAAPI request option keys must be strings.');
            }

            $preparedOptions[$key] = $value;
        }

        if (isset($preparedOptions['json']) && is_array($preparedOptions['json'])) {
            unset($preparedOptions['json']['account'], $preparedOptions['json']['relatable']);
        }

        return $preparedOptions;
    }

    /**
     * Pace every real HTTP attempt, including internal retries.
     *
     * @param  array<string, mixed>  $options
     */
    protected function executeHttpRequest(string $method, string $path, array $options): ResponseInterface
    {
        TaapiThrottler::throttleRequest();

        return parent::executeHttpRequest($method, $path, $options);
    }
}
