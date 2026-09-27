<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\Entity\ModerationCase;
use App\Community\Domain\Repository\ModerationCaseRepositoryInterface;

/**
 * In-memory moderation cases (story 39.1 tests). `flushes` counts the commits.
 */
final class InMemoryModerationCaseRepository implements ModerationCaseRepositoryInterface
{
    /** @var array<string, ModerationCase> */
    private array $byTarget = [];

    public int $flushes = 0;

    public function findById(string $id): ?ModerationCase
    {
        foreach ($this->byTarget as $case) {
            if ($case->getId() === $id) {
                return $case;
            }
        }

        return null;
    }

    public function findByTargetUserId(string $targetUserId): ?ModerationCase
    {
        return $this->byTarget[$targetUserId] ?? null;
    }

    public function openWithDirectMessageChannel(): array
    {
        return array_values(array_filter(
            $this->byTarget,
            static fn (ModerationCase $case): bool => $case->isOpen() && null !== $case->getDirectMessageChannelId(),
        ));
    }

    public function save(ModerationCase $case): void
    {
        $this->byTarget[$case->getTargetUserId()] = $case;
    }

    public function flush(): void
    {
        ++$this->flushes;
    }
}
