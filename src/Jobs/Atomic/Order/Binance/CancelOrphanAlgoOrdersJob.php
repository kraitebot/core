<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order\Binance;

use Illuminate\Support\Facades\Log;
use Kraite\Core\Jobs\Atomic\Order\CancelOrphanAlgoOrdersJob as BaseCancelOrphanAlgoOrdersJob;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Throwable;

/**
 * CancelOrphanAlgoOrdersJob (Atomic) - Binance
 *
 * Binance's UI exposes a "modify" action on stop / take-profit orders
 * but the Futures API does not support in-place modification of algo
 * orders. Under the hood, "Edit" on an algo order cancels the original
 * and places a brand-new algo order at the new trigger. The new algo
 * order is created directly on the exchange — our DB never sees it,
 * so `exchange_order_id` will not match anything we have on record.
 *
 * This step runs ahead of RecreateCancelledOrderJob inside
 * SmartReplaceOrdersJob and scrubs any open algo order on the
 * position's symbol whose algoId isn't in our known set for this
 * position. Without it, the replacement workflow lands our reference
 * stop on the exchange while the user's ghost stop is still alive,
 * and both trigger.
 */
final class CancelOrphanAlgoOrdersJob extends BaseCancelOrphanAlgoOrdersJob
{
    /**
     * @return array<string, mixed>
     */
    public function computeApiable(): array
    {
        $account = $this->position->account;
        $symbol = mb_strtoupper((string) $this->position->parsed_trading_pair);

        // On an account shared with the user (allow_other_orders=true) an
        // unknown algo order on this symbol may be the USER's own stop or
        // take-profit — never scrub it. The cost is tolerating a possible
        // ghost of our own next to the user's orders; protection of the
        // user's orders outranks tidiness.
        if ($account->allow_other_orders) {
            return [
                'position_id' => $this->position->id,
                'symbol' => $symbol,
                'cancelled_count' => 0,
                'failed_count' => 0,
                'cancelled' => [],
                'failed' => [],
                'message' => 'Scrub skipped — account allows user orders (allow_other_orders=true)',
            ];
        }

        $response = $account->apiQueryAlgoOrders();
        $allOpenAlgoOrders = is_array($response->result) ? $response->result : [];

        $openOrdersForSymbol = array_values(array_filter(
            $allOpenAlgoOrders,
            static function (array $order) use ($symbol): bool {
                return mb_strtoupper((string) ($order['symbol'] ?? '')) === $symbol;
            }
        ));

        $knownAlgoIds = $this->position->orders()
            ->where('is_algo', true)
            ->whereNotNull('exchange_order_id')
            ->pluck('exchange_order_id')
            ->map(static function ($id): string {
                return (string) $id;
            })
            ->all();

        $cancelled = [];
        $failed = [];

        foreach ($openOrdersForSymbol as $order) {
            $algoId = (string) ($order['algoId'] ?? '');

            if ($algoId === '' || in_array($algoId, $knownAlgoIds, true)) {
                continue;
            }

            try {
                $this->cancelOrphan($symbol, $algoId);
                $cancelled[] = [
                    'algoId' => $algoId,
                    'orderType' => $order['orderType'] ?? null,
                    'triggerPrice' => $order['triggerPrice'] ?? null,
                ];
            } catch (Throwable $e) {
                Log::channel('jobs')->warning('[CANCEL-ORPHAN-ALGO] failed', [
                    'position_id' => $this->position->id,
                    'symbol' => $symbol,
                    'algoId' => $algoId,
                    'error' => $e->getMessage(),
                ]);

                $failed[] = [
                    'algoId' => $algoId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'position_id' => $this->position->id,
            'symbol' => $symbol,
            'cancelled_count' => count($cancelled),
            'failed_count' => count($failed),
            'cancelled' => $cancelled,
            'failed' => $failed,
            'message' => 'Orphan algo orders scrubbed',
        ];
    }

    /**
     * Cancel a single orphan algo order directly against the exchange
     * (no Order model — the row doesn't exist locally, we only have
     * the algoId from Binance's open-algo-orders feed).
     */
    private function cancelOrphan(string $symbol, string $algoId): void
    {
        $account = $this->position->account;

        $properties = new ApiProperties;
        $properties->set('account', $account);
        $properties->set('relatable', $this->position);
        $properties->set('options.symbol', $symbol);
        $properties->set('options.algoId', $algoId);

        $account->withApi()->cancelAlgoOrder($properties);
    }
}
