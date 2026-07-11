<?php

declare(strict_types=1);

namespace Kraite\Core\Policies;

use Kraite\Core\Models\Account;
use Kraite\Core\Models\User;

/**
 * AccountPolicy
 *
 * Authorizes operations against a specific Account. The connectivity
 * endpoints touch the account's exchange credentials, fire whitelist
 * notifications to its owner, and leak per-server topology/error
 * detail — so "authenticated" is not enough; the caller must own the
 * account or be an admin.
 */
final class AccountPolicy
{
    /**
     * Operate on an account's workflows (connectivity checks,
     * owner-facing notifications, workflow status).
     */
    public function operate(User $user, Account $account): bool
    {
        return $user->is_admin || $account->user_id === $user->id;
    }
}
