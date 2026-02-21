<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Debug\Accounts;

use Kraite\Core\Models\Account;
use StepDispatcher\Support\BaseCommand;
use Throwable;

final class QueryAccountCommand extends BaseCommand
{
    protected $signature = 'debug:query-account
                            {--account-id= : The account ID to query}
                            {--raw : Show raw API response before filtering}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Query account positions from the exchange API and display the result';

    public function handle(): int
    {
        $accountId = $this->option('account-id');

        if (! $accountId) {
            $this->verboseError('Please provide --account-id');

            return self::FAILURE;
        }

        $account = Account::find((int) $accountId);

        if (! $account) {
            $this->verboseError("Account #{$accountId} not found");

            return self::FAILURE;
        }

        $this->verboseInfo("Querying positions for Account #{$account->id} ({$account->name})");
        $this->verboseInfo("Exchange: {$account->apiSystem->canonical}");
        $this->verboseNewLine();

        try {
            $apiResponse = $account->apiQueryPositions();

            if ($this->option('raw')) {
                $this->verboseInfo('Raw API Response (before filtering):');
                $this->verboseNewLine();
                $this->verboseLine((string) json_encode(json_decode((string) $apiResponse->response->getBody(), associative: true), JSON_PRETTY_PRINT));
                $this->verboseNewLine();
            }

            $this->verboseInfo('Mapped Result (after filtering):');
            $this->verboseNewLine();

            if (empty($apiResponse->result)) {
                $this->verboseComment('No open positions found');
            } else {
                /** @var array<string, array<string, mixed>> $positionsResult */
                $positionsResult = $apiResponse->result;

                /** @var array<int, array<string, string>> $positions */
                $positions = collect($positionsResult)->map(function (array $position, string $symbol): array {
                    return [
                        'symbol' => $symbol,
                        'size' => $this->extractPositionValue($position, 'positionAmt', 'size'),
                        'side' => $this->extractPositionValue($position, 'positionSide', 'side'),
                        'entry_price' => $this->extractPositionValue($position, 'entryPrice'),
                        'unrealized_pnl' => $this->extractPositionValue($position, 'unRealizedProfit', 'unrealisedPnl'),
                        'leverage' => $this->extractPositionValue($position, 'leverage'),
                    ];
                })->values()->toArray();

                $this->verboseTable(
                    ['Symbol', 'Size', 'Side', 'Entry Price', 'Unrealized PnL', 'Leverage'],
                    $positions
                );

                $this->verboseNewLine();
                $this->verboseInfo('Raw response:');
                $this->verboseLine((string) json_encode($apiResponse->result, JSON_PRETTY_PRINT));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->verboseError("API Error: {$e->getMessage()}");
            $this->verboseNewLine();
            $this->verboseLine("File: {$e->getFile()}:{$e->getLine()}");

            return self::FAILURE;
        }
    }

    /**
     * Extract a position value from an array with type-safe conversion.
     *
     * @param  array<string, mixed>  $position
     */
    private function extractPositionValue(array $position, string $key, ?string $fallbackKey = null): string
    {
        $value = $position[$key] ?? ($fallbackKey !== null ? ($position[$fallbackKey] ?? null) : null);

        if ($value === null) {
            return 'N/A';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return 'N/A';
    }
}
