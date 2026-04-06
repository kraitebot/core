<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Ingestion;

use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\ApiDataMappers\Binance\BinanceApiDataMapper;
use Kraite\Core\Support\ApiDataMappers\Bybit\BybitApiDataMapper;
use Kraite\Core\Support\ApiDataMappers\Taapi\TaapiApiDataMapper;
use Kraite\Core\Support\Apis\REST\BinanceApi;
use Kraite\Core\Support\Apis\REST\BybitApi;
use Kraite\Core\Support\Apis\REST\CoinmarketCapApi;
use Kraite\Core\Support\Apis\REST\TaapiApi;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use StepDispatcher\Support\BaseCommand;
use Throwable;

/**
 * Determines if a cryptocurrency token is eligible for trading on the Kraite system.
 *
 * This command validates that a token meets all requirements for algorithmic trading:
 * it must exist on CoinMarketCap, be listed on both Binance and Bybit futures markets
 * (with USDT and/or USDC pairs), and have TAAPI indicator data available for all
 * exchange/quote combinations. Only tokens passing all checks are considered eligible.
 *
 * @see CoinmarketCapApi For token discovery and ranking
 * @see BinanceApi For Binance futures availability checks
 * @see BybitApi For Bybit futures availability checks
 * @see TaapiApi For technical indicator data availability
 */
final class IsEligibleCommand extends BaseCommand
{
    /**
     * @var string
     */
    protected $signature = 'ingestion:is-eligible
                            {token : The cryptocurrency token symbol (e.g., SOL, BTC)}';

    /**
     * @var string
     */
    protected $description = 'Check if a cryptocurrency token is eligible for Kraite trading system';

    /**
     * The token symbol being checked, stored for use across helper methods.
     */
    private string $currentSymbol;

    /**
     * Execute the console command.
     *
     * Orchestrates the full eligibility check workflow: searches CoinMarketCap for the token,
     * verifies listing on both Binance and Bybit exchanges, and confirms TAAPI indicator
     * data is available for all exchange/quote combinations.
     */
    public function handle(): int
    {
        $token = mb_strtoupper($this->argument('token'));
        $this->currentSymbol = $token;

        $this->verboseInfo("🔍 Checking eligibility for: {$token}");
        $this->verboseNewLine();

        // Step 1: Search CoinMarketCap for the token
        $cmcResults = $this->searchCoinMarketCap($token);

        if (empty($cmcResults)) {
            $this->verboseError("❌ No results found on CoinMarketCap for token: {$token}");

            return self::FAILURE;
        }

        // Sort by rank to get the best one first (lowest rank = best)
        usort($cmcResults, static function (mixed $a, mixed $b): int {
            if (! is_array($a) || ! is_array($b)) {
                return 0;
            }
            $rankA = is_int($a['rank'] ?? null) ? $a['rank'] : (is_numeric($a['rank'] ?? null) ? (int) $a['rank'] : PHP_INT_MAX);
            $rankB = is_int($b['rank'] ?? null) ? $b['rank'] : (is_numeric($b['rank'] ?? null) ? (int) $b['rank'] : PHP_INT_MAX);

            return $rankA <=> $rankB;
        });

        // Only check the best-ranked token
        $bestToken = $cmcResults[0];
        if (! is_array($bestToken)) {
            return self::FAILURE;
        }
        $this->verboseInfo('✅ Found on CoinMarketCap (checking best ranked):');
        $this->verboseNewLine();

        // Step 2: Check exchanges for the best result
        $cmcId = is_int($bestToken['id'] ?? null) ? $bestToken['id'] : (is_numeric($bestToken['id'] ?? null) ? (int) $bestToken['id'] : 0);
        $name = is_string($bestToken['name'] ?? null) ? $bestToken['name'] : 'Unknown';

        $this->verboseNewLine();
        $this->verboseLine("  [1] <fg=cyan>{$name}</> (CMC ID: <fg=yellow>{$cmcId}</>)");
        $this->verboseNewLine();

        $binancePairs = $this->checkBinancePairs($this->currentSymbol);
        $bybitPairs = $this->checkBybitPairs($this->currentSymbol);

        $hasBinance = ! empty($binancePairs);
        $hasBybit = ! empty($bybitPairs);

        $taapiAvailability = [];

        foreach ($binancePairs as $quote) {
            $available = $this->checkTaapiIndicatorData($this->currentSymbol, 'binancefutures', (string) $quote);
            $taapiAvailability['binance'][(string) $quote] = $available;
        }

        foreach ($bybitPairs as $quote) {
            $available = $this->checkTaapiIndicatorData($this->currentSymbol, 'bybit', (string) $quote);
            $taapiAvailability['bybit'][(string) $quote] = $available;
        }

        $allHaveTaapiData = true;
        foreach ($taapiAvailability as $quotes) {
            foreach ($quotes as $available) {
                if ($available) {
                    continue;
                }

                $allHaveTaapiData = false;
                break 2;
            }
        }

        $this->verboseTable(
            ['Exchange', 'Listed Quotes', 'TAAPI Data'],
            [
                [
                    'Binance',
                    ! empty($binancePairs) ? implode(', ', $binancePairs) : '—',
                    $this->formatTaapiStatus($taapiAvailability['binance'] ?? []),
                ],
                [
                    'Bybit',
                    ! empty($bybitPairs) ? implode(', ', $bybitPairs) : '—',
                    $this->formatTaapiStatus($taapiAvailability['bybit'] ?? []),
                ],
            ]
        );

        $isEligible = $hasBinance && $hasBybit && $allHaveTaapiData;

        if ($isEligible) {
            $this->verboseInfo('      ✅ ELIGIBLE');
        } else {
            $this->verboseError('      ❌ NOT ELIGIBLE');
        }

        $this->verboseNewLine();

        if (! $isEligible) {
            return self::FAILURE;
        }

        $symbol = is_string($bestToken['symbol'] ?? null) ? $bestToken['symbol'] : 'N/A';
        $rank = $bestToken['rank'] ?? 'N/A';
        $rankDisplay = is_int($rank) ? (string) $rank : (is_string($rank) ? $rank : 'N/A');

        $this->verboseInfo("✅ {$name} ({$symbol}) is ELIGIBLE for Kraite (Rank #{$rankDisplay})");

        return self::SUCCESS;
    }

