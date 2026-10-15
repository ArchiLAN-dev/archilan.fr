<?php

declare(strict_types=1);

namespace App\Events\Application\Command;

final readonly class PrivateAccessResult
{
    public function __construct(
        public bool $granted,
        /** Story 4.24: too many refused codes this hour; the code was not even checked. */
        public bool $limited = false,
    ) {
    }
}
