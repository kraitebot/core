<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Connectivity;

use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\RequestException as HttpClientRequestException;
use Kraite\Core\Enums\BitgetAccountMode;
use Kraite\Core\Models\Account;

/**
 * Detects whether a BitGet account runs in classic or unified (UTA) mode.
 *
 * BitGet rejects any classic (v2) private call from a unified account with
 * error 40085 BEFORE validating credentials, which makes a cheap v2 probe a
 * reliable mode discriminator. Any other failure (bad key, IP not
 * whitelisted, …) is rethrown so the caller surfaces the real problem —
 * mode detection must never mask a connectivity error.
 *
 * The API client raises Guzzle's RequestException in production and the
 * Laravel HTTP client's RequestException under the testing transport, so
 * both are treated as the probe answer.
 */
final class BitgetAccountModeDetector
{
    private const UNIFIED_ACCOUNT_ERROR_CODE = '40085';

    public function detect(Account $account): BitgetAccountMode
    {
        try {
            $account->withApi()->getClassicAccountInfo();
        } catch (GuzzleRequestException|HttpClientRequestException $exception) {
            if ($this->isUnifiedAccountError($exception)) {
                return BitgetAccountMode::Unified;
            }

            throw $exception;
        }

        return BitgetAccountMode::Classic;
    }

    private function isUnifiedAccountError(GuzzleRequestException|HttpClientRequestException $exception): bool
    {
        $body = $exception instanceof GuzzleRequestException
            ? (string) $exception->getResponse()?->getBody()
            : $exception->response->body();

        $payload = json_decode($body, associative: true);

        return is_array($payload)
            && (string) ($payload['code'] ?? '') === self::UNIFIED_ACCOUNT_ERROR_CODE;
    }
}