    /**
     * Search CoinMarketCap for tokens matching the given symbol.
     *
     * Queries the CoinMarketCap API and filters results to only include exact symbol matches.
     * Multiple tokens may share the same symbol (e.g., different projects using "SOL"),
     * so results are returned as an array for the caller to rank and select.
     *
     * @return array<int, mixed> Array of token data from CoinMarketCap, filtered by exact symbol match
     */
    private function searchCoinMarketCap(string $token): array
    {
        try {
            $engine = Kraite::firstOrFail();
            $credentials = ApiCredentials::make([
                'coinmarketcap_api_key' => $engine->coinmarketcap_api_key,
            ]);

            $cmcApi = new CoinmarketCapApi($credentials);

            $properties = ApiProperties::make();
            $properties->set('symbol', $token);
            $properties->set('limit', 100);

            $response = $cmcApi->getSymbols($properties);
            if ($response === null) {
                return [];
            }
            if (! $response instanceof ResponseInterface) {
                throw new RuntimeException('Expected ResponseInterface from getSymbols, got '.get_debug_type($response));
            }

            $data = json_decode((string) $response->getBody(), associative: true);

            if (! is_array($data) || ! isset($data['data']) || ! is_array($data['data']) || empty($data['data'])) {
                return [];
            }

            $results = array_filter($data['data'], callback: static function (mixed $item) use ($token): bool {
                if (! is_array($item)) {
                    return false;
                }
                $symbol = is_string($item['symbol'] ?? null) ? $item['symbol'] : '';

                return mb_strtoupper($symbol) === $token;
            });

            return array_values($results);
        } catch (Throwable $e) {
            $this->verboseError('Error searching CoinMarketCap: '.$e->getMessage());
            $this->verboseNewLine();
            $this->verboseLine('Exception: '.get_class($e));
            $this->verboseLine('File: '.$e->getFile().':'.$e->getLine());

            return [];
        }
    }

    /**
     * Check which quote currencies are available for the symbol on Binance futures.
     *
     * @return array<int, string> List of available quote currencies (e.g., ['USDT', 'USDC'])
     */
    private function checkBinancePairs(string $symbol): array
    {
        $availablePairs = [];

        if ($this->isListedOnBinance($symbol, 'USDT')) {
            $availablePairs[] = 'USDT';
        }

        if ($this->isListedOnBinance($symbol, 'USDC')) {
            $availablePairs[] = 'USDC';
        }

        return $availablePairs;
    }

    /**
     * Check which quote currencies are available for the symbol on Bybit futures.
     *
     * @return array<int, string> List of available quote currencies (e.g., ['USDT', 'USDC'])
     */
    private function checkBybitPairs(string $symbol): array
    {
        $availablePairs = [];

        if ($this->isListedOnBybit($symbol, 'USDT')) {
            $availablePairs[] = 'USDT';
        }

        if ($this->isListedOnBybit($symbol, 'USDC')) {
            $availablePairs[] = 'USDC';
        }

        return $availablePairs;
    }

