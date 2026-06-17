<?php

declare(strict_types=1);

namespace App\Community\Domain;

interface CommunityProfileRepositoryInterface
{
    public function findByUserId(string $userId): ?CommunityProfile;

    /**
     * Profiles whose cached avatar is missing or older than $staleBefore - candidates for a refresh pass.
     *
     * @return list<CommunityProfile>
     */
    public function findNeedingAvatarRefresh(\DateTimeImmutable $staleBefore, int $limit): array;

    public function save(CommunityProfile $profile): void;

    public function flush(): void;
}
