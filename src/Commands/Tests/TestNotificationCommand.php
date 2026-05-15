<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Notification;
use Kraite\Core\Models\NotificationLog;
use Kraite\Core\Models\User;
use Kraite\Core\Support\NotificationService;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\BaseCommand;

final class TestNotificationCommand extends BaseCommand
{
    protected $signature = 'test:notification
                            {canonical : Notification canonical to test (server_rate_limit_exceeded, server_ip_forbidden, server_ip_not_whitelisted, server_ip_rate_limited, server_ip_banned, server_account_blocked, stale_dispatched_steps_detected, stale_priority_steps_detected, exchange_symbol_no_taapi_data, token_delisting, slow_query_detected, private_beta_email_verification, private_beta_welcome_password_reset, password_reset)}
                            {--times=1 : Number of times to send the notification (tests throttling)}
                            {--clean : Truncate notification_logs tables and clear laravel.log}
                            {--output : Display command output (silent by default)}
                            {--email= : Override recipient user (by email) for onboarding canonicals — private_beta_email_verification, private_beta_welcome_password_reset, password_reset}';

    protected $description = 'Test notification system - each canonical configured exactly as production';

    public function handle(): int
    {
        // Temporarily enable notifications for this command execution
        $wasEnabled = config('kraite.notifications_enabled', false);
        config(['kraite.notifications_enabled' => true]);

        if (! $wasEnabled) {
            $this->verboseWarn('⚡ Notifications temporarily ENABLED for this test');
        } else {
            $this->verboseInfo('✓ Notifications are ENABLED');
        }
        $this->verboseNewLine();

        // Handle --clean flag
        if ($this->option('clean')) {
            $this->verboseWarn('🗑️  Cleaning notification logs, cache, and Laravel log...');

            DB::table('notification_logs')->truncate();
            $this->verboseInfo('✓ Truncated notification_logs table');

            Cache::flush();
            $this->verboseInfo('✓ Flushed all cache (Redis)');

            $logPath = storage_path('logs/laravel.log');
            if (File::exists($logPath)) {
                File::put($logPath, '');
                $this->verboseInfo('✓ Cleared laravel.log');
            }

            $this->verboseNewLine();
        }

        // Get canonical
        $canonical = $this->argument('canonical');
        $times = max(1, (int) $this->option('times'));

        // Onboarding canonicals don't need an account context — they
        // target a specific user (by email or admin fallback) and only
        // carry URL data. Skip the account-existence check for those.
        $onboardingCanonicals = ['private_beta_email_verification', 'private_beta_welcome_password_reset', 'password_reset'];
        $needsAccount = ! in_array($canonical, $onboardingCanonicals, true);

        // Get hardcoded defaults
        $admin = Kraite::admin();
        $account = $needsAccount ? Account::with('user')->first() : null;
        if ($needsAccount && (! $account || ! $account->user)) {
            $this->verboseError('❌ No accounts with users found in database. Please seed accounts first.');

            return 1;
        }

        $accountUser = $account?->user;

        $this->verboseInfo("🔔 Testing Notification: {$canonical}");
        $this->verboseLine("Admin: {$admin->name} (#{$admin->id})");
        if ($account) {
            $this->verboseLine("Account: {$account->name} (#{$account->id})");
        }
        $this->verboseLine("Times: {$times}");
        $this->verboseNewLine();

        // Send notification based on canonical
        $result = match ($canonical) {
            'server_rate_limit_exceeded' => $this->testServerRateLimitExceeded($admin, $account, $times),
            'server_ip_forbidden' => $this->testServerIpForbidden($admin, $account, $times),
            'server_ip_not_whitelisted' => $this->testServerIpNotWhitelisted($accountUser, $account, $times),
            'server_ip_rate_limited' => $this->testServerIpRateLimited($admin, $account, $times),
            'server_ip_banned' => $this->testServerIpBanned($admin, $account, $times),
            'server_account_blocked' => $this->testServerAccountBlocked($accountUser, $account, $times),
            'stale_dispatched_steps_detected' => $this->testStaleDispatchedStepsDetected($admin, $times),
            'stale_priority_steps_detected' => $this->testStalePriorityStepsDetected($admin, $times),
            'exchange_symbol_no_taapi_data' => $this->testExchangeSymbolNoTaapiData($admin, $times),
            'token_delisting' => $this->testTokenDelisting($admin, $times),
            'slow_query_detected' => $this->testSlowQueryDetected($admin, $times),
            'private_beta_email_verification' => $this->testPrivateBetaEmailVerification($times),
            'private_beta_welcome_password_reset' => $this->testPrivateBetaWelcomePasswordReset($times),
            'password_reset' => $this->testPasswordReset($times),
            default => $this->verboseError("❌ Unknown canonical: {$canonical}"),
        };

        if ($result === false) {
            return 1;
        }

        $this->verboseNewLine();
        $this->verboseInfo('✅ Test completed successfully');

        return 0;
    }

