<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Repository;

use App\GameSelection\Domain\Entity\ApworldIncident;
use App\GameSelection\Domain\Enum\ApworldIncidentType;

interface ApworldIncidentRepositoryInterface
{
    public function save(ApworldIncident $incident): void;

    public function findById(string $id): ?ApworldIncident;

    /**
     * The open or acknowledged incident for this key, if any. There is at most one.
     */
    public function findActive(string $gameId, string $apworldHash, ApworldIncidentType $type): ?ApworldIncident;

    /**
     * The most recently closed (resolved or ignored) incident for this key, if any.
     */
    public function findLatestClosed(string $gameId, string $apworldHash, ApworldIncidentType $type): ?ApworldIncident;

    /**
     * @return list<ApworldIncident>
     */
    public function findAllActive(): array;

    /**
     * Every open or acknowledged incident of one game, whatever its hash and type.
     *
     * @return list<ApworldIncident>
     */
    public function findActiveForGame(string $gameId): array;

    public function flush(): void;
}
