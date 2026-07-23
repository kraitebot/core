<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\ApiSystem\Bitget;

use Kraite\Core\Jobs\Lifecycles\ApiSystem\PerSymbolSyncLeverageBracketsJob;

/**
 * Bitget requires the symbol on its position-tier endpoint.
 */
final class SyncLeverageBracketsJob extends PerSymbolSyncLeverageBracketsJob {}