    private function testServerRateLimitExceeded(User $admin, Account $account, int $times): bool
    {
        $apiSystem = $this->getApiSystem('binance');
        if (! $apiSystem) {
            return false;
        }

        $hostname = gethostname();
        $this->displayConfig("ApiSystem (Binance #{$apiSystem->id})", "{$apiSystem->canonical},account:{$account->id},server:{$hostname}");

        $accountUser = $account->user;
        $userName = $accountUser->name ?? 'Unknown';

        return $this->sendNotificationWithThrottleCheck(
            canonical: 'server_rate_limit_exceeded',
            times: $times,
            admin: $admin,
            referenceData: [
                'apiSystem' => $apiSystem,
                'http_code' => 429,
                'vendor_code' => null,
                'path' => '/fapi/v1/premiumIndex',
                'account_info' => "{$userName} ({$account->name})",
            ],
            relatable: $apiSystem,
            cacheKeys: ['api_system' => $apiSystem->canonical, 'account' => $account->id, 'server' => $hostname]
        );
    }

    private function testServerIpForbidden(User $admin, Account $account, int $times): bool
    {
        $apiSystem = $this->getApiSystem('binance');
        if (! $apiSystem) {
            return false;
        }

        $hostname = gethostname();
        $this->displayConfig("ApiSystem (Binance #{$apiSystem->id})", "{$apiSystem->canonical},server:{$hostname}");

        $accountUser = $account->user;
        $userName = $accountUser->name ?? 'Unknown';

        return $this->sendNotificationWithThrottleCheck(
            canonical: 'server_ip_forbidden',
            times: $times,
            admin: $admin,
            referenceData: [
                'apiSystem' => $apiSystem,
                'ip' => '127.0.0.1',
                'server' => $hostname,
                'http_code' => 418,
                'vendor_code' => null,
                'path' => '/fapi/v1/premiumIndex',
                'account_info' => "{$userName} ({$account->name})",
            ],
            relatable: $apiSystem,
            cacheKeys: ['api_system' => $apiSystem->canonical, 'server' => $hostname]
        );
    }

    /**
     * Test IP not whitelisted notification (sent to USER).
     * Triggered when user forgot to whitelist the server IP on their API key.
     */
    private function testServerIpNotWhitelisted(User $user, Account $account, int $times): bool
    {
        $apiSystem = $this->getApiSystem('binance');
        if (! $apiSystem) {
            return false;
        }

        $hostname = gethostname();
        $ipAddress = Kraite::ip();

        $this->verboseWarn("📤 Sending to USER: {$user->name} (#{$user->id})");
        $this->displayConfig("Account #{$account->id}", "account_id:{$account->id},ip_address:{$ipAddress}");

        return $this->sendNotificationWithThrottleCheck(
            canonical: 'server_ip_not_whitelisted',
            times: $times,
            admin: $user,
            referenceData: [
                'type' => 'ip_not_whitelisted',
                'exchange' => $apiSystem->canonical,
                'ip_address' => $ipAddress,
                'server' => $hostname,
                'account_id' => $account->id,
                'forbidden_until' => null,
                'error_code' => '-2015',
                'error_message' => 'Invalid API-key, IP, or permissions for action.',
            ],
            relatable: $apiSystem,
            cacheKeys: ['account_id' => $account->id, 'ip_address' => $ipAddress]
        );
    }

