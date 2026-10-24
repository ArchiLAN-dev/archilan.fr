<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

/**
 * What a credit of the blind test did (story 41.40): the pelles it gave (none when the key was already credited) and
 * the member's event balance after it.
 */
final readonly class BlindTestCredit
{
    public function __construct(
        public int $credited,
        public int $balance,
        public bool $duplicate,
    ) {
    }
}
