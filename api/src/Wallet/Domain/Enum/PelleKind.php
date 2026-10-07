<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Enum;

/** Story 41.1: gold pelles are kept forever, event pelles belong to one event. */
enum PelleKind: string
{
    case Gold = 'gold';
    case Event = 'event';
}
