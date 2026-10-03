<?php

declare(strict_types=1);

namespace App\Community\Application\Command;

/** The frame {@see ManageAvatarFrames::upload()} added. */
final readonly class UploadedAvatarFrame
{
    public function __construct(public string $key)
    {
    }
}
