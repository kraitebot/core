<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Apis\REST;

use Illuminate\Validation\Rule;
use Kraite\Core\Concerns\HasPropertiesValidation;
use Kraite\Core\Support\ApiClients\REST\BinanceApiClient;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiRequest;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class BinanceApi
{
    use HasPropertiesValidation;

    private BinanceApiClient $client;

    private BinanceApiClient $walletClient;

    public function __construct(ApiCredentials $credentials)
    {
        $this->client = new BinanceApiClient([
            'url' => config('kraite.api.url.binance.rest'),
            'api_key' => $credentials->get('binance_api_key'),
            'api_secret' => $credentials->get('binance_api_secret'),
        ]);

        $this->walletClient = new BinanceApiClient([
            'url' => config('kraite.api.url.binance.wallet_rest', 'https://api.binance.com'),
            'api_key' => $credentials->get('binance_api_key'),
            'api_secret' => $credentials->get('binance_api_secret'),
        ]);
    }

    public function serverTime(): mixed
    {
        $apiRequest = ApiRequest::make('GET', '/fapi/v1/time');

        return $this->client->publicRequest($apiRequest);
    }

    public function getLeverageBrackets(ApiProperties $properties): mixed
    {
        $apiRequest = ApiRequest::make('GET', '/fapi/v1/leverageBracket', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function getExchangeInformation(ApiProperties $properties): mixed
    {
        $apiRequest = ApiRequest::make('GET', '/fapi/v1/exchangeInfo', $properties);

        return $this->client->publicRequest($apiRequest);
    }

    public function getKlines(?ApiProperties $properties = null): mixed
    {
        $properties ??= new ApiProperties;
        $apiRequest = ApiRequest::make('GET', '/fapi/v1/klines', $properties);

        return $this->client->publicRequest($apiRequest);
    }

    public function getCurrentOpenOrders(ApiProperties $properties): mixed
    {
        $apiRequest = ApiRequest::make('GET', '/fapi/v1/openOrders', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function getAlgoOpenOrders(ApiProperties $properties): mixed
    {
        $apiRequest = ApiRequest::make('GET', '/fapi/v1/openAlgoOrders', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function placeAlgoOrder(ApiProperties $properties): mixed
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.side' => 'required|string',
            'options.quantity' => 'required_without:options.closePosition|string',
            'options.closePosition' => 'required_without:options.quantity|string',
            'options.algoType' => 'required|string',
            'options.triggerPrice' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make('POST', '/fapi/v1/algoOrder', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function queryAlgoOrder(ApiProperties $properties): mixed
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.algoId' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make('GET', '/fapi/v1/openAlgoOrders', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function cancelAlgoOrder(ApiProperties $properties): mixed
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.algoId' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make('DELETE', '/fapi/v1/algoOrder', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function getAllOrders(ApiProperties $properties): mixed
    {
        $apiRequest = ApiRequest::make('GET', '/fapi/v1/allOrders', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function getOrder(ApiProperties $properties): mixed
    {
        $apiRequest = ApiRequest::make('GET', '/fapi/v1/order', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function cancelOrder(ApiProperties $properties): mixed
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.orderId' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make('DELETE', '/fapi/v1/order', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function cancelAllOpenOrders(ApiProperties $properties): mixed
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make('DELETE', '/fapi/v1/allOpenOrders', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function updateMarginType(ApiProperties $properties): mixed
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.margintype' => ['required', Rule::in(['ISOLATED', 'CROSSED'])],
        ]);

        $apiRequest = ApiRequest::make('POST', '/fapi/v1/marginType', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function getPositions(?ApiProperties $properties = null): mixed
    {
        $properties ??= new ApiProperties;
        $apiRequest = ApiRequest::make('GET', '/fapi/v3/positionRisk', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function getSymbolConfig(?ApiProperties $properties = null): mixed
    {
        $properties ??= new ApiProperties;
        $apiRequest = ApiRequest::make('GET', '/fapi/v1/symbolConfig', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function getAccountBalance(): mixed
    {
        $apiRequest = ApiRequest::make('GET', '/fapi/v3/balance');

        return $this->client->signRequest($apiRequest);
    }

    /**
     * @see https://developers.binance.com/docs/wallet/account/api-key-permission
     */
    public function getApiKeyPermissions(?ApiProperties $properties = null): mixed
    {
        $properties ??= new ApiProperties;
        $apiRequest = ApiRequest::make('GET', '/sapi/v1/account/apiRestrictions', $properties);

        return $this->walletClient->signRequest($apiRequest);
    }

    public function getSpotAccountBalance(): mixed
    {
        $apiRequest = ApiRequest::make('GET', '/api/v3/account');

        return $this->client->signRequest($apiRequest);
    }

    public function changeInitialLeverage(ApiProperties $properties): mixed
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.leverage' => 'required|integer',
        ]);

        $apiRequest = ApiRequest::make('POST', '/fapi/v1/leverage', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function getMarkPrice(ApiProperties $properties): mixed
    {
        $this->validate($properties, [
            'options.symbol' => 'required',
        ]);

        $apiRequest = ApiRequest::make('GET', '/fapi/v1/premiumIndex', $properties);

        return $this->client->publicRequest($apiRequest);
    }

    public function placeOrder(ApiProperties $properties): mixed
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.side' => 'required|string',
            'options.type' => 'required|string',
            'options.quantity' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make('POST', '/fapi/v1/order', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function orderQuery(ApiProperties $properties): mixed
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.orderId' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make('GET', '/fapi/v1/order', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function modifyOrder(ApiProperties $properties): mixed
    {
        $this->validate($properties, [
            'options.symbol' => 'required',
            'options.quantity' => 'required',
            'options.price' => 'required',
        ]);

        $apiRequest = ApiRequest::make('PUT', '/fapi/v1/order', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function accountTrades(ApiProperties $properties): mixed
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.orderId' => 'string',
            'options.limit' => 'string',
        ]);

        $apiRequest = ApiRequest::make('GET', '/fapi/v1/userTrades', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function account(ApiProperties $properties): mixed
    {
        $apiRequest = ApiRequest::make('GET', '/fapi/v3/account', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function income(ApiProperties $properties): mixed
    {
        $this->validate($properties, [
            'options.symbol' => 'required|string',
            'options.incomeType' => 'required|string',
        ]);

        $apiRequest = ApiRequest::make('GET', '/fapi/v1/income', $properties);

        return $this->client->signRequest($apiRequest);
    }

    /**
     * Account-wide income history — every symbol and every income type in one
     * paginated call.
     *
     * Deliberately separate from `income()`: that one is per-symbol and
     * per-type for attributing a single position's PnL, and asking it for a
     * whole account means symbols × types requests. Doing that against a live
     * account is what tripped Binance's 2,400/minute IP limit on 2026-07-29 —
     * a limit the trading engine shares.
     */
    public function incomeHistory(ApiProperties $properties): mixed
    {
        $this->validate($properties, [
            'options.startTime' => 'required|integer',
            'options.endTime' => 'required|integer',
        ]);

        $apiRequest = ApiRequest::make('GET', '/fapi/v1/income', $properties);

        return $this->client->signRequest($apiRequest);
    }

    public function createListenKey(): string
    {
        $apiRequest = ApiRequest::make('POST', '/fapi/v1/listenKey');
        $response = $this->client->publicRequest($apiRequest);

        $body = $response instanceof ResponseInterface
            ? json_decode((string) $response->getBody(), associative: true)
            : $response;

        return $body['listenKey'] ?? throw new RuntimeException('No listenKey returned');
    }

    public function refreshListenKey(?string $currentKey = null): string
    {
        $apiRequest = ApiRequest::make('POST', '/fapi/v1/listenKey');
        $response = $this->client->publicRequest($apiRequest);

        if ($response instanceof ResponseInterface) {
            $body = (string) $response->getBody();
        } elseif (is_array($response)) {
            return $response['listenKey'] ?? throw new RuntimeException('Stub missing listenKey.');
        } else {
            throw new RuntimeException('Unexpected response type: '.get_debug_type($response));
        }

        // Empty body means Binance kept the same key alive
        if ($body === '') {
            return $currentKey ?? throw new RuntimeException('Empty listenKey body and no current key supplied.');
        }

        $data = json_decode($body, associative: true);

        // Check for Binance error payload
        if (isset($data['code'], $data['msg'])) {
            throw new RuntimeException("Binance error {$data['code']}: {$data['msg']}");
        }

        if (! isset($data['listenKey'])) {
            throw new RuntimeException("No listenKey in Binance response: {$body}");
        }

        return $data['listenKey'];
    }

    public function keepAliveListenKey(string $key): void
    {
        $apiRequest = ApiRequest::make('PUT', '/fapi/v1/listenKey', [
            'listenKey' => $key,
        ]);

        $this->client->publicRequest($apiRequest);
    }

    public function closeListenKey(): mixed
    {
        $apiRequest = ApiRequest::make('DELETE', '/fapi/v1/listenKey');

        return $this->client->signRequest($apiRequest);
    }
}
