<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\ApiSystem\Kucoin;

use Kraite\Core\Jobs\Lifecycles\ApiSystem\PerSymbolSyncLeverageBracketsJob;

/**
 * KuCoin requires the symbol in its risk-limit endpoint path.
 */
final class SyncLeverageBracketsJob extends PerSymbolSyncLeverageBracketsJob {}
