<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

use App\Community\Domain\Entity\AvatarFrameDefinition;

interface AvatarFrameDefinitionRepositoryInterface
{
    public function find(string $key): ?AvatarFrameDefinition;

    /**
     * @return list<AvatarFrameDefinition> every row, retired ones included, by position then key
     */
    public function all(): array;

    public function save(AvatarFrameDefinition $definition): void;
}
