<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

/** Where the member's browser goes back to the blind test app, with their one-time code (story 41.45). */
final readonly class BlindTestLoginRedirect
{
    public function __construct(
        public string $url,
    ) {
    }
}
