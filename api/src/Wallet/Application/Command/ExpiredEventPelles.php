<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

/** The outcome of {@see ExpireEventPelles}: members whose event pelles ended, gold created, pelles destroyed. */
final readonly class ExpiredEventPelles
{
    public function __construct(
        public int $members,
        public int $converted,
        public int $destroyed,
    ) {
    }
}
