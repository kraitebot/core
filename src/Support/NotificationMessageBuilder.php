<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Kraite\Core\Enums\NotificationSeverity;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Notification;
use Kraite\Core\Models\User;
use Throwable;

/**
 * NotificationMessageBuilder
 *
 * Transforms notification message canonicals into user-friendly content
 * with appropriate severity levels, action items, and exchange-specific URLs.
 *
 * IMPORTANT: This class accepts base message canonicals (e.g., 'ip_not_whitelisted')
 * without exchange prefixes. Exchange-specific context is passed via the $context array.
 * This separation allows message templates to be reusable across different API systems.
 *
 * Canonicals are validated against the notifications database table for governance.
 */
final class NotificationMessageBuilder
{
    /**
     * Build a user-friendly notification message from a base message canonical.
     *
     * @param  string|Notification  $canonical  The base message canonical or Notification model instance
     * @param  array<string, mixed>  $context  Additional context data (exchange, ip, hostname, account, amount, etc.)
     * @param  User|null  $user  The user receiving the notification (for personalization)
     * @return array{severity: NotificationSeverity, title: string, emailMessage: string, pushoverMessage: string, actionUrl: string|null, actionLabel: string|null, priority?: int}
     *
     * The optional `priority` key (int in range -2..2) is piped straight
     * through to AlertNotification's additionalParameters and overrides
     * the default severity-based mapping. Useful when a canonical wants a
     * specific Pushover priority that doesn't match the severity bucket
     * (e.g. a trading-event notification whose severity is Info but which
     * should ping the device silently with priority = -1, or a WAP event
     * whose High severity should specifically get priority = 1 to bypass
     * quiet hours on the user's device).
     */
    public static function build(string|Notification $canonical, array $context = [], ?User $user = null): array
    {
        // Extract canonical string from model if provided
        $canonicalString = $canonical instanceof Notification ? $canonical->canonical : $canonical;

        // Validate canonical exists in database
        // Note: canonical validation against notifications table happens during development
        // Extract common context variables with type safety
        $userName = $user !== null ? $user->name : 'there';

        // Support both Eloquent models (new format) and raw values (legacy format)
        // New format: 'apiSystem' => $model, 'apiRequestLog' => $model, 'account' => $model
        // Legacy format: 'exchange' => 'binance', 'http_code' => 418, etc.
        $apiSystem = $context['apiSystem'] ?? null;
        $apiRequestLog = $context['apiRequestLog'] ?? null;
        $accountModel = $context['account'] ?? null;
        $contextUser = $context['user'] ?? null;

        // Extract exchange info from apiSystem model or legacy 'exchange' key
        $exchangeRaw = $apiSystem ?? ($context['exchange'] ?? 'exchange');
        $exchange = is_object($exchangeRaw) ? ($exchangeRaw->canonical ?? 'exchange') : (is_string($exchangeRaw) ? $exchangeRaw : 'exchange');
        $exchangeTitle = is_object($exchangeRaw) ? ($exchangeRaw->name ?? ucfirst($exchange)) : (is_string($exchangeRaw) ? ucfirst($exchangeRaw) : ucfirst($exchange));

        // Extract IP from Engine model or legacy 'ip' key
        $ipRaw = $context['ip'] ?? null;
        $ip = is_string($ipRaw) ? $ipRaw : Kraite::ip();

        // Extract hostname from 'server' key (new) or 'hostname' key (legacy) or apiRequestLog
        $hostnameRaw = $context['server'] ?? ($context['hostname'] ?? ($apiRequestLog->hostname ?? gethostname()));
        $hostname = is_string($hostnameRaw) ? $hostnameRaw : (string) gethostname();

        // Build account info string from account model or legacy 'account_info' key
        $accountInfoRaw = $context['account_info'] ?? null;
        if ($accountInfoRaw === null && $accountModel && $contextUser) {
            $accountInfo = "{$contextUser->name} ({$accountModel->name})";
        } elseif ($accountInfoRaw === null && $accountModel) {
            $accountInfo = $accountModel->name;
        } else {
            $accountInfo = is_string($accountInfoRaw) ? $accountInfoRaw : null;
        }

        $accountNameRaw = $context['account_name'] ?? ($accountModel->name ?? 'your account');
        $accountName = is_string($accountNameRaw) ? $accountNameRaw : 'your account';

        $walletBalanceRaw = $context['wallet_balance'] ?? 'N/A';
        $walletBalance = is_string($walletBalanceRaw) ? $walletBalanceRaw : 'N/A';

        $unrealizedPnlRaw = $context['unrealized_pnl'] ?? 'N/A';
        $unrealizedPnl = is_string($unrealizedPnlRaw) ? $unrealizedPnlRaw : 'N/A';

        $exceptionRaw = $context['exception'] ?? null;
        $exception = is_string($exceptionRaw) ? $exceptionRaw : null;

        // Extract HTTP code from apiRequestLog model or legacy 'http_code' key
        $httpCodeRaw = $apiRequestLog->http_response_code ?? ($context['http_code'] ?? null);
        $httpCode = is_int($httpCodeRaw) ? $httpCodeRaw : (is_string($httpCodeRaw) ? (int) $httpCodeRaw : null);

        // Extract vendor code from apiRequestLog response or legacy 'vendor_code' key
        $vendorCodeRaw = $context['vendor_code'] ?? null;
        if ($vendorCodeRaw === null && $apiRequestLog) {
            $response = $apiRequestLog->response;
            if (is_array($response)) {
                $vendorCodeRaw = $response['code'] ?? $response['retCode'] ?? null;
            }
        }
        $vendorCode = is_int($vendorCodeRaw) ? $vendorCodeRaw : (is_string($vendorCodeRaw) ? (int) $vendorCodeRaw : null);

        return match ($canonicalString) {

            'server_rate_limit_exceeded' => [
                'severity' => NotificationSeverity::Info,
                'title' => 'Rate Limit Exceeded',
                'emailMessage' => "{$exchangeTitle} API rate limit exceeded.\n\n".($accountInfo ? "Account: {$accountInfo}\n" : "Type: System-level API call\n")."Server: {$hostname}\n\nPlatform automatically implemented request throttling and exponential backoff. Pending operations queued for retry.\n\nResolution steps:\n\n1. Check recent API request patterns:\n[CMD]SELECT path, COUNT(*) as requests, AVG(duration) as avg_ms FROM api_request_logs WHERE api_system_id = (SELECT id FROM api_systems WHERE canonical = '{$exchange}') AND created_at > NOW() - INTERVAL 5 MINUTE GROUP BY path ORDER BY requests DESC LIMIT 10;[/CMD]\n\n2. Check recent rate limit errors:\n[CMD]SELECT created_at, path, http_response_code, hostname FROM api_request_logs WHERE api_system_id = (SELECT id FROM api_systems WHERE canonical = '{$exchange}') AND http_response_code = 429 AND created_at > NOW() - INTERVAL 1 HOUR ORDER BY created_at DESC LIMIT 10;[/CMD]",
                'pushoverMessage' => "{$exchangeTitle} rate limit exceeded - {$hostname}".($accountInfo ? " - {$accountInfo}" : ''),
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'server_ip_forbidden' => [
                'severity' => NotificationSeverity::Critical,
                'title' => 'Server IP Forbidden by Exchange',
                'emailMessage' => "🚨 CRITICAL: Server IP forbidden by {$exchangeTitle}\n\nServer IP: [COPY]{$ip}[/COPY]\nHostname: {$hostname}\nHTTP Code: {$httpCode}\n".($vendorCode ? "Vendor Code: {$vendorCode}\n" : '')."\n".($accountInfo ? "Last request from account: {$accountInfo}\n\n" : '')."The exchange has banned our server IP. This is typically caused by:\n• Repeated rate limit violations (HTTP 418 for Binance - auto-ban 2 min to 3 days)\n• Server/IP-level restrictions (HTTP 403 with specific vendor codes)\n\nIMPACT:\n• All API requests from this server to {$exchangeTitle} are blocked\n• Jobs automatically retry on other workers if available\n• Affects all accounts using this exchange on this worker\n\nRESOLUTION:\n\nFor HTTP 418 (temporary ban):\n• System will auto-retry after ban period expires\n• Review rate limiting patterns to prevent future bans\n\nFor HTTP 403 (permanent restrictions):\n• Contact exchange support with server IP and timestamp\n• May require IP whitelisting or rotation\n\nMonitor recent API errors:\n[CMD]SELECT created_at, http_response_code, path, response FROM api_request_logs WHERE api_system_id = (SELECT id FROM api_systems WHERE canonical = '{$exchange}') AND http_response_code >= 400 ORDER BY created_at DESC LIMIT 20;[/CMD]",
                'pushoverMessage' => "🚨 {$exchangeTitle} forbidden server {$hostname} ({$ip}) - HTTP {$httpCode}",
                'actionUrl' => null,
                'actionLabel' => null,
            ],

            'server_ip_rate_limited' => (static function () use ($context, $exchangeTitle, $ip, $hostname) {
                // Extract rate limit details
                $forbiddenUntilRaw = $context['forbidden_until'] ?? null;
                $errorCode = is_string($context['error_code'] ?? null) ? $context['error_code'] : 'N/A';
                $errorMessage = is_string($context['error_message'] ?? null) ? $context['error_message'] : 'N/A';

                // Parse forbidden_until for display
                $forbiddenUntilCarbon = null;
                if (is_string($forbiddenUntilRaw)) {
                    try {
                        $forbiddenUntilCarbon = Carbon::parse($forbiddenUntilRaw);
                    } catch (Exception $e) {
                        // Invalid date format, ignore
                    }
                }

                $forbiddenText = $forbiddenUntilCarbon
                    ? "Rate limit expires: {$forbiddenUntilCarbon->format('H:i:s')} ({$forbiddenUntilCarbon->diffForHumans()})"
                    : 'Rate limit duration: Unknown (typically 2-10 minutes)';

                $pushoverRecovery = $forbiddenUntilCarbon
                    ? "Resumes: {$forbiddenUntilCarbon->format('H:i:s')}"
                    : 'Resumes: ~2-10 min';

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => 'Server IP Temporarily Rate Limited',
                    'emailMessage' => "⚠️ Server IP Temporarily Rate Limited\n\n".
                        "The server IP has been temporarily rate-limited by {$exchangeTitle}.\n\n".
                        "📊 DETAILS:\n\n".
                        "• Server IP: [COPY]{$ip}[/COPY]\n".
                        "• Hostname: {$hostname}\n".
                        "• {$forbiddenText}\n".
                        "• Error Code: {$errorCode}\n".
                        "• Error Message: {$errorMessage}\n\n".
                        "✅ AUTOMATIC RECOVERY:\n\n".
                        "• The system will automatically pause requests to {$exchangeTitle}\n".
                        "• Requests will resume after the rate limit expires\n".
                        "• No manual intervention required in most cases\n\n".
                        "🔍 IF ISSUE PERSISTS:\n\n".
                        "• Check recent API request volume:\n".
                        "[CMD]SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') as minute, COUNT(*) as requests FROM api_request_logs WHERE created_at > NOW() - INTERVAL 30 MINUTE GROUP BY minute ORDER BY minute DESC;[/CMD]\n\n".
                        "• Review request patterns by endpoint:\n".
                        '[CMD]SELECT path, COUNT(*) as requests FROM api_request_logs WHERE created_at > NOW() - INTERVAL 10 MINUTE GROUP BY path ORDER BY requests DESC LIMIT 10;[/CMD]',
                    'pushoverMessage' => "{$exchangeTitle}: IP rate limited\nServer: {$hostname}\nError: {$errorCode} - {$errorMessage}\n{$pushoverRecovery}",
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'server_ip_banned' => (static function () use ($context, $exchangeTitle, $ip, $hostname) {
                // Extract ban details
                $errorCode = is_string($context['error_code'] ?? null) ? $context['error_code'] : 'N/A';
                $errorMessage = is_string($context['error_message'] ?? null) ? $context['error_message'] : 'N/A';
                $exchange = is_string($context['exchange'] ?? null) ? $context['exchange'] : 'exchange';

                return [
                    'severity' => NotificationSeverity::Critical,
                    'title' => 'Server IP Permanently Banned',
                    'emailMessage' => "🚨 CRITICAL: Server IP Permanently Banned\n\n".
                        "The server IP has been permanently banned by {$exchangeTitle}.\n\n".
                        "📊 DETAILS:\n\n".
                        "• Server IP: [COPY]{$ip}[/COPY]\n".
                        "• Hostname: {$hostname}\n".
                        "• Error Code: {$errorCode}\n".
                        "• Error Message: {$errorMessage}\n\n".
                        "⚠️ IMPACT:\n\n".
                        "• All API requests from this server to {$exchangeTitle} are BLOCKED\n".
                        "• This ban does NOT expire automatically\n".
                        "• Other workers (if available) will continue operating\n".
                        "• Manual intervention REQUIRED\n\n".
                        "🔧 RESOLUTION OPTIONS:\n\n".
                        "1. Contact {$exchangeTitle} Support:\n".
                        "   • Provide the banned IP address: {$ip}\n".
                        "   • Request IP unban or whitelist\n".
                        "   • Explain legitimate trading bot usage\n\n".
                        "2. Server IP Rotation:\n".
                        "   • Provision new server with different IP\n".
                        "   • Update infrastructure configuration\n".
                        "   • Migrate workloads to new server\n\n".
                        "3. Review Rate Limiting Patterns:\n".
                        "[CMD]SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00') as hour, COUNT(*) as requests, SUM(CASE WHEN http_response_code = 429 THEN 1 ELSE 0 END) as rate_limits FROM api_request_logs WHERE api_system_id = (SELECT id FROM api_systems WHERE canonical = '{$exchange}') AND created_at > NOW() - INTERVAL 24 HOUR GROUP BY hour ORDER BY hour DESC;[/CMD]",
                    'pushoverMessage' => "🚨 CRITICAL: {$exchangeTitle} BANNED\nIP: {$ip}\nServer: {$hostname}\nManual intervention required!",
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'stale_priority_steps_detected' => (static function () use ($context, $hostname) {
                // Extract stale step details - CRITICAL: steps stuck even after promotion
                $count = is_int($context['count'] ?? null) ? $context['count'] : 0;
                $oldestStepId = is_int($context['oldest_step_id'] ?? null) ? $context['oldest_step_id'] : 0;
                $oldestCanonical = is_string($context['oldest_canonical'] ?? null) ? $context['oldest_canonical'] : 'N/A';
                $oldestGroup = is_string($context['oldest_group'] ?? null) ? $context['oldest_group'] : 'N/A';
                $oldestIndex = is_int($context['oldest_index'] ?? null) ? $context['oldest_index'] : 0;
                $oldestMinutesStuck = is_int($context['oldest_minutes_stuck'] ?? null) ? $context['oldest_minutes_stuck'] : 0;
                $oldestDispatchedAt = is_string($context['oldest_dispatched_at'] ?? null) ? $context['oldest_dispatched_at'] : 'N/A';

                return [
                    'severity' => NotificationSeverity::Critical,
                    'title' => 'Priority Steps Still Stuck - Manual Action Required',
                    'emailMessage' => "🚨 CRITICAL: {$count} step(s) still stuck after promotion to priority queue!\n\n".
                        "Self-healing FAILED. These steps were promoted to high priority but are still not being processed.\n\n".
                        "Oldest stuck step:\n\n".
                        "• Step ID: {$oldestStepId}\n".
                        "• Class: {$oldestCanonical}\n".
                        "• Group: {$oldestGroup}\n".
                        "• Index: {$oldestIndex}\n".
                        "• Minutes Stuck: {$oldestMinutesStuck}\n".
                        "• Dispatched At: {$oldestDispatchedAt}\n".
                        "• Server: {$hostname}\n\n".
                        "MANUAL INTERVENTION REQUIRED:\n\n".
                        "• Check priority queue workers: supervisorctl status\n".
                        "• Check Redis connection\n".
                        "• Check circuit breaker (can_dispatch_steps)\n".
                        '• Consider resetting steps to Pending state',
                    'pushoverMessage' => "🚨 CRITICAL: {$count} step(s) STILL stuck after promotion!\n".
                        "Self-healing FAILED\n".
                        "Oldest: Step #{$oldestStepId}\n".
                        "Stuck: {$oldestMinutesStuck}m\n".
                        'MANUAL ACTION REQUIRED',
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'stale_dispatched_steps_detected' => (static function () use ($context, $hostname) {
                // Extract stale step details
                $count = is_int($context['count'] ?? null) ? $context['count'] : 0;
                $oldestStepId = is_int($context['oldest_step_id'] ?? null) ? $context['oldest_step_id'] : 0;
                $oldestCanonical = is_string($context['oldest_canonical'] ?? null) ? $context['oldest_canonical'] : 'N/A';
                $oldestGroup = is_string($context['oldest_group'] ?? null) ? $context['oldest_group'] : 'N/A';
                $oldestIndex = is_int($context['oldest_index'] ?? null) ? $context['oldest_index'] : 0;
                $oldestMinutesStuck = is_int($context['oldest_minutes_stuck'] ?? null) ? $context['oldest_minutes_stuck'] : 0;
                $oldestDispatchedAt = is_string($context['oldest_dispatched_at'] ?? null) ? $context['oldest_dispatched_at'] : 'N/A';
                $promotedCount = is_int($context['promoted_count'] ?? null) ? $context['promoted_count'] : 0;

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => 'Stale Dispatched Steps Detected',
                    'emailMessage' => "{$count} step(s) stuck in Dispatched state for over 5 minutes.\n\n".
                        "✅ SELF-HEALING APPLIED:\n\n".
                        "{$promotedCount} step(s) promoted to high priority queue.\n\n".
                        "Oldest stuck step:\n\n".
                        "• Step ID: {$oldestStepId}\n".
                        "• Class: {$oldestCanonical}\n".
                        "• Group: {$oldestGroup}\n".
                        "• Index: {$oldestIndex}\n".
                        "• Minutes Stuck: {$oldestMinutesStuck}\n".
                        "• Dispatched At: {$oldestDispatchedAt}\n".
                        "• Server: {$hostname}\n\n".
                        "If issue persists, check:\n\n".
                        "• Redis connection\n".
                        "• Priority queue workers (supervisorctl status)\n".
                        '• Circuit breaker (can_dispatch_steps)',
                    'pushoverMessage' => "{$count} step(s) stuck in Dispatched\n".
                        "Self-healing: {$promotedCount} promoted to priority queue\n".
                        "Oldest: Step #{$oldestStepId}\n".
                        "Stuck: {$oldestMinutesStuck}m",
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'group_no_progress_detected' => (static function () use ($context, $hostname) {
                $group = is_string($context['group'] ?? null) ? $context['group'] : 'N/A';
                $pendingCount = is_int($context['pending_count'] ?? null) ? $context['pending_count'] : 0;
                $lastTerminalUpdate = is_string($context['last_terminal_update'] ?? null) ? $context['last_terminal_update'] : 'never';
                $thresholdSeconds = is_int($context['progress_threshold_seconds'] ?? null) ? $context['progress_threshold_seconds'] : 0;
                $thresholdMinutes = $thresholdSeconds > 0 ? (int) round($thresholdSeconds / 60) : 0;

                return [
                    'severity' => NotificationSeverity::Critical,
                    'title' => "Dispatcher Group '{$group}' Stalled",
                    'emailMessage' => "🚨 CRITICAL: Dispatcher group has Pending work but no terminal-state progress.\n\n".
                        "📊 DETAILS:\n\n".
                        "• Group: {$group}\n".
                        "• Pending steps: {$pendingCount}\n".
                        "• Last terminal update: {$lastTerminalUpdate}\n".
                        "• Threshold: {$thresholdMinutes} minute(s)\n".
                        "• Server: {$hostname}\n\n".
                        "⚠️ WHAT THIS MEANS:\n\n".
                        'This watchdog catches dispatcher wedges that the per-step stuck detector misses — '.
                        'no individual step is stuck, but no group-level work is concluding either. '.
                        'The 2026-04-25 incident hid this exact failure mode for 16h before manual '.
                        "intervention; this alarm closes that blind spot.\n\n".
                        "🔍 RESOLUTION STEPS:\n\n".
                        "1. Inspect the wedged group's tick state:\n".
                        "[CMD]SELECT `group`, can_dispatch, current_tick_id, updated_at FROM steps_dispatcher WHERE `group` = '{$group}';[/CMD]\n\n".
                        "2. Check the Pending pile-up:\n".
                        "[CMD]SELECT class, COUNT(*) c, MIN(created_at) oldest FROM steps WHERE `group` = '{$group}' AND state = 'StepDispatcher\\\\States\\\\Pending' GROUP BY class ORDER BY c DESC LIMIT 10;[/CMD]\n\n".
                        "3. Look for Skipped parents with non-null child_block_uuid (the historical wedge shape):\n".
                        "[CMD]SELECT id, class, child_block_uuid, updated_at FROM steps WHERE `group` = '{$group}' AND state = 'StepDispatcher\\\\States\\\\Skipped' AND child_block_uuid IS NOT NULL;[/CMD]\n\n".
                        "4. Compare healthy vs wedged groups' last terminal update:\n".
                        "[CMD]SELECT `group`, MAX(updated_at) last_terminal FROM steps WHERE state IN ('StepDispatcher\\\\States\\\\Completed','StepDispatcher\\\\States\\\\Skipped','StepDispatcher\\\\States\\\\Cancelled','StepDispatcher\\\\States\\\\Failed','StepDispatcher\\\\States\\\\Stopped') GROUP BY `group` ORDER BY last_terminal;[/CMD]",
                    'pushoverMessage' => "🚨 Group '{$group}' wedged\nPending: {$pendingCount}\nLast progress: {$lastTerminalUpdate}\nServer: {$hostname}",
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'exchange_symbol_no_taapi_data' => (static function () use ($context) {
                // Support both new format ('exchangeSymbol') and legacy format ('exchange_symbol')
                $exchangeSymbol = $context['exchangeSymbol'] ?? ($context['exchange_symbol'] ?? null);

                // Build display string manually: "TOKEN/QUOTE@Exchange" for readability
                $displayString = 'Exchange Symbol';
                if ($exchangeSymbol) {
                    $symbolToken = $exchangeSymbol->token ?? 'UNKNOWN';
                    $quoteCanonical = $exchangeSymbol->quote ?? 'UNKNOWN';
                    $exchangeName = $exchangeSymbol->apiSystem->name ?? 'UNKNOWN';
                    $displayString = "{$symbolToken}/{$quoteCanonical}@{$exchangeName}";
                }

                return [
                    'severity' => NotificationSeverity::Info,
                    'title' => $displayString.' Auto-Deactivated',
                    'emailMessage' => "ℹ️ Exchange Symbol Auto-Deactivated\n\n".
                        'Exchange Symbol: '.$displayString."\n".
                        "Reason: Consistent lack of TAAPI indicator data\n".
                        'Failed Requests: '.($context['failure_count'] ?? 'N/A')." in last 24 hours\n\n".
                        "📊 WHAT HAPPENED:\n\n".
                        'This exchange symbol has been automatically deactivated because TAAPI (Technical Analysis API) consistently failed to provide indicator data. '.
                        "After multiple consecutive failures, the platform determined this symbol is not supported by TAAPI and deactivated it to prevent further errors.\n\n".
                        "✅ IMPACT:\n\n".
                        "• Symbol marked as auto_disabled = true\n".
                        "• Symbol marked with auto_disabled_reason = 'no_indicator_data'\n".
                        "• Symbol marked as receives_indicator_data = false\n".
                        "• No more TAAPI requests will be made for this symbol\n".
                        "• No new positions will be opened for this symbol\n".
                        '• Already open positions will continue normally',
                    'pushoverMessage' => 'Exchange Symbol: '.$displayString."\n".
                        'Reason: '.($context['failure_count'] ?? 'N/A')." Taapi failures\n".
                        'Action: Changed to Deactivated and Ineligible',
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'token_delisting' => (static function () use ($context, $exchangeTitle) {
                // Extract delisting details
                $pairText = is_string($context['pair_text'] ?? null) ? $context['pair_text'] : 'N/A';
                $deliveryDate = is_string($context['delivery_date'] ?? null) ? $context['delivery_date'] : 'N/A';
                $positionsCount = is_int($context['positions_count'] ?? null) ? $context['positions_count'] : 0;
                $positionsDetails = is_string($context['positions_details'] ?? null) ? $context['positions_details'] : '';

                $message = "🚨 Token Delisting Detected\n\n".
                    "Exchange: {$exchangeTitle}\n".
                    "Token: {$pairText}\n".
                    "Deadline: {$deliveryDate} UTC\n".
                    "Total open positions: {$positionsCount}\n\n";

                if ($positionsCount > 0) {
                    $message .= "⚠️ OPEN POSITIONS - System will not take any action on them:\n\n{$positionsDetails}";
                }

                $pushoverMessage = "Exchange: {$exchangeTitle}\n".
                    "Token: {$pairText}\n".
                    "Deadline: {$deliveryDate}\n".
                    "Total open positions: {$positionsCount}";

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => 'Token Delisting Detected',
                    'emailMessage' => $message,
                    'pushoverMessage' => $pushoverMessage,
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'slow_query_detected' => (static function () use ($context) {
                $sqlFull = is_string($context['sql_full'] ?? null) ? $context['sql_full'] : 'N/A';
                $timeMs = is_int($context['time_ms'] ?? null) ? $context['time_ms'] : 0;
                $connection = is_string($context['connection'] ?? null) ? $context['connection'] : 'unknown';
                $thresholdMs = is_int($context['threshold_ms'] ?? null) ? $context['threshold_ms'] : 2500;

                // Truncate SQL for pushover (max ~256 chars recommended)
                $truncatedSql = mb_strlen($sqlFull) > 100 ? mb_substr($sqlFull, 0, length: 100).'...' : $sqlFull;

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => 'Slow Database Query Detected',
                    'emailMessage' => "A database query exceeded the configured threshold.\n\n".
                        "Query duration: {$timeMs}ms\n".
                        "Threshold: {$thresholdMs}ms\n".
                        "Connection: {$connection}\n\n".
                        "Query:\n\n".
                        "[CMD]{$sqlFull}[/CMD]",
                    'pushoverMessage' => "Query duration: {$timeMs}ms\n".
                        "Threshold: {$thresholdMs}ms\n".
                        "Query: {$truncatedSql}",
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'server_ip_not_whitelisted' => (static function () use ($exchangeTitle, $ip, $accountName) {
                return [
                    'severity' => NotificationSeverity::High,
                    'title' => 'Please Whitelist Our Server IP',
                    'emailMessage' => "Hi!\n\n".
                        "We noticed that your {$exchangeTitle} API key requires IP whitelisting. ".
                        "To ensure uninterrupted service, please add our server IP to your API key whitelist.\n\n".
                        "Exchange: {$exchangeTitle}\n".
                        "Account: {$accountName}\n".
                        "IP to whitelist: [COPY]{$ip}[/COPY]\n\n".
                        "🔧 HOW TO ADD:\n\n".
                        "1. Log into {$exchangeTitle}\n".
                        "2. Go to API Management\n".
                        "3. Edit the API key used for \"{$accountName}\"\n".
                        "4. Add this IP to the whitelist: {$ip}\n".
                        "5. Save\n\n".
                        'Once done, everything will work seamlessly. Thank you!',
                    'pushoverMessage' => "{$exchangeTitle}: Please whitelist IP {$ip}\nAccount: {$accountName}",
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'server_account_blocked' => (static function () use ($context, $exchangeTitle, $ip, $hostname, $accountName) {
                // Extract details
                $errorCode = is_string($context['error_code'] ?? null) ? $context['error_code'] : 'N/A';
                $errorMessage = is_string($context['error_message'] ?? null) ? $context['error_message'] : 'N/A';
                $accountId = $context['account_id'] ?? null;

                return [
                    'severity' => NotificationSeverity::Critical,
                    'title' => 'Account API Access Blocked',
                    'emailMessage' => "🚨 Account API Access Blocked\n\n".
                        "Your {$exchangeTitle} account API access has been blocked.\n\n".
                        "📊 DETAILS:\n\n".
                        "• Account: {$accountName}\n".
                        "• Server IP: {$ip}\n".
                        "• Hostname: {$hostname}\n".
                        "• Error Code: {$errorCode}\n".
                        "• Error Message: {$errorMessage}\n\n".
                        "⚠️ POSSIBLE CAUSES:\n\n".
                        "• API key has been revoked or disabled\n".
                        "• API key permissions are insufficient\n".
                        "• Account has been restricted by the exchange\n".
                        "• Payment or subscription issues\n\n".
                        "🔧 HOW TO FIX:\n\n".
                        "1. Log into your {$exchangeTitle} account\n".
                        "2. Go to API Management settings\n".
                        "3. Check your API key status\n".
                        "4. If needed, generate a new API key with correct permissions:\n".
                        "   • Enable Futures trading (if using futures)\n".
                        "   • Enable Spot trading (if using spot)\n".
                        "   • Enable Read permissions\n".
                        "5. Update your API credentials in the platform\n\n".
                        "⏱️ WHAT HAPPENS NEXT:\n\n".
                        "• Once you fix the API key, update your credentials in Settings\n".
                        '• The system will automatically resume operations',
                    'pushoverMessage' => "🚨 {$exchangeTitle} API BLOCKED\nAccount: {$accountName}\nCheck API key permissions",
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            // Trading-lifecycle notifications. Context keys:
            //   token        — e.g. "GMX"
            //   pair         — e.g. "GMXUSDT"
            //   direction    — "LONG" or "SHORT"
            //   position_id  — DB id (optional but useful in the body)
            //   account_name — e.g. "Main Binance Account"
            // Priority override per canonical: most are -1 (low / silent),
            // the WAP event is 1 (high / bypass quiet hours).
            'position_opened' => (static function () use ($context) {
                $token = is_string($context['token'] ?? null) ? $context['token'] : 'symbol';
                $pair = is_string($context['pair'] ?? null) ? $context['pair'] : $token;
                $direction = is_string($context['direction'] ?? null) ? $context['direction'] : '';
                $positionId = is_int($context['position_id'] ?? null) ? (int) $context['position_id'] : null;
                $accountName = is_string($context['account_name'] ?? null) ? $context['account_name'] : 'account';
                $entry = is_string($context['entry_price'] ?? null) ? $context['entry_price'] : null;
                $qty = is_string($context['quantity'] ?? null) ? $context['quantity'] : null;

                $posRef = $positionId !== null ? "#{$positionId}" : '';

                $pushoverLines = ["📈 {$direction} {$pair} opened · {$posRef}"];
                if ($entry !== null && $qty !== null) {
                    $pushoverLines[] = "Entry: {$entry} × {$qty}";
                } elseif ($entry !== null) {
                    $pushoverLines[] = "Entry: {$entry}";
                } elseif ($qty !== null) {
                    $pushoverLines[] = "Quantity: {$qty}";
                }
                $pushoverLines[] = "Account: {$accountName}";

                $emailLines = [
                    "📈 Position {$posRef} opened",
                    '',
                    "• Pair: {$pair}",
                    "• Direction: {$direction}",
                    "• Account: {$accountName}",
                ];
                if ($entry !== null) {
                    $emailLines[] = "• Entry price: {$entry}";
                }
                if ($qty !== null) {
                    $emailLines[] = "• Quantity: {$qty}";
                }
                $emailLines[] = '';
                $emailLines[] = 'Market entry filled; TP / SL / LIMITs placed.';

                return [
                    'severity' => NotificationSeverity::Info,
                    'priority' => -1,
                    'title' => "Position Opened — {$direction} {$pair}",
                    'emailMessage' => implode(separator: "\n", array: $emailLines),
                    'pushoverMessage' => implode(separator: "\n", array: $pushoverLines),
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'position_closed' => (static function () use ($context) {
                $token = is_string($context['token'] ?? null) ? $context['token'] : 'symbol';
                $pair = is_string($context['pair'] ?? null) ? $context['pair'] : $token;
                $direction = is_string($context['direction'] ?? null) ? $context['direction'] : '';
                $positionId = is_int($context['position_id'] ?? null) ? (int) $context['position_id'] : null;
                $accountName = is_string($context['account_name'] ?? null) ? $context['account_name'] : 'account';
                $closingPrice = is_string($context['closing_price'] ?? null) ? $context['closing_price'] : null;
                $filledLimits = is_int($context['filled_limits'] ?? null) ? (int) $context['filled_limits'] : null;
                $wasFastTraded = ! empty($context['was_fast_traded']);

                $posRef = $positionId !== null ? "#{$positionId}" : '';
                $fastLabel = $wasFastTraded ? ' · fast trade' : '';

                $pushoverLines = ["🏁 {$direction} {$pair} closed · {$posRef}{$fastLabel}"];
                if ($closingPrice !== null) {
                    $pushoverLines[] = "Exit: {$closingPrice}";
                }
                if ($filledLimits !== null) {
                    $pushoverLines[] = "DCA rungs filled: {$filledLimits}";
                }
                $pushoverLines[] = "Account: {$accountName}";

                $emailLines = [
                    "🏁 Position {$posRef} closed".($wasFastTraded ? ' (fast trade)' : ''),
                    '',
                    "• Pair: {$pair}",
                    "• Direction: {$direction}",
                    "• Account: {$accountName}",
                ];
                if ($closingPrice !== null) {
                    $emailLines[] = "• Closing price: {$closingPrice}";
                }
                if ($filledLimits !== null) {
                    $emailLines[] = "• DCA rungs filled: {$filledLimits}";
                }

                return [
                    'severity' => NotificationSeverity::Info,
                    'priority' => -1,
                    'title' => "Position Closed — {$direction} {$pair}",
                    'emailMessage' => implode(separator: "\n", array: $emailLines),
                    'pushoverMessage' => implode(separator: "\n", array: $pushoverLines),
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'position_wap_applied' => (static function () use ($context) {
                $token = is_string($context['token'] ?? null) ? $context['token'] : 'symbol';
                $pair = is_string($context['pair'] ?? null) ? $context['pair'] : $token;
                $direction = is_string($context['direction'] ?? null) ? $context['direction'] : '';
                $positionId = is_int($context['position_id'] ?? null) ? (int) $context['position_id'] : null;
                $oldTp = is_string($context['old_tp_price'] ?? null) ? $context['old_tp_price'] : null;
                $newTp = is_string($context['new_tp_price'] ?? null) ? $context['new_tp_price'] : null;
                $oldQty = is_string($context['old_tp_quantity'] ?? null) ? $context['old_tp_quantity'] : null;
                $newQty = is_string($context['new_tp_quantity'] ?? null) ? $context['new_tp_quantity'] : null;
                $breakEven = is_string($context['break_even_price'] ?? null) ? $context['break_even_price'] : null;

                $posRef = $positionId !== null ? "#{$positionId}" : '';

                // Show the before/after on both price AND qty — this is the
                // signal the operator wants to see at a glance on Pushover.
                $pushoverLines = ["🧮 WAP · {$direction} {$pair} · {$posRef}"];
                if ($oldTp !== null && $newTp !== null) {
                    $pushoverLines[] = "TP price: {$oldTp} → {$newTp}";
                } elseif ($newTp !== null) {
                    $pushoverLines[] = "TP price: {$newTp}";
                }
                if ($oldQty !== null && $newQty !== null) {
                    $pushoverLines[] = "TP qty: {$oldQty} → {$newQty}";
                } elseif ($newQty !== null) {
                    $pushoverLines[] = "TP qty: {$newQty}";
                }
                if ($breakEven !== null) {
                    $pushoverLines[] = "Break-even: {$breakEven}";
                }

                $emailLines = [
                    "🧮 WAP applied on position {$posRef}",
                    '',
                    "• Pair: {$pair}",
                    "• Direction: {$direction}",
                ];
                if ($breakEven !== null) {
                    $emailLines[] = "• Break-even (exchange): {$breakEven}";
                }
                if ($oldTp !== null && $newTp !== null) {
                    $emailLines[] = "• TP price: {$oldTp} → {$newTp}";
                }
                if ($oldQty !== null && $newQty !== null) {
                    $emailLines[] = "• TP quantity: {$oldQty} → {$newQty}";
                }
                $emailLines[] = '';
                $emailLines[] = "DCA rungs filled; TP repositioned against the exchange's breakEvenPrice.";

                return [
                    'severity' => NotificationSeverity::High,
                    'priority' => 1,
                    'title' => "WAP Applied — {$direction} {$pair}",
                    'emailMessage' => implode(separator: "\n", array: $emailLines),
                    'pushoverMessage' => implode(separator: "\n", array: $pushoverLines),
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'position_high_profit_closed' => (static function () use ($context) {
                $token = is_string($context['token'] ?? null) ? $context['token'] : 'symbol';
                $pair = is_string($context['pair'] ?? null) ? $context['pair'] : $token;
                $direction = is_string($context['direction'] ?? null) ? $context['direction'] : '';
                $positionId = is_int($context['position_id'] ?? null) ? (int) $context['position_id'] : null;
                $filledLimits = is_int($context['filled_limits'] ?? null) ? (int) $context['filled_limits'] : 0;
                $closingPrice = is_string($context['closing_price'] ?? null) ? $context['closing_price'] : null;

                $posRef = $positionId !== null ? "#{$positionId}" : '';

                $pushoverLines = ["🎉 Nice one! · {$direction} {$pair} · {$posRef}"];
                if ($closingPrice !== null) {
                    $pushoverLines[] = "Exit: {$closingPrice}";
                }
                $pushoverLines[] = "DCA rungs filled: {$filledLimits}";

                $emailLines = [
                    "🎉 High-profit position {$posRef} closed",
                    '',
                    "• Pair: {$pair}",
                    "• Direction: {$direction}",
                    "• DCA rungs filled: {$filledLimits}",
                ];
                if ($closingPrice !== null) {
                    $emailLines[] = "• Closing price: {$closingPrice}";
                }
                $emailLines[] = '';
                $emailLines[] = 'The strategy ran the ladder down/up before reversing. Nice.';

                return [
                    'severity' => NotificationSeverity::Info,
                    'priority' => -1,
                    'title' => "🎉 High-Profit Close — {$direction} {$pair}",
                    'emailMessage' => implode(separator: "\n", array: $emailLines),
                    'pushoverMessage' => implode(separator: "\n", array: $pushoverLines),
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'position_pump_cooldown_triggered' => (static function () use ($context) {
                $token = is_string($context['token'] ?? null) ? $context['token'] : 'symbol';
                $pair = is_string($context['pair'] ?? null) ? $context['pair'] : $token;
                $changePercent = is_string($context['change_percent'] ?? null) ? $context['change_percent'] : 'N/A';
                $threshold = is_string($context['threshold'] ?? null) ? $context['threshold'] : 'N/A';
                $tradeableAt = is_string($context['tradeable_at'] ?? null) ? $context['tradeable_at'] : 'soon';
                $currentPrice = is_string($context['current_price'] ?? null) ? $context['current_price'] : null;
                $dailyClose = is_string($context['daily_close'] ?? null) ? $context['daily_close'] : null;

                $pushoverLines = [
                    "⚠️ Pump cooldown · {$pair}",
                    "Move: {$changePercent}% (threshold {$threshold}%)",
                ];
                if ($currentPrice !== null && $dailyClose !== null) {
                    $pushoverLines[] = "Price: {$currentPrice} vs daily {$dailyClose}";
                }
                $pushoverLines[] = "Tradeable again: {$tradeableAt}";

                $emailLines = [
                    "⚠️ Pump cooldown triggered for {$pair}",
                    '',
                    "• Price change: {$changePercent}% (threshold {$threshold}%)",
                ];
                if ($currentPrice !== null && $dailyClose !== null) {
                    $emailLines[] = "• Current vs daily close: {$currentPrice} vs {$dailyClose}";
                }
                $emailLines[] = "• Tradeable again: {$tradeableAt}";
                $emailLines[] = '';
                $emailLines[] = 'Symbol auto-disabled from new-position selection until the cooldown expires. Review whether this was a news-driven move (leave cooled) or noise (manually re-enable).';

                return [
                    'severity' => NotificationSeverity::High,
                    'priority' => 1,
                    'title' => "Pump Cooldown — {$pair}",
                    'emailMessage' => implode(separator: "\n", array: $emailLines),
                    'pushoverMessage' => implode(separator: "\n", array: $pushoverLines),
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'position_opening_failed' => (static function () use ($context) {
                $token = is_string($context['token'] ?? null) ? $context['token'] : 'symbol';
                $pair = is_string($context['pair'] ?? null) ? $context['pair'] : $token;
                $direction = is_string($context['direction'] ?? null) ? $context['direction'] : '';
                $positionId = is_int($context['position_id'] ?? null) ? (int) $context['position_id'] : null;
                $reason = is_string($context['reason'] ?? null) ? $context['reason'] : 'Unknown error';
                $tokenBlocked = (bool) ($context['token_blocked'] ?? false);

                $posRef = $positionId !== null ? "#{$positionId}" : '';

                $pushoverLines = [
                    "⛔ Opening failed · {$direction} {$pair} · {$posRef}",
                    "Reason: {$reason}",
                ];
                if ($tokenBlocked) {
                    $pushoverLines[] = "Token {$token} auto-disabled from selection.";
                }

                $emailLines = [
                    "⛔ Position opening failed {$posRef} on {$pair}",
                    '',
                    "• Direction: {$direction}",
                    "• Reason: {$reason}",
                ];
                if ($tokenBlocked) {
                    $emailLines[] = "• Token {$token} auto-disabled (is_manually_enabled=false) so the scheduler won't retry it.";
                }
                $emailLines[] = '';
                $emailLines[] = 'Inspect the position on the exchange UI, reconcile if needed, and re-enable the token manually once the root cause is addressed.';

                return [
                    'severity' => NotificationSeverity::High,
                    'priority' => 1,
                    'title' => "Opening Failed — {$pair}",
                    'emailMessage' => implode(separator: "\n", array: $emailLines),
                    'pushoverMessage' => implode(separator: "\n", array: $pushoverLines),
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'position_residual_detected' => (static function () use ($context) {
                $token = is_string($context['token'] ?? null) ? $context['token'] : 'symbol';
                $pair = is_string($context['pair'] ?? null) ? $context['pair'] : $token;
                $direction = is_string($context['direction'] ?? null) ? $context['direction'] : '';
                $positionId = is_int($context['position_id'] ?? null) ? (int) $context['position_id'] : null;
                $residual = is_string($context['residual_amount'] ?? null) ? $context['residual_amount'] : 'N/A';

                $posRef = $positionId !== null ? "#{$positionId}" : '';

                $pushoverLines = [
                    "🚨 Residual position · {$direction} {$pair} · {$posRef}",
                    "Amount on exchange: {$residual}",
                    'Reconcile manually on the exchange.',
                ];

                $emailLines = [
                    "🚨 Residual position {$posRef} on {$pair}",
                    '',
                    "• Direction: {$direction}",
                    "• Residual amount: {$residual}",
                    '',
                    'Close-on-exchange reported success but the exchange still carries a position. Possible causes:',
                    '• Partial fill race',
                    '• reduceOnly rejection',
                    '• Exchange-side ledger lag',
                    '',
                    'Inspect on the exchange UI and reconcile manually.',
                ];

                return [
                    'severity' => NotificationSeverity::Critical,
                    // Critical → default mapping (priority 2 emergency) is
                    // correct here — this is a genuine wake-up-now event.
                    'title' => "Residual Position — {$pair}",
                    'emailMessage' => implode(separator: "\n", array: $emailLines),
                    'pushoverMessage' => implode(separator: "\n", array: $pushoverLines),
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'market_regime_critical' => (function () use ($context) {
                $score = (int) ($context['score'] ?? 0);
                $hours = (int) ($context['cooldown_hours'] ?? 24);
                $hostname = is_string($context['hostname'] ?? null) ? $context['hostname'] : (string) gethostname();

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => "BSCS Critical — {$score}/100, opens paused {$hours}h",
                    'emailMessage' => "Black Swan Composite Score crossed the cooldown threshold (score={$score}).\n\n".
                        "New position opens paused for {$hours} hours via the system cooldown gate. ".
                        "Existing positions untouched (no auto-close, no SL move).\n\n".
                        "If the score is still high when the cooldown expires, another {$hours}h window arms automatically. ".
                        "If recovered, the gate releases and you'll receive a `market_regime_recovered` notification.\n\n".
                        "Operator escape hatch: set `bscs_override_until` on the kraite singleton to a future timestamp to force opens through.\n\n".
                        "Server: {$hostname}",
                    'pushoverMessage' => "BSCS Critical {$score}/100 — opens paused for {$hours}h",
                    'actionUrl' => null,
                    'actionLabel' => null,
                    'priority' => 1,
                ];
            })(),

            'market_regime_recovered' => (function () use ($context) {
                $score = (int) ($context['score'] ?? 0);

                return [
                    'severity' => NotificationSeverity::Info,
                    'title' => "BSCS Recovered — {$score}/100, opens resumed",
                    'emailMessage' => "Black Swan Composite Score has fallen below the cooldown threshold (score={$score}). ".
                        'The system cooldown has been released; new position opens resume on the next `kraite:cron-create-positions` tick.',
                    'pushoverMessage' => "BSCS recovered to {$score}/100 — opens resumed",
                    'actionUrl' => null,
                    'actionLabel' => null,
                    'priority' => -1,
                ];
            })(),

            'market_shock_circuit_breaker' => (function () use ($context) {
                $rules = (string) ($context['rules'] ?? 'unknown');
                $btc15m = (float) ($context['btc_15m_pct'] ?? 0.0);
                $btc1h = (float) ($context['btc_1h_pct'] ?? 0.0);
                $altBasket = (float) ($context['alt_basket_1h_pct'] ?? 0.0);
                $corr = (float) ($context['corr_1h'] ?? 0.0);
                $hours = (int) ($context['cooldown_hours'] ?? 24);
                $hostname = is_string($context['hostname'] ?? null) ? $context['hostname'] : (string) gethostname();

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => "Market shock detected — opens paused {$hours}h",
                    'emailMessage' => "MarketShockCircuitBreaker fired ({$rules}).\n\n".
                        "BTC 15m: {$btc15m}%   BTC 1h: {$btc1h}%   Alt basket 1h: {$altBasket}%   Corr 1h: {$corr}\n\n".
                        "New position opens paused for {$hours} hours via the shared BSCS cooldown gate. ".
                        "Existing positions untouched.\n\n".
                        'Detector cadence is 1 minute on 15m klines for BTC + 4 alts; this is faster than the hourly BSCS compute would have noticed. '.
                        "Cooldown will release automatically; if cascade keeps firing, the next detector run is silent (no spam).\n\n".
                        "Operator escape hatch: set `bscs_override_until` on the kraite singleton to a future timestamp to force opens through.\n\n".
                        "Server: {$hostname}",
                    'pushoverMessage' => "Market shock ({$rules}) — opens paused {$hours}h. BTC 15m {$btc15m}% / 1h {$btc1h}%",
                    'actionUrl' => null,
                    'actionLabel' => null,
                    'priority' => 1,
                ];
            })(),

            'websocket_reconnect_triggered' => (static function () use ($context) {
                $url = is_string($context['url'] ?? null) ? $context['url'] : 'wss://(unknown)';
                $idleSeconds = (int) ($context['idle_seconds'] ?? 0);
                $framesReceived = (int) ($context['frames_received'] ?? 0);
                $uptime = (int) ($context['uptime_seconds'] ?? 0);

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => 'WebSocket Idle-Timeout Reconnect',
                    'emailMessage' => "The price-stream WebSocket received no frames for {$idleSeconds}s and is forcing a reconnect.\n\nURL: {$url}\nFrames received this connection: {$framesReceived}\nUptime before reconnect: {$uptime}s\n\nIf this fires repeatedly without the data healing, investigate upstream (URL deprecation, IP ban, upstream outage).",
                    'pushoverMessage' => "WS reconnect — idle {$idleSeconds}s, frames={$framesReceived} (uptime {$uptime}s).",
                    'actionUrl' => null,
                    'actionLabel' => null,
                    'priority' => 0,
                ];
            })(),

            'price_data_stale' => (static function () use ($context) {
                $count = (int) ($context['stale_count'] ?? 0);
                $oldest = (int) ($context['oldest_age_seconds'] ?? 0);
                $sample = is_array($context['sample_pairs'] ?? null)
                    ? implode(', ', array_slice($context['sample_pairs'], 0, 5))
                    : '';

                return [
                    'severity' => NotificationSeverity::Critical,
                    'title' => 'Mark Price Data Stale',
                    'emailMessage' => "{$count} enabled exchange_symbols have not received a mark_price update in over 60 seconds.\n\nOldest age: {$oldest}s\nSample: {$sample}\n\nThe WebSocket reconnect loop is not healing the underlying problem. Likely causes: URL deprecation by Binance, IP ban, upstream gateway outage. Trading guards continue using stale prices for selection — investigate immediately.",
                    'pushoverMessage' => "Mark prices stale ({$count} symbols, oldest {$oldest}s) — investigate.",
                    'actionUrl' => null,
                    'actionLabel' => null,
                    'priority' => 1,
                ];
            })(),

            'market_regime_compute_stale' => (function () use ($context) {
                $hours = (int) ($context['stale_hours'] ?? 0);

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => 'BSCS Compute Stale',
                    'emailMessage' => "Black Swan Composite Score has not been recomputed for {$hours} hours. The hourly cron `kraite:cron-compute-market-regime` may be failing. The gate (Phase 2) fail-opens on stale signals — opens still allowed.\n\nInvestigate Horizon and check for repeated failures of `ComputeMarketRegimeJob`.",
                    'pushoverMessage' => "BSCS compute stale {$hours}h — investigate",
                    'actionUrl' => null,
                    'actionLabel' => null,
                    'priority' => 1,
                ];
            })(),

            'subscription_low_balance' => (static function () use ($context) {
                $renewsAt = is_string($context['renews_at'] ?? null) ? $context['renews_at'] : null;
                $balance = (float) ($context['balance_usdt'] ?? 0);
                $rate = (float) ($context['monthly_rate_usdt'] ?? 0);
                $shortfall = (float) ($context['shortfall_usdt'] ?? 0);

                $renewsLabel = $renewsAt ?? 'soon';

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => 'Renewal in 7 days — wallet short',
                    'emailMessage' => "Your Kraite subscription renews on {$renewsLabel} at the current monthly rate of {$rate} USDT.\n\n".
                        "Current balance: {$balance} USDT — short {$shortfall} USDT for the next renewal.\n\n".
                        'Top up before the renewal date to avoid the bot pausing new positions. Existing positions will continue to TP/SL regardless.',
                    'pushoverMessage' => "Kraite renews {$renewsLabel} — wallet {$balance} USDT, short {$shortfall}. Top up to avoid pause.",
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'subscription_closing_mode' => (static function () use ($context) {
                $rate = (float) ($context['monthly_rate_usdt'] ?? 0);
                $balance = (float) ($context['balance_usdt'] ?? 0);
                $shortfall = (float) ($context['shortfall_usdt'] ?? 0);

                return [
                    'severity' => NotificationSeverity::Critical,
                    'title' => 'Renewal failed — read-only mode',
                    'emailMessage' => "Your Kraite wallet ({$balance} USDT) cannot cover the monthly renewal rate ({$rate} USDT). You need {$shortfall} USDT more.\n\n".
                        "The bot is now in read-only mode for your accounts:\n".
                        "  • New positions are blocked.\n".
                        "  • Existing positions continue trading their full lifecycle to TP/SL — they are not closed early.\n\n".
                        'Top up your wallet to resume new opens. The renewal will retry the moment the balance covers the monthly rate.',
                    'pushoverMessage' => 'Kraite renewal failed — read-only mode. New opens paused, existing trades unaffected. Top up to resume.',
                    'actionUrl' => null,
                    'actionLabel' => null,
                    'priority' => 1,
                ];
            })(),

            'subscription_trial_ending' => (static function () use ($context) {
                $rate = (float) ($context['monthly_rate_usdt'] ?? 0);
                $shortfall = (float) ($context['shortfall_usdt'] ?? 0);
                $balance = (float) ($context['balance_usdt'] ?? 0);

                return [
                    'severity' => NotificationSeverity::Info,
                    'title' => 'Trial ends in 2 days — wallet short',
                    'emailMessage' => "Your free Kraite trial ends in 2 days.\n\n".
                        "After the trial, the bot debits the monthly subscription rate of {$rate} USDT once per renewal cycle. Your current wallet balance is {$balance} USDT — {$shortfall} USDT short of the first renewal.\n\n".
                        'Top up before the trial ends to keep the bot running without interruption. If your wallet is short at the first renewal tick, your accounts go into read-only mode (existing positions continue, no new opens) until you top up.',
                    'pushoverMessage' => "Kraite trial ends in 2 days — wallet {$balance} USDT, short {$shortfall}. Top up to keep trading.",
                    'actionUrl' => null,
                    'actionLabel' => null,
                ];
            })(),

            'subscription_topup_confirmed' => (static function () use ($context, $user) {
                $amount = (float) ($context['amount_usdt'] ?? 0);
                $balance = (float) ($context['balance_after'] ?? 0);
                $source = is_string($context['source'] ?? null) ? $context['source'] : 'top-up';
                $rate = (float) ($context['monthly_rate_usdt'] ?? 0);
                $shortfall = (float) ($context['shortfall_usdt'] ?? 0);
                $renewalRan = (bool) ($context['renewal_ran'] ?? false);
                $payCurrency = is_string($context['pay_currency'] ?? null) ? $context['pay_currency'] : null;
                $sufficient = $shortfall <= 0;

                $statusLine = match (true) {
                    $renewalRan => 'Renewal applied immediately — accounts back in write-mode.',
                    $sufficient => 'Balance now covers the next renewal.',
                    default => "Still {$shortfall} USDT short of the next renewal.",
                };

                // One-click "fund the rest" link in the email when the wallet
                // is still short. Uses the same coin the user just paid in so
                // they don't repick. Signed URL valid for 7 days.
                $actionUrl = null;
                $actionLabel = null;
                if (! $sufficient && $shortfall > 0 && $payCurrency !== null && $user instanceof User) {
                    try {
                        $actionUrl = URL::temporarySignedRoute(
                            'billing.quick-topup',
                            now()->addDays(7),
                            [
                                'user' => $user->id,
                                'amount' => round($shortfall, 4),
                                'coin' => $payCurrency,
                            ],
                        );
                        $actionLabel = sprintf(
                            'Top up the remaining %s USDT in %s',
                            number_format($shortfall, 2),
                            mb_strtoupper($payCurrency),
                        );
                    } catch (Throwable) {
                        // Route not registered or signing failed — fall back
                        // to no CTA. The body still tells the user to top up
                        // from /billing manually.
                    }
                }

                return [
                    'severity' => NotificationSeverity::Info,
                    'title' => "Top-up credited: {$amount} USDT",
                    'emailMessage' => "Your Kraite wallet has been credited.\n\n".
                        "Source: {$source}\n".
                        "Amount: {$amount} USDT\n".
                        "New balance: {$balance} USDT\n".
                        "Monthly rate: {$rate} USDT\n\n".
                        $statusLine.
                        ($actionUrl !== null
                            ? "\n\nYou can top up the remaining amount with one click — the link below pre-fills the shortfall and the same coin you just paid with."
                            : ''),
                    'pushoverMessage' => "Kraite +{$amount} USDT → balance {$balance} USDT. {$statusLine}",
                    'actionUrl' => $actionUrl,
                    'actionLabel' => $actionLabel,
                ];
            })(),

            // Proactive 5-minute drift spotter — fires when an active position
            // disagrees with the exchange after the 10-minute quiet window.
            // The reactive cron (kraite:cron-sync-orders) is the primary
            // healer; this notification means the spotter found something
            // the reactive loop missed for ≥10 minutes and dispatched a
            // sync-orders pass to recover.
            'position_drift_detected' => (static function () use ($context) {
                $pair = is_string($context['pair'] ?? null) ? $context['pair'] : 'unknown';
                $direction = is_string($context['direction'] ?? null) ? $context['direction'] : '?';
                $accountName = is_string($context['account_name'] ?? null) ? $context['account_name'] : 'account';
                $exchange = is_string($context['exchange'] ?? null) ? $context['exchange'] : 'exchange';
                $pairStatus = is_string($context['pair_status'] ?? null) ? $context['pair_status'] : 'drift';
                $positionDriftFields = is_array($context['position_drift_fields'] ?? null) ? $context['position_drift_fields'] : [];
                $orderDrifts = is_array($context['order_drifts'] ?? null) ? $context['order_drifts'] : [];
                $positionId = isset($context['position_id']) ? (int) $context['position_id'] : null;

                $orderDriftLines = [];
                foreach ($orderDrifts as $od) {
                    $type = is_string($od['type'] ?? null) ? $od['type'] : '?';
                    $status = is_string($od['status'] ?? null) ? $od['status'] : '?';
                    $fields = is_array($od['drift_fields'] ?? null) ? implode(',', $od['drift_fields']) : '';
                    $orderDriftLines[] = "  • {$type} ({$status})".($fields !== '' ? " — fields: {$fields}" : '');
                }
                $orderDriftBody = empty($orderDriftLines) ? '(none)' : implode("\n", $orderDriftLines);

                $positionDriftBody = empty($positionDriftFields)
                    ? '(none)'
                    : implode(', ', $positionDriftFields);

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => 'Position Drift Detected',
                    'emailMessage' => "Drift detected on position #{$positionId} {$pair} {$direction}.\n\n".
                        "Account: {$accountName} ({$exchange})\n".
                        "Pair status: {$pairStatus}\n".
                        "Position-level drift fields: {$positionDriftBody}\n\n".
                        "Order-level drifts:\n{$orderDriftBody}\n\n".
                        "The spotter has dispatched `PrepareSyncOrdersJob` for position #{$positionId}. Sync-orders handles the heal.\n\n".
                        "Inspect:\n[CMD]SELECT id, type, side, status, price, quantity, updated_at FROM orders WHERE position_id = {$positionId} ORDER BY id;[/CMD]",
                    'pushoverMessage' => "Drift: {$pair} {$direction} ({$accountName}) — ".count($orderDrifts).' order drift(s); sync-orders dispatched',
                    'actionUrl' => null,
                    'actionLabel' => null,
                    'priority' => 0,
                ];
            })(),

            // Orphan open orders attached to a non-active position (closed,
            // cancelled, or failed). The spotter splits orphans into two
            // tracks at detection:
            //   • Ghost (no exchange_order_id) — never reached the
            //     exchange. Marked CANCELLED in DB inline by the spotter
            //     since apiCancel has nothing to act on.
            //   • Real algo (is_algo + exchange_order_id) — the spotter
            //     dispatches CancelSingleAlgoOrderJob to clean them on
            //     the exchange.
            // The notification reports BOTH counts so the operator sees
            // what the spotter took care of automatically vs what was
            // dispatched async to Horizon.
            'position_orphan_orders_detected' => (static function () use ($context) {
                $pair = is_string($context['pair'] ?? null) ? $context['pair'] : 'unknown';
                $direction = is_string($context['direction'] ?? null) ? $context['direction'] : '?';
                $accountName = is_string($context['account_name'] ?? null) ? $context['account_name'] : 'account';
                $exchange = is_string($context['exchange'] ?? null) ? $context['exchange'] : 'exchange';
                $positionStatus = is_string($context['position_status'] ?? null) ? $context['position_status'] : 'closed';
                $orphanOrders = is_array($context['orphan_orders'] ?? null) ? $context['orphan_orders'] : [];
                $positionId = isset($context['position_id']) ? (int) $context['position_id'] : null;
                $ghostsCancelled = isset($context['ghosts_cancelled_in_db']) ? (int) $context['ghosts_cancelled_in_db'] : 0;
                $lifecycleDispatched = ! empty($context['cancel_lifecycle_dispatched']);

                $orderLines = [];
                foreach ($orphanOrders as $oo) {
                    $oid = isset($oo['id']) ? (int) $oo['id'] : 0;
                    $type = is_string($oo['type'] ?? null) ? $oo['type'] : '?';
                    $side = is_string($oo['side'] ?? null) ? $oo['side'] : '?';
                    $status = is_string($oo['status'] ?? null) ? $oo['status'] : '?';
                    $isGhost = ! empty($oo['is_ghost']);
                    $tag = $isGhost ? ' [ghost]' : '';

                    // Exchange-side ids if present — operator can paste
                    // these straight into the exchange UI's order search.
                    $exchangeOrderId = is_string($oo['exchange_order_id'] ?? null)
                        ? $oo['exchange_order_id']
                        : null;
                    $clientOrderId = is_string($oo['client_order_id'] ?? null)
                        ? $oo['client_order_id']
                        : null;

                    $idParts = [];
                    if ($exchangeOrderId !== null && $exchangeOrderId !== '') {
                        $idParts[] = "exch:{$exchangeOrderId}";
                    }
                    if ($clientOrderId !== null && $clientOrderId !== '') {
                        $idParts[] = "client:{$clientOrderId}";
                    }
                    $idSuffix = $idParts === [] ? '' : '  '.implode(' ', $idParts);

                    $orderLines[] = "  • #{$oid} {$type} {$side} ({$status}){$tag}{$idSuffix}";
                }
                $orderBody = empty($orderLines) ? '(none)' : implode("\n", $orderLines);
                $count = count($orphanOrders);

                // Compact order-id summary for the Pushover body —
                // a single line with the local Order id(s) so the
                // operator can paste straight into the admin UI's
                // order search without opening the email. Format:
                // single order  → "order #1219 (exch:29235944015)"
                // multi orders  → "orders #1219, #1220, #1221"
                $orderIdSummary = '(none)';
                if (! empty($orphanOrders)) {
                    if ($count === 1) {
                        $oo = $orphanOrders[0] ?? [];
                        $oid = isset($oo['id']) ? (int) $oo['id'] : 0;
                        $exch = is_string($oo['exchange_order_id'] ?? null) && $oo['exchange_order_id'] !== ''
                            ? $oo['exchange_order_id']
                            : null;
                        $orderIdSummary = $exch !== null
                            ? "order #{$oid} (exch:{$exch})"
                            : "order #{$oid}";
                    } else {
                        $idsCsv = implode(
                            ', ',
                            array_map(
                                static fn (array $oo): string => '#'.(isset($oo['id']) ? (int) $oo['id'] : 0),
                                $orphanOrders
                            )
                        );
                        $orderIdSummary = "orders {$idsCsv}";
                    }
                }

                $actions = [];
                if ($ghostsCancelled > 0) {
                    $actions[] = "{$ghostsCancelled} marked CANCELLED in DB (ghosts — never on exchange)";
                }
                if ($lifecycleDispatched) {
                    $actions[] = 'cancel-orphan-orders lifecycle dispatched (covers regular + algo orders)';
                }
                $actionsBody = empty($actions)
                    ? '(none — orders surfaced for manual review)'
                    : "\n  • ".implode("\n  • ", $actions);
                $actionsSummary = empty($actions) ? 'no auto-action' : implode(' + ', $actions);

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => 'Orphan Orders Detected',
                    'emailMessage' => "Position #{$positionId} {$pair} {$direction} is `{$positionStatus}` but still carries {$count} open order(s) in our DB.\n\n".
                        "Account: {$accountName} ({$exchange})\n\n".
                        "Orphan orders:\n{$orderBody}\n\n".
                        "Spotter actions:{$actionsBody}",
                    'pushoverMessage' => "Orphan: {$pair} {$direction} ({$accountName}) — {$orderIdSummary}; {$actionsSummary}",
                    'actionUrl' => null,
                    'actionLabel' => null,
                    'priority' => 0,
                ];
            })(),

            'recovery_completed' => (function () use ($context) {
                $detail = (string) ($context['detail'] ?? '(no detail)');
                $snapshotPath = $context['snapshot_path'] ?? null;
                $warningsCount = (int) ($context['warnings_count'] ?? 0);

                $title = '✅ Recovery Completed';

                $body = "kraite:recover-positions finished.\n\n{$detail}";

                if ($snapshotPath !== null) {
                    $body .= "\n\nSnapshot: {$snapshotPath}";
                }

                if ($warningsCount > 0) {
                    $body .= "\n\nWarnings: {$warningsCount} (see scheduler.log)";
                }

                return [
                    'severity' => NotificationSeverity::Info,
                    'title' => $title,
                    'emailMessage' => $body,
                    'pushoverMessage' => $body,
                    'actionUrl' => null,
                    'actionLabel' => null,
                    'priority' => 0,
                ];
            })(),

            'system_health_alert' => (function () use ($context) {
                $signal = (string) ($context['signal'] ?? 'unknown_signal');
                $severityValue = (string) ($context['severity'] ?? 'high');
                $title = (string) ($context['title'] ?? 'System health alert');
                $detail = (string) ($context['detail'] ?? '(no detail)');
                $detectedAt = (string) ($context['detected_at'] ?? '');

                $severityEnum = match (mb_strtolower($severityValue)) {
                    'critical' => NotificationSeverity::Critical,
                    'medium' => NotificationSeverity::Medium,
                    default => NotificationSeverity::High,
                };

                $emailBody = "{$title}\n\n{$detail}";
                if ($detectedAt !== '') {
                    $emailBody .= "\n\nDetected at: {$detectedAt}";
                }
                $emailBody .= "\nSignal: {$signal}";

                return [
                    'severity' => $severityEnum,
                    'title' => $title,
                    'emailMessage' => $emailBody,
                    'pushoverMessage' => "[{$severityValue}] {$title}\n{$detail}",
                    'actionUrl' => null,
                    'actionLabel' => null,
                    'priority' => 0,
                ];
            })(),

            'binance_user_data_account_connected' => (function () use ($context) {
                $accountId = (string) ($context['account_id'] ?? 'unknown');
                $accountName = (string) ($context['account_name'] ?? 'unknown');
                $body = "User-data WebSocket opened for `{$accountName}` (account #{$accountId}).";

                return [
                    'severity' => NotificationSeverity::Info,
                    'title' => "Binance user-data stream connected — {$accountName}",
                    'emailMessage' => $body,
                    'pushoverMessage' => $body,
                    'actionUrl' => null,
                    'actionLabel' => null,
                    'priority' => -1,
                ];
            })(),

            'binance_user_data_account_init_failed' => (function () use ($context) {
                $accountId = (string) ($context['account_id'] ?? 'unknown');
                $accountName = (string) ($context['account_name'] ?? 'unknown');
                $error = (string) ($context['error'] ?? '(no detail)');
                $body = "Could not initialise the user-data WebSocket for `{$accountName}` (account #{$accountId}).\n\nReason: {$error}\n\nOther accounts on this daemon stay alive; the failing account is retried on the next 60-second discovery sweep.";

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => "Binance user-data init failed — {$accountName}",
                    'emailMessage' => $body,
                    'pushoverMessage' => $body,
                    'actionUrl' => null,
                    'actionLabel' => null,
                    'priority' => 1,
                ];
            })(),

            'binance_user_data_listen_key_expired' => (function () use ($context) {
                $accountId = (string) ($context['account_id'] ?? 'unknown');
                $accountName = (string) ($context['account_name'] ?? 'unknown');
                $body = "Binance pushed a `listenKeyExpired` frame for `{$accountName}` (account #{$accountId}). The daemon is re-initialising the WebSocket automatically. Frequent firings on the same account suggest aggressive Binance-side key invalidation — investigate the API-key configuration.";

                return [
                    'severity' => NotificationSeverity::Medium,
                    'title' => "Binance listenKey expired — {$accountName}",
                    'emailMessage' => $body,
                    'pushoverMessage' => $body,
                    'actionUrl' => null,
                    'actionLabel' => null,
                    'priority' => 0,
                ];
            })(),

            'binance_user_data_account_reaped' => (function () use ($context) {
                $accountId = (string) ($context['account_id'] ?? 'unknown');
                $body = "User-data daemon reaped account #{$accountId} — the account is no longer eligible for the user-data stream (deleted, deactivated, or API keys removed). The per-account WebSocket has been closed and the listenKey row deleted.";

                return [
                    'severity' => NotificationSeverity::Info,
                    'title' => "Binance user-data account reaped — #{$accountId}",
                    'emailMessage' => $body,
                    'pushoverMessage' => $body,
                    'actionUrl' => null,
                    'actionLabel' => null,
                    'priority' => -1,
                ];
            })(),

            'binance_user_data_memory_restart' => (function () use ($context) {
                $memoryBytes = (int) ($context['memory_bytes'] ?? 0);
                $limitBytes = (int) ($context['limit_bytes'] ?? 0);
                $pid = (string) ($context['pid'] ?? 'unknown');
                $memoryMb = number_format($memoryBytes / 1048576, 1);
                $limitMb = number_format($limitBytes / 1048576, 1);
                $body = "User-data daemon (pid {$pid}) exceeded its memory ceiling and is exiting for supervisor restart.\n\nProcess memory: {$memoryMb} MB\nLimit: {$limitMb} MB\n\nSupervisor will respawn the daemon and re-initialise every account.";

                return [
                    'severity' => NotificationSeverity::Medium,
                    'title' => 'Binance user-data daemon — memory restart',
                    'emailMessage' => $body,
                    'pushoverMessage' => $body,
                    'actionUrl' => null,
                    'actionLabel' => null,
                    'priority' => 0,
                ];
            })(),

            'binance_listen_key_keepalive_failed' => (function () use ($context) {
                $accountId = (string) ($context['account_id'] ?? 'unknown');
                $accountName = (string) ($context['account_name'] ?? 'unknown');
                $failureCount = (int) ($context['failure_count'] ?? 0);
                $error = (string) ($context['error'] ?? '(no detail)');
                $body = "Three consecutive listenKey keepalive failures for `{$accountName}` (account #{$accountId}). Without a successful refresh the listenKey will hit Binance's 60-minute hard expiry and the user-data WebSocket will die.\n\nFailure count: {$failureCount}\nLast error: {$error}";

                return [
                    'severity' => NotificationSeverity::High,
                    'title' => "Binance listenKey keepalive failed — {$accountName}",
                    'emailMessage' => $body,
                    'pushoverMessage' => $body,
                    'actionUrl' => null,
                    'actionLabel' => null,
                    'priority' => 1,
                ];
            })(),

            // Fail loud on unknown canonicals. Previously this returned a
            // generic placeholder notification — meaning a typo at a call
            // site shipped an incoherent live notification with no runtime
            // error, masking the bug. Better to throw at build time so the
            // dispatcher logs/notifies at the call site (which knows the
            // canonical) rather than ship junk to admin/user.
            default => throw new InvalidArgumentException(
                "NotificationMessageBuilder: unknown canonical `{$canonicalString}` — add a match arm or fix the call site."
            ),
        };
    }

    /**
     * Get the API management URL for an exchange.
     */
    private static function getApiManagementUrl(string $exchange): ?string
    {
        return match (mb_strtolower($exchange)) {
            'binance' => 'https://www.binance.com/en/my/settings/api-management',
            'bybit' => 'https://www.bybit.com/app/user/api-management',
            default => null,
        };
    }

    /**
     * Get the exchange status/announcement URL.
     */
    private static function getExchangeStatusUrl(string $exchange): ?string
    {
        return match (mb_strtolower($exchange)) {
            'binance' => 'https://www.binance.com/en/support/announcement/system',
            'bybit' => 'https://www.bybit.com/en/announcement-info?category=latest_activities',
            default => null,
        };
    }

    /**
     * Get the exchange futures trading URL (for login/position management).
     */
    private static function getExchangeFuturesUrl(string $exchange): ?string
    {
        return match (mb_strtolower($exchange)) {
            'binance' => 'https://www.binance.com/en/futures',
            'bybit' => 'https://www.bybit.com/trade/usdt',
            default => null,
        };
    }
}
