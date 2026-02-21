<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Debug;

use Illuminate\Console\Command;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Trading\Engine;
use Throwable;

final class CalculateOrdersCommand extends Command
{
    /**
     * Usage (single line):
     * php artisan debug:calculate-orders --price=64000 --margin=100 --exchange_symbol_id=96 --direction=LONG --account_id=1
     *
     * This command previews:
     * - Market slice (entry MARKET)
     * - Profit order (TP)
     * - Stop-loss (STOP-MARKET)
     * - Limit ladder (now also shows cumulative WAP per rung)
     *
     * All tactical parameters now come from the Account's TradeConfiguration:
     * - leverage:           position_leverage_long / position_leverage_short (by --direction)
     * - profit_percent:     profit_percentage
     * - stop_percent:       stop_market_initial_percentage
     * - limit_orders (N):   total_limit_orders (from ExchangeSymbol)
     */
    protected $signature = 'debug:calculate-orders
        {--price= : Reference price to compute quantities and the ladder from}
        {--margin= : Margin allocation (quote currency amount)}
        {--exchange_symbol_id= : ExchangeSymbol ID}
        {--direction= : Direction LONG or SHORT}
        {--account_id= : Account ID providing TradeConfiguration}';

    protected $description = 'Preview market, profit, stop-loss, and limit orders using Kraite calculators, driven by Account TradeConfiguration.';

