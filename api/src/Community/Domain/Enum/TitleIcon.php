<?php

declare(strict_types=1);

namespace App\Community\Domain\Enum;

/** The icon a profile title may wear before its label (story 41.27), drawn by the site. */
enum TitleIcon: string
{
    case Crown = 'crown';
    case Star = 'star';
    case Sword = 'sword';
    case Gem = 'gem';
    case Shield = 'shield';
    case Flame = 'flame';
    case Trophy = 'trophy';
    case Bolt = 'bolt';
}
