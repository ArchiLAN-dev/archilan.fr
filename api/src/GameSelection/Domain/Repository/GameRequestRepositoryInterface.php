<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Repository;

use App\GameSelection\Domain\Entity\GameRequest;

interface GameRequestRepositoryInterface
{
    public function findByNormalizedNameAndUserId(string $normalizedName, string $userId): ?GameRequest;

    public function save(GameRequest $request): void;

    public function remove(GameRequest $request): void;
}
