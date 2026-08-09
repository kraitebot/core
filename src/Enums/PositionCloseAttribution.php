<?php

declare(strict_types=1);

namespace Kraite\Core\Enums;

enum PositionCloseAttribution
{
    case External;
    case Kraite;
    case Forced;
    case Unknown;
}
