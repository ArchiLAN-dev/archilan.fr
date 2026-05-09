<?php

declare(strict_types=1);

namespace App\Streaming\Domain;

final readonly class StreamStatus
{
    public function __construct(
        public bool $live,
        public ?int $viewerCount,
    ) {
    }

    public static function offline(): self
    {
        return new self(live: false, viewerCount: null);
    }

    public static function live(int $viewerCount): self
    {
        return new self(live: true, viewerCount: $viewerCount);
    }
}
