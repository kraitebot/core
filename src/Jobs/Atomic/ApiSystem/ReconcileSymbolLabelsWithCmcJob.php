<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\ApiSystem;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Trading\Exchange\Exchange;

/**
 * Reconciles catalogue tickers/names with CoinMarketCap's current record.
 *
 * The CMC id is the coin's identity; the ticker is only a label that can
 * change (TON → GRAM rebrand, 2026-07). Discovery refreshes the label
 * when it links an orphan, but coins linked BEFORE a rebrand would keep
 * a stale label forever — and a stale label is what arms the
 * recycled-ticker trap in the name-based matchers. This job runs inside
 * the exchange-symbols refresh cycle and sweeps every catalogued coin in
 * batched /v1/cryptocurrency/map calls.
 */
final class ReconcileSymbolLabelsWithCmcJob extends BaseApiableJob
{
    /**
     * Ids per /map call. CMC accepts long id lists; 100 keeps each
     * request comfortably inside URL and credit limits.
     */
    private const BATCH_SIZE = 100;

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make('coinmarketcap')
            ->withAccount(Account::admin('coinmarketcap'));
    }

    public function relatable()
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function computeApiable(): array
    {
        $mapper = Exchange::forCanonical('coinmarketcap')->mapper();
        $cmcAccount = Account::admin('coinmarketcap');

        $relabelled = [];
        $checked = 0;

        Symbol::query()
            ->whereNotNull('cmc_id')
            ->chunkById(self::BATCH_SIZE, function ($symbols) use ($mapper, $cmcAccount, &$relabelled, &$checked): void {
                $properties = $mapper->prepareSymbolMapByIdsProperties(
                    $symbols->pluck('cmc_id')->map(static function ($cmcId): int {
                        return (int) $cmcId;
                    })->all()
                );

                $response = $cmcAccount->withApi()->getSymbols($properties);
                $result = $mapper->resolveSymbolMapByIdsResponse($response);

                $currentByCmcId = collect($result['data'] ?? [])
                    ->keyBy(static function (array $entry): int {
                        return (int) ($entry['id'] ?? 0);
                    });

                foreach ($symbols as $symbol) {
                    $checked++;
                    $current = $currentByCmcId->get((int) $symbol->cmc_id);

                    if (! is_array($current)) {
                        continue;
                    }

                    $currentTicker = mb_strtoupper(mb_trim((string) ($current['symbol'] ?? '')));

                    if ($currentTicker === '' || $currentTicker === $symbol->token) {
                        continue;
                    }

                    $previousTicker = $symbol->token;

                    $symbol->updateSaving([
                        'token' => $currentTicker,
                        'name' => $current['name'] ?? $symbol->name,
                    ]);

                    $relabelled[] = [
                        'cmc_id' => (int) $symbol->cmc_id,
                        'from' => $previousTicker,
                        'to' => $currentTicker,
                    ];
                }
            });

        return [
            'checked' => $checked,
            'relabelled_count' => count($relabelled),
            'relabelled' => $relabelled,
            'message' => $relabelled === []
                ? 'All catalogue labels match CMC.'
                : 'Catalogue labels refreshed from CMC.',
        ];
    }
}