    /**
     * Test IP rate limited notification (sent to ADMIN).
     * Triggered when server IP is temporarily rate-limited by exchange.
     */
    private function testServerIpRateLimited(User $admin, Account $account, int $times): bool
    {
        $apiSystem = $this->getApiSystem('bybit');
        if (! $apiSystem) {
            return false;
        }

        $hostname = gethostname();
        $ipAddress = Kraite::ip();

        $this->verboseWarn("📤 Sending to ADMIN: {$admin->name} (#{$admin->id})");
        $this->displayConfig("ApiSystem (Bybit #{$apiSystem->id})", "api_system:{$apiSystem->canonical},ip_address:{$ipAddress}");

        return $this->sendNotificationWithThrottleCheck(
            canonical: 'server_ip_rate_limited',
            times: $times,
            admin: $admin,
            referenceData: [
                'type' => 'ip_rate_limited',
                'exchange' => $apiSystem->canonical,
                'ip_address' => $ipAddress,
                'server' => $hostname,
                'account_id' => null,
                'forbidden_until' => now()->addMinutes(10)->toIso8601String(),
                'error_code' => '10018',
                'error_message' => 'IP has been banned due to rate limiting.',
            ],
            relatable: $apiSystem,
            cacheKeys: ['api_system' => $apiSystem->canonical, 'ip_address' => $ipAddress]
        );
    }

    /**
     * Test IP permanently banned notification (sent to ADMIN).
     * Triggered when server IP is permanently banned by exchange.
     */
    private function testServerIpBanned(User $admin, Account $account, int $times): bool
    {
        $apiSystem = $this->getApiSystem('binance');
        if (! $apiSystem) {
            return false;
        }

        $hostname = gethostname();
        $ipAddress = Kraite::ip();

        $this->verboseWarn("📤 Sending to ADMIN: {$admin->name} (#{$admin->id})");
        $this->displayConfig("ApiSystem (Binance #{$apiSystem->id})", "api_system:{$apiSystem->canonical},ip_address:{$ipAddress}");

        return $this->sendNotificationWithThrottleCheck(
            canonical: 'server_ip_banned',
            times: $times,
            admin: $admin,
            referenceData: [
                'type' => 'ip_banned',
                'exchange' => $apiSystem->canonical,
                'ip_address' => $ipAddress,
                'server' => $hostname,
                'account_id' => null,
                'forbidden_until' => null,
                'error_code' => '418',
                'error_message' => 'I\'m a teapot - IP has been auto-banned for repeated rate limit violations.',
            ],
            relatable: $apiSystem,
            cacheKeys: ['api_system' => $apiSystem->canonical, 'ip_address' => $ipAddress]
        );
    }

    /**
     * Test account blocked notification (sent to USER).
     * Triggered when account's API key is revoked, disabled, or has permission issues.
     */
    private function testServerAccountBlocked(User $user, Account $account, int $times): bool
    {
        $apiSystem = $this->getApiSystem('bybit');
        if (! $apiSystem) {
            return false;
        }

        $hostname = gethostname();
        $ipAddress = Kraite::ip();

        $this->verboseWarn("📤 Sending to USER: {$user->name} (#{$user->id})");
        $this->displayConfig("Account #{$account->id}", "account_id:{$account->id},api_system:{$apiSystem->canonical}");

        return $this->sendNotificationWithThrottleCheck(
            canonical: 'server_account_blocked',
            times: $times,
            admin: $user,
            referenceData: [
                'type' => 'account_blocked',
                'exchange' => $apiSystem->canonical,
                'ip_address' => $ipAddress,
                'server' => $hostname,
                'account_id' => $account->id,
                'forbidden_until' => null,
                'error_code' => '10003',
                'error_message' => 'API key is invalid or has been revoked.',
            ],
            relatable: $apiSystem,
            cacheKeys: ['account_id' => $account->id, 'api_system' => $apiSystem->canonical]
        );
    }

    private function testStaleDispatchedStepsDetected(User $admin, int $times): true
    {
        // Get a step to use as relatable (optional - uses null if no steps exist)
        $step = Step::first();

        $this->displayConfig($step ? "Step #{$step->id}" : 'Global (no relatable)', 'none (global throttling)', 600);

        // Create mock stale step data
        $mockParameters = [
            'symbol_id' => 1,
            'quote_id' => 2,
            'exchange_id' => 1,
        ];

        return $this->sendNotificationWithThrottleCheck(
            canonical: 'stale_dispatched_steps_detected',
            times: $times,
            admin: $admin,
            referenceData: [
                'count' => 49,
                'oldest_step_id' => $step->id ?? 999,
                'oldest_canonical' => $step->class ?? 'FetchAndStoreTickersCommand',
                'oldest_group' => $step->group ?? 'monitoring',
                'oldest_index' => $step->index ?? 1,
                'oldest_minutes_stuck' => 12,
                'oldest_dispatched_at' => now()->subMinutes(12)->toDateTimeString(),
                'oldest_parameters' => json_encode($mockParameters, JSON_PRETTY_PRINT),
                'server' => gethostname(),
                'promoted_count' => 49,
            ],
            relatable: $step,
            duration: 60,
            cacheKeys: null // Global throttling
        );
    }

