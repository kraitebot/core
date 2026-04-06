<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Debug;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Trading\Kraite;
use StepDispatcher\Support\BaseCommand;

/**
 * PlaceOrderCommand
 *
 * Debug command to place a limit order on the exchange via the step dispatcher.
 * Creates a Position + Order record, then dispatches through the full workflow.
 *
 * Hardcoded: Account #1 (Binance), leverage 5, direction LONG.
 */
final class PlaceOrderCommand extends BaseCommand
{
    private const LEVERAGE = 5;

    private const DIRECTION = 'LONG';

    protected $signature = 'debug:place-order
                            {margin : Margin amount in USDT}
                            {token : Token to trade (e.g. BTC, ETH)}
                            {--account=1 : Account ID to use}
                            {--clean : Truncate positions, orders, steps, and related tables before running}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Place a limit order via the step dispatcher workflow (debug/testing).';

    public function handle(): int
    {
        if ($this->option('clean')) {
            $this->clean();
        }

        $margin = $this->argument('margin');
        $token = strtoupper($this->argument('token'));
        $accountId = (int) $this->option('account');

        // Load account
        $account = Account::find($accountId);
        if (! $account) {
            $this->error("Account #{$accountId} not found.");

            return self::FAILURE;
        }

        // Find exchange symbol for this account's exchange
        $exchangeSymbol = ExchangeSymbol::query()
            ->where('api_system_id', $account->api_system_id)
            ->where('token', $token)
            ->first();

        if (! $exchangeSymbol) {
            $this->error("Exchange symbol for {$token} not found on {$account->name}.");

            return self::FAILURE;
        }

        $parsedTradingPair = $exchangeSymbol->parsed_trading_pair;
        $side = self::DIRECTION === 'LONG' ? 'BUY' : 'SELL';

        // Calculate market order data to get opening price and quantity
        $marketData = Kraite::calculateMarketOrderData(
            marketMargin: $margin,
            leverage: self::LEVERAGE,
            exchangeSymbol: $exchangeSymbol,
        );

        $openingPrice = $marketData['price'];
        $quantity = $marketData['quantity'];

        $this->verboseInfo("Creating position for {$parsedTradingPair}...");
        $this->verboseInfo("  Direction: " . self::DIRECTION . " | Leverage: " . self::LEVERAGE);
        $this->verboseInfo("  Margin: {$margin} USDT | Opening price: {$openingPrice} | Qty: {$quantity}");

        return DB::transaction(function () use ($account, $exchangeSymbol, $parsedTradingPair, $side, $margin, $openingPrice, $quantity): int {
            // Create position
            $position = Position::create([
                'account_id' => $account->id,
                'exchange_symbol_id' => $exchangeSymbol->id,
                'parsed_trading_pair' => $parsedTradingPair,
                'direction' => self::DIRECTION,
                'status' => 'opening',
                'leverage' => self::LEVERAGE,
                'margin' => $margin,
                'opening_price' => $openingPrice,
                'quantity' => $quantity,
                'total_limit_orders' => 1,
                'opened_at' => now(),
            ]);

            $this->verboseInfo("  Position #{$position->id} created");

            // Calculate limit order data from the Engine
            $limitOrders = Kraite::calculateLimitOrdersData(
                totalLimitOrders: 1,
                direction: self::DIRECTION,
                referencePrice: $openingPrice,
                marketOrderQty: $quantity,
                exchangeSymbol: $exchangeSymbol,
                limitQuantityMultipliers: $exchangeSymbol->limit_quantity_multipliers,
            );

            if (empty($limitOrders)) {
                $this->error('Engine returned no limit orders (quantity may round to zero).');

                return self::FAILURE;
            }

            $limitData = $limitOrders[0];

            // Create the limit order
            $order = Order::create([
                'position_id' => $position->id,
                'type' => 'LIMIT',
                'status' => 'NEW',
                'side' => $side,
                'position_side' => self::DIRECTION,
                'price' => $limitData['price'],
                'quantity' => $limitData['quantity'],
            ]);

            $this->verboseInfo("  Order #{$order->id} created");
            $this->verboseInfo("    Price: {$limitData['price']} | Qty: {$limitData['quantity']} | Amount: {$limitData['amount']}");
            $this->verboseInfo("    Client order ID: {$order->client_order_id}");

            // TODO: Dispatch step here

            return self::SUCCESS;
        });
    }

    private function clean(): void
    {
        $this->verboseInfo('Truncating positions, orders, steps, and related tables...');

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('orders')->truncate();
        DB::table('positions')->truncate();
        DB::table('steps')->truncate();
        DB::table('steps_dispatcher_ticks')->truncate();
        DB::table('api_request_logs')->truncate();
        DB::table('api_snapshots')->truncate();
        DB::table('notification_logs')->truncate();
        DB::table('model_logs')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->verboseInfo('✓ Tables truncated');

        cleanLogsFolder();
        $this->verboseInfo('✓ All logs and log directories cleared');

        $this->verboseNewLine();
    }
}
