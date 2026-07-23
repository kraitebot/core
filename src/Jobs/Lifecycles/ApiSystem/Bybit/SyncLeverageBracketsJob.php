<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\ApiSystem\Bybit;

use Kraite\Core\Jobs\Lifecycles\ApiSystem\PerSymbolSyncLeverageBracketsJob;

/**
 * Bybit requires per-symbol requests to avoid its partial unfiltered response.
 */
final class SyncLeverageBracketsJob extends PerSymbolSyncLeverageBracketsJob {}
