<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Entity\ApworldIncident;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\GameSelection\Domain\Repository\ApworldIncidentRepositoryInterface;

/**
 * Test double for the incident rules (story 38.1). A fake rather than stubs: the rules under test
 * are about what the store contains (active, closed, ignored), which chained stubs would only
 * restate. `flushes` lets a test check the single unit of work.
 */
final class InMemoryApworldIncidentRepository implements ApworldIncidentRepositoryInterface
{
    /** @var array<string, ApworldIncident> */
    private array $incidents = [];

    public int $flushes = 0;

    public function save(ApworldIncident $incident): void
    {
        $this->incidents[$incident->getId()] = $incident;
    }

    public function findById(string $id): ?ApworldIncident
    {
        return $this->incidents[$id] ?? null;
    }

    public function findActive(string $gameId, string $apworldHash, ApworldIncidentType $type): ?ApworldIncident
    {
        foreach ($this->incidents as $incident) {
            if ($incident->isActive() && $this->matches($incident, $gameId, $apworldHash, $type)) {
                return $incident;
            }
        }

        return null;
    }

    public function findLatestClosed(string $gameId, string $apworldHash, ApworldIncidentType $type): ?ApworldIncident
    {
        $latest = null;
        foreach ($this->incidents as $incident) {
            if ($incident->isActive() || !$this->matches($incident, $gameId, $apworldHash, $type)) {
                continue;
            }
            if (null === $latest || $incident->getClosedAt() > $latest->getClosedAt()) {
                $latest = $incident;
            }
        }

        return $latest;
    }

    public function findAllActive(): array
    {
        return array_values(array_filter($this->incidents, static fn (ApworldIncident $i): bool => $i->isActive()));
    }

    public function findActiveForGame(string $gameId): array
    {
        return array_values(array_filter(
            $this->incidents,
            static fn (ApworldIncident $i): bool => $i->isActive() && $i->getGameId() === $gameId,
        ));
    }

    public function flush(): void
    {
        ++$this->flushes;
    }

    /**
     * @return list<ApworldIncident>
     */
    public function all(): array
    {
        return array_values($this->incidents);
    }

    private function matches(ApworldIncident $incident, string $gameId, string $apworldHash, ApworldIncidentType $type): bool
    {
        return self::key($incident->getGameId(), $incident->getApworldHash(), $incident->getType())
            === self::key($gameId, $apworldHash, $type);
    }

    /**
     * Compares the whole deduplication key at once, the way the unique index does.
     */
    private static function key(string $gameId, string $apworldHash, ApworldIncidentType $type): string
    {
        return $gameId."\0".$apworldHash."\0".$type->value;
    }
}
