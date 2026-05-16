<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountBalanceQuery
{
    public function prepareGetBalanceProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        return $properties;
    }

    /**
     * Returns structured balance data for the account's portfolio quote.
     *
     * Response format:
     * [
     *     'wallet-balance' => '3997.21',
     *     'available-balance' => '2000.00',
     *     'cross-wallet-balance' => '3997.21',
     *     'cross-unrealized-pnl' => '0.00',
     * ]
     */
    public function resolveGetBalanceResponse(Response $response, Account $account): array
    {
        $assets = json_decode((string) $response->getBody(), associative: true);
        $balanceQuote = $account->balanceQuote();

        $quoteBalance = collect($assets)
            ->first(static function ($item) use ($balanceQuote) {
                return ($item['asset'] ?? null) === $balanceQuote;
            });

        if ($quoteBalance === null) {
            return $this->emptyAccountBalance();
        }

        return [
            'total-wallet-balance' => $quoteBalance['balance'] ?? '0',
            'wallet-balance' => $quoteBalance['balance'] ?? '0',
            'available-balance' => $quoteBalance['availableBalance'] ?? '0',
            'cross-wallet-balance' => $quoteBalance['crossWalletBalance'] ?? '0',
            'cross-unrealized-pnl' => $quoteBalance['crossUnPnl'] ?? '0',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function emptyAccountBalance(): array
    {
        return [
            'total-wallet-balance' => '0',
            'wallet-balance' => '0',
            'available-balance' => '0',
            'cross-wallet-balance' => '0',
            'cross-unrealized-pnl' => '0',
        ];
    }
}
