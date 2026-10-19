<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Enum;

/**
 * Story 41.1: gold pelles are kept forever, event pelles belong to one event. Story 41.36: bronze pelles belong to
 * one private run and burn with it.
 */
enum PelleKind: string
{
    case Gold = 'gold';
    case Event = 'event';
    case Bronze = 'bronze';
}
