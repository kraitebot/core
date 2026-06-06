<?php

declare(strict_types=1);

namespace Kraite\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Kraite\Core\Models\ExchangeSymbol;

/**
 * ProfitableTokensBacktestApprovalSeeder
 *
 * Backfills `was_backtesting_approved = true` for every token that
 * closed at least one position in profit on Bruno's live Binance
 * account. The list below is a point-in-time extraction (generated
 * 2026-06-06) from 12 months of `/fapi/v1/income` REALIZED_PNL
 * records — 1,242 closed positions across 152 symbols, of which 141
 * tokens had one or more profitable closes.
 *
 * The flag is flipped on the BINANCE/USDT row via Eloquent so
 * `ExchangeSymbolObserver` fires and propagates the approval to the
 * same token on every other exchange — identical to the operator
 * approving each token by hand after a backtest review, just batched.
 *
 * Idempotent: re-running re-saves the same true values, a no-op.
 * Tokens in the list that no longer exist as Binance/USDT exchange
 * symbols (delisted since the extraction) are reported and skipped.
 */
final class ProfitableTokensBacktestApprovalSeeder extends Seeder
{
    /**
     * Tokens with >= 1 profitable position close (12-month window,
     * Bruno Falcao Binance account, extracted 2026-06-06).
     *
     * @var list<string>
     */
    public const PROFITABLE_TOKENS = [
        '0G',
        '1000FLOKI',
        '1000SHIB',
        '1000XEC',
        'AAVE',
        'ACU',
        'ADA',
        'AERO',
        'AGLD',
        'AIOT',
        'AKT',
        'ALCH',
        'ALGO',
        'ALL',
        'APE',
        'API3',
        'APT',
        'ARB',
        'ARIA',
        'ARKM',
        'ATA',
        'ATOM',
        'AVAX',
        'BAS',
        'BASED',
        'BAT',
        'BCH',
        'BEAT',
        'BEL',
        'BIGTIME',
        'BLUR',
        'BNB',
        'BSB',
        'BTC',
        'CAKE',
        'CC',
        'CELR',
        'CETUS',
        'CHILLGUY',
        'CHIP',
        'CHR',
        'CHZ',
        'CVC',
        'CYBER',
        'DASH',
        'DEEP',
        'DEXE',
        'DOGE',
        'DOLO',
        'DOT',
        'DUSK',
        'ENSO',
        'ETC',
        'ETH',
        'EUL',
        'FET',
        'FHE',
        'FLOCK',
        'GENIUS',
        'GIGGLE',
        'GMT',
        'GMX',
        'GRT',
        'GTC',
        'HBAR',
        'HYPE',
        'HYPER',
        'ICNT',
        'IMX',
        'INJ',
        'IOTA',
        'IP',
        'IR',
        'IRYS',
        'JTO',
        'JUP',
        'KAVA',
        'KITE',
        'LA',
        'LAB',
        'LDO',
        'LIGHT',
        'LINK',
        'LIT',
        'LPT',
        'LTC',
        'M',
        'MANA',
        'MASK',
        'MELANIA',
        'MOVE',
        'MTL',
        'MYX',
        'NEAR',
        'NMR',
        'ON',
        'ONDO',
        'PARTI',
        'PAXG',
        'PIPPIN',
        'PNUT',
        'POL',
        'POLYX',
        'PRL',
        'PROM',
        'PUNDIX',
        'QNT',
        'RAVE',
        'RENDER',
        'REZ',
        'RSR',
        'RUNE',
        'RVV',
        'SFP',
        'SKY',
        'SKYAI',
        'SLP',
        'SOL',
        'SOMI',
        'STABLE',
        'STX',
        'TAKE',
        'TAO',
        'THETA',
        'TIA',
        'TON',
        'TRUMP',
        'TRX',
        'TURTLE',
        'UNI',
        'USELESS',
        'VELVET',
        'VET',
        'XMR',
        'XPIN',
        'YB',
        'YFI',
        'ZEREBRO',
        'ZETA',
        'ZK',
        'ZRO',
    ];

    public function run(): void
    {
        $symbols = ExchangeSymbol::query()
            ->whereHas('apiSystem', static function ($query): void {
                $query->where('canonical', 'binance');
            })
            ->where('quote', 'USDT')
            ->whereIn('token', self::PROFITABLE_TOKENS)
            ->get();

        $symbols->each(static function (ExchangeSymbol $symbol): void {
            $symbol->was_backtesting_approved = true;
            $symbol->save();
        });

        $missing = array_values(array_diff(
            self::PROFITABLE_TOKENS,
            $symbols->pluck('token')->all()
        ));

        $this->command?->info(sprintf(
            'Approved %d of %d profitable tokens (Binance/USDT rows; observer propagates cross-exchange).',
            $symbols->count(),
            count(self::PROFITABLE_TOKENS)
        ));

        if ($missing !== []) {
            $this->command?->warn('Not found as Binance/USDT symbols (skipped): '.implode(', ', $missing));
        }
    }
}
