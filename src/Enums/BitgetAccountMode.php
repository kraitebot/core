<?php

declare(strict_types=1);

namespace Kraite\Core\Enums;

/**
 * BitGet locks the API surface by account mode: classic accounts must use
 * the v2 mix API, unified (UTA) accounts must use the v3 API. The mode is
 * detected once per account (v2 private probe: error 40085 means unified)
 * and persisted on `accounts.bitget_account_mode`.
 */
enum BitgetAccountMode: string
{
    case Classic = 'classic';

    case Unified = 'unified';
}
