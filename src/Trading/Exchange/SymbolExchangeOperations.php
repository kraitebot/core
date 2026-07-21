<?php

declare(strict_types=1);

namespace Kraite\Core\Trading\Exchange;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiResponse;

final class SymbolExchangeOperations
{
    /**
     * Broad categories to prioritize over granular ones.
     */
    private const BROAD_CATEGORIES = [
        'defi',
        'layer-1',
        'layer-2',
        'gaming',
        'stablecoin',
        'payments',
    ];

    public Account $apiAccount;

    private ApiProperties $apiProperties;

    private Response $apiResponse;

    public function __construct(private readonly Symbol $symbol) {}

    public function apiMapper(): ApiDataMapperProxy
    {
        return Exchange::forCanonical($this->apiAccount->apiSystem->canonical)->mapper();
    }

    public function apiSyncCMCData(): ApiResponse
    {
        $this->apiAccount = Account::admin('coinmarketcap');
        $this->apiProperties = $this->apiMapper()->prepareSyncMarketDataProperties($this->symbol);
        $this->apiProperties->set('account', $this->apiAccount);
        $this->apiResponse = $this->apiAccount->withApi()->getSymbolsMetadata($this->apiProperties);
        $result = json_decode((string) $this->apiResponse->getBody(), associative: true);

        // Sync symbol metadata
        $marketData = collect($result['data'])->first();

        if ($marketData) {
            // Detect if this is a stablecoin by checking the tags array
            $isStableCoin = in_array('stablecoin', $marketData['tags'] ?? [], strict: true);

            // Extract primary category from tags
            $cmcCategory = $this->extractPrimaryCategory(
                $marketData['tags'] ?? [],
                $marketData['tag-groups'] ?? []
            );

            $updateData = [
                'name' => $marketData['name'],
                'description' => $marketData['description'],
                'image_url' => $marketData['logo'],
                'site_url' => $this->sanitizeWebsiteAttribute($marketData['urls']['website']),
                'is_stable_coin' => $isStableCoin,
                'cmc_category' => $cmcCategory,
            ];

            // Only update token if not already set (preserve exchange-specific naming)
            if (! $this->symbol->token) {
                $updateData['token'] = $marketData['symbol'];
            }

            $this->symbol->updateSaving($updateData);
        }

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveSyncMarketDataResponse($this->apiResponse)
        );
    }

    private function sanitizeWebsiteAttribute(mixed $website): ?string
    {
        return is_array($website) ? collect($website)->first() : $website;
    }

    /**
     * Extract primary category from CMC tags.
     *
     * Priority:
     * 1. First INDUSTRY tag (e.g., "memes", "ai-big-data", "gaming")
     * 2. Broad CATEGORY tags (defi, layer-1, layer-2, gaming, stablecoin, payments)
     * 3. First non-excluded CATEGORY tag
     */
    private function extractPrimaryCategory(array $tags, array $tagGroups): ?string
    {
        // Priority 1: First INDUSTRY tag
        foreach ($tags as $index => $tag) {
            if (($tagGroups[$index] ?? null) !== 'INDUSTRY') {
                continue;
            }

            return $tag;
        }

        // Collect all valid CATEGORY tags (excluding portfolios, ecosystems, listings)
        $excludePatterns = ['-portfolio', '-ecosystem', 'binance-', 'ftx-', '-listing'];
        $categoryTags = [];

        foreach ($tags as $index => $tag) {
            if (($tagGroups[$index] ?? null) !== 'CATEGORY') {
                continue;
            }

            $isExcluded = false;

            foreach ($excludePatterns as $pattern) {
                if (! (str_contains(haystack: $tag, needle: $pattern))) {
                    continue;
                }

                $isExcluded = true;
                break;
            }

            if (! $isExcluded) {
                $categoryTags[] = $tag;
            }
        }

        // Priority 2: Return first broad category found
        foreach (self::BROAD_CATEGORIES as $broadCategory) {
            if (! (in_array($broadCategory, $categoryTags, strict: true))) {
                continue;
            }

            return $broadCategory;
        }

        // Priority 3: Return first non-excluded CATEGORY tag
        // Priority 4: Fallback to 'other' if no category found
        return $categoryTags[0] ?? 'other';
    }
}
