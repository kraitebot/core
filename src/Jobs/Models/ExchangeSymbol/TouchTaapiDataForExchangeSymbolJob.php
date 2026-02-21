<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\ExchangeSymbol;

use GuzzleHttp\Exception\RequestException;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\TokenMapper;
use Kraite\Core\Support\ApiDataMappers\Taapi\TaapiApiDataMapper;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use Throwable;

/**
 * TouchTaapiDataForExchangeSymbolJob
 *
 * Touches TAAPI to check if candle data is available for a specific exchange symbol.
 * Sets two flags in api_statuses:
 * - taapi_verified: true when we've checked TAAPI (regardless of result)
 * - has_taapi_data: true only if TAAPI has candle data for this symbol
 *
 * HTTP error handling:
 * - 429 (rate limit): Retried by framework via TaapiExceptionHandler
 * - 400 (invalid symbol/no data): Ignored by job's ignoreException(), marks symbol as verified with no data
 * - Other errors: Fail the step normally
 */
final class TouchTaapiDataForExchangeSymbolJob extends BaseApiableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public function __construct(int $exchangeSymbolId)
    {
        $this->exchangeSymbol = ExchangeSymbol::with('apiSystem')->findOrFail($exchangeSymbolId);
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function assignExceptionHandler()
    {
        $this->exceptionHandler = BaseExceptionHandler::make('taapi')
            ->withAccount(Account::admin('taapi'));
    }

    /**
     * Handle ignorable exceptions - HTTP 400 with specific "no data" messages.
     *
     * Only ignore 400s that indicate the symbol doesn't have data on TAAPI.
     * Any other 400 (plan limits, malformed request, etc.) should fail the step.
     *
     * Known "no data" patterns from TAAPI:
     * - "invalid symbol" - symbol doesn't exist on the exchange
     * - "no candles" - no candle data available for this symbol
     */
    public function ignoreException(Throwable $e): bool
    {
        // Only handle HTTP 400 from TAAPI
        if (! $e instanceof RequestException) {
            return false;
        }

        $response = $e->getResponse();

        if ($response === null) {
            return false;
        }

        if ($response->getStatusCode() !== 400) {
            return false;
        }

        // Only ignore specific "no data" patterns - anything else should fail
        $body = mb_strtolower((string) $response->getBody());
        $noDataPatterns = [
            'invalid symbol',
            'no candles',
        ];

        $isNoDataError = false;
        foreach ($noDataPatterns as $pattern) {
            if (! (str_contains(haystack: $body, needle: $pattern))) {
                continue;
            }

            $isNoDataError = true;
            break;
        }

        if (! $isNoDataError) {
            return false;
        }

        // Mark symbol as verified with no TAAPI data
        $this->markAsVerified(hasData: false);

        return true;
    }

    public function startOrSkip()
    {
        // Skip if already verified (may have been verified by parallel job)
        $taapiVerified = $this->exchangeSymbol->api_statuses['taapi_verified'] ?? false;
        if ($taapiVerified === true) {
            return false;
        }

        // TAAPI only supports Binance - skip if not Binance
        if ($this->exchangeSymbol->apiSystem->canonical !== 'binance') {
            return false;
        }

        return true;
    }

    public function computeApiable()
    {
        $token = $this->exchangeSymbol->token;
        $quote = $this->exchangeSymbol->quote;
        $taapiExchange = 'binancefutures';
        $exchangeName = $this->exchangeSymbol->apiSystem->canonical;

        // Use TAAPI data mapper to format the symbol correctly (e.g., SOL/USDT)
        $dataMapper = new TaapiApiDataMapper;
        $formattedSymbol = $dataMapper->baseWithQuote($token, $quote);

        // Get the TAAPI account to make the API call
        $taapiAccount = Account::admin('taapi');

        // Query TAAPI for candle data to verify availability
        // Exceptions (429 rate limits, 500 errors, etc.) are handled by BaseApiableJob
        $properties = ApiProperties::make();
        $properties->set('options.endpoint', 'candles');
        $properties->set('options.exchange', $taapiExchange);
        $properties->set('options.symbol', $formattedSymbol);
        $properties->set('options.interval', '1h');
        $properties->set('relatable', $this->exchangeSymbol);

        $response = $taapiAccount->withApi()->getIndicatorValues($properties);

        if (! $response instanceof ResponseInterface) {
            return $this->markAsNoData($token, $exchangeName, $formattedSymbol, 'Invalid response type');
        }

        $data = json_decode((string) $response->getBody(), associative: true);

        // If we got a valid response with candle data, TAAPI has data for this symbol
        if (is_array($data) && (isset($data['timestamp']) || ! empty($data))) {
            $this->markAsVerified(hasData: true);

            return [
                'status' => 'has_data',
                'taapi_verified' => true,
                'has_taapi_data' => true,
                'token' => $token,
                'quote' => $quote,
                'exchange' => $exchangeName,
                'taapi_exchange' => $taapiExchange,
                'symbol' => $formattedSymbol,
                'message' => "TAAPI has data for {$formattedSymbol} on {$exchangeName}",
            ];
        }

        return $this->markAsNoData($token, $exchangeName, $formattedSymbol, 'Empty response');
    }

    /**
     * Mark the symbol as verified with TAAPI (sets both flags).
     * Also propagates has_taapi_data to overlapping non-Binance symbols.
     */
    private function markAsVerified(bool $hasData): void
    {
        $apiStatuses = $this->exchangeSymbol->api_statuses ?? [];
        $apiStatuses['taapi_verified'] = true;
        $apiStatuses['has_taapi_data'] = $hasData;
        $this->exchangeSymbol->updateSaving(['api_statuses' => $apiStatuses]);

        // Propagate has_taapi_data to overlapping non-Binance symbols
        $this->propagateToOverlappingSymbols($hasData);
    }

    /**
     * Propagate has_taapi_data status to non-Binance symbols that overlap with this Binance symbol.
     * Uses TokenMapper for exchanges that use different token names (e.g., NEIRO on Binance = 1000NEIRO on KuCoin).
     */
    private function propagateToOverlappingSymbols(bool $hasData): void
    {
        $otherExchanges = ApiSystem::where('canonical', '!=', 'binance')
            ->where('is_exchange', true)
            ->get();

        foreach ($otherExchanges as $exchange) {
            // Try direct token match first (get ALL matching symbols, not just first)
            $targetSymbols = ExchangeSymbol::query()
                ->where('api_system_id', $exchange->id)
                ->where('token', $this->exchangeSymbol->token)
                ->where('overlaps_with_binance', true)
                ->get();

            // If no direct match, try TokenMapper for exchanges with different token names
            if ($targetSymbols->isEmpty()) {
                $mappedToken = TokenMapper::query()
                    ->where('binance_token', $this->exchangeSymbol->token)
                    ->where('other_api_system_id', $exchange->id)
                    ->first();

                if ($mappedToken) {
                    $targetSymbols = ExchangeSymbol::query()
                        ->where('api_system_id', $exchange->id)
                        ->where('token', $mappedToken->other_token)
                        ->where('overlaps_with_binance', true)
                        ->get();
                }
            }

            // Update has_taapi_data on ALL matching target symbols
            foreach ($targetSymbols as $targetSymbol) {
                $targetApiStatuses = $targetSymbol->api_statuses ?? [];
                $targetApiStatuses['has_taapi_data'] = $hasData;
                $targetSymbol->updateSaving(['api_statuses' => $targetApiStatuses]);
            }
        }
    }

    /**
     * Mark the symbol as verified but having no TAAPI data.
     */
    private function markAsNoData(#[SensitiveParameter] string $token, string $exchangeName, string $symbol, string $reason): array
    {
        // Mark as verified (we checked) but no data available
        $this->markAsVerified(hasData: false);

        return [
            'status' => 'no_data',
            'taapi_verified' => true,
            'has_taapi_data' => false,
            'token' => $token,
            'exchange' => $exchangeName,
            'symbol' => $symbol,
            'reason' => $reason,
            'message' => "No TAAPI data for {$symbol} on {$exchangeName}: {$reason}",
        ];
    }
}
