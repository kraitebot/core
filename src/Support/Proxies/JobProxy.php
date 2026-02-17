<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Proxies;

use Kraite\Core\Models\Account;

/**
 * JobProxy
 *
 * Resolves job classes to exchange-specific implementations.
 * Falls back to default class if no exchange-specific override exists.
 *
 * Usage:
 * ```php
 * $resolver = JobProxy::with($account);
 * $class = $resolver->resolve(Lifecycle\Account\VerifyMinAccountBalanceJob::class);
 * // Returns exchange-specific FQCN if exists, otherwise default FQCN
 * ```
 */
final class JobProxy
{
    private const JOBS_NAMESPACE = 'Kraite\\Core\\Jobs\\';

    private Account $account;

    private string $exchangeCanonical;

    private function __construct(Account $account)
    {
        $this->account = $account;
        $this->exchangeCanonical = $account->apiSystem->canonical;
    }

    /**
     * Create a new JobProxy for the given account.
     */
    public static function with(Account $account): self
    {
        return new self($account);
    }

    /**
     * Resolve a job class to its exchange-specific implementation.
     *
     * @param  string  $jobClass  The default job class FQCN
     * @return string The resolved FQCN (exchange-specific if exists, otherwise default)
     */
    public function resolve(string $jobClass): string
    {
        // Check if the class is in the Jobs namespace
        if (! str_starts_with($jobClass, self::JOBS_NAMESPACE)) {
            // Not a Jobs class, return as-is
            return $jobClass;
        }

        // Extract path after Jobs\ namespace
        // e.g., "Lifecycle\Account\VerifyMinAccountBalanceJob"
        $relativePath = mb_substr($jobClass, mb_strlen(self::JOBS_NAMESPACE));

        // Split into parts
        $parts = explode('\\', $relativePath);

        // Need at least 2 parts: [Type, ClassName] or [Type, Model, ClassName]
        if (count($parts) < 2) {
            return $jobClass;
        }

        // Get the class name (last part)
        $className = array_pop($parts);

        // Insert exchange canonical before class name (e.g., Binance, Bybit)
        $exchangeNamespace = ucfirst($this->exchangeCanonical);
        $parts[] = $exchangeNamespace;
        $parts[] = $className;

        // Build the exchange-specific FQCN
        $exchangeSpecificClass = self::JOBS_NAMESPACE.implode('\\', $parts);

        // Return exchange-specific if exists, otherwise default
        if (class_exists($exchangeSpecificClass)) {
            return $exchangeSpecificClass;
        }

        return $jobClass;
    }

    /**
     * Get the account associated with this proxy.
     */
    public function account(): Account
    {
        return $this->account;
    }

    /**
     * Get the exchange canonical name.
     */
    public function exchangeCanonical(): string
    {
        return $this->exchangeCanonical;
    }
}
