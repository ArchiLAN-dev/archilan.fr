<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

/** The outcome of {@see BuyHintWithPelles}: which pelles paid, how many, and what is left of them. */
final readonly class PelleHintPurchase
{
    public function __construct(
        public string $paidWith,
        public int $price,
        public int $balanceAfter,
        public bool $alreadyBought,
    ) {
    }
}
