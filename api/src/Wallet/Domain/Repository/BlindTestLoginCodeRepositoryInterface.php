<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Repository;

use App\Wallet\Domain\Entity\BlindTestLoginCode;

interface BlindTestLoginCodeRepositoryInterface
{
    public function find(string $codeHash): ?BlindTestLoginCode;

    public function save(BlindTestLoginCode $code): void;

    public function remove(BlindTestLoginCode $code): void;

    /** Codes nobody exchanged in time: deleted whenever a new one is made. */
    public function removeExpired(\DateTimeImmutable $now): void;
}
