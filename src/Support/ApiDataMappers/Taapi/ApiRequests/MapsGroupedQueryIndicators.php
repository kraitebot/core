<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Taapi\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Collection;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsGroupedQueryIndicators
{
    public function prepareGroupedQueryIndicatorsProperties(ExchangeSymbol $exchangeSymbol, Collection $indicators, string $timeframe): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $exchangeSymbol);

        $apiDataMapper = new ApiDataMapperProxy('taapi');

        // Token and quote are stored directly on exchange_symbols
        $symbol = $apiDataMapper->baseWithQuote(
            $exchangeSymbol->token,
            $exchangeSymbol->quote
        );

        $properties->set('options.symbol', $symbol);
        $properties->set('options.interval', $timeframe);
        $properties->set('options.exchange', 'binancefutures');
        $properties->set('options.indicators', $this->getIndicatorsListForApi($exchangeSymbol, $indicators, $timeframe));

        return $properties;
    }

    public function resolveGroupedQueryIndicatorsResponse(Response $response): array
    {
        return json_decode((string) $response->getBody(), associative: true);
    }

    /**
     * @param  Collection<int, Indicator>  $indicators
     */
    public function getIndicatorsListForApi(ExchangeSymbol $exchangeSymbol, Collection $indicators, string $timeframe): array
    {
        $enrichedIndicators = [];

        foreach ($indicators as $indicatorModel) {
            // Instanciate indicator to retrieve the right db parameters.
            $indicatorClass = $indicatorModel->class;
            $indicatorInstance = new $indicatorClass($exchangeSymbol, ['interval' => $timeframe]);

            $parameters = $indicatorModel->parameters ?? [];

            $enrichedIndicator = array_merge([
                'id' => $indicatorModel->canonical,
                'indicator' => $indicatorInstance->endpoint,
            ], $indicatorInstance->parameters);

            $enrichedIndicators[] = $enrichedIndicator;
        }

        return $enrichedIndicators;
    }
}
