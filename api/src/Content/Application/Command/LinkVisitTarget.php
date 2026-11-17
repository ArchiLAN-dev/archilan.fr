<?php

declare(strict_types=1);

namespace App\Content\Application\Command;

/** Where a tracked link sends its visitor (story 55.1): a path of the site, or an https address. */
final readonly class LinkVisitTarget
{
    public function __construct(public string $target)
    {
    }
}
