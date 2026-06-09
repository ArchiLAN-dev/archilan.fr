<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application;

use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\SessionConfig\Application\ClearSessionConfigOverride;
use App\SessionConfig\Application\SessionConfigOverrideQuery;
use App\SessionConfig\Application\SetSessionConfigOverride;
use App\SessionConfig\Domain\SessionConfigProfileRepositoryInterface;
use App\SessionConfig\Domain\SessionType;

/**
 * Owner-managed config override for a private run. The override is keyed by the run id (the stable
 * key the resolver uses for private sessions), and only the run's owner may read/change it.
 */
final readonly class PersonalRunConfigOverride
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private SessionConfigOverrideQuery $query,
        private SetSessionConfigOverride $setOverride,
        private ClearSessionConfigOverride $clearOverride,
        private SessionConfigProfileRepositoryInterface $profiles,
    ) {
    }

    /**
     * @return array{found: bool, authorized: bool, override?: array<string, mixed>, profile?: array<string, mixed>}
     */
    public function get(string $runId, string $userId): array
    {
        $run = $this->guard($runId, $userId);
        if (!$run instanceof Run) {
            return $this->denial($runId, $userId);
        }

        return ['found' => true, 'authorized' => true, 'override' => $this->query->execute($runId), 'profile' => $this->privateProfile()];
    }

    /**
     * @param array<array-key, mixed> $override
     *
     * @return array{found: bool, authorized: bool, override?: array<string, mixed>, profile?: array<string, mixed>}
     *
     * @throws \DomainException on an invalid override field
     */
    public function set(string $runId, string $userId, array $override): array
    {
        $run = $this->guard($runId, $userId);
        if (!$run instanceof Run) {
            return $this->denial($runId, $userId);
        }

        $this->setOverride->execute($runId, $override);

        return ['found' => true, 'authorized' => true, 'override' => $this->query->execute($runId), 'profile' => $this->privateProfile()];
    }

    /**
     * @return array{found: bool, authorized: bool}
     */
    public function clear(string $runId, string $userId): array
    {
        $run = $this->guard($runId, $userId);
        if (!$run instanceof Run) {
            return $this->denial($runId, $userId);
        }

        $this->clearOverride->execute($runId);

        return ['found' => true, 'authorized' => true];
    }

    /**
     * The resolved "private" type profile (the values an unset override field inherits).
     *
     * @return array<string, mixed>
     */
    private function privateProfile(): array
    {
        return $this->profiles->get(SessionType::Private)->toArray();
    }

    private function guard(string $runId, string $userId): ?Run
    {
        $run = $this->runs->findById($runId);

        return $run instanceof Run && $run->isOwnedBy($userId) ? $run : null;
    }

    /**
     * @return array{found: bool, authorized: bool}
     */
    private function denial(string $runId, string $userId): array
    {
        $run = $this->runs->findById($runId);

        return ['found' => $run instanceof Run, 'authorized' => false];
    }
}