    /**
     * Test CRITICAL stale priority steps notification.
     * This is for when steps are STILL stuck after being promoted to priority queue.
     */
    private function testStalePriorityStepsDetected(User $admin, int $times): true
    {
        // Get a step to use as relatable (optional - uses null if no steps exist)
        $step = Step::first();

        $this->displayConfig($step ? "Step #{$step->id}" : 'Global (no relatable)', 'none (global throttling)', 60);

        // Create mock stale step data - these are steps that were ALREADY promoted but still stuck
        $mockParameters = [
            'symbol_id' => 1,
            'quote_id' => 2,
            'exchange_id' => 1,
        ];

        return $this->sendNotificationWithThrottleCheck(
            canonical: 'stale_priority_steps_detected',
            times: $times,
            admin: $admin,
            referenceData: [
                'count' => 12,
                'oldest_step_id' => $step->id ?? 999,
                'oldest_canonical' => $step->class ?? 'FetchAndStoreTickersCommand',
                'oldest_group' => $step->group ?? 'monitoring',
                'oldest_index' => $step->index ?? 1,
                'oldest_minutes_stuck' => 18,
                'oldest_dispatched_at' => now()->subMinutes(18)->toDateTimeString(),
                'oldest_parameters' => json_encode($mockParameters, JSON_PRETTY_PRINT),
                'server' => gethostname(),
            ],
            relatable: $step,
            duration: 60,
            cacheKeys: null // Global throttling
        );
    }

    private function testExchangeSymbolNoTaapiData(User $admin, int $times): bool
    {
        $exchangeSymbol = ExchangeSymbol::with('apiSystem')->first();
        if (! $exchangeSymbol) {
            $this->verboseError('❌ No exchange symbols found');

            return false;
        }

        $this->displayConfig("ExchangeSymbol #{$exchangeSymbol->id}", "exchange_symbol:{$exchangeSymbol->id},exchange:{$exchangeSymbol->apiSystem->canonical}");

        return $this->sendNotificationWithThrottleCheck(
            canonical: 'exchange_symbol_no_taapi_data',
            times: $times,
            admin: $admin,
            referenceData: ['exchangeSymbol' => $exchangeSymbol, 'failure_count' => 42],
            relatable: $exchangeSymbol,
            cacheKeys: ['exchange_symbol' => $exchangeSymbol->id, 'exchange' => $exchangeSymbol->apiSystem->canonical]
        );
    }

    private function testTokenDelisting(User $admin, int $times): bool
    {
        $exchangeSymbol = ExchangeSymbol::with('apiSystem')->first();
        if (! $exchangeSymbol) {
            $this->verboseError('❌ No exchange symbols found');

            return false;
        }

        $this->displayConfig("ExchangeSymbol #{$exchangeSymbol->id}", "exchange_symbol:{$exchangeSymbol->id}");

        return $this->sendNotificationWithThrottleCheck(
            canonical: 'token_delisting',
            times: $times,
            admin: $admin,
            referenceData: [
                'apiSystem' => $exchangeSymbol->apiSystem,
                'exchangeSymbol' => $exchangeSymbol,
                'pair_text' => $exchangeSymbol->parsed_trading_pair ?? 'BTC/USDT',
                'delivery_date' => '31 Dec 2025 08:00',
                'positions_count' => 2,
                'positions_details' => "• Position #1 (LONG)\n  Account: Test Account\n  User: Test User\n\n• Position #2 (SHORT)\n  Account: Demo Account\n  User: Demo User\n\n",
            ],
            relatable: $exchangeSymbol,
            cacheKeys: ['exchange_symbol' => $exchangeSymbol->id]
        );
    }

