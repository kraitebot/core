<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountBalanceQuery
{
    public function prepareGetBalanceProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);
        $properties->set('options.accountType', 'UNIFIED');

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
     *
     * Bybit V5 response structure:
     * { result: { list: [{ totalWalletBalance, coin: [{ coin: "USDT", walletBalance, ... }] }] } }
     */
    public function resolveGetBalanceResponse(Response $response, Account $account): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $balanceQuote = $account->balanceQuote();

        if (! isset($data['result']['list'][0])) {
            return $this->emptyAccountBalance();
        }

        $accountData = $data['result']['list'][0];
        $coins = $accountData['coin'] ?? [];

        $quoteBalance = collect($coins)
            ->first(static function ($item) use ($balanceQuote) {
                return ($item['coin'] ?? null) === $balanceQuote;
            });

        if ($quoteBalance === null) {
            return $this->emptyAccountBalance();
        }

        // Calculate available = wallet - locked (availableToWithdraw is deprecated)
        $walletBalance = $quoteBalance['walletBalance'] ?? '0';
        $locked = $quoteBalance['locked'] ?? '0';
        $availableBalance = bcsub($walletBalance, $locked, scale: 8);

        return [
            'total-wallet-balance' => $walletBalance,
            'wallet-balance' => $walletBalance,
            'available-balance' => $availableBalance,
            'cross-wallet-balance' => $walletBalance,
            'cross-unrealized-pnl' => $quoteBalance['unrealisedPnl'] ?? '0',
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
