<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Account;

use InvalidArgumentException;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ValueObjects\ApiResponse;

final class InspectRegistrationAccountStep extends BaseApiableJob
{
    public const MODE_ACTIVITY = 'activity';

    public const MODE_BALANCES = 'balances';

    public Account $account;

    public ApiSystem $apiSystem;

    /** @var array<int, string> */
    public array $balanceQuotes;

    /**
     * @param  array<int, string>  $balanceQuotes
     */
    public function __construct(
        int $accountId,
        public string $mode,
        array $balanceQuotes = [],
    ) {
        $this->account = Account::findOrFail($accountId);
        $this->apiSystem = $this->account->apiSystem;
        $this->balanceQuotes = array_values(array_unique($balanceQuotes));
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make($this->apiSystem->canonical)
            ->withAccount($this->account);
    }

    public function relatable(): Account
    {
        return $this->account;
    }

    /** @return array<string, mixed> */
    public function computeApiable(): array
    {
        return match ($this->mode) {
            self::MODE_BALANCES => [
                'account_id' => $this->account->id,
                'mode' => self::MODE_BALANCES,
                'assets' => $this->balanceAssets(),
            ],
            self::MODE_ACTIVITY => [
                'account_id' => $this->account->id,
                'mode' => self::MODE_ACTIVITY,
                'has_own_positions' => collect($this->account->apiQueryPositions()->result ?? [])->isNotEmpty(),
                'has_own_orders' => collect($this->account->apiQueryOpenOrders()->result ?? [])->isNotEmpty(),
            ],
            default => throw new InvalidArgumentException("Unsupported registration inspection mode: {$this->mode}"),
        };
    }

    /**
     * @return array<string, array{balance: string, available: string}>
     */
    private function balanceAssets(): array
    {
        if ($this->apiSystem->canonical === 'binance') {
            return $this->binanceBalanceAssets($this->account->apiQueryBalance());
        }

        $assets = [];

        foreach (array_intersect($this->balanceQuotes, ['USDT', 'USDC']) as $quote) {
            $quotedAccount = clone $this->account;
            $quotedAccount->forceFill([
                'portfolio_quote' => $quote,
                'trading_quote' => $quote,
            ]);
            $response = $quotedAccount->apiQueryBalance();
            $assets[$quote] = [
                'balance' => (string) ($response->result['total-wallet-balance'] ?? '0'),
                'available' => (string) ($response->result['available-balance'] ?? '0'),
            ];
        }

        return $assets;
    }

    /**
     * @return array<string, array{balance: string, available: string}>
     */
    private function binanceBalanceAssets(ApiResponse $response): array
    {
        if ($response->response === null) {
            return [];
        }

        $decoded = json_decode((string) $response->response->getBody(), associative: true);

        if (! is_array($decoded)) {
            return [];
        }

        $assets = [];

        foreach ($decoded as $row) {
            if (! is_array($row) || ! isset($row['asset'])) {
                continue;
            }

            $asset = (string) $row['asset'];

            if (! in_array($asset, $this->balanceQuotes, true)) {
                continue;
            }

            $assets[$asset] = [
                'balance' => (string) ($row['balance'] ?? '0'),
                'available' => (string) ($row['availableBalance'] ?? '0'),
            ];
        }

        return $assets;
    }
}
