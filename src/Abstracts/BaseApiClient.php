<?php

declare(strict_types=1);

namespace Kraite\Core\Abstracts;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Kraite\Core\Models\ApiRequestLog;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\FreezeMode;
use Kraite\Core\Support\HeaderSanitizer;
use Kraite\Core\Support\Logging\ApiRequestLogRetention;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiRequest;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/*
 * BaseApiClient
 *
 * • Abstract base class for API clients using Guzzle HTTP.
 * • Handles authenticated and unauthenticated requests with logging.
 * • Logs API requests and responses in the `api_request_logs` table.
 * • Measures and stores duration, headers, and status codes.
 * • Supports optional relational linking via relatable models.
 * • Handles JSON or query-based payloads depending on request type.
 * • Captures and rethrows exceptions for higher-level handling (e.g. jobs).
 * • Subclasses must implement `getHeaders()` to inject required auth headers.
 */
abstract class BaseApiClient
{
    protected string $baseURL;

    protected ?ApiCredentials $credentials = null;

    protected ?Client $httpRequest = null;

    protected ?ApiRequestLog $apiRequestLog = null;

    protected ?ApiSystem $apiSystem = null;

    protected ?BaseExceptionHandler $exceptionHandler = null;

    /**
     * Total request timeout in seconds. Subclasses can override per
     * exchange/endpoint class. 30s default — long enough for a slow
     * legitimate exchange response, short enough to free a worker
     * within a single dispatcher tick when a socket wedges.
     */
    protected ?int $httpTimeout = null;

    /**
     * TCP connect timeout in seconds. 10s default — exchange API
     * gateways resolve+connect well under this window in healthy state;
     * slower than this typically indicates DNS/firewall trouble that
     * deserves to escape to the job layer.
     */
    protected ?int $httpConnectTimeout = null;

    public function __construct(string $baseURL, ?ApiCredentials $credentials = null)
    {
        $this->baseURL = $baseURL;
        $this->credentials = $credentials;
        $this->buildClient();
    }

    abstract protected function getHeaders(): array;

    protected function processRequest(ApiRequest $apiRequest, bool $sendAsJson = false): ResponseInterface
    {
        FreezeMode::assertExternalTrafficAllowed('Exchange/API request');

        $headers = array_merge($this->getHeaders(), (array) ($apiRequest->properties->getOr('headers', [])));

        $logData = $this->prepareLogData($apiRequest, $headers);
        $options = $this->prepareRequestOptions($apiRequest, $sendAsJson, $headers);

        $startTime = microtime(true);
        $logData['started_at'] = now();

        $this->apiRequestLog = ApiRequestLog::create($logData);

        try {
            $response = $this->executeHttpRequest(
                $apiRequest->method,
                $apiRequest->path,
                $options
            );

            $this->throwForApiErrorResponse($response, $apiRequest);

            $this->recordSuccessfulResponse($response, $logData, $startTime);

            return $response;
        } catch (RequestException $e) {
            return $this->handleRequestException($e, $apiRequest, $options, $logData, $startTime);
        } catch (Throwable $e) {
            $this->updateRequestLogData([
                'error_message' => $e->getMessage().' (line '.$e->getLine().')',
            ]);

            throw $e;
        }
    }

