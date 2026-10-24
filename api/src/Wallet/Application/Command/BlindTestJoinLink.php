<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

/** The signed address a member opens to play the blind test (story 41.40). */
final readonly class BlindTestJoinLink
{
    public function __construct(
        public string $url,
    ) {
    }
}
