<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Debug;

use Illuminate\Console\Command;
use Kraite\Core\Models\Order;
use Throwable;

/**
 * QueryOrderCommand
 *
 * Queries a single order from the exchange API and displays
 * the raw response alongside the local database state.
 */
final class QueryOrderCommand extends Command
{
    protected $signature = 'kraite:debug-query-order
                            {order_id : The local order ID to query}';

    protected $description = 'Query an order from the exchange and display the API response alongside local state.';

    public function handle(): int
    {
        $orderId = (int) $this->argument('order_id');

        /** @var Order|null $order */
        $order = Order::find($orderId);

        if (! $order) {
            $this->error("Order #{$orderId} not found.");

            return self::FAILURE;
        }

        if (! $order->exchange_order_id) {
            $this->error("Order #{$orderId} has no exchange_order_id.");

            return self::FAILURE;
        }

        // Display local state
        $this->info('── Local State ──');
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $order->id],
                ['Type', $order->type],
                ['Status', $order->status],
                ['Reference Status', $order->reference_status],
                ['Exchange Order ID', $order->exchange_order_id],
                ['Quantity', $order->quantity],
                ['Price', $order->price],
                ['Position ID', $order->position_id],
                ['Is Algo', $order->is_algo ? 'Yes' : 'No'],
            ]
        );

        // Query exchange
        $this->info('── Exchange Query ──');

        try {
            $apiResponse = $order->apiQuery();

            $this->table(
                ['Field', 'Value'],
                collect($apiResponse->result)
                    ->map(function (mixed $value, string $key): array {
                        if (is_array($value)) {
                            return [$key, (string) json_encode($value)];
                        }

                        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                            return [$key, (string) $value];
                        }

                        return [$key, $value === null ? '' : print_r($value, true)];
                    })
                    ->values()
                    ->toArray()
            );

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("API query failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
