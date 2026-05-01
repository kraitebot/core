<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renames the seeded top-up coins so the dropdown surfaces the
 * ticker symbol (USDT, USDC, BTC, …) as well as the chain. The
 * previous "Tether (Tron)" wording assumed users know "Tether"
 * means "USDT" — they often don't. New format keeps the chain in
 * parens for stablecoins on multiple chains and otherwise just
 * carries the ticker for single-chain natives.
 *
 * Idempotent — only updates rows that match the original seeded
 * canonical + display_name pair, so admin-edited rows stay
 * untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $renames = [
            ['canonical' => 'usdttrc20', 'from' => 'Tether (Tron)',      'to' => 'USDT (Tron)'],
            ['canonical' => 'usdtbsc',   'from' => 'Tether (BSC)',       'to' => 'USDT (BSC)'],
            ['canonical' => 'usdcbsc',   'from' => 'USD Coin (BSC)',     'to' => 'USDC (BSC)'],
            ['canonical' => 'usdcsol',   'from' => 'USD Coin (Solana)',  'to' => 'USDC (Solana)'],
            ['canonical' => 'usdtsol',   'from' => 'Tether (Solana)',    'to' => 'USDT (Solana)'],
            ['canonical' => 'btc',       'from' => 'Bitcoin',            'to' => 'BTC (Bitcoin)'],
            ['canonical' => 'eth',       'from' => 'Ethereum',           'to' => 'ETH (Ethereum)'],
            ['canonical' => 'sol',       'from' => 'Solana',             'to' => 'SOL (Solana)'],
            ['canonical' => 'ltc',       'from' => 'Litecoin',           'to' => 'LTC (Litecoin)'],
            ['canonical' => 'bnbbsc',    'from' => 'BNB (BSC)',          'to' => 'BNB (BSC)'],
        ];

        foreach ($renames as $r) {
            DB::table('top_up_coins')
                ->where('canonical', $r['canonical'])
                ->where('display_name', $r['from'])
                ->update(['display_name' => $r['to'], 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $reverses = [
            ['canonical' => 'usdttrc20', 'from' => 'USDT (Tron)',      'to' => 'Tether (Tron)'],
            ['canonical' => 'usdtbsc',   'from' => 'USDT (BSC)',       'to' => 'Tether (BSC)'],
            ['canonical' => 'usdcbsc',   'from' => 'USDC (BSC)',       'to' => 'USD Coin (BSC)'],
            ['canonical' => 'usdcsol',   'from' => 'USDC (Solana)',    'to' => 'USD Coin (Solana)'],
            ['canonical' => 'usdtsol',   'from' => 'USDT (Solana)',    'to' => 'Tether (Solana)'],
            ['canonical' => 'btc',       'from' => 'BTC (Bitcoin)',    'to' => 'Bitcoin'],
            ['canonical' => 'eth',       'from' => 'ETH (Ethereum)',   'to' => 'Ethereum'],
            ['canonical' => 'sol',       'from' => 'SOL (Solana)',     'to' => 'Solana'],
            ['canonical' => 'ltc',       'from' => 'LTC (Litecoin)',   'to' => 'Litecoin'],
        ];

        foreach ($reverses as $r) {
            DB::table('top_up_coins')
                ->where('canonical', $r['canonical'])
                ->where('display_name', $r['from'])
                ->update(['display_name' => $r['to'], 'updated_at' => now()]);
        }
    }
};
