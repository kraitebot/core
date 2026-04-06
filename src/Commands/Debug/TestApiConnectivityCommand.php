<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Debug;

use Illuminate\Console\Command;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\Proxies\ApiRESTProxy;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Throwable;

/**
 * Test API connectivity for an account or admin API system.
 * Uses a signed endpoint (account balance) to verify credentials work.
 */
final class TestApiConnectivityCommand extends Command
{
    protected $signature = 'debug:test-api-connectivity
                            {--account= : Account ID to test}
                            {--admin : Test admin credentials from Engine model}
                            {--canonical= : API system canonical (required with --admin)}';

    protected $description = 'Test API connectivity using signed endpoints to verify credentials';

    public function handle(): int
    {
        $accountId = $this->option('account');
        $isAdmin = $this->option('admin');
        $canonical = $this->option('canonical');

        // Validate options
        if ($accountId && $isAdmin) {
            $this->error('Cannot use both --account and --admin options together.');

            return self::FAILURE;
        }

        if (! $accountId && ! $isAdmin) {
            $this->error('Must specify either --account=ID or --admin with --canonical=EXCHANGE.');

            return self::FAILURE;
        }

        if ($isAdmin && ! $canonical) {
            $this->error('The --canonical option is required when using --admin.');

            return self::FAILURE;
        }

        if ($accountId) {
            return $this->testAccountConnectivity((int) $accountId);
        }

        // $canonical is guaranteed to be non-null here due to the check above (lines 47-51)
        return $this->testAdminConnectivity((string) $canonical);
    }

    /**
     * Test connectivity for a specific account.
     */
    private function testAccountConnectivity(int $accountId): int
    {
        $account = Account::with('apiSystem')->find($accountId);

        if (! $account) {
            $this->error("Account with ID {$accountId} not found.");

            return self::FAILURE;
        }

        $canonical = $account->apiSystem->canonical;

        $this->info("Testing connectivity for Account #{$accountId}: {$account->name}");
        $this->info("Exchange: {$canonical}");
        $this->newLine();

        $this->displayCredentialInfo($account->all_credentials, $canonical);

        return $this->executeConnectivityTest(
            $canonical,
            $account->all_credentials,
            "Account #{$accountId}"
        );
    }

    /**
     * Test connectivity for admin credentials.
     */
    private function testAdminConnectivity(string $canonical): int
    {
        $apiSystem = ApiSystem::where('canonical', $canonical)->first();

        if (! $apiSystem) {
            $this->error("API system '{$canonical}' not found.");

            return self::FAILURE;
        }

        if (! $apiSystem->is_exchange) {
            $this->error("API system '{$canonical}' is not an exchange.");

            return self::FAILURE;
        }

        $engine = Kraite::first();

        if (! $engine) {
            $this->error('Engine configuration not found.');

            return self::FAILURE;
        }

        $this->info("Testing admin connectivity for: {$canonical}");
        $this->newLine();

        // Build credentials array based on canonical
        $credentials = $this->buildAdminCredentials($engine, $canonical);

        $this->displayCredentialInfo($credentials, $canonical);

        return $this->executeConnectivityTest($canonical, $credentials, 'Admin');
    }

    /**
     * Build credentials array for admin based on exchange canonical.
     *
     * @return array<string, string|null>
     */
    private function buildAdminCredentials(Kraite $engine, string $canonical): array
    {
        return match ($canonical) {
            'binance' => [
                'binance_api_key' => $engine->binance_api_key,
                'binance_api_secret' => $engine->binance_api_secret,
            ],
            'bybit' => [
                'bybit_api_key' => $engine->bybit_api_key,
                'bybit_api_secret' => $engine->bybit_api_secret,
            ],
            'kucoin' => [
                'kucoin_api_key' => $engine->kucoin_api_key,
                'kucoin_api_secret' => $engine->kucoin_api_secret,
                'kucoin_passphrase' => $engine->kucoin_passphrase,
            ],
            'bitget' => [
                'bitget_api_key' => $engine->bitget_api_key,
                'bitget_api_secret' => $engine->bitget_api_secret,
                'bitget_passphrase' => $engine->bitget_passphrase,
            ],
            default => [],
        };
    }

    /**
     * Display credential information (masked).
     *
     * @param  array<string, string|null>  $credentials
     */
    private function displayCredentialInfo(array $credentials, string $canonical): void
    {
        $this->info('Credentials:');

        $relevantKeys = $this->getRelevantCredentialKeys($canonical);

        foreach ($relevantKeys as $key) {
            $value = $credentials[$key] ?? null;

            if ($value === null || $value === '') {
                $this->line("  {$key}: <fg=red>NOT SET</>");
            } else {
                $masked = $this->maskValue($value);
                $length = mb_strlen($value);
                $this->line("  {$key}: <fg=green>{$masked}</> ({$length} chars)");
            }
        }

        $this->newLine();
    }

