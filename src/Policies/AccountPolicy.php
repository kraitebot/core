<?php

declare(strict_types=1);

namespace Kraite\Core\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Kraite\Core\Models\Account;

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
     *
     * Typed against the auth contract, not the core User model: consuming
     * apps (admin) authenticate their OWN User class against the shared
     * users table — a concrete core-User hint made the Gate throw a
     * TypeError for every such caller instead of authorizing them.
     */
    public function operate(Authenticatable $user, Account $account): bool
    {
        return (bool) ($user->is_admin ?? false) || $account->user_id === $user->getAuthIdentifier();
    }
}
