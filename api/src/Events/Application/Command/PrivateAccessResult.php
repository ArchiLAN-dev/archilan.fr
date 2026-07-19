<?php

declare(strict_types=1);

namespace App\Events\Application\Command;

final readonly class PrivateAccessResult
{
    public function __construct(
        public bool $granted,
    ) {
    }
}
