<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Enum;

enum HintStatus: int
{
    case Unspecified = 0;
    case NoPriority  = 10;
    case Avoid       = 20;
    case Priority    = 30;
    case Found       = 40;

    public function label(): string
    {
        return match ($this) {
            self::Unspecified => 'unspecified',
            self::NoPriority  => 'no_priority',
            self::Avoid       => 'avoid',
            self::Priority    => 'priority',
            self::Found       => 'found',
        };
    }

    public function isFound(): bool
    {
        return $this === self::Found;
    }
}