    private function testSlowQueryDetected(User $admin, int $times): true
    {
        $this->displayConfig('Global (no relatable)', 'none (global throttling)', 300);

        // Sample slow query with realistic SQL
        $sampleQuery = 'SELECT s.*, es.parsed_trading_pair, es.min_notional, a.name as account_name, u.email as user_email '.
            'FROM steps s '.
            "INNER JOIN exchange_symbols es ON s.relatable_id = es.id AND s.relatable_type = 'Kraite\\\\Core\\\\Models\\\\ExchangeSymbol' ".
            'LEFT JOIN accounts a ON es.account_id = a.id '.
            'LEFT JOIN users u ON a.user_id = u.id '.
            "WHERE s.state = 'Kraite\\\\Core\\\\States\\\\Running' ".
            "AND s.started_at < '2025-11-23 10:00:00' ".
            'ORDER BY s.started_at ASC '.
            'LIMIT 100';

        return $this->sendNotificationWithThrottleCheck(
            canonical: 'slow_query_detected',
            times: $times,
            admin: $admin,
            referenceData: [
                'sql_full' => $sampleQuery,
                'time_ms' => 3750,
                'connection' => 'mysql',
                'threshold_ms' => 2500,
            ],
            relatable: null, // Global notification - no relatable
            duration: null, // Uses cache_duration from notifications table (300s)
            cacheKeys: null // Global throttling - no cache keys
        );
    }

    private function getApiSystem(string $canonical): ?ApiSystem
    {
        $apiSystem = ApiSystem::where('canonical', $canonical)->first();
        if (! $apiSystem) {
            $this->verboseError("❌ {$canonical} API system not found");
        }

        return $apiSystem;
    }

    private function displayConfig(string $relatable, string $cacheKey, ?int $duration = null): void
    {
        $this->verboseLine('Configuration:');
        $this->verboseLine("  Relatable: {$relatable}");
        if ($duration) {
            $this->verboseLine("  Duration: {$duration} seconds");
        }
        $this->verboseLine("  CacheKey: {$cacheKey}");
        $this->verboseNewLine();
    }

    /**
     * Send notification with throttle checking (matches NotificationService logic).
     *
     * @param  array<string, mixed>  $referenceData
     * @param  array<string, mixed>|null  $cacheKeys
     */
    private function sendNotificationWithThrottleCheck(
        string $canonical,
        int $times,
        User $admin,
        array $referenceData,
        ?object $relatable = null,
        ?int $duration = null,
        ?array $cacheKeys = null
    ): true {
        $sentCount = 0;
        $throttledCount = 0;

        for ($i = 1; $i <= $times; $i++) {
            $this->verboseLine("Attempt #{$i}:");

            $isThrottled = false;

            // Load notification for cache key template
            $notification = Notification::where('canonical', $canonical)->first();

            // Build cache key if data provided
            $builtCacheKey = null;
            if ($cacheKeys && $notification && $notification->cache_key) {
                $parts = [];
                foreach ($notification->cache_key as $key) {
                    $value = $cacheKeys[$key] ?? 'missing';
                    $valueString = is_scalar($value) ? (string) $value : 'missing';
                    $parts[] = "{$key}:{$valueString}";
                }
                $builtCacheKey = "{$canonical}-".implode(separator: ',', array: $parts);
            }

            // Check throttling based on mode (cache vs database)
            if ($builtCacheKey) {
                // Cache-based throttling
                $isThrottled = Cache::has($builtCacheKey);

                if ($isThrottled) {
                    $this->verboseLine("  ⏸  Throttled (cache-based: key '{$builtCacheKey}' exists)");
                    $throttledCount++;
                } else {
                    $this->verboseLine("  ✓  Sending (cache-based: key '{$builtCacheKey}' not found)...");
                }
            } else {
                // Database-based throttling (default) - check NotificationLog
                $throttleRelatable = $relatable ?? $admin;
                $throttleDuration = $duration ?? 60;

                /** @var User|Model $throttleRelatable */
                $throttleRelatableKey = $throttleRelatable->getKey();
                $throttleRelatableId = is_numeric($throttleRelatableKey) ? (int) $throttleRelatableKey : 0;
                $throttleRelatableClass = get_class($throttleRelatable);

                $isThrottled = NotificationLog::query()
                    ->where('canonical', $canonical)
                    ->where('relatable_type', $throttleRelatableClass)
                    ->where('relatable_id', $throttleRelatableId)
                    ->where('created_at', '>', now()->subSeconds($throttleDuration))
                    ->exists();

                if ($isThrottled) {
                    $this->verboseLine("  ⏸  Throttled (database-based: recent log exists for {$throttleRelatableClass} #{$throttleRelatableId})");
                    $throttledCount++;
                } else {
                    $this->verboseLine("  ✓  Sending (database-based: no recent log for {$throttleRelatableClass} #{$throttleRelatableId})...");
                }
            }

            // Send notification if not throttled
            if (! $isThrottled) {
                NotificationService::send(
                    user: $admin,
                    canonical: $canonical,
                    referenceData: array_merge($referenceData, ['attempt' => $i]),
                    relatable: $relatable,
                    duration: $duration,
                    cacheKeys: $cacheKeys
                );

                $sentCount++;
                $this->verboseLine('  → Notification dispatched');
            }

            // Small delay between attempts
            if ($i < $times) {
                usleep(100000); // 0.1 second
            }
        }

        $this->verboseNewLine();
        $this->verboseInfo('📊 Results:');
        $this->verboseLine("  ✓ Sent: {$sentCount}");
        $this->verboseLine("  ⏸ Throttled: {$throttledCount}");

        return true;
    }

