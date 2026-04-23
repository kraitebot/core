<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Collection;
use Kraite\Core\Models\ExchangeSymbol;
use StepDispatcher\Support\BaseCommand;

/**
 * DisableVolatileTokensCommand
 *
 * Sweeps the exchange_symbols table every hour and flips
 * is_manually_enabled=false on any row whose token is on the curated
 * "don't trade" list below — across every exchange, not just Binance —
 * so the token-discovery + position-opening pipelines never pick them.
 *
 * Why a periodic sweep instead of a one-shot patch:
 * - Token discovery re-ingests symbols as exchanges list them, so a row
 *   for e.g. TRUMP on a newly-enabled exchange will appear with
 *   is_manually_enabled defaulted to true. The hourly sweep catches it
 *   the same hour.
 * - Operators who flip a row back to enabled by accident will be
 *   auto-corrected on the next tick.
 *
 * The command is strictly additive: it only ever flips TRUE → FALSE on
 * listed tokens. It never re-enables anything. A token has to fall off
 * the static list below before it is trade-eligible again, which keeps
 * the decision explicit and source-controlled.
 *
 * Categories (all merged into one disable pass):
 *
 *   1. MEMES: pump-dump tokens driven by social sentiment with no
 *      fundamental price floor. Routinely show 30–90% swings that
 *      break the ladder math and blow up the cancel workflow.
 *
 *   2. SPECULATIVE/NARRATIVE: new or politically-charged listings with
 *      volume cliffs, thin order books, and regulatory tail risk.
 *
 *   3. STRUCTURAL-BRITTLE: low-price tokens where the chained limit
 *      ladder collapses below min_notional at typical margin tiers,
 *      producing realized losses from the unwind path (USELESS #64
 *      incident on 2026-04-23).
 */
final class DisableVolatileTokensCommand extends BaseCommand
{
    /**
     * Memes / pump-dump tokens. Extend by adding the ticker (uppercase,
     * without the quote suffix) — applies across every api_system row.
     *
     * @var list<string>
     */
    private const MEME_TOKENS = [
        '1000SATS', 'ACT', 'APU', 'BANANA', 'BASED', 'BOME', 'BONK', 'BRETT',
        'CATI', 'CHEEMS', 'DOGE', 'DOGS', 'FARTCOIN', 'FLOKI', 'GOAT', 'HIPPO',
        'HMSTR', 'MANEKI', 'MEME', 'MEMEFI', 'MEW', 'MICHI', 'MOODENG', 'MOTHER',
        'MYRO', 'NEIRO', 'NEIROETH', 'PEPE', 'PNUT', 'POPCAT', 'SHIB', 'SLERF',
        'SUNDOG', 'TRUMP', 'TURBO', 'WIF',
    ];

    /**
     * Narrative-driven / speculative / thin-book listings.
     *
     * @var list<string>
     */
    private const SPECULATIVE_TOKENS = [
        'BIO', 'DUEL', 'FTT', 'MON', 'NIGHT', 'ORDI', 'SAFE', 'SOON', 'ULTIMA',
        'VVV', 'WLFI', 'XPL',
    ];

    /**
     * Tokens blocked for structural reasons (ladder / min_notional
     * brittleness). The limit-ladder pre-gate in
     * VerifyOrderNotionalForMarketOrderJob catches these at runtime, but
     * we also keep them off the selection list to avoid wasting the
     * scheduler's attention on them.
     *
     * @var list<string>
     */
    private const STRUCTURAL_BRITTLE_TOKENS = [
        'ATA', 'AZTEC', 'BAS', 'BSB', 'CHILLGUY', 'IR', 'IRYS', 'MYX', 'ON',
        'PRL', 'SYN', 'USELESS', 'VELVET',
    ];

    /**
     * Tokens excluded by operator judgement — behavioural patterns that
     * don't necessarily fit the automated buckets above (excessive
     * exceptions, repeated fast-trade races, unclean fills, etc.).
     * Moved here rather than MEMES/SPECULATIVE so the reasoning stays
     * honest; these are calls on the trading desk, not algorithmic
     * categorisations.
     *
     * @var list<string>
     */
    private const OPERATOR_EXCLUDED_TOKENS = [
        'BEAT', 'CYS', 'GENIUS', 'PARTI', 'SKYAI', 'XPIN',
    ];

    protected $signature = 'kraite:disable-volatile-tokens
                            {--dry-run : Report what would be disabled without writing}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Sweep exchange_symbols and disable tokens on the volatility deny-list across all exchanges.';

    public function handle(): int
    {
        $denyList = array_values(array_unique(array_merge(
            self::MEME_TOKENS,
            self::SPECULATIVE_TOKENS,
            self::STRUCTURAL_BRITTLE_TOKENS,
            self::OPERATOR_EXCLUDED_TOKENS,
        )));

        $dryRun = (bool) $this->option('dry-run');

        $matches = ExchangeSymbol::whereIn('token', $denyList)
            ->where('is_manually_enabled', true)
            ->get();

        if ($matches->isEmpty()) {
            $this->verboseInfo('All deny-listed tokens are already disabled. Nothing to do.');

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

        $this->verboseInfo("Disabled {$rowCount} exchange_symbol row(s) across {$tokenCount} token(s).");
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
