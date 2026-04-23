<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Proxies;

use InvalidArgumentException;
use Kraite\Core\Support\Apis\Websocket\BinanceApi;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use React\EventLoop\LoopInterface;

/**
 * ApiWebsocketProxy
 *
 * Dispatches websocket calls to the matching exchange-specific client,
 * mirroring the shape of ApiDataMapperProxy / ApiThrottlerProxy. Only
 * Binance is implemented — other exchanges can be added as they become
 * load-bearing.
 *
 * @method void markPrices(array $callbacks, bool $prefersSlowerUpdate = false)
 */
final class ApiWebsocketProxy
{
    private BinanceApi $api;

    public function __construct(string $apiType, ApiCredentials $credentials)
    {
        $this->api = match ($apiType) {
            'binance' => new BinanceApi($credentials),
            default => throw new InvalidArgumentException("Unsupported WebSocket API: {$apiType}"),
        };
    }

    public function __call(string $method, array $arguments)
    {
        if (method_exists($this->api, $method)) {
            return call_user_func_array([$this->api, $method], $arguments);
        }

        throw new InvalidArgumentException("Method {$method} does not exist on the WebSocket API.");
    }

    public function getLoop(): LoopInterface
    {
        return $this->api->getLoop();
    }
}
