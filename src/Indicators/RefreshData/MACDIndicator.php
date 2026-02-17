<?php

declare(strict_types=1);

namespace Kraite\Core\Indicators\RefreshData;

use Kraite\Core\Abstracts\BaseIndicator;
use Kraite\Core\Contracts\Indicators\DirectionIndicator;

final class MACDIndicator extends BaseIndicator implements DirectionIndicator
{
    public string $endpoint = 'macd';

    public function conclusion(): ?string
    {
        return $this->direction();
    }

    public function direction(): ?string
    {
        $macd = $this->data['valueMACD'] ?? null;
        $macdSignal = $this->data['valueMACDSignal'] ?? null;
        $macdHist = $this->data['valueMACDHist'] ?? null;

        if ($macd && $macdSignal && $macdHist && count($macd) > 1 && count($macdSignal) > 1 && count($macdHist) > 1) {
            if ($macd[1] > $macdSignal[1] && $macdHist[1] > 0) {
                return 'LONG';
            }

            if ($macd[1] < $macdSignal[1] && $macdHist[1] < 0) {
                return 'SHORT';
            }
        }

        return null;
    }
}
