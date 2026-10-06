<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

use App\Community\Domain\Entity\ProfileTitleDefinition;

/** The profile titles the admins write (story 41.22). */
interface ProfileTitleDefinitionRepositoryInterface
{
    public function find(string $key): ?ProfileTitleDefinition;

    /**
     * @return list<ProfileTitleDefinition> every row, retired ones included, by position then key
     */
    public function all(): array;

    public function save(ProfileTitleDefinition $definition): void;
}
