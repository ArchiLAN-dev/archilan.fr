<?php

declare(strict_types=1);

namespace App\Community\Domain;

interface ProfileCommentRepositoryInterface
{
    public function findById(string $id): ?ProfileComment;

    /**
     * Visible (non-hidden) comments on a profile, newest first.
     *
     * @return list<ProfileComment>
     */
    public function visibleForProfile(string $profileUserId, int $limit): array;

    public function countByAuthorSince(string $authorId, \DateTimeImmutable $since): int;

    public function save(ProfileComment $comment): void;

    public function remove(ProfileComment $comment): void;

    public function flush(): void;
}
