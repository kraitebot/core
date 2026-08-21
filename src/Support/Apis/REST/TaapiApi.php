<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Apis\REST;

use InvalidArgumentException;
use Kraite\Core\Contracts\TaapiApiDriver;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Psr\Http\Message\ResponseInterface;

/**
 * TaapiApi handles the communication with the Taapi.io API,
 * allowing retrieval of indicator values for specific symbols.
 */
final class TaapiApi
{
    private TaapiApiDriver $driver;

    // Constructor to initialize the API client with credentials.
    public function __construct(ApiCredentials $credentials)
    {
        $this->driver = match (self::configuredDriver()) {
            'legacy' => new TaapiLegacyApi($credentials),
            'v2' => new TaapiV2Api($credentials),
            default => throw new InvalidArgumentException(sprintf(
                'Unsupported TAAPI driver "%s". Expected legacy or v2.',
                self::configuredDriver(),
            )),
        };
    }

    public static function configuredDriver(): string
    {
        return mb_strtolower(self::configuredString('kraite.api.taapi.driver', 'v2'));
    }

    public static function configuredBaseUrl(): string
    {
        return self::configuredDriver() === 'v2'
            ? self::configuredString('kraite.api.url.taapi.v2', 'https://v2.taapi.io')
            : self::configuredString('kraite.api.url.taapi.rest', 'https://api.taapi.io');
    }

    public function getGroupedIndicatorsValues(ApiProperties $properties): ResponseInterface
    {
        return $this->driver->getGroupedIndicatorsValues($properties);
    }

    /**
     * Fetches indicator values for multiple constructs (symbols) in a single bulk request.
     * Each construct can have multiple indicators.
     */
    public function getBulkIndicatorsValues(ApiProperties $properties): ResponseInterface
    {
        return $this->driver->getBulkIndicatorsValues($properties);
    }

    public function getIndicatorValues(ApiProperties $properties): ResponseInterface
    {
        return $this->driver->getIndicatorValues($properties);
    }

    public function baseUrl(): string
    {
        return $this->driver->baseUrl();
    }

    private static function configuredString(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