    protected function buildClient()
    {
        // Use Laravel's service container to resolve Guzzle client if available
        // This allows tests to inject mock clients
        if (app()->bound(Client::class)) {
            $this->httpRequest = app(Client::class);
        } else {
            // Explicit Guzzle timeouts. Pre-fix, the client was constructed
            // with no `timeout` / `connect_timeout`, so a stuck exchange
            // socket could pin a worker indefinitely (BaseQueueableJob
            // intentionally sets job timeout=0 and relies on Horizon's
            // supervisor timeout — which is far coarser than per-request
            // bounds). Under exchange degradation across many accounts,
            // unbounded sockets pin the entire fleet on outbound calls
            // and delay protective cancels/closes. The values below are
            // intentionally conservative — long enough that a slow but
            // legitimate exchange response completes, short enough that
            // a wedged socket releases the worker within a single
            // dispatcher tick window. Both can be overridden per-class
            // via $this->httpTimeout / $this->httpConnectTimeout if a
            // specific exchange needs a different bound.
            $this->httpRequest = new Client([
                'base_uri' => $this->baseURL,
                'timeout' => $this->httpTimeout ?? 30,
                'connect_timeout' => $this->httpConnectTimeout ?? 10,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept-Encoding' => 'application/json',
                    'User-Agent' => 'api-client-php',
                ],
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ]);
        }
    }

    protected function updateRequestLogData(array $logData)
    {
        $this->apiRequestLog->update($logData);
    }

    protected function buildQuery(string $path, array $properties = []): string
    {
        return count($properties) === 0 ? $path : $path.'?'.http_build_query($properties);
    }

    protected function prepareLogData(ApiRequest $apiRequest, array $headers): array
    {
        $properties = $apiRequest->properties->toArray();

        $logData = [
            'path' => $apiRequest->path,
            'payload' => $properties,
            'http_method' => $apiRequest->method,
            // Auth headers (ACCESS-KEY, ACCESS-PASSPHRASE, X-MBX-APIKEY,
            // KC-API-KEY, etc.) carry full credentials in plaintext —
            // redacted before persistence to api_request_logs.
            'http_headers_sent' => HeaderSanitizer::sanitize($headers),
            'hostname' => gethostname(),
            'debug_data' => $apiRequest->properties->getOr('debug', []),
            'api_system_id' => $this->apiSystem->id,
        ];

        $relatable = $apiRequest->properties->getOr('relatable', null);
        if ($relatable) {
            $logData['relatable_id'] = $relatable->getKey();
            $logData['relatable_type'] = get_class($relatable);
        }

        $account = $apiRequest->properties->getOr('account', null);
        if ($account && $account->id) {
            $logData['account_id'] = $account->id;
        }

        return $logData;
    }

    protected function prepareRequestOptions(ApiRequest $apiRequest, bool $sendAsJson, array $headers): array
    {
        $properties = $apiRequest->properties->toArray();

        $options = [
            'headers' => $headers,
        ];

        if ($sendAsJson && mb_strtoupper($apiRequest->method) !== 'GET') {
            $bodyPayload = $properties;
            unset($bodyPayload['headers']);

            $options['json'] = $bodyPayload;
        } else {
            // Check for pre-serialized body (used by Bitget, KuCoin for signed POST requests)
            $body = $apiRequest->properties->getOr('body', null);
            if ($body !== null && mb_strtoupper($apiRequest->method) !== 'GET') {
                $options['body'] = $body;
            }

            // Only set query if there are actual options to send.
            // Setting query => [] causes Guzzle to strip query params from the path.
            $queryOptions = $apiRequest->properties->getOr('options', []);
            if (! empty($queryOptions) && mb_strtoupper($apiRequest->method) === 'GET') {
                $options['query'] = $queryOptions;
            }
        }

        return $options;
    }

    protected function recordSuccessfulResponse(ResponseInterface $response, array &$logData, float $startTime): void
    {
        $endTime = microtime(true);
        $logData['completed_at'] = now();
        $logData['duration'] = max(0, (int) (($endTime - $startTime) * 1000)); // Ensure non-negative
        $logData['http_response_code'] = $response->getStatusCode();
        $logData['response'] = json_decode((string) $response->getBody(), associative: true);
        $logData['http_headers_returned'] = $response->getHeaders();

        // Drop the request payload once the call is confirmed successful.
        // The payload column dominates row size (~70% on average) and successful
        // responses provide no forensic value — failures, retries, and 4xx/5xx
        // paths still keep the original payload because they take a different
        // code path (handleRequestException / retryRequest) that does not null
        // it out.
        $logData['payload'] = null;

        // Same reasoning, applied to what remained. On a successful call the
        // response body averages 11.5 KB — 92% of the row — and a fast 200 has
        // never been the thing anyone debugged an incident from. Failures and
        // slow calls keep everything; see ApiRequestLogRetention.
        $logData = ApiRequestLogRetention::apply($logData);

        $this->updateRequestLogData($logData);

        if ($this->exceptionHandler) {
            $this->exceptionHandler->recordResponseHeaders($response);
        }

        $this->selfHealUserFixableBans($logData);
    }

    protected function executeHttpRequest(string $method, string $path, array $options): ResponseInterface
    {
        if (app()->environment('testing')) {
            $url = mb_rtrim($this->baseURL, '/').'/'.mb_ltrim($path, '/');
            $headers = $options['headers'] ?? [];

            if ($method === 'GET') {
                $request = Http::withHeaders($headers);
                $query = $options['query'] ?? null;

                return ($query === null ? $request->get($url) : $request->get($url, $query))
                    ->throw()
                    ->toPsrResponse();
            }
            if ($method === 'POST') {
                $body = $this->testingRequestBody($options);

                return Http::withHeaders($headers)->post($url, $body)->throw()->toPsrResponse();
            }
            if ($method === 'PUT') {
                $body = $this->testingRequestBody($options);

                return Http::withHeaders($headers)->put($url, $body)->throw()->toPsrResponse();
            }
            if ($method === 'DELETE') {
                return Http::withHeaders($headers)->delete($url, $this->testingRequestBody($options))->throw()->toPsrResponse();
            }
        }

        return $this->httpRequest->request($method, $path, $options);
    }

    /** @return array<string, mixed> */
    private function testingRequestBody(array $options): array
    {
        if (isset($options['json']) && is_array($options['json'])) {
            return $options['json'];
        }

        if (isset($options['body']) && is_string($options['body'])) {
            $decoded = json_decode($options['body'], associative: true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        }

        return isset($options['query']) && is_array($options['query']) ? $options['query'] : [];
    }

    protected function handleRequestException(RequestException $e, ApiRequest $apiRequest, array $options, array &$logData, float $startTime): ResponseInterface
    {
        $this->captureFailedResponse($e, $logData);

        if ($this->shouldRetryRequest($e)) {
            return $this->retryRequest($apiRequest, $options, $logData, $startTime);
        }

        $logData['completed_at'] = now();
        $this->updateRequestLogData($logData);
        throw $e;
    }

    protected function shouldRetryRequest(RequestException $e): bool
    {
        return $this->exceptionHandler && $this->exceptionHandler->retryException($e);
    }

    protected function retryRequest(ApiRequest $apiRequest, array $options, array &$logData, float $startTime): ResponseInterface
    {
        $delay = $this->getRetryDelay();
        Sleep::for($delay)->seconds();

        try {
            $response = $this->executeHttpRequest(
                $apiRequest->method,
                $apiRequest->path,
                $options
            );

            $this->throwForApiErrorResponse($response, $apiRequest);
            $this->recordSuccessfulResponse($response, $logData, $startTime);

            return $response;
        } catch (Throwable $retryException) {
            if ($retryException instanceof RequestException) {
                $this->captureFailedResponse($retryException, $logData);
            }

            $logData['completed_at'] = now();
            $this->updateRequestLogData($logData);
            throw $retryException;
        }
    }

    protected function getRetryDelay(): int
    {
        if (property_exists($this->exceptionHandler, 'backoffSeconds') && is_int($this->exceptionHandler->backoffSeconds)) {
            return $this->exceptionHandler->backoffSeconds;
        }

        return 5;
    }

    private function captureFailedResponse(RequestException $exception, array &$logData): void
    {
        $response = $exception->getResponse();

        $logData['http_response_code'] = $response?->getStatusCode();
        $logData['response'] = $response === null
            ? null
            : json_decode((string) $response->getBody(), associative: true);
        $logData['http_headers_returned'] = $response?->getHeaders();
    }

    private function throwForApiErrorResponse(ResponseInterface $response, ApiRequest $apiRequest): void
    {
        if ($this->exceptionHandler === null || $response->getStatusCode() !== 200) {
            return;
        }

        $request = new Request($apiRequest->method, $this->baseURL.$apiRequest->path);
        $this->exceptionHandler->shouldThrowExceptionFromHTTP200($response, $request);
    }

    /**
     * Self-heal user-fixable ForbiddenHostname rows.
     *
     * A successful authenticated response from this account+IP+exchange
     * is positive proof that the credential/whitelist problem the row
     * was reporting is no longer real. The pre-flight gate in
     * BaseApiableJob keys off `forbidden_until IS NULL OR > now()`, so
     * any user-fixable row left behind after the user fixes their API
     * key whitelist would block every subsequent step indefinitely —
     * the exact failure mode that knocked out account #1 on 2026-05-12.
     *
     * Scope is intentionally narrow:
     *  - Only types the user can self-resolve (`ip_not_whitelisted`,
     *    `account_blocked`). `ip_rate_limited` auto-recovers via
     *    `forbidden_until`; `ip_banned` is exchange-side and a single
     *    successful call says nothing about a global IP ban.
     *  - Only the exact (account_id, api_system_id, ip_address) tuple
     *    that just succeeded — the success cannot vouch for sibling
     *    accounts or other servers.
     */
    private function selfHealUserFixableBans(array $logData): void
    {
        $accountId = $logData['account_id'] ?? null;

        if ($accountId === null) {
            return;
        }

        if ($this->apiSystem === null) {
            return;
        }

        $bans = ForbiddenHostname::query()
            ->where('account_id', $accountId)
            ->where('api_system_id', $this->apiSystem->id)
            ->where('ip_address', Kraite::ip())
            ->whereIn('type', [
                ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
                ForbiddenHostname::TYPE_ACCOUNT_BLOCKED,
            ])
            ->get();

        foreach ($bans as $ban) {
            $ban->delete();
        }
    }
}
