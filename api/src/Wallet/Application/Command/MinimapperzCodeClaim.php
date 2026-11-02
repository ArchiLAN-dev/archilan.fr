<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

/** Story 49.2: a registrant's Minimapperz code, and where to type it. */
final readonly class MinimapperzCodeClaim
{
    public function __construct(
        public string $code,
        public string $lanUrl,
    ) {
    }
}
