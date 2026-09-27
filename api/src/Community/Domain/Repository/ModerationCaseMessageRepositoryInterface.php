<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

use App\Community\Domain\Entity\ModerationCaseMessage;

interface ModerationCaseMessageRepositoryInterface
{
    /** Tracks a new message; written by the next flush. */
    public function save(ModerationCaseMessage $message): void;

    public function findById(string $id): ?ModerationCaseMessage;

    public function findByDiscordMessageId(string $discordMessageId): ?ModerationCaseMessage;

    /**
     * The case's latest messages, oldest first.
     *
     * @return list<ModerationCaseMessage>
     */
    public function forCase(string $caseId, int $limit): array;

    public function countFromMemberSince(string $caseId, \DateTimeImmutable $since): int;

    public function flush(): void;
}
