<?php

declare(strict_types=1);

namespace Kraite\Core\Http\Controllers\Api;

use Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Log;

final class DashboardApiController extends Controller
{
    /**
     * Get dashboard data with global statistics and positions.
     *
     * Caching behavior:
     * - If refresh=true (F5/page refresh): Bypass cache and regenerate data
     * - Otherwise (dashboard auto-refresh): Use cached data (30 min TTL)
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $cacheKey = "dashboard_data_user_{$user->id}";
        $shouldRefresh = $request->boolean('refresh', false);

        // If refresh=true (F5), clear cache and regenerate
        if ($shouldRefresh) {
            Cache::forget($cacheKey);
            Log::info('🔄 Dashboard cache cleared (F5 refresh)', ['user_id' => $user->id]);
        }

        // Get data from cache or generate new
        $data = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($user) {
            Log::info('🎯 Generating fresh dashboard data', ['user_id' => $user->id]);

            $globalStats = $this->generateGlobalStats();
            $longPositions = $this->generatePositions('LONG', 6);
            $shortPositions = $this->generatePositions('SHORT', 6);

            return [
                'global_stats' => $globalStats,
                'positions' => [
                    'long' => $longPositions,
                    'short' => $shortPositions,
                ],
            ];
        });

        if (! $shouldRefresh) {
            Log::info('📦 Using cached dashboard data', ['user_id' => $user->id]);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get global stats only (for 30s polling).
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        return response()->json([
            'success' => true,
            'data' => $this->generateGlobalStats(),
        ]);
    }

    /**
     * Get all positions list (for 10s polling).
     */
    public function positions(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        // Return all 12 stub positions (deterministic data)
        return response()->json([
            'success' => true,
            'data' => $this->getStubPositions(),
        ]);
    }

