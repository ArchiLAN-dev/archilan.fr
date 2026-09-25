<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Enum\ApworldCandidateStatus;
use App\GameSelection\Domain\Repository\ApworldCandidateRepositoryInterface;

/**
 * Test double for the candidate rules (story 38.6): the rules are about what the store holds.
 */
final class InMemoryApworldCandidateRepository implements ApworldCandidateRepositoryInterface
{
    /** @var array<string, ApworldCandidate> */
    private array $candidates = [];

    public int $flushes = 0;

    public function save(ApworldCandidate $candidate): void
    {
        $this->candidates[$candidate->getId()] = $candidate;
    }

    public function findById(string $id): ?ApworldCandidate
    {
        return $this->candidates[$id] ?? null;
    }

    public function findTestingForGame(string $gameId): ?ApworldCandidate
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->getGameId() === $gameId && ApworldCandidateStatus::Testing === $candidate->getStatus()) {
                return $candidate;
            }
        }

        return null;
    }

    public function findAllTesting(): array
    {
        return array_values(array_filter(
            $this->candidates,
            static fn (ApworldCandidate $c): bool => ApworldCandidateStatus::Testing === $c->getStatus(),
        ));
    }

    public function findLatestForGame(string $gameId): ?ApworldCandidate
    {
        $latest = null;
        foreach ($this->candidates as $candidate) {
            if ($candidate->getGameId() === $gameId && (null === $latest || $candidate->getSubmittedAt() >= $latest->getSubmittedAt())) {
                $latest = $candidate;
            }
        }

        return $latest;
    }

    public function hasRejectedVersion(string $gameId, string $versionTag): bool
    {
        return array_any($this->candidates, fn ($candidate) => $candidate->getGameId() === $gameId && $candidate->getVersionTag() === $versionTag
            && ApworldCandidateStatus::Rejected === $candidate->getStatus());
    }

    public function flush(): void
    {
        ++$this->flushes;
    }

    /**
     * @return list<ApworldCandidate>
     */
    public function all(): array
    {
        return array_values($this->candidates);
    }
}