    /**
     * Get relevant credential keys for an exchange.
     *
     * @return list<string>
     */
    private function getRelevantCredentialKeys(string $canonical): array
    {
        return match ($canonical) {
            'binance' => ['binance_api_key', 'binance_api_secret'],
            'bybit' => ['bybit_api_key', 'bybit_api_secret'],
            'kucoin' => ['kucoin_api_key', 'kucoin_api_secret', 'kucoin_passphrase'],
            'bitget' => ['bitget_api_key', 'bitget_api_secret', 'bitget_passphrase'],
            default => [],
        };
    }

    /**
     * Mask a credential value for display.
     */
    private function maskValue(string $value): string
    {
        $length = mb_strlen($value);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return mb_substr($value, 0, 4).str_repeat('*', $length - 8).mb_substr($value, -4);
    }

    /**
     * Execute the connectivity test.
     *
     * @param  array<string, string|null>  $credentials
     */
    private function executeConnectivityTest(string $canonical, array $credentials, string $context): int
    {
        $this->info('Testing signed API endpoint (account balance)...');

        try {
            $proxy = new ApiRESTProxy($canonical, new ApiCredentials($credentials));

            // Use getAccountBalance as the test - it's a signed endpoint
            // @phpstan-ignore method.notFound (magic __call method in ApiRESTProxy)
            $response = $proxy->getAccountBalance();

            /** @var \Psr\Http\Message\ResponseInterface $response */
            $statusCode = $response->getStatusCode();
            /** @var array<string, mixed>|null $body */
            $body = json_decode((string) $response->getBody(), associative: true);

            $this->newLine();

            if ($statusCode === 200 && $this->isSuccessResponse($body, $canonical)) {
                $this->info("✓ {$context} connectivity test PASSED");
                $this->line("  Status: {$statusCode}");
                $this->displaySuccessDetails($body, $canonical);

                return self::SUCCESS;
            }

            $this->error("✗ {$context} connectivity test FAILED");
            $this->line("  Status: {$statusCode}");
            $this->displayErrorDetails($body, $canonical);

            return self::FAILURE;

        } catch (Throwable $e) {
            $this->newLine();
            $this->error("✗ {$context} connectivity test FAILED");
            $this->newLine();

            // Parse the error message
            $message = $e->getMessage();
            $this->line("  <fg=red>Error:</> {$message}");

            // Try to extract API error details
            if (preg_match('/response:\s*({.+})/s', $message, matches: $matches)) {
                /** @var array<string, mixed>|null $errorBody */
                $errorBody = json_decode($matches[1], associative: true);
                if (is_array($errorBody)) {
                    $this->newLine();
                    $this->displayErrorDetails($errorBody, $canonical);
                }
            }

            return self::FAILURE;
        }
    }

    /**
     * Check if the response indicates success based on exchange format.
     * Note: Response body here is the RAW API response, not the mapped result.
     *
     * @param  array<int|string, mixed>|null  $body
     */
    private function isSuccessResponse(?array $body, string $canonical): bool
    {
        if ($body === null) {
            return false;
        }

        return match ($canonical) {
            // Binance returns an array of assets on success (numeric keys)
            'binance' => $this->isBinanceSuccessResponse($body),
            'bybit' => ((int) ($body['retCode'] ?? -1)) === 0, // @phpstan-ignore cast.int
            'kucoin' => ((string) ($body['code'] ?? '')) === '200000', // @phpstan-ignore cast.string
            'bitget' => ((string) ($body['code'] ?? '')) === '00000', // @phpstan-ignore cast.string
            default => true,
        };
    }

    /**
     * Check if Binance response indicates success.
     *
     * @param  array<int|string, mixed>  $body
     */
    private function isBinanceSuccessResponse(array $body): bool
    {
        // Check for array of assets with numeric keys
        if (isset($body[0]) && is_array($body[0]) && isset($body[0]['asset'])) {
            return true;
        }

        return isset($body['totalWalletBalance']) || isset($body['assets']);
    }

    /**
     * Display success details based on exchange format.
     *
     * @param  array<int|string, mixed>|null  $body
     */
    private function displaySuccessDetails(?array $body, string $canonical): void
    {
        if ($body === null) {
            return;
        }

        $this->newLine();

        match ($canonical) {
            'binance' => $this->displayBinanceSuccess($body),
            'bybit' => $this->displayBybitSuccess($body),
            'bitget' => $this->displayBitgetSuccess($body),
            'kucoin' => $this->displayKucoinSuccess($body),
            default => $this->line('  Response: '.json_encode($body, JSON_PRETTY_PRINT)),
        };
    }

