<?php

declare(strict_types=1);

namespace App\Tests\Unit\CatalogSync;

use App\CatalogSync\Domain\Entity\ApworldUpdateCheckRun;
use App\CatalogSync\Domain\Repository\ApworldUpdateCheckRunRepositoryInterface;

/**
 * Story 38.18: the passes, in memory, with the same one-active-pass rule as the unique active slot.
 */
final class InMemoryApworldUpdateCheckRunRepository implements ApworldUpdateCheckRunRepositoryInterface
{
    /** @var array<string, ApworldUpdateCheckRun> */
    private array $runs = [];

    public int $saves = 0;

    public function findById(string $id): ?ApworldUpdateCheckRun
    {
        return $this->runs[$id] ?? null;
    }

    public function findLatest(): ?ApworldUpdateCheckRun
    {
        $runs = array_values($this->runs);
        usort($runs, static fn (ApworldUpdateCheckRun $a, ApworldUpdateCheckRun $b): int => $b->getQueuedAt() <=> $a->getQueuedAt());

        return $runs[0] ?? null;
    }

    public function findActive(): ?ApworldUpdateCheckRun
    {
        foreach ($this->runs as $run) {
            if ($run->isActive()) {
                return $run;
            }
        }

        return null;
    }

    public function claim(ApworldUpdateCheckRun $run): bool
    {
        if (null !== $this->findActive()) {
            return false;
        }
        $this->runs[$run->getId()] = $run;

        return true;
    }

    public function save(ApworldUpdateCheckRun $run): void
    {
        $this->runs[$run->getId()] = $run;
        ++$this->saves;
    }

    /** @return list<ApworldUpdateCheckRun> */
    public function all(): array
    {
        return array_values($this->runs);
    }
}
