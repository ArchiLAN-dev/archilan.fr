<?php

declare(strict_types=1);

namespace App\GameSelection\Domain;

interface GameRequestRepositoryInterface
{
    public function findByNormalizedNameAndUserId(string $normalizedName, string $userId): ?GameRequest;

    public function save(GameRequest $request): void;

    public function remove(GameRequest $request): void;
}
