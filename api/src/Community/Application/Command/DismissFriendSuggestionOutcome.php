<?php

declare(strict_types=1);

namespace App\Community\Application\Command;

enum DismissFriendSuggestionOutcome
{
    case Dismissed;

    /** No listable member behind the slug, or the user themself. */
    case NotFound;
}
