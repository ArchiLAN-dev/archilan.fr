<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

/** A quest just written (story 41.15). */
final readonly class WrittenQuest
{
    public function __construct(public string $id)
    {
    }
}
