<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\Entity\ModerationCaseMessage;
use App\Community\Domain\Repository\ModerationCaseMessageRepositoryInterface;

/**
 * In-memory messages of the moderation cases (story 39.2 tests). `flushes` counts the commits.
 */
final class InMemoryModerationCaseMessageRepository implements ModerationCaseMessageRepositoryInterface
{
    /** @var list<ModerationCaseMessage> */
    public array $messages = [];

    public int $flushes = 0;

    public function save(ModerationCaseMessage $message): void
    {
        $this->messages[] = $message;
    }

    public function findById(string $id): ?ModerationCaseMessage
    {
        foreach ($this->messages as $message) {
            if ($message->getId() === $id) {
                return $message;
            }
        }

        return null;
    }

    public function forCase(string $caseId, int $limit): array
    {
        $found = array_values(array_filter($this->messages, static fn (ModerationCaseMessage $m): bool => $m->getCaseId() === $caseId));

        return array_slice($found, -$limit);
    }

    public function countFromMemberSince(string $caseId, \DateTimeImmutable $since): int
    {
        return \count(array_filter(
            $this->messages,
            static fn (ModerationCaseMessage $m): bool => $m->getCaseId() === $caseId
                && ModerationCaseMessage::AUTHOR_MEMBER === $m->getAuthorRole()
                && $m->getCreatedAt() >= $since,
        ));
    }

    public function flush(): void
    {
        ++$this->flushes;
    }
}