    /** @param  array<int|string, mixed>  $body */
    private function displayBinanceSuccess(array $body): void
    {
        // Binance returns array of assets - find USDT
        if (isset($body[0]) && is_array($body[0]) && isset($body[0]['asset'])) {
            foreach ($body as $asset) {
                if (! is_array($asset)) {
                    continue;
                }
                $assetName = (string) ($asset['asset'] ?? ''); // @phpstan-ignore cast.string
                $balance = (float) ($asset['balance'] ?? 0); // @phpstan-ignore cast.double
                if ($assetName === 'USDT' && $balance > 0) {
                    $this->line('  USDT Balance: '.(string) ($asset['balance'] ?? '0')); // @phpstan-ignore cast.string
                    $this->line('  USDT Available: '.(string) ($asset['availableBalance'] ?? '0')); // @phpstan-ignore cast.string

                    return;
                }
            }
            $this->line('  Assets found: '.count($body));

            return;
        }
        if (isset($body['totalWalletBalance'])) {
            $this->line('  Total Wallet Balance: '.(string) $body['totalWalletBalance']); // @phpstan-ignore cast.string
        }
        if (isset($body['availableBalance'])) {
            $this->line('  Available Balance: '.(string) $body['availableBalance']); // @phpstan-ignore cast.string
        }
    }

    /** @param  array<int|string, mixed>  $body */
    private function displayBybitSuccess(array $body): void
    {
        $result = $body['result'] ?? [];
        if (! is_array($result)) {
            return;
        }
        $list = $result['list'] ?? [];
        if (! is_array($list) || ! isset($list[0]) || ! is_array($list[0])) {
            return;
        }
        if (isset($list[0]['totalEquity'])) {
            $this->line('  Total Equity: '.(string) $list[0]['totalEquity']); // @phpstan-ignore cast.string
        }
    }

    /** @param  array<int|string, mixed>  $body */
    private function displayBitgetSuccess(array $body): void
    {
        $data = $body['data'] ?? [];
        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            $account = $data[0];
            if (isset($account['usdtEquity'])) {
                $this->line('  USDT Equity: '.(string) $account['usdtEquity']); // @phpstan-ignore cast.string
            }
            if (isset($account['available'])) {
                $this->line('  Available: '.(string) $account['available']); // @phpstan-ignore cast.string
            }
        }
    }

    /** @param  array<int|string, mixed>  $body */
    private function displayKucoinSuccess(array $body): void
    {
        $data = $body['data'] ?? [];
        if (is_array($data) && isset($data['accountEquity'])) {
            $this->line('  Account Equity: '.(string) $data['accountEquity']); // @phpstan-ignore cast.string
        }
    }

    /** @param  array<int|string, mixed>|null  $body */
    private function displayErrorDetails(?array $body, string $canonical): void
    {
        if ($body === null) {
            return;
        }
        $this->line('  <fg=yellow>API Response:</>');
        match ($canonical) {
            'binance' => $this->displayBinanceError($body),
            'bybit' => $this->displayBybitError($body),
            'bitget' => $this->displayBitgetError($body),
            'kucoin' => $this->displayKucoinError($body),
            default => $this->line('  '.json_encode($body)),
        };
    }

    /** @param  array<int|string, mixed>  $body */
    private function displayBinanceError(array $body): void
    {
        $this->line('    Code: '.(string) ($body['code'] ?? 'N/A')); // @phpstan-ignore cast.string
        $this->line('    Message: '.(string) ($body['msg'] ?? 'Unknown error')); // @phpstan-ignore cast.string
    }

    /** @param  array<int|string, mixed>  $body */
    private function displayBybitError(array $body): void
    {
        $this->line('    Code: '.(string) ($body['retCode'] ?? 'N/A')); // @phpstan-ignore cast.string
        $this->line('    Message: '.(string) ($body['retMsg'] ?? 'Unknown error')); // @phpstan-ignore cast.string
    }

    /** @param  array<int|string, mixed>  $body */
    private function displayBitgetError(array $body): void
    {
        $code = (string) ($body['code'] ?? 'N/A'); // @phpstan-ignore cast.string
        $this->line("    Code: {$code}");
        $this->line('    Message: '.(string) ($body['msg'] ?? 'Unknown error')); // @phpstan-ignore cast.string
        $hints = match ($code) {
            '40009' => 'Signature error - check API secret is correct',
            '40014' => 'Invalid API key - verify key exists on BitGet',
            '40018' => 'Invalid passphrase - verify passphrase matches',
            '40037' => 'API key does not exist - key may be deleted or wrong',
            default => null,
        };
        if ($hints) {
            $this->line("    <fg=cyan>Hint: {$hints}</>");
        }
    }

    /** @param  array<int|string, mixed>  $body */
    private function displayKucoinError(array $body): void
    {
        $this->line('    Code: '.(string) ($body['code'] ?? 'N/A')); // @phpstan-ignore cast.string
        $this->line('    Message: '.(string) ($body['msg'] ?? 'Unknown error')); // @phpstan-ignore cast.string
    }
}
