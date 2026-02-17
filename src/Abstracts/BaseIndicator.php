<?php

declare(strict_types=1);

namespace Kraite\Core\Abstracts;

use Exception;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;

/*
 * BaseIndicator
 *
 * • Abstract base class for all indicator logic (ADX, RSI, etc.).
 * • Dynamically loads parameters from DB based on class type.
 * • Accepts constructor-level parameter overrides (e.g., interval).
 * • Executes indicator API call using a proxy over 'taapi'.
 * • Stores raw and processed data in `$this->data`.
 * • Supports dynamic timestamps and human-readable conversions.
 * • Provides structure for result access (`data()`) and conclusion logic.
 * • Subclasses must implement `conclusion()` to return interpreted signal.
 */
abstract class BaseIndicator
{
    // Unique identifier for the indicator call (optional use-case).
    public string $id;

    // API endpoint name to use (e.g., 'adx', 'rsi').
    public string $endpoint;

    // Full payload to send in the request.
    public array $parameters = [];

    // Optional: defines which exchange symbol is being queried.
    public ?ExchangeSymbol $exchangeSymbol = null;

    // Holds the result from the indicator API.
    protected array $data = [];

    public function __construct(ExchangeSymbol $exchangeSymbol, array $parameters = [])
    {
        $this->exchangeSymbol = $exchangeSymbol;

        // Identify child class to match the correct Indicator model.
        $childClass = get_called_class();

        // Load default indicator parameters from DB.
        $indicator = Indicator::where('class', $childClass)->first();
        $indicatorParams = $indicator->parameters ?? [];

        // Merge default DB parameters with constructor overrides.
        $mergedParams = array_merge($indicatorParams, $parameters);

        // Enforce the presence of the "interval" parameter.
        if (! array_key_exists(key: 'interval', array: $mergedParams)) {
            throw new Exception('Indicator misses key -- interval --');
        }

        // Inject standard flag to return timestamps in TAAPI responses.
        $mergedParams['addResultTimestamp'] = true;

        // Apply parameters one by one.
        foreach ($mergedParams as $key => $value) {
            $this->addParameter($key, $value);
        }
    }

    /**
     * Returns the indicator's conclusion/signal.
     *
     * Direction indicators return: 'LONG' | 'SHORT' | null
     * Validation indicators return: true | false (stored as '1' | '0')
     * Reporting indicators return: array (statistical data)
     * Non-conclusive indicators return: null
     */
    abstract public function conclusion(): string|bool|array|null;

    // Retrieve previously fetched data.
    final public function data($addTimestampForHumans = false)
    {
        if ($addTimestampForHumans) {
            $this->addTimestampForHumans();
        }

        return $this->data;
    }

    final public function compute()
    {
        /*
         * Perform API call and convert timestamps for readability.
         * Returns the full processed indicator data array.
         */
        $this->apiQuery();

        $this->addTimestampForHumans();

        return $this->data;
    }

    final public function parameters()
    {
        return $this->parameters;
    }

    // Add/override a specific parameter.
    final public function addParameter(string $key, $value)
    {
        $this->parameters[$key] = $value;
    }

    // Perform the API call using the taapi proxy and process response.
    final public function apiQuery()
    {
        $apiAccount = Account::admin('taapi');

        if (! $this->exchangeSymbol) {
            throw new Exception('No exchange symbol defined for the indicator query');
        }

        $apiDataMapper = new ApiDataMapperProxy('taapi');

        $this->parameters['endpoint'] = $this->endpoint;

        $apiProperties = $apiDataMapper->prepareQueryIndicatorProperties($this->exchangeSymbol, $this->parameters);

        $this->data = $apiDataMapper->resolveQueryIndicatorResponse(
            $apiAccount->withApi()->getIndicatorValues($apiProperties)
        );
    }

    // Load previously stored API response data (e.g., for caching).
    final public function load(array $data)
    {
        $this->data = $data;
    }

    /**
     * Make $this->data include human-readable timestamps — safely, for
     *  - a single candle (assoc array),
     *  - a list of candles (results = X),
     *  - or legacy mapped arrays with "timestamp" list.
     *
     * Also materializes a flat "timestamp" array when a list of candles is returned,
     * so callers (e.g., storing history) can always rely on it.
     */
    protected function addTimestampForHumans()
    {
        // Case 1: Legacy/flat shape with 'timestamp' already present
        if (isset($this->data['timestamp'])) {
            if (is_array($this->data['timestamp'])) {
                $this->data['timestamp_for_humans'] = array_map(callback: static function ($ts) {
                    return date('Y-m-d H:i:s', (int) $ts);
                }, array: $this->data['timestamp']);
            } else {
                $this->data['timestamp_for_humans'] = date('Y-m-d H:i:s', (int) $this->data['timestamp']);
            }

            return;
        }

        // Case 2: A list of candles (numeric keys) — extract timestamps column if available
        if (is_array($this->data) && array_key_exists(key: 0, array: $this->data) && is_array($this->data[0])) {
            $timestamps = [];
            foreach ($this->data as $row) {
                if (!(isset($row['timestamp']))) { continue; }

$timestamps[] = (int) $row['timestamp'];
            }

            if (! empty($timestamps)) {
                // Materialize a flat timestamp array + its human variant
                $this->data['timestamp'] = $timestamps;
                $this->data['timestamp_for_humans'] = array_map(callback: static function ($ts) {
                    return date('Y-m-d H:i:s', (int) $ts);
                }, array: $timestamps);
            }

            // If there are no timestamps per candle, we silently skip
            return;
        }

        // Case 3: Single candle assoc without explicit guard above
        if (is_array($this->data) && isset($this->data['timestamp'])) {
            $this->data['timestamp_for_humans'] = date('Y-m-d H:i:s', (int) $this->data['timestamp']);
        }
    }

    /**
     * Helper method to determine trend direction by comparing two values.
     * Returns 'LONG' if value increased, 'SHORT' if decreased, null if insufficient data.
     *
     * @param  string  $dataKey  The key in $this->data containing the value array
     * @return string|null 'LONG' | 'SHORT' | null
     */
    protected function compareTrendFromValues(string $dataKey = 'value'): ?string
    {
        $values = $this->data[$dataKey] ?? null;

        if (! $values || ! is_array($values) || count($values) < 2) {
            return null;
        }

        return $values[1] > $values[0] ? 'LONG' : 'SHORT';
    }
}