    public function handle(): int
    {
        try {
            // ---- 1) Read & validate options ----
            $price = (string) $this->option('price');
            $margin = (string) $this->option('margin');
            $symbolId = (string) $this->option('exchange_symbol_id');
            $direction = mb_strtoupper((string) $this->option('direction'));
            $accountId = (string) $this->option('account_id');

            $missing = [];
            foreach (['price', 'margin', 'exchange_symbol_id', 'direction', 'account_id'] as $k) {
                if ($this->option($k) === null || $this->option($k) === '') {
                    $missing[] = $k;
                }
            }
            if ($missing) {
                $this->error('Missing required options: --'.implode(' --', $missing));

                return self::FAILURE;
            }

            if (! in_array($direction, ['LONG', 'SHORT'], true)) {
                $this->error('Invalid --direction. Must be LONG or SHORT.');

                return self::FAILURE;
            }
            if (! is_numeric($price) || (float) $price <= 0) {
                $this->error('Invalid --price. Must be a positive number.');

                return self::FAILURE;
            }
            if (! is_numeric($margin) || (float) $margin <= 0) {
                $this->error('Invalid --margin. Must be a positive number.');

                return self::FAILURE;
            }
            if (! ctype_digit($symbolId)) {
                $this->error('Invalid --exchange_symbol_id. Must be an integer ID.');

                return self::FAILURE;
            }
            if (! ctype_digit($accountId)) {
                $this->error('Invalid --account_id. Must be an integer ID.');

                return self::FAILURE;
            }

            /** @var ExchangeSymbol $exchangeSymbol */
            $exchangeSymbol = ExchangeSymbol::findOrFail((int) $symbolId);

            /** @var Account $account */
            $account = Account::with('tradeConfiguration')->findOrFail((int) $accountId);

            $cfg = $account->tradeConfiguration;

            if ($cfg === null) {
                $this->error("Account {$account->id} has no TradeConfiguration attached.");

                return self::FAILURE;
            }

            // ---- 1.1) Resolve tactical parameters from TradeConfiguration ----
            $configuredLeverage = (int) ($direction === 'LONG'
                ? $account->position_leverage_long
                : $account->position_leverage_short);

            if ($configuredLeverage < 1) {
                $this->error("Configured leverage for {$direction} must be >= 1.");

                return self::FAILURE;
            }

            $tpPercentRaw = $account->profit_percentage ?? 0.36;
            $slPercentRaw = $account->stop_market_initial_percentage ?? 15.0;
            $tpPercent = is_numeric($tpPercentRaw) ? (float) $tpPercentRaw : 0.36;
            $slPercent = is_numeric($slPercentRaw) ? (float) $slPercentRaw : 15.0;
            $N = (int) ($exchangeSymbol->total_limit_orders ?? 4);

            if ($N < 1) {
                $this->error('ExchangeSymbol::total_limit_orders must be >= 1.');

                return self::FAILURE;
            }
            if ($tpPercent <= 0) {
                $this->error('Account::profit_percentage must be > 0.');

                return self::FAILURE;
            }
            if ($slPercent <= 0) {
                $this->error('Account::stop_market_initial_percentage must be > 0.');

                return self::FAILURE;
            }

            $gapPercentRaw = $direction === 'LONG'
                ? $exchangeSymbol->percentage_gap_long
                : $exchangeSymbol->percentage_gap_short;
            $gapDisplay = $gapPercentRaw === null
                ? 'n/a'
                : mb_rtrim(mb_rtrim((string) $gapPercentRaw, '0'), '.').'%';

            // ---- 2) Market order slice using Kraite ----
            $market = Engine::calculateMarketOrderData(
                $margin,
                $configuredLeverage,
                $exchangeSymbol,
                $price
            );

            $resumeRows = [
                ['Key' => 'Account ID', 'Value' => (string) $account->id],
                ['Key' => 'Pair', 'Value' => $exchangeSymbol->parsed_trading_pair ?? $exchangeSymbol->symbol ?? ('#'.$exchangeSymbol->id)],
                ['Key' => 'ExchangeSymbol ID', 'Value' => (string) $exchangeSymbol->id],
                ['Key' => 'Direction', 'Value' => $direction],
                ['Key' => 'Side Gap (%)', 'Value' => $gapDisplay],
                ['Key' => 'Reference Price', 'Value' => api_format_price($price, $exchangeSymbol)],
                ['Key' => 'Margin', 'Value' => (string) $margin],
                ['Key' => 'Leverage (configured)', 'Value' => (string) $configuredLeverage],
                ['Key' => 'Limit Orders (N)', 'Value' => (string) $N],
                ['Key' => 'TP Percent', 'Value' => mb_rtrim(mb_rtrim((string) $tpPercent, '0'), '.').'%'],
                ['Key' => 'SL Percent', 'Value' => mb_rtrim(mb_rtrim((string) $slPercent, '0'), '.').'%'],
                ['Key' => 'Notional (quote)', 'Value' => $market['notional']],
                ['Key' => 'Market Qty (base)', 'Value' => $market['quantity']],
            ];

            $this->info('Parameters & Market Slice');
            $this->table(['Key', 'Value'], $resumeRows);

            // Market order (price, quantity)
            $marketRow = [[
                'price' => $market['price'],
                'quantity' => $market['quantity'],
            ]];
            $this->info('Market Order');
            $this->table(['price', 'quantity'], $marketRow);

            // ---- 3) Profit (TP) order ----
            $tp = Engine::calculateProfitOrder(
                $direction,
                $price,
                (string) $tpPercent,
                (string) $market['quantity'],
                $exchangeSymbol
            );
            $tpSide = $direction === 'LONG' ? 'SELL' : 'BUY';

            $this->info('Profit Order (TP)');
            $this->table(['side', 'price', 'quantity'], [[
                'side' => $tpSide,
                'price' => $tp['price'],
                'quantity' => $tp['quantity'],
            ]]);

            // ---- 4) Limit ladder (+ WAP per rung) ----
            $ladder = Engine::calculateLimitOrdersData(
                $N,
                $direction,
                $price,
                (string) $market['quantity'],
                $exchangeSymbol
            );

            $this->info("Limit Orders — {$direction}");
            $anchorPrice = null;

            /** @var array<int, array{price:string, quantity:string, amount:string}> $ladder */
            if (empty($ladder)) {
                $this->warn('No limit orders generated (check gap/multipliers config).');
                $anchorPrice = $price;
            } else {
                // Compute cumulative WAP per rung
                $wapSeries = Engine::calculateWAPData($ladder, $direction, (string) $tpPercent);

                $rows = [];
                foreach ($ladder as $i => $row) {
                    $wap = $wapSeries[$i]['wap'] ?? null;
                    $rows[] = [
                        '#' => (int) $i + 1,
                        'price' => $row['price'],
                        'quantity' => $row['quantity'],
                        'wap' => $wap === null ? '—' : api_format_price($wap, $exchangeSymbol),
                    ];
                }
                $this->table(['#', 'price', 'quantity', 'wap'], $rows);

                // Use the last ladder rung as anchor for SL
                $last = end($ladder);
                $anchorPrice = $last['price'];
            }

            // ---- 5) Stop-loss (SL) ----
            if ($anchorPrice === null) { // @phpstan-ignore identical.alwaysFalse
                $this->warn('Unable to compute stop-loss anchor price; skipping SL preview.');
            } else {
                $sl = Engine::calculateStopLossOrder(
                    $direction,
                    $anchorPrice,
                    (string) $slPercent,
                    $tp['quantity'],
                    $exchangeSymbol
                );
                $slSide = $tpSide;

                $this->info('Stop-Loss Order (STOP-MARKET)');
                $this->table(['side', 'anchor', 'percent', 'price', 'quantity'], [[
                    'side' => $slSide,
                    'anchor' => api_format_price($anchorPrice, $exchangeSymbol),
                    'percent' => mb_rtrim(mb_rtrim((string) $slPercent, '0'), '.').'%',
                    'price' => $sl['price'],
                    'quantity' => $sl['quantity'],
                ]]);
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Failed to calculate orders: '.$e->getMessage());
            $this->error($e->getTraceAsString());

            return self::FAILURE;
        }
    }
}
