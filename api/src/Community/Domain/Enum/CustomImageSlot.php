<?php

declare(strict_types=1);

namespace App\Community\Domain\Enum;

/** Where an uploaded profile image goes (story 30.40). */
enum CustomImageSlot
{
    case Avatar;
    case Banner;
}