    /**
     * Get single position by ID (for 5s polling per card).
     */
    public function position(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        // Find position by ID from stub data
        $position = collect($this->getStubPositions())->firstWhere('id', $id);

        if (! $position) {
            return response()->json([
                'success' => false,
                'message' => 'Position not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $position,
        ]);
    }

    /**
     * Generate stub global statistics.
     *
     * Flatter structure as requested: ['gross_revenue' => XXX, 'daily_gross' => XXX, ...]
     */
    public function generateGlobalStats(): array
    {
        return [
            'gross_revenue' => round(random_int(1000, 5000) + (random_int(0, 99) / 100), precision: 2),
            'daily_gross' => round(random_int(50, 300) + (random_int(0, 99) / 100), precision: 2),
            'margin_ratio' => round(random_int(20, 100) + (random_int(0, 99) / 100), precision: 2),
            'drawdown' => round(random_int(-100, -5) + (random_int(0, 99) / 100), precision: 2),
            'clean_revenue' => round(random_int(500, 4000) + (random_int(0, 99) / 100), precision: 2),
            'avg_variation' => round(random_int(-50, 50) + (random_int(0, 99) / 100), precision: 2),
        ];
    }

    /**
     * Get all 12 stub positions (6 long + 6 short) with fixed deterministic data.
     *
     * RULER LAYOUT (same for both LONG and SHORT):
     *   [P] --- [1] --- [2] --- [3] --- [4]
     *   LEFT (0%)                    RIGHT (100%)
     *
     * - Profit (P) is ALWAYS at LEFT (0%)
     * - Limit orders spread left to right (1, 2, 3, 4)
     * - Limit 4 is ALWAYS at RIGHT (100%)
     * - Mark (current price) positioned somewhere on the ruler
     *
     * Price direction:
     * - LONG: P is highest price, L4 is lowest (prices DECREASE left→right)
     * - SHORT: P is lowest price, L4 is highest (prices INCREASE left→right)
     *
     * @return array<int, array>
     */
    public function getStubPositions(): array
    {
        return [
            // === LONG POSITIONS (6) ===
            // LONG: Profit (high price) at LEFT, Limits (low prices) at RIGHT
            // Prices DECREASE from left to right: P > L1 > L2 > L3 > L4

            // Long #1: BTC - 0 limits filled, price near profit
            [
                'id' => 'long_0',
                'token' => 'BTC',
                'name' => 'Bitcoin',
                'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/1.png',
                'position' => 'long',
                'leverage' => '20x',
                'opened_at' => now()->subDays(3)->toIso8601String(),
                'opened_at_human' => '3 days ago',
                'is_hedged' => false,
                'is_waped' => false,
                'is_recently_opened' => false,
                'mark_price' => 101500.00,
                'variation_percent' => 1.50,
                'chart' => $this->generateChartDataStub(100000, 'up'),
                'ladder' => [
                    // No initial_profit - profit hasn't been adjusted (0 limits filled)
                    'profit_price' => 102000.00,
                    'profit_percent' => 0,
                    'mark_price' => 101500.00,
                    'mark_percent' => 10.0,
                    'limits' => [
                        ['price' => 100750.00, 'number' => 1, 'percent' => 25.0, 'filled' => false],
                        ['price' => 99500.00, 'number' => 2, 'percent' => 50.0, 'filled' => false],
                        ['price' => 98250.00, 'number' => 3, 'percent' => 75.0, 'filled' => false],
                        ['price' => 97000.00, 'number' => 4, 'percent' => 100, 'filled' => false],
                    ],
                    'limits_filled' => 0,
                    'limits_total' => 4,
                ],
                'stats' => ['size' => 50000, 'alpha_path' => 95.5, 'limits_filled' => 0, 'limits_total' => 4],
                'timeframes' => ['1w' => 1, '1d' => 1, '4h' => 1, '1h' => 1],
            ],
            // Long #2: ETH - 1 limit filled, hedged, profit adjusted
            [
                'id' => 'long_1',
                'token' => 'ETH',
                'name' => 'Ethereum',
                'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/1027.png',
                'position' => 'long',
                'leverage' => '15x',
                'opened_at' => now()->subDays(5)->toIso8601String(),
                'opened_at_human' => '5 days ago',
                'is_hedged' => true,
                'is_waped' => false,
                'is_recently_opened' => false,
                'mark_price' => 3650.00,
                'variation_percent' => -2.63,
                'chart' => $this->generateChartDataStub(3800, 'down'),
                'ladder' => [
                    'initial_profit_price' => 3950.00,
                    'initial_profit_percent' => 0,
                    'profit_price' => 3876.00,
                    'profit_percent' => 13.96,
                    'mark_price' => 3650.00,
                    'mark_percent' => 56.60,
                    'limits' => [
                        ['price' => 3705.00, 'number' => 1, 'percent' => 46.23, 'filled' => true],
                        ['price' => 3610.00, 'number' => 2, 'percent' => 64.15, 'filled' => false],
                        ['price' => 3515.00, 'number' => 3, 'percent' => 82.08, 'filled' => false],
                        ['price' => 3420.00, 'number' => 4, 'percent' => 100, 'filled' => false],
                    ],
                    'limits_filled' => 1,
                    'limits_total' => 4,
                ],
                'stats' => ['size' => 35000, 'alpha_path' => 88.2, 'limits_filled' => 1, 'limits_total' => 4],
                'timeframes' => ['1w' => -1, '1d' => -1, '4h' => 1, '1h' => 1],
            ],
            // Long #3: SOL - 3 limits filled (L1, L2, L3), waped, profit adjusted
            [
                'id' => 'long_2',
                'token' => 'SOL',
                'name' => 'Solana',
                'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/5426.png',
                'position' => 'long',
                'leverage' => '25x',
                'opened_at' => now()->subDays(7)->toIso8601String(),
                'opened_at_human' => '1 week ago',
                'is_hedged' => false,
                'is_waped' => true,
                'is_recently_opened' => false,
                'mark_price' => 182.00,
                'variation_percent' => -7.50,
                'chart' => $this->generateChartDataStub(200, 'down'),
                'ladder' => [
                    // Range: initial 215 to L4 178 = 37
                    // Mark at 182, which is below L3 (183), so L3 is filled
                    'initial_profit_price' => 215.00,
                    'initial_profit_percent' => 0,
                    'profit_price' => 204.00,
                    'profit_percent' => 29.73, // (215-204)/37*100
                    'mark_price' => 182.00,
                    'mark_percent' => 89.19, // (215-182)/37*100
                    'limits' => [
                        ['price' => 197.00, 'number' => 1, 'percent' => 48.65, 'filled' => true],
                        ['price' => 190.00, 'number' => 2, 'percent' => 67.57, 'filled' => true],
                        ['price' => 183.00, 'number' => 3, 'percent' => 86.49, 'filled' => true],
                        ['price' => 178.00, 'number' => 4, 'percent' => 100, 'filled' => false],
                    ],
                    'limits_filled' => 3,
                    'limits_total' => 4,
                ],
                'stats' => ['size' => 28000, 'alpha_path' => 110.3, 'limits_filled' => 3, 'limits_total' => 4],
                'timeframes' => ['1w' => -1, '1d' => -1, '4h' => -1, '1h' => 1],
            ],
            // Long #4: AVAX - 3 limits filled, profit adjusted
            [
                'id' => 'long_3',
                'token' => 'AVAX',
                'name' => 'Avalanche',
                'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/5805.png',
                'position' => 'long',
                'leverage' => '10x',
                'opened_at' => now()->subDays(10)->toIso8601String(),
                'opened_at_human' => '1 week ago',
                'is_hedged' => false,
                'is_waped' => false,
                'is_recently_opened' => false,
                'mark_price' => 35.00,
                'variation_percent' => -11.25,
                'chart' => $this->generateChartDataStub(40, 'down'),
                'ladder' => [
                    // Range: initial P=40.80 to L4=34 = 6.80
                    // Profit adjusted after fills
                    'initial_profit_price' => 40.80,
                    'initial_profit_percent' => 0,
                    'profit_price' => 38.50,
                    'profit_percent' => 33.82, // (40.8-38.5)/6.8*100
                    'mark_price' => 35.00,
                    'mark_percent' => 85.29, // (40.8-35)/6.8*100
                    'limits' => [
                        ['price' => 39.10, 'number' => 1, 'percent' => 25.0, 'filled' => true],
                        ['price' => 37.40, 'number' => 2, 'percent' => 50.0, 'filled' => true],
                        ['price' => 35.70, 'number' => 3, 'percent' => 75.0, 'filled' => true],
                        ['price' => 34.00, 'number' => 4, 'percent' => 100, 'filled' => false],
                    ],
                    'limits_filled' => 3,
                    'limits_total' => 4,
                ],
                'stats' => ['size' => 15000, 'alpha_path' => 78.9, 'limits_filled' => 3, 'limits_total' => 4],
                'timeframes' => ['1w' => -1, '1d' => -1, '4h' => 1, '1h' => 1],
            ],
            // Long #5: LINK - 4 limits filled (all), profit adjusted
            [
                'id' => 'long_4',
                'token' => 'LINK',
                'name' => 'Chainlink',
                'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/1975.png',
                'position' => 'long',
                'leverage' => '12x',
                'opened_at' => now()->subDays(14)->toIso8601String(),
                'opened_at_human' => '2 weeks ago',
                'is_hedged' => false,
                'is_waped' => false,
                'is_recently_opened' => false,
                'mark_price' => 12.80,
                'variation_percent' => -16.67,
                'chart' => $this->generateChartDataStub(15, 'down'),
                'ladder' => [
                    // Range: initial P=15.30 to L4=12.50 = 2.80, all limits filled
                    // Profit adjusted after all fills
                    'initial_profit_price' => 15.30,
                    'initial_profit_percent' => 0,
                    'profit_price' => 13.80,
                    'profit_percent' => 53.57, // (15.30-13.80)/2.80*100
                    'mark_price' => 12.80,
                    'mark_percent' => 89.29, // Near L4
                    'limits' => [
                        ['price' => 14.60, 'number' => 1, 'percent' => 25.0, 'filled' => true],
                        ['price' => 13.90, 'number' => 2, 'percent' => 50.0, 'filled' => true],
                        ['price' => 13.20, 'number' => 3, 'percent' => 75.0, 'filled' => true],
                        ['price' => 12.50, 'number' => 4, 'percent' => 100, 'filled' => true],
                    ],
                    'limits_filled' => 4,
                    'limits_total' => 4,
                ],
                'stats' => ['size' => 22000, 'alpha_path' => 65.0, 'limits_filled' => 4, 'limits_total' => 4],
                'timeframes' => ['1w' => -1, '1d' => -1, '4h' => -1, '1h' => 1],
            ],
            // Long #6: XRP - recently opened, 0 limits filled
            [
                'id' => 'long_5',
                'token' => 'XRP',
                'name' => 'Ripple',
                'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/52.png',
                'position' => 'long',
                'leverage' => '18x',
                'opened_at' => now()->subHours(2)->toIso8601String(),
                'opened_at_human' => '2 hours ago',
                'is_hedged' => false,
                'is_waped' => false,
                'is_recently_opened' => true,
                'mark_price' => 2.53,
                'variation_percent' => 0.80,
                'chart' => $this->generateChartDataStub(2.5, 'flat'),
                'ladder' => [
                    // Range: P=2.55 to L4=2.36 = 0.19, 0 limits filled
                    'profit_price' => 2.55,
                    'profit_percent' => 0,
                    'mark_price' => 2.53,
                    'mark_percent' => 10.53, // Very close to P
                    'limits' => [
                        ['price' => 2.48, 'number' => 1, 'percent' => 36.84, 'filled' => false],
                        ['price' => 2.44, 'number' => 2, 'percent' => 57.89, 'filled' => false],
                        ['price' => 2.40, 'number' => 3, 'percent' => 78.95, 'filled' => false],
                        ['price' => 2.36, 'number' => 4, 'percent' => 100, 'filled' => false],
                    ],
                    'limits_filled' => 0,
                    'limits_total' => 4,
                ],
                'stats' => ['size' => 45000, 'alpha_path' => 100.0, 'limits_filled' => 0, 'limits_total' => 4],
                'timeframes' => ['1w' => 1, '1d' => 1, '4h' => 1, '1h' => 1],
            ],

            // === SHORT POSITIONS (6) ===
            // SHORT: Profit (low price) at LEFT, Limits (high prices) at RIGHT
            // Prices INCREASE from left to right: P < L1 < L2 < L3 < L4

            // Short #1: BNB - 0 limits filled
            [
                'id' => 'short_0',
                'token' => 'BNB',
                'name' => 'BNB',
                'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/1839.png',
                'position' => 'short',
                'leverage' => '15x',
                'opened_at' => now()->subDays(2)->toIso8601String(),
                'opened_at_human' => '2 days ago',
                'is_hedged' => false,
                'is_waped' => false,
                'is_recently_opened' => false,
                'mark_price' => 592.00,
                'variation_percent' => 1.67,
                'chart' => $this->generateChartDataStub(600, 'down'),
                'ladder' => [
                    // No initial_profit - profit hasn't been adjusted (0 limits filled)
                    // SHORT: Range P=588 to L4=624 = 36
                    'profit_price' => 588.00,
                    'profit_percent' => 0,
                    'mark_price' => 592.00,
                    'mark_percent' => 11.11, // (592-588)/36*100
                    'limits' => [
                        ['price' => 597.00, 'number' => 1, 'percent' => 25.0, 'filled' => false],
                        ['price' => 606.00, 'number' => 2, 'percent' => 50.0, 'filled' => false],
                        ['price' => 615.00, 'number' => 3, 'percent' => 75.0, 'filled' => false],
                        ['price' => 624.00, 'number' => 4, 'percent' => 100, 'filled' => false],
                    ],
                    'limits_filled' => 0,
                    'limits_total' => 4,
                ],
                'stats' => ['size' => 40000, 'alpha_path' => 98.0, 'limits_filled' => 0, 'limits_total' => 4],
                'timeframes' => ['1w' => 1, '1d' => 1, '4h' => 1, '1h' => 1],
            ],
            // Short #2: DOGE - 1 limit filled, hedged, profit adjusted
            [
                'id' => 'short_1',
                'token' => 'DOGE',
                'name' => 'Dogecoin',
                'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/74.png',
                'position' => 'short',
                'leverage' => '20x',
                'opened_at' => now()->subDays(4)->toIso8601String(),
                'opened_at_human' => '4 days ago',
                'is_hedged' => true,
                'is_waped' => false,
                'is_recently_opened' => false,
                'mark_price' => 0.318,
                'variation_percent' => -5.00,
                'chart' => $this->generateChartDataStub(0.30, 'up'),
                'ladder' => [
                    // Range: initial P=0.294 to L4=0.350 = 0.056
                    // Profit adjusted after L1 filled
                    'initial_profit_price' => 0.294,
                    'initial_profit_percent' => 0,
                    'profit_price' => 0.302,
                    'profit_percent' => 14.29, // (0.302-0.294)/0.056*100
                    'mark_price' => 0.318,
                    'mark_percent' => 42.86, // (0.318-0.294)/0.056*100
                    'limits' => [
                        ['price' => 0.308, 'number' => 1, 'percent' => 25.0, 'filled' => true],
                        ['price' => 0.322, 'number' => 2, 'percent' => 50.0, 'filled' => false],
                        ['price' => 0.336, 'number' => 3, 'percent' => 75.0, 'filled' => false],
                        ['price' => 0.350, 'number' => 4, 'percent' => 100, 'filled' => false],
                    ],
                    'limits_filled' => 1,
                    'limits_total' => 4,
                ],
                'stats' => ['size' => 18000, 'alpha_path' => 85.5, 'limits_filled' => 1, 'limits_total' => 4],
                'timeframes' => ['1w' => -1, '1d' => -1, '4h' => 1, '1h' => 1],
            ],
            // Short #3: DOT - 2 limits filled, waped, profit adjusted
            [
                'id' => 'short_2',
                'token' => 'DOT',
                'name' => 'Polkadot',
                'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/6636.png',
                'position' => 'short',
                'leverage' => '22x',
                'opened_at' => now()->subDays(6)->toIso8601String(),
                'opened_at_human' => '6 days ago',
                'is_hedged' => false,
                'is_waped' => true,
                'is_recently_opened' => false,
                'mark_price' => 7.90,
                'variation_percent' => -11.43,
                'chart' => $this->generateChartDataStub(7.0, 'up'),
                'ladder' => [
                    // SHORT: Range from initial 6.50 to L4 8.40 = 1.90
                    'initial_profit_price' => 6.50,
                    'initial_profit_percent' => 0,
                    'profit_price' => 6.86,
                    'profit_percent' => 18.95, // (6.86-6.50)/1.90*100
                    'mark_price' => 7.90,
                    'mark_percent' => 73.68, // (7.90-6.50)/1.90*100
                    'limits' => [
                        ['price' => 7.22, 'number' => 1, 'percent' => 37.89, 'filled' => true],
                        ['price' => 7.60, 'number' => 2, 'percent' => 57.89, 'filled' => true],
                        ['price' => 8.05, 'number' => 3, 'percent' => 81.58, 'filled' => false],
                        ['price' => 8.40, 'number' => 4, 'percent' => 100, 'filled' => false],
                    ],
                    'limits_filled' => 2,
                    'limits_total' => 4,
                ],
                'stats' => ['size' => 25000, 'alpha_path' => 72.0, 'limits_filled' => 2, 'limits_total' => 4],
                'timeframes' => ['1w' => -1, '1d' => -1, '4h' => -1, '1h' => 1],
            ],
            // Short #4: ADA - 3 limits filled, profit adjusted
            [
                'id' => 'short_3',
                'token' => 'ADA',
                'name' => 'Cardano',
                'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/2010.png',
                'position' => 'short',
                'leverage' => '10x',
                'opened_at' => now()->subDays(9)->toIso8601String(),
                'opened_at_human' => '1 week ago',
                'is_hedged' => false,
                'is_waped' => false,
                'is_recently_opened' => false,
                'mark_price' => 1.13,
                'variation_percent' => -12.00,
                'chart' => $this->generateChartDataStub(1.0, 'up'),
                'ladder' => [
                    // Range: initial P=0.98 to L4=1.15 = 0.17
                    // Profit adjusted after L1-L3 filled
                    'initial_profit_price' => 0.98,
                    'initial_profit_percent' => 0,
                    'profit_price' => 1.05,
                    'profit_percent' => 41.18, // (1.05-0.98)/0.17*100
                    'mark_price' => 1.13,
                    'mark_percent' => 88.24, // (1.13-0.98)/0.17*100
                    'limits' => [
                        ['price' => 1.02, 'number' => 1, 'percent' => 23.53, 'filled' => true],
                        ['price' => 1.07, 'number' => 2, 'percent' => 52.94, 'filled' => true],
                        ['price' => 1.11, 'number' => 3, 'percent' => 76.47, 'filled' => true],
                        ['price' => 1.15, 'number' => 4, 'percent' => 100, 'filled' => false],
                    ],
                    'limits_filled' => 3,
                    'limits_total' => 4,
                ],
                'stats' => ['size' => 12000, 'alpha_path' => 60.0, 'limits_filled' => 3, 'limits_total' => 4],
                'timeframes' => ['1w' => -1, '1d' => -1, '4h' => 1, '1h' => 1],
            ],
            // Short #5: UNI - 4 limits filled (all), profit adjusted
            [
                'id' => 'short_4',
                'token' => 'UNI',
                'name' => 'Uniswap',
                'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/7083.png',
                'position' => 'short',
                'leverage' => '8x',
                'opened_at' => now()->subDays(12)->toIso8601String(),
                'opened_at_human' => '2 weeks ago',
                'is_hedged' => false,
                'is_waped' => false,
                'is_recently_opened' => false,
                'mark_price' => 13.40,
                'variation_percent' => -22.73,
                'chart' => $this->generateChartDataStub(11, 'up'),
                'ladder' => [
                    // Range: initial P=10.78 to L4=13.50 = 2.72, all limits filled
                    // Profit adjusted after all fills
                    'initial_profit_price' => 10.78,
                    'initial_profit_percent' => 0,
                    'profit_price' => 12.20,
                    'profit_percent' => 52.21, // (12.20-10.78)/2.72*100
                    'mark_price' => 13.40,
                    'mark_percent' => 96.32, // Near L4
                    'limits' => [
                        ['price' => 11.46, 'number' => 1, 'percent' => 25.0, 'filled' => true],
                        ['price' => 12.14, 'number' => 2, 'percent' => 50.0, 'filled' => true],
                        ['price' => 12.82, 'number' => 3, 'percent' => 75.0, 'filled' => true],
                        ['price' => 13.50, 'number' => 4, 'percent' => 100, 'filled' => true],
                    ],
                    'limits_filled' => 4,
                    'limits_total' => 4,
                ],
                'stats' => ['size' => 20000, 'alpha_path' => 45.0, 'limits_filled' => 4, 'limits_total' => 4],
                'timeframes' => ['1w' => -1, '1d' => -1, '4h' => -1, '1h' => 1],
            ],
            // Short #6: MATIC - recently opened, 0 limits filled
            [
                'id' => 'short_5',
                'token' => 'MATIC',
                'name' => 'Polygon',
                'icon_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/3890.png',
                'position' => 'short',
                'leverage' => '16x',
                'opened_at' => now()->subHours(4)->toIso8601String(),
                'opened_at_human' => '4 hours ago',
                'is_hedged' => false,
                'is_waped' => false,
                'is_recently_opened' => true,
                'mark_price' => 0.886,
                'variation_percent' => 2.22,
                'chart' => $this->generateChartDataStub(0.90, 'flat'),
                'ladder' => [
                    // Range: P=0.882 to L4=0.946 = 0.064, 0 limits filled
                    'profit_price' => 0.882,
                    'profit_percent' => 0,
                    'mark_price' => 0.886,
                    'mark_percent' => 6.25, // Very close to P
                    'limits' => [
                        ['price' => 0.898, 'number' => 1, 'percent' => 25.0, 'filled' => false],
                        ['price' => 0.914, 'number' => 2, 'percent' => 50.0, 'filled' => false],
                        ['price' => 0.930, 'number' => 3, 'percent' => 75.0, 'filled' => false],
                        ['price' => 0.946, 'number' => 4, 'percent' => 100, 'filled' => false],
                    ],
                    'limits_filled' => 0,
                    'limits_total' => 4,
                ],
                'stats' => ['size' => 30000, 'alpha_path' => 100.0, 'limits_filled' => 0, 'limits_total' => 4],
                'timeframes' => ['1w' => 1, '1d' => 1, '4h' => 1, '1h' => 1],
            ],
        ];
    }

    /**
     * Generate stub chart data with a specific trend.
     *
     * @param  string  $trend  'up', 'down', or 'flat'
     */
    public function generateChartDataStub(float $basePrice, string $trend): array
    {
        $now = now()->timestamp * 1000;
        $ticks = [];

        // Use deterministic seed based on basePrice for consistent data
        $seed = (int) ($basePrice * 100);

        for ($i = 0; $i < 36; $i++) {
            $timestamp = $now - ((35 - $i) * 60 * 1000);

            $progress = $i / 35; // 0 to 1

            // Add realistic noise (deterministic based on seed + index)
            $noise = sin($seed + $i * 0.7) * 0.008 + cos($seed * 2 + $i * 1.3) * 0.005;

            $variation = match ($trend) {
                'up' => $basePrice * (1 + ($progress * 0.05) + $noise),
                'down' => $basePrice * (1 - ($progress * 0.05) + $noise),
                default => $basePrice * (1 + (sin($seed + $i * 0.5) * 0.015) + $noise),
            };

            $ticks[] = [
                'timestamp' => $timestamp,
                'mark_price' => round($variation, 2),
            ];
        }

        return $ticks;
    }

    /**
     * Generate stub positions filtered by type.
     *
     * @param  string  $positionType  LONG or SHORT
     * @param  int  $count  Number of positions to return
     */
    public function generatePositions(string $positionType, int $count): array
    {
        $allPositions = $this->getStubPositions();
        $type = mb_strtolower($positionType);

        return array_values(array_filter(
            $allPositions,
            fn ($p) => $p['position'] === $type
        ));
    }

    /**
     * Generate chart data in real format: array of {timestamp, mark_price} objects.
     */
    public function generateChartDataRealFormat(float $basePrice): array
    {
        $now = now()->timestamp * 1000; // JavaScript timestamp (milliseconds)
        $ticks = [];

        // Generate 56 ticks (approximately 1 hour at ~1 minute intervals)
        for ($i = 0; $i < 56; $i++) {
            $timestamp = $now - ((55 - $i) * 60 * 1000); // 1 minute intervals, going backwards

            // Create realistic price movement
            $priceVariation = (random_int(-500, 500) / 100); // -5% to +5%
            $markPrice = round($basePrice * (1 + ($priceVariation / 100)), precision: 2);

            $ticks[] = [
                'timestamp' => $timestamp,
                'mark_price' => $markPrice,
            ];
        }

        return $ticks;
    }

    /**
     * Generate ladder data with all business logic computed server-side.
     * UI receives pre-calculated positions and just renders them.
     *
     * The ruler shows:
     * - P marker at profit_price
     * - Numbered markers (1,2,3,4) for PENDING limit orders only
     * - Current price indicator (amber line)
     *
     * Ruler range: from profit_price (left, 0%) to last_limit_price (right, 100%)
     *
     * For LONG positions: price dropping means limits get filled
     *   - Limit is filled when mark_price <= limit_price
     * For SHORT positions: price rising means limits get filled
     *   - Limit is filled when mark_price >= limit_price
     *
     * @return array{
     *     profit_price: float,
     *     profit_percent: float,
     *     mark_price: float,
     *     mark_percent: float,
     *     pending_limits: array<array{price: float, percent: float, number: int}>,
     *     limits_filled: int,
     *     limits_total: int,
     *     ruler_start: float,
     *     ruler_end: float
     * }
     */
    public function generateLadderData(float $basePrice, string $position): array
    {
        $isLong = $position === 'long';

        // Opening price (entry)
        $openingPrice = round($basePrice * (1 + (random_int(-10, 10) / 100)), precision: 2);

        // For LONG: limits are BELOW opening price (we buy lower on dips)
        // For SHORT: limits are ABOVE opening price (we sell higher on spikes)
        $limitDirection = $isLong ? -1 : 1;
        $step = $openingPrice * 0.0125; // 1.25% steps between limits

        // Generate 4 limit order prices
        $allLimits = [];
        for ($i = 1; $i <= 4; $i++) {
            $allLimits[] = round($openingPrice + ($limitDirection * $step * $i), precision: 2);
        }

        // Profit price is in the opposite direction from limits
        // For LONG: profit is ABOVE opening (sell higher)
        // For SHORT: profit is BELOW opening (buy back lower)
        $profitPrice = round($openingPrice * (1 + ($limitDirection * -0.02)), precision: 2); // 2% profit target

        // Mark price: random position that determines which limits are filled
        // Make it somewhere in the ladder range for interesting visualization
        $filledCount = random_int(0, 4);
        if ($filledCount === 0) {
            // Price hasn't reached any limits yet - between profit and first limit
            $markPrice = $isLong
                ? round($openingPrice - ($step * 0.3), precision: 2)  // Slightly below opening for long
                : round($openingPrice + ($step * 0.3), precision: 2); // Slightly above opening for short
        } else {
            // Price has passed some limits
            $markPrice = $allLimits[$filledCount - 1]; // At or past the filled limit
            // Add some noise
            $noise = $step * 0.2 * (random_int(-10, 10) / 10);
            $markPrice = round($markPrice + ($limitDirection * abs($noise)), precision: 2);
        }

        // Determine which limits are filled based on mark_price
        $pendingLimits = [];
        $actualFilledCount = 0;

        foreach ($allLimits as $index => $limitPrice) {
            $isFilled = $isLong
                ? $markPrice <= $limitPrice  // LONG: filled if price dropped to/below limit
                : $markPrice >= $limitPrice; // SHORT: filled if price rose to/above limit

            if ($isFilled) {
                $actualFilledCount++;
            } else {
                // Only include pending (unfilled) limits
                $pendingLimits[] = [
                    'price' => $limitPrice,
                    'number' => $index + 1,
                ];
            }
        }

        // Ruler range: always show profit at 100% (right side)
        // For LONG: low prices (limits) at 0%, high prices (profit) at 100%
        // For SHORT: high prices (limits) at 0%, low prices (profit) at 100% (inverted)
        $lastLimit = end($allLimits);
        $rulerStart = min($profitPrice, $lastLimit);
        $rulerEnd = max($profitPrice, $lastLimit);
        $rulerRange = $rulerEnd - $rulerStart;

        // Calculate percentage positions on the ruler (0-100%)
        // Profit is ALWAYS at 100%, deepest limit is ALWAYS at 0%
        $toPercent = function (float $price) use ($rulerStart, $rulerEnd, $rulerRange, $isLong): float {
            if ($rulerRange === 0) {
                return 50;
            }

            if ($isLong) {
                // LONG: higher price = higher percent (profit at top/right)
                return round((($price - $rulerStart) / $rulerRange) * 100, precision: 2);
            }

            // SHORT: lower price = higher percent (profit at top/right, limits at bottom/left)
            // Invert: as price goes DOWN towards profit, percent goes UP
            return round((($rulerEnd - $price) / $rulerRange) * 100, precision: 2);
        };

        // Add percentage to each pending limit
        foreach ($pendingLimits as &$limit) {
            $limit['percent'] = $toPercent($limit['price']);
        }
        unset($limit);

        return [
            'profit_price' => $profitPrice,
            'profit_percent' => $toPercent($profitPrice),
            'mark_price' => $markPrice,
            'mark_percent' => min(100, max(0, $toPercent($markPrice))), // Clamp to 0-100
            'pending_limits' => $pendingLimits,
            'limits_filled' => $actualFilledCount,
            'limits_total' => 4,
            'ruler_start' => $rulerStart,
            'ruler_end' => $rulerEnd,
        ];
    }
}
