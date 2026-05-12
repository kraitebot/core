<?php

declare(strict_types=1);

namespace Kraite\Core\Commands;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\ApiDataStream;
use Kraite\Core\Models\Order;
use StepDispatcher\Support\BaseCommand;

/**
 * BackfillOriginalPricesCommand
 *
 * Stamps the immutable forensic anchors `original_price` and
 * `original_quantity` onto pre-migration order rows that were
 * inserted before those columns existed.
 *
 * Source-of-truth priority:
 *
 *   1. earliest `api_data_stream` NEW event for the order's
 *      `exchange_order_id` — the WS daemon's first echo of the
 *      placed values is the closest-to-source signal still in our
 *      DB. Several NEW rows can exist for one exchange_order_id
 *      (e.g. AMENDMENT events also surface as native NEW); the
 *      EARLIEST one is the legitimate placement record, every
 *      later one is post-modification noise.
 *
 *   2. the order's own `reference_price` / `reference_quantity` —
 *      best guess for orders whose placement NEW event never
 *      reached our DB (WS daemon down at placement, legacy rows
 *      pre-WS, etc.).
 *
 *   3. the order's own `price` / `quantity` — last-resort fallback.
 *
 * Idempotent: rows that already have non-null originals are skipped.
 * Run once after deploy to seed legacy data; safe to re-run.
 *
 *   php artisan kraite:backfill-original-prices
 *   php artisan kraite:backfill-original-prices --dry-run
 */
final class BackfillOriginalPricesCommand extends BaseCommand
{
    protected $signature = 'kraite:backfill-original-prices
                            {--dry-run : Report what would be backfilled without writing}
                            {--chunk=500 : Number of orders processed per chunk}';

    protected $description = 'Backfill orders.original_price / original_quantity for legacy rows from api_data_stream NEW events';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');

        $totalScanned = 0;
        $stampedFromStream = 0;
        $stampedFromReference = 0;
        $stampedFromPrice = 0;
        $skippedNoSignal = 0;

        Order::query()
            ->whereNull('original_price')
            ->orWhereNull('original_quantity')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($orders) use (
                $dryRun,
                &$totalScanned,
                &$stampedFromStream,
                &$stampedFromReference,
                &$stampedFromPrice,
                &$skippedNoSignal,
            ) {
                foreach ($orders as $order) {
                    $totalScanned++;

                    $resolved = $this->resolveAnchors($order);

                    if ($resolved === null) {
                        $skippedNoSignal++;

                        continue;
                    }

                    [$source, $price, $quantity] = $resolved;

                    match ($source) {
                        'stream' => $stampedFromStream++,
                        'reference' => $stampedFromReference++,
                        'price' => $stampedFromPrice++,
                    };

                    if ($dryRun) {
                        $this->line(sprintf(
                            '[dry-run] order #%d ← %s (price=%s, quantity=%s)',
                            $order->id,
                            $source,
                            $price ?? 'null',
                            $quantity ?? 'null',
                        ));

                        continue;
                    }

                    DB::transaction(function () use ($order, $price, $quantity) {
                        Order::query()->whereKey($order->id)->lockForUpdate()->first();

                        $update = [];

                        if ($order->getRawOriginal('original_price') === null && $price !== null) {
                            $update['original_price'] = $price;
                        }

                        if ($order->getRawOriginal('original_quantity') === null && $quantity !== null) {
                            $update['original_quantity'] = $quantity;
                        }

                        if ($update === []) {
                            return;
                        }

                        $order->updateSaving($update);
                    });
                }
            });

        $this->info(sprintf(
            'scanned=%d stamped_from_stream=%d stamped_from_reference=%d stamped_from_price=%d skipped_no_signal=%d (dry_run=%s)',
            $totalScanned,
            $stampedFromStream,
            $stampedFromReference,
            $stampedFromPrice,
            $skippedNoSignal,
            $dryRun ? 'true' : 'false',
        ));

        return self::SUCCESS;
    }

    /**
     * Resolve the (source, price, quantity) triple for one order, or
     * null when no signal at all is available — that's the
     * "skipped_no_signal" bucket.
     *
     * @return array{0: string, 1: ?string, 2: ?string}|null
     */
    private function resolveAnchors(Order $order): ?array
    {
        if ($order->exchange_order_id !== null) {
            $earliest = ApiDataStream::query()
                ->where('exchange_order_id', $order->exchange_order_id)
                ->where('normalized_status', 'NEW')
                ->orderBy('event_time')
                ->orderBy('id')
                ->first();

            if ($earliest !== null) {
                $price = $earliest->price !== null ? (string) $earliest->price : null;
                $quantity = $earliest->original_quantity !== null
                    ? (string) $earliest->original_quantity
                    : null;

                if ($price !== null || $quantity !== null) {
                    return ['stream', $price, $quantity];
                }
            }
        }

        $referencePrice = $order->getRawOriginal('reference_price');
        $referenceQuantity = $order->getRawOriginal('reference_quantity');

        if ($referencePrice !== null || $referenceQuantity !== null) {
            return ['reference', $referencePrice, $referenceQuantity];
        }

        $rawPrice = $order->getRawOriginal('price');
        $rawQuantity = $order->getRawOriginal('quantity');

        if ($rawPrice !== null || $rawQuantity !== null) {
            return ['price', $rawPrice, $rawQuantity];
        }

        return null;
    }
}
