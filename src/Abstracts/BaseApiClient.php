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
use Kraite\Core\Support\HeaderSanitizer;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiRequest;
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

    public function __construct(string $baseURL, ?ApiCredentials $credentials = null)
    {
        $this->baseURL = $baseURL;
        $this->credentials = $credentials;
        $this->buildClient();
    }

    abstract protected function getHeaders(): array;

    protected function processRequest(ApiRequest $apiRequest, bool $sendAsJson = false)
    {
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

            $this->recordSuccessfulResponse($response, $logData, $startTime);

            // Check for API-level errors in HTTP 200 responses (e.g., Bybit returns errors in body)
            if ($this->exceptionHandler && $response->getStatusCode() === 200) {
                $request = new Request($apiRequest->method, $this->baseURL.$apiRequest->path);
                $this->exceptionHandler->shouldThrowExceptionFromHTTP200($response, $request);
            }

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
            $this->httpRequest = new Client([
                'base_uri' => $this->baseURL,
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

    protected function recordSuccessfulResponse($response, array &$logData, float $startTime): void
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

        $this->updateRequestLogData($logData);

        if ($this->exceptionHandler) {
            $this->exceptionHandler->recordResponseHeaders($response);
        }
    }

    protected function executeHttpRequest(string $method, string $path, array $options)
    {
        if (app()->environment('testing')) {
            $url = $this->baseURL.'/'.$path;
            $headers = $options['headers'] ?? [];

            if ($method === 'GET') {
                return Http::withHeaders($headers)->get($url, $options['query'] ?? [])->throw()->toPsrResponse();
            }
            if ($method === 'POST') {
                $body = $options['json'] ?? $options['query'] ?? [];

                return Http::withHeaders($headers)->post($url, $body)->throw()->toPsrResponse();
            }
            if ($method === 'PUT') {
                $body = $options['json'] ?? $options['query'] ?? [];

                return Http::withHeaders($headers)->put($url, $body)->throw()->toPsrResponse();
            }
            if ($method === 'DELETE') {
                return Http::withHeaders($headers)->delete($url, $options['query'] ?? [])->throw()->toPsrResponse();
            }
        }

        return $this->httpRequest->request($method, $path, $options);
    }

    protected function handleRequestException(RequestException $e, ApiRequest $apiRequest, array $options, array &$logData, float $startTime)
    {
        $logData['http_response_code'] = $e->getResponse() ? $e->getResponse()->getStatusCode() : null;
        $logData['response'] = $e->getResponse() ? json_decode((string) $e->getResponse()->getBody(), associative: true) : null;
        $logData['http_headers_returned'] = $e->getResponse() ? $e->getResponse()->getHeaders() : null;

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

    protected function retryRequest(ApiRequest $apiRequest, array $options, array &$logData, float $startTime)
    {
        $delay = $this->getRetryDelay();
        Sleep::for($delay)->seconds();

        try {
            $response = $this->executeHttpRequest(
                $apiRequest->method,
                $apiRequest->path,
                $options
            );

            $this->recordSuccessfulResponse($response, $logData, $startTime);

            return $response;
        } catch (Throwable $retryException) {
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
}
