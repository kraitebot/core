<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\ExchangeSymbol;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\NotificationService;
use Kraite\Core\Trading\Exchange\Exchange;
use SensitiveParameter;

/**
 * DiscoverCMCTokenForExchangeSymbolJob
 *
 * Attempts to find and link a CMC symbol to an exchange symbol that has no symbol_id.
 *
 * Strategy (ordered by preference):
 * 1. Direct match in symbols table
 * 2. Strip numeric prefix (1000000BABYDOGE → BABYDOGE)
 * 3. Strip 1M/1K/1B notation (1MBABYDOGE → BABYDOGE)
 * 4. Strip trailing numbers (SHIB1000 → SHIB)
 * 5. Strip common suffixes (BTCPERP → BTC)
 * 6. Strip W prefix for wrapped tokens (WBTC → BTC)
 * 7. Strip st prefix for staked tokens (stETH → ETH)
 * 8. Strip .P suffix for perpetual (BTC.P → BTC)
 * 9. Use hardcoded aliases (XBT → BTC)
 * 10. Query CMC API for original token
 * 11. Query CMC API for cleaned token
 *
 * If a match is found, links the exchange symbol to the existing symbol (by cmc_id)
 * or creates a new symbol record if the cmc_id doesn't exist yet.
 */
final class DiscoverCMCTokenForExchangeSymbolJob extends BaseApiableJob
{
    /**
     * Known token aliases that cannot be discovered automatically.
     * Maps exchange ticker → canonical CMC ticker.
     */
    private const ALIASES = [
        'XBT' => 'BTC',      // ISO 4217 compliant Bitcoin ticker
        'XETH' => 'ETH',     // Some exchanges use X-prefix
        'XDG' => 'DOGE',     // Dogecoin alias
        'XBTC' => 'BTC',     // Bitcoin alias
        'XXBT' => 'BTC',     // Extended X-prefix format
        'XLTC' => 'LTC',     // Extended X-prefix format
        'XXRP' => 'XRP',     // Extended X-prefix format
        'XXLM' => 'XLM',     // Extended X-prefix format
        'XZEC' => 'ZEC',     // Extended X-prefix format
        'XXMR' => 'XMR',     // Extended X-prefix format
    ];

    /**
     * A vanished listing older than this is not auto-linked to a new
     * arrival — months-apart pairs get a "suspected rename" alert and a
     * human decision instead.
     */
    private const RENAME_DETECTION_WINDOW_DAYS = 3;

    public ExchangeSymbol $exchangeSymbol;

