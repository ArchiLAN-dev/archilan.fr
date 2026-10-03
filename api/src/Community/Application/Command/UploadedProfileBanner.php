<?php

declare(strict_types=1);

namespace App\Community\Application\Command;

/** The banner an admin just uploaded (story 41.11). */
final readonly class UploadedProfileBanner
{
    public function __construct(public string $key)
    {
    }
}
