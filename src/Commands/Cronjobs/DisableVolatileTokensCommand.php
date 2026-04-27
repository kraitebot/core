<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Collection;
use Kraite\Core\Models\ExchangeSymbol;
use StepDispatcher\Support\BaseCommand;

/**
 * DisableVolatileTokensCommand
 *
 * Allow-list sweep. Every hour, this command flips
 * is_manually_enabled=false on any exchange_symbols row whose token is
 * NOT on the curated ALLOWED_TOKENS list below — across every exchange,
 * not just Binance. If we haven't explicitly vouched for a token, it
 * doesn't trade.
 *
 * Why the allow-list model (rather than the old deny-list of memes /
 * speculative / structural-brittle buckets):
 *   - Deny-lists are always a step behind the market. New volatile
 *     listings (GIGGLE, USELESS, VELVET, SYN, ...) showed up on an
 *     exchange, ran into the ladder-math / fast-trade / min_notional
 *     class of failures, and only got added to the deny-list after an
 *     incident. The allow-list inverts this: unknown = disabled.
 *   - The base list is sourced from "CMC top 75 × Binance availability"
 *     (snapshot 2026-04-23). These are liquid, large-cap, well-book'd
 *     pairs where the ladder pre-gate and fast-trade guards are unlikely
 *     to fire. Operators extend the list by adding tickers explicitly.
 *   - Periodic (hourly) rather than one-shot because token-discovery
 *     keeps re-ingesting new listings; any row for a non-allow-listed
 *     token will be caught within the hour.
 *
 * The command is strictly additive: it only ever flips TRUE → FALSE on
 * non-allow-listed tokens. It never re-enables anything. Bringing a
 * token back into trade-eligibility requires adding it to the list
 * below AND an operator flipping `is_manually_enabled` back to true
 * (the hourly sweep won't do that on its own).
 */
final class DisableVolatileTokensCommand extends BaseCommand
{
    /**
     * Curated trade-eligibility allow-list. Tokens that are NOT on this
     * list get disabled across every api_system row.
     *
     * Seed source: CoinMarketCap top-75 by market cap (2026-04-23)
     * intersected with what Binance actually offers as a USDT perp.
     * Stablecoins and non-traded assets from the CMC list dropped out
     * of the intersection naturally. Extend by adding the ticker
     * (uppercase, no quote suffix) — applies across every api_system row.
     *
     * @var list<string>
     */
    private const ALLOWED_TOKENS = [
        'AAVE', 'ADA', 'AERO', 'ALGO', 'APT', 'ARB', 'ASTER', 'ATOM', 'AVAX', 'AXS',
        'BAT', 'BCH', 'BNB', 'BSV', 'CC', 'CFX', 'CHZ', 'COMP', 'CRV',
        'CVX', 'DASH', 'DEEP', 'DEXE', 'DOT', 'ENS', 'ETC', 'ETH', 'FET',
        'FIL', 'GALA', 'GLM', 'GRT', 'HBAR', 'ICP', 'IMX', 'INJ', 'IP', 'JASMY',
        'JST', 'JTO', 'JUP', 'KAS', 'LDO', 'LINK', 'LIT', 'LTC', 'MANA',
        'NEAR', 'NEO', 'ONDO', 'OP', 'PAXG', 'PENDLE', 'POL', 'QNT', 'RAVE', 'RAY',
        'RENDER', 'RUNE', 'SAND', 'SEI', 'SENT', 'SFP', 'SOL', 'STRK', 'STX', 'SUI',
        'TAO', 'THETA', 'TIA', 'TON', 'TRX', 'TWT', 'UNI', 'VET', 'WAL', 'XLM',
        'XMR', 'XRP', 'XTZ', 'ZK', 'ZRO',
    ];

    protected $signature = 'kraite:disable-volatile-tokens
                            {--dry-run : Report what would be disabled without writing}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Sweep exchange_symbols and disable every token NOT on the curated allow-list, across all exchanges.';

    public function handle(): int
    {
        $allowList = array_values(array_unique(self::ALLOWED_TOKENS));

        $dryRun = (bool) $this->option('dry-run');

        $matches = ExchangeSymbol::whereNotIn('token', $allowList)
            ->where('is_manually_enabled', true)
            ->get();

        if ($matches->isEmpty()) {
            $this->verboseInfo('Every enabled exchange_symbol row is already on the allow-list. Nothing to do.');

            return self::SUCCESS;
        }

        $groupedByToken = $matches->groupBy('token');
        $rowCount = $matches->count();
        $tokenCount = $groupedByToken->count();

        if ($dryRun) {
            $this->verboseWarn("DRY RUN - would disable {$rowCount} row(s) across {$tokenCount} token(s):");
            $this->logTokenGroups($groupedByToken);

            return self::SUCCESS;
        }

        foreach ($matches as $exchangeSymbol) {
            $exchangeSymbol->updateSaving(['is_manually_enabled' => false]);
        }

        $this->verboseInfo("Disabled {$rowCount} exchange_symbol row(s) across {$tokenCount} token(s) (non-allow-listed).");
        $this->logTokenGroups($groupedByToken);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<string, Collection<int, ExchangeSymbol>>  $groupedByToken
     */
    private function logTokenGroups(Collection $groupedByToken): void
    {
        foreach ($groupedByToken as $token => $rows) {
            $this->verboseInfo(sprintf(
                '  %-12s  api_systems=[%s]',
                $token,
                $rows->pluck('api_system_id')->unique()->sort()->implode(','),
            ));
        }
    }
}