    public function __construct(int $exchangeSymbolId)
    {
        $this->exchangeSymbol = ExchangeSymbol::findOrFail($exchangeSymbolId);
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function assignExceptionHandler()
    {
        $this->exceptionHandler = BaseExceptionHandler::make('coinmarketcap')
            ->withAccount(Account::admin('coinmarketcap'));
    }

    public function startOrSkip()
    {
        // Skip if already has a symbol_id linked (may have been linked by parallel job)
        // But first mark it as CMC verified since it already has a symbol.
        // Returns false from startOrSkip (not startOrFail) so the step transitions to
        // Skipped rather than Failed — the parallel-linked case is a benign no-op.
        if ($this->exchangeSymbol->symbol_id !== null) {
            $this->markAsCmcVerified();

            return false;
        }

        return true;
    }

    public function computeApiable()
    {
        $token = $this->exchangeSymbol->token;
        $debug = [];

        $debug['original_token'] = $token;
        $debug['candidates'] = $this->generateTokenCandidates($token);

        // Phase 1: Try all DB lookups first (no API calls)
        $symbol = $this->tryDatabaseLookups($token, $debug);

        if ($symbol) {
            $debug['phase'] = 'database_lookup';
            $debug['matched_symbol'] = $symbol->token;

            // The observer will set cmc_api_called=true when symbol_id is updated
            return $this->linkToSymbol($symbol, 'database_lookup', $debug);
        }

        $debug['database_result'] = 'no_match';

        // Phase 2: Query CMC API as fallback
        // Mark that we're about to call the CMC API (so we don't retry on next refresh)
        $this->markAsCmcVerified();

        $symbol = $this->tryCmcApiLookup($token, $debug);

        if ($symbol) {
            $debug['phase'] = 'cmc_api';
            $debug['matched_symbol'] = $symbol->token;

            return $this->linkToSymbol($symbol, 'cmc_api', $debug);
        }

        $debug['cmc_api_result'] = 'no_match';

        // No match found anywhere
        $exchange = $this->exchangeSymbol->apiSystem->canonical ?? 'unknown';

        return [
            'status' => 'not_found',
            'token' => $token,
            'exchange' => $exchange,
            'message' => "No CMC match found for {$token} ({$exchange})",
            'tried' => [
                'database_candidates' => count($debug['db_attempts'] ?? []),
                'api_candidates' => count($debug['cmc_attempts'] ?? []),
            ],
            'candidates_tried' => $debug['candidates'] ?? [],
        ];
    }

    /**
     * Try all database lookup strategies to find a matching symbol.
     */
    private function tryDatabaseLookups(#[SensitiveParameter] string $token, array &$debug): ?Symbol
    {
        $candidates = $this->generateTokenCandidates($token);
        $debug['db_attempts'] = [];

        foreach ($candidates as $candidate) {
            $symbol = Symbol::where('token', $candidate)->first();
            $debug['db_attempts'][] = [
                'candidate' => $candidate,
                'found' => $symbol !== null,
            ];

            if ($symbol) {
                return $symbol;
            }
        }

        return null;
    }

    /**
     * Generate all possible token candidates from the original token.
     * Order matters - most specific first.
     */
    private function generateTokenCandidates(#[SensitiveParameter] string $token): array
    {
        $candidates = [];
        $upperToken = mb_strtoupper($token);

        // 1. Direct match
        $candidates[] = $upperToken;

        // 2. Check hardcoded aliases first
        if (isset(self::ALIASES[$upperToken])) {
            $candidates[] = self::ALIASES[$upperToken];
        }

        // 3. Strip leading numeric characters (1000000BABYDOGE → BABYDOGE)
        $stripped = preg_replace('/^[0-9]+/', '', $upperToken);
        if ($stripped !== $upperToken && $stripped !== '') {
            $candidates[] = $stripped;
        }

        // 4. Strip 1M prefix (1MBABYDOGE → BABYDOGE)
        if (preg_match('/^1M(.+)$/i', $upperToken, matches: $matches)) {
            $candidates[] = mb_strtoupper($matches[1]);
        }

        // 5. Strip 1K prefix (1KSHIB → SHIB)
        if (preg_match('/^1K(.+)$/i', $upperToken, matches: $matches)) {
            $candidates[] = mb_strtoupper($matches[1]);
        }

        // 6. Strip 1B prefix (1BCAT → CAT)
        if (preg_match('/^1B(.+)$/i', $upperToken, matches: $matches)) {
            $candidates[] = mb_strtoupper($matches[1]);
        }

        // 7. Strip trailing numbers (SHIB1000 → SHIB)
        $strippedTrailing = preg_replace('/[0-9]+$/', '', $upperToken);
        if ($strippedTrailing !== $upperToken && $strippedTrailing !== '') {
            $candidates[] = $strippedTrailing;
        }

        // 8. Strip 1000 anywhere (1000PEPE → PEPE)
        $stripped1000 = str_replace(search: '1000', replace: '', subject: $upperToken);
        if ($stripped1000 !== $upperToken && $stripped1000 !== '') {
            $candidates[] = $stripped1000;
        }

        // 9. Strip common suffixes (BTCPERP → BTC, BTCUSD → BTC)
        $suffixes = ['PERP', 'USD', 'USDT', 'USDC', 'BUSD', 'PERPETUAL'];
        foreach ($suffixes as $suffix) {
            if (! (str_ends_with(haystack: $upperToken, needle: $suffix))) {
                continue;
            }

            $stripped = mb_substr($upperToken, 0, length: -mb_strlen($suffix));
            if ($stripped !== '') {
                $candidates[] = $stripped;
            }
        }

        // 10. Strip W prefix for wrapped tokens (WBTC → BTC)
        if (preg_match('/^W(.+)$/i', $upperToken, matches: $matches) && mb_strlen($matches[1]) >= 2) {
            $candidates[] = mb_strtoupper($matches[1]);
        }

        // 11. Strip st prefix for staked tokens (stETH → ETH)
        if (preg_match('/^ST(.+)$/i', $upperToken, matches: $matches) && mb_strlen($matches[1]) >= 2) {
            $candidates[] = mb_strtoupper($matches[1]);
        }

        // 12. Strip .P suffix for perpetual (BTC.P → BTC)
        if (str_ends_with(haystack: $upperToken, needle: '.P')) {
            $candidates[] = mb_substr($upperToken, 0, length: -2);
        }

        // 13. Try partial match - extract longest alphabetic sequence
        if (preg_match('/([A-Z]{3,})/', $upperToken, matches: $matches)) {
            if ($matches[1] !== $upperToken) {
                $candidates[] = $matches[1];
            }
        }

        // Remove duplicates while preserving order
        return array_values(array_unique($candidates));
    }

    /**
     * Query CMC API to find a matching symbol.
     * Only called if all DB lookups fail.
     */
    private function tryCmcApiLookup(#[SensitiveParameter] string $token, array &$debug): ?Symbol
    {
        $candidates = $this->generateTokenCandidates($token);
        $debug['cmc_attempts'] = [];

        // Try original token first, then cleaned versions
        foreach ($candidates as $candidate) {
            $result = $this->queryCmcForToken($candidate);
            $debug['cmc_attempts'][] = [
                'candidate' => $candidate,
                'found' => $result['symbol'] !== null,
                'api_response' => $result['api_data'] ?? null,
            ];

            if ($result['symbol']) {
                return $result['symbol'];
            }
        }

        return null;
    }

    /**
     * Query CMC API for a specific token.
     * Returns array with 'symbol' and 'api_data' for debugging.
     */
    private function queryCmcForToken(#[SensitiveParameter] string $token): array
    {
        $mapper = Exchange::forCanonical('coinmarketcap')->mapper();
        $properties = $mapper->prepareSearchSymbolByTokenProperties($token);
        $properties->set('relatable', $this->exchangeSymbol);

        // Use the admin CMC account to make the API call
        $cmcAccount = Account::admin('coinmarketcap');

        try {
            $response = $cmcAccount->withApi()->getSymbols($properties);
            $result = $mapper->resolveSearchSymbolByTokenResponse($response);
        } catch (RequestException $e) {
            // CMC returns 400 for invalid/unknown symbols - this is expected
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;

            return [
                'symbol' => null,
                'api_data' => [
                    'error' => 'api_error',
                    'status_code' => $statusCode,
                    'message' => $statusCode === 400
                        ? "CMC does not recognize symbol: {$token}"
                        : $e->getMessage(),
                ],
            ];
        }

        // Check if we got valid results
        if (! isset($result['data']) || empty($result['data'])) {
            return ['symbol' => null, 'api_data' => 'no_data'];
        }

        // Find the best match - first one with a valid rank
        foreach ($result['data'] as $cmcData) {
            $cmcId = $cmcData['id'] ?? null;
            $rank = $cmcData['rank'] ?? null;

            // Skip if no cmc_id or no ranking (likely a shitcoin)
            if (! $cmcId || ! $rank) {
                continue;
            }

            // Check if we already have a symbol with this cmc_id
            $existingSymbol = Symbol::where('cmc_id', $cmcId)->first();

            if ($existingSymbol) {
                // The CMC id is the coin's identity; the ticker is only a
                // label. When CMC reports a different current ticker than
                // our catalogue holds (TON → GRAM rebrand), refresh the
                // label in place — this also disarms the recycled-ticker
                // trap, since the abandoned name stops matching anything
                // in future name lookups.
                $this->refreshCatalogueLabel($existingSymbol, $cmcData);

                return [
                    'symbol' => $existingSymbol,
                    'api_data' => [
                        'cmc_id' => $cmcId,
                        'token' => $cmcData['symbol'] ?? $token,
                        'name' => $cmcData['name'] ?? null,
                        'rank' => $rank,
                        'source' => 'existing_symbol',
                    ],
                ];
            }

            // Create new symbol with CMC data
            $newSymbol = Symbol::create([
                'cmc_id' => $cmcId,
                'token' => $cmcData['symbol'] ?? $token,
                'name' => $cmcData['name'] ?? null,
                'cmc_ranking' => $rank,
            ]);

            return [
                'symbol' => $newSymbol,
                'api_data' => [
                    'cmc_id' => $cmcId,
                    'token' => $cmcData['symbol'] ?? $token,
                    'name' => $cmcData['name'] ?? null,
                    'rank' => $rank,
                    'source' => 'created_new',
                ],
            ];
        }

        return ['symbol' => null, 'api_data' => 'no_ranked_match'];
    }

    /**
     * Link the exchange symbol to the found symbol.
     */
    private function linkToSymbol(Symbol $symbol, string $source, array $debug = []): array
    {
        $this->exchangeSymbol->update(['symbol_id' => $symbol->id]);

        $renameOutcome = $this->handlePossibleRename($symbol);

        $exchange = $this->exchangeSymbol->apiSystem->canonical ?? 'unknown';
        $originalToken = $this->exchangeSymbol->token;
        $matchedVia = $debug['matched_symbol'] ?? $symbol->token;

        // Build a human-readable message
        $message = $originalToken === $matchedVia
            ? "Linked {$originalToken} → {$symbol->token} (direct match)"
            : "Linked {$originalToken} → {$symbol->token} (via {$matchedVia})";

        return [
            'status' => 'linked',
            'token' => $originalToken,
            'exchange' => $exchange,
            'matched_to' => $symbol->token,
            'matched_via' => $matchedVia,
            'source' => $source,
            'cmc_id' => $symbol->cmc_id,
            'rename' => $renameOutcome,
            'message' => $message,
        ];
    }

    /**
     * Keep the catalogue ticker/name current with CMC's record for the
     * same coin identity (cmc_id). No-op when the label already matches.
     */
    private function refreshCatalogueLabel(Symbol $symbol, array $cmcData): void
    {
        $currentTicker = mb_strtoupper(mb_trim((string) ($cmcData['symbol'] ?? '')));

        if ($currentTicker === '' || $currentTicker === $symbol->token) {
            return;
        }

        $symbol->updateSaving([
            'token' => $currentTicker,
            'name' => $cmcData['name'] ?? $symbol->name,
        ]);
    }

    /**
     * Exchange-rename detection (TON → GRAM). Fires at the identity-link
     * moment — the earliest point where the freshly created listing and
     * the vanished one can be proven to be the same coin.
     *
     * Shape of a rename: a sibling row on the SAME exchange and quote,
     * linked to the SAME coin identity, currently flagged missing from
     * the exchange feed, and not yet retired. Recency decides handling:
     *
     *  - Flagged missing recently (within RENAME_DETECTION_WINDOW_DAYS,
     *    proven from the append-only audit trail, not updated_at — the
     *    pipelines keep touching rows and would fake freshness):
     *    auto-process. Retire the old row (one-way `renamed` block),
     *    thread the ancestry, alert the admin. Open positions on the
     *    retired row are counted into the alert and left for a human —
     *    never auto-migrated.
     *
     *  - Flagged missing long ago (or no audit record): a months-apart
     *    disappear/appear pair can be a real delisting plus an unrelated
     *    relisting, so nothing is blocked or linked — the admin gets a
     *    "suspected rename" alert and decides.
     */
    private function handlePossibleRename(Symbol $symbol): ?array
    {
        $retired = ExchangeSymbol::query()
            ->where('symbol_id', $symbol->id)
            ->where('api_system_id', $this->exchangeSymbol->api_system_id)
            ->where('quote', $this->exchangeSymbol->quote)
            ->where('id', '!=', $this->exchangeSymbol->id)
            ->where('token', '!=', $this->exchangeSymbol->token)
            ->where('is_marked_for_delisting', true)
            ->whereNull('system_disabled_at')
            ->orderByDesc('id')
            ->first();

        if (! $retired instanceof ExchangeSymbol) {
            return null;
        }

        $flaggedMissingAt = DB::table('model_logs')
            ->where('loggable_type', ExchangeSymbol::class)
            ->where('loggable_id', $retired->id)
            ->where('attribute_name', 'is_marked_for_delisting')
            ->where('new_value', '1')
            ->orderByDesc('id')
            ->value('created_at');

        $openPositions = Position::query()
            ->where('exchange_symbol_id', $retired->id)
            ->whereIn('status', (new Position)->openedStatuses())
            ->count();

        $exchange = $this->exchangeSymbol->apiSystem->canonical ?? 'unknown';

        $referenceData = [
            'old_token' => $retired->token,
            'new_token' => $this->exchangeSymbol->token,
            'quote' => $this->exchangeSymbol->quote,
            'exchange' => $exchange,
            'coin_name' => $symbol->name,
            'cmc_id' => $symbol->cmc_id,
            'retired_exchange_symbol_id' => $retired->id,
            'successor_exchange_symbol_id' => $this->exchangeSymbol->id,
            'open_positions' => $openPositions,
        ];

        $isRecent = $flaggedMissingAt !== null
            && Carbon::parse((string) $flaggedMissingAt)->greaterThanOrEqualTo(
                now()->subDays(self::RENAME_DETECTION_WINDOW_DAYS)
            );

        if (! $isRecent) {
            NotificationService::send(
                user: Kraite::admin(),
                canonical: 'exchange_symbol_rename_suspected',
                referenceData: $referenceData,
                relatable: $this->exchangeSymbol,
                cacheKeys: ['exchange_symbol' => $retired->id],
            );

            return ['status' => 'suspected_only', 'retired_id' => $retired->id];
        }

        $retired->applySystemBlock(ExchangeSymbol::SYSTEM_BLOCK_RENAMED);
        $this->exchangeSymbol->updateSaving([
            'renamed_from_exchange_symbol_id' => $retired->id,
        ]);

        NotificationService::send(
            user: Kraite::admin(),
            canonical: 'exchange_symbol_renamed',
            referenceData: $referenceData,
            relatable: $this->exchangeSymbol,
            cacheKeys: ['exchange_symbol' => $retired->id],
        );

        return ['status' => 'renamed', 'retired_id' => $retired->id, 'open_positions' => $openPositions];
    }

    /**
     * Mark the exchange symbol as CMC verified in api_statuses.
     * This is set when: symbol_id already exists, found via DB lookup, or after CMC API call.
     */
    private function markAsCmcVerified(): void
    {
        $apiStatuses = $this->exchangeSymbol->api_statuses ?? [];
        $apiStatuses['cmc_api_called'] = true;
        $this->exchangeSymbol->updateSaving(['api_statuses' => $apiStatuses]);
    }
}