    /**
     * Check if a symbol is listed on Binance futures market.
     *
     * Verifies the trading pair exists by querying the mark price endpoint.
     * Uses the BinanceApiDataMapper to handle symbol format transformations.
     */
    private function isListedOnBinance(string $symbol, string $quote = 'USDT'): bool
    {
        try {
            $engine = Kraite::firstOrFail();
            $credentials = ApiCredentials::make([
                'binance_api_key' => $engine->binance_api_key,
                'binance_api_secret' => $engine->binance_api_secret,
            ]);

            $binanceApi = new BinanceApi($credentials);
            $dataMapper = new BinanceApiDataMapper;

            $tradingPair = $dataMapper->baseWithQuote($symbol, $quote);

            $properties = ApiProperties::make();
            $properties->set('options.symbol', $tradingPair);

            $response = $binanceApi->getMarkPrice($properties);
            if ($response === null) {
                return false;
            }
            if (! $response instanceof ResponseInterface) {
                throw new RuntimeException('Expected ResponseInterface from getMarkPrice, got '.get_debug_type($response));
            }
            $data = json_decode((string) $response->getBody(), associative: true);

            return is_array($data) && isset($data['markPrice']) && is_numeric($data['markPrice']);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Check if a symbol is listed on Bybit futures market.
     *
     * Verifies the trading pair exists and has 'Trading' status by querying the
     * exchange information endpoint. Uses the BybitApiDataMapper to handle symbol
     * format transformations (USDC pairs use PERP suffix, USDT pairs use direct pairing).
     */
    private function isListedOnBybit(string $symbol, string $quote = 'USDT'): bool
    {
        try {
            $engine = Kraite::firstOrFail();
            $credentials = ApiCredentials::make([
                'bybit_api_key' => $engine->bybit_api_key,
                'bybit_api_secret' => $engine->bybit_api_secret,
            ]);

            $bybitApi = new BybitApi($credentials);
            $dataMapper = new BybitApiDataMapper;

            $tradingPair = $dataMapper->baseWithQuote($symbol, $quote);

            $properties = ApiProperties::make();
            $properties->set('options.category', 'linear');
            $properties->set('options.symbol', $tradingPair);

            $response = $bybitApi->getExchangeInformation($properties);
            if ($response === null) {
                return false;
            }
            if (! $response instanceof ResponseInterface) {
                throw new RuntimeException('Expected ResponseInterface from getExchangeInformation, got '.get_debug_type($response));
            }
            $data = json_decode((string) $response->getBody(), associative: true);

            if (! is_array($data) || ! isset($data['result']) || ! is_array($data['result'])) {
                return false;
            }
            $instruments = is_array($data['result']['list'] ?? null) ? $data['result']['list'] : [];
            foreach ($instruments as $instrument) {
                if (! is_array($instrument)) {
                    continue;
                }
                $status = is_string($instrument['status'] ?? null) ? $instrument['status'] : '';
                if ($status === 'Trading') {
                    return true;
                }
            }

            return false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Check if TAAPI has indicator data available for the given symbol/exchange/quote combination.
     *
     * Queries the TAAPI candles endpoint to verify that technical indicator data can be
     * retrieved for this trading pair. This is essential for the Kraite algorithm
     * which relies on technical indicators for trading decisions.
     */
    private function checkTaapiIndicatorData(string $symbol, string $taapiExchange, string $quote): bool
    {
        try {
            $engine = Kraite::firstOrFail();
            $credentials = ApiCredentials::make([
                'taapi_secret' => $engine->taapi_secret,
            ]);

            $taapiApi = new TaapiApi($credentials);
            $dataMapper = new TaapiApiDataMapper;

            $formattedSymbol = $dataMapper->baseWithQuote($symbol, $quote);

            $properties = ApiProperties::make();
            $properties->set('options.endpoint', 'candles');
            $properties->set('options.exchange', $taapiExchange);
            $properties->set('options.symbol', $formattedSymbol);
            $properties->set('options.interval', '1h');

            $response = $taapiApi->getIndicatorValues($properties);
            if ($response === null) {
                return false;
            }
            if (! $response instanceof ResponseInterface) {
                throw new RuntimeException('Expected ResponseInterface from getIndicatorValues, got '.get_debug_type($response));
            }
            $data = json_decode((string) $response->getBody(), associative: true);

            return is_array($data) && (isset($data['timestamp']) || ! empty($data));
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Format TAAPI availability status for console table display.
     *
     * Converts the quote availability array into a colored string for terminal output,
     * showing green checkmarks for available quotes and red X marks for unavailable ones.
     *
     * @param  array<string, bool>  $quotes  Map of quote currency to availability status
     */
    private function formatTaapiStatus(array $quotes): string
    {
        if (empty($quotes)) {
            return '—';
        }

        $parts = [];
        foreach ($quotes as $quote => $available) {
            if ($available) {
                $parts[] = "<fg=green>✅ {$quote}</>";
            } else {
                $parts[] = "<fg=red>❌ {$quote}</>";
            }
        }

        return implode(', ', $parts);
    }
}