    /**
     * Resolve the recipient user for onboarding canonicals.
     *  - --email=foo@bar wins
     *  - else first non-admin user found
     *  - else admin user (fallback)
     */
    private function resolveOnboardingRecipient(): ?User
    {
        $email = $this->option('email');
        if (is_string($email) && $email !== '') {
            $user = User::where('email', $email)->first();
            if (! $user) {
                $this->verboseError("❌ No user found with email '{$email}'.");

                return null;
            }

            return $user;
        }

        $user = User::where('is_admin', false)->orderBy('id')->first()
            ?? User::orderBy('id')->first();

        if (! $user) {
            $this->verboseError('❌ No users in database. Seed at least one or pass --email=');

            return null;
        }

        return $user;
    }

    private function testPrivateBetaEmailVerification(int $times): bool
    {
        $user = $this->resolveOnboardingRecipient();
        if (! $user) {
            return false;
        }

        $this->verboseWarn("📤 Sending to USER: {$user->name} <{$user->email}> (#{$user->id})");
        $this->verboseNewLine();

        for ($i = 1; $i <= $times; $i++) {
            $this->verboseLine("Attempt #{$i}:");
            $verificationUrl = url('/private-beta/verify/TEST-'.bin2hex(random_bytes(8)));
            $ok = NotificationService::send(
                user: $user,
                canonical: 'private_beta_email_verification',
                referenceData: ['verification_url' => $verificationUrl],
                channels: ['mail'],
            );
            $this->verboseLine($ok ? '  ✓ Dispatched via mail channel' : '  ✗ Send returned false (check logs)');
            if ($i < $times) {
                usleep(100000);
            }
        }

        return true;
    }

    private function testPrivateBetaWelcomePasswordReset(int $times): bool
    {
        $user = $this->resolveOnboardingRecipient();
        if (! $user) {
            return false;
        }

        $this->verboseWarn("📤 Sending to USER: {$user->name} <{$user->email}> (#{$user->id})");
        $this->verboseNewLine();

        $expireMinutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
        $base = rtrim((string) config('kraite.admin_url'), '/');

        for ($i = 1; $i <= $times; $i++) {
            $this->verboseLine("Attempt #{$i}:");
            $resetUrl = $base.'/reset-password/TEST-'.bin2hex(random_bytes(8)).'?email='.urlencode($user->email);
            $ok = NotificationService::send(
                user: $user,
                canonical: 'private_beta_welcome_password_reset',
                referenceData: ['reset_url' => $resetUrl, 'expire_minutes' => $expireMinutes],
                channels: ['mail'],
            );
            $this->verboseLine($ok ? '  ✓ Dispatched via mail channel' : '  ✗ Send returned false (check logs)');
            if ($i < $times) {
                usleep(100000);
            }
        }

        return true;
    }

    private function testPasswordReset(int $times): bool
    {
        $user = $this->resolveOnboardingRecipient();
        if (! $user) {
            return false;
        }

        $this->verboseWarn("📤 Sending to USER: {$user->name} <{$user->email}> (#{$user->id})");
        $this->verboseNewLine();

        $expireMinutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
        $base = rtrim((string) config('kraite.admin_url'), '/');

        for ($i = 1; $i <= $times; $i++) {
            $this->verboseLine("Attempt #{$i}:");
            $resetUrl = $base.'/reset-password/TEST-'.bin2hex(random_bytes(8)).'?email='.urlencode($user->email);
            $ok = NotificationService::send(
                user: $user,
                canonical: 'password_reset',
                referenceData: ['reset_url' => $resetUrl, 'expire_minutes' => $expireMinutes],
                channels: ['mail'],
            );
            $this->verboseLine($ok ? '  ✓ Dispatched via mail channel' : '  ✗ Send returned false (check logs)');
            if ($i < $times) {
                usleep(100000);
            }
        }

        return true;
    }
}
