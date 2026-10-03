<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

use App\Community\Domain\Entity\ProfileBannerDefinition;

interface ProfileBannerDefinitionRepositoryInterface
{
    public function find(string $key): ?ProfileBannerDefinition;

    /**
     * @return list<ProfileBannerDefinition> every row, retired ones included, by position then key
     */
    public function all(): array;

    public function save(ProfileBannerDefinition $definition): void;
}
