<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Debug;

use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;
use StepDispatcher\Support\BaseCommand;

/**
 * QueryPositionsCommand
 *
 * Displays all positions for an account with their orders and key metrics.
 * Useful for debugging WAP calculations, order states, and position health.
 */
final class QueryPositionsCommand extends BaseCommand
{
    protected $signature = 'kraite:debug-query-positions
                            {account_id : The account ID to query positions for}
                            {--status= : Filter by position status (e.g., active, closed)}
                            {--with-orders : Include order details for each position}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Query positions for an account and display their state with orders.';

    public function handle(): int
    {
        $accountId = (int) $this->argument('account_id');

        $account = Account::find($accountId);

        if (! $account) {
            $this->verboseError("Account #{$accountId} not found.");

            return self::FAILURE;
        }

        $this->verboseInfo("Positions for Account #{$account->id} ({$account->name})");
        $this->verboseInfo("Exchange: {$account->apiSystem->canonical}");
        $this->verboseNewLine();

        $query = Position::where('account_id', $accountId)
            ->orderByDesc('id');

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        $positions = $query->get();

        if ($positions->isEmpty()) {
            $this->verboseComment('No positions found.');

            return self::SUCCESS;
        }

        // Summary table
        $this->verboseInfo("Found {$positions->count()} position(s):");
        $this->verboseNewLine();

        $rows = $positions->map(function (Position $position): array {
            $ordersCount = $position->orders()->count();
            $filledOrders = $position->orders()->where('status', 'FILLED')->count();

            return [
                'id' => $position->id,
                'symbol' => $position->exchangeSymbol?->token ?? 'N/A',
                'direction' => $position->direction ?? 'N/A',
                'status' => $position->status,
                'opening_price' => $position->opening_price ?? 'N/A',
                'quantity' => $position->quantity ?? 'N/A',
                'leverage' => $position->leverage ?? 'N/A',
                'orders' => "{$filledOrders}/{$ordersCount}",
                'created' => $position->created_at?->format('Y-m-d H:i'),
            ];
        })->toArray();

        $this->verboseTable(
            ['ID', 'Symbol', 'Direction', 'Status', 'Opening Price', 'Quantity', 'Leverage', 'Orders (F/T)', 'Created'],
            $rows
        );

        // Detailed order view if requested
        if ($this->option('with-orders')) {
            $this->verboseNewLine();
            $this->verboseInfo('── Order Details ──');

            foreach ($positions as $position) {
                $this->verboseNewLine();
                $symbol = $position->exchangeSymbol?->token ?? 'N/A';
                $direction = $position->direction ?? 'N/A';
                $this->verboseComment("Position #{$position->id} ({$symbol} {$direction}):");

                $orders = $position->orders()->orderBy('id')->get();

                if ($orders->isEmpty()) {
                    $this->verboseLine('  No orders');

                    continue;
                }

                $orderRows = $orders->map(function ($order): array {
                    return [
                        'id' => $order->id,
                        'type' => $order->type,
                        'status' => $order->status,
                        'ref_status' => $order->reference_status ?? '-',
                        'price' => $order->price ?? '-',
                        'ref_price' => $order->reference_price ?? '-',
                        'qty' => $order->quantity ?? '-',
                        'ref_qty' => $order->reference_quantity ?? '-',
                        'filled' => $order->filled_quantity ?? '0',
                        'algo' => $order->is_algo ? 'Y' : 'N',
                    ];
                })->toArray();

                $this->verboseTable(
                    ['ID', 'Type', 'Status', 'Ref Status', 'Price', 'Ref Price', 'Qty', 'Ref Qty', 'Filled', 'Algo'],
                    $orderRows
                );
            }
        }

        return self::SUCCESS;
    }
}
