<?php

declare(strict_types=1);

namespace App\Wallet\Application\Support;

/** Story 49.1: an event as Minimapperz's organiser API describes it. */
final readonly class MinimapperzEvent
{
    public function __construct(
        public string $slug,
        public string $name,
        public \DateTimeImmutable $opensAt,
        public \DateTimeImmutable $closesAt,
        public bool $isOpen,
    ) {
    }
}
