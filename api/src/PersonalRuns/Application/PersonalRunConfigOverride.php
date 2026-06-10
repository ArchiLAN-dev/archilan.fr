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
    /**
     * Config fields a run owner may not change: they stay locked to the admin-managed "private"
     * type profile. `autoShutdown` (inactivity watchdog, epic 17) is a platform-resource decision,
     * not a per-player setting.
     */
    private const array OWNER_LOCKED_FIELDS = ['autoShutdown'];

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

        return ['found' => true, 'authorized' => true, 'override' => $this->stripLocked($this->query->execute($runId)), 'profile' => $this->privateProfile()];
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

        // Owner-locked fields stay inherited from the admin "private" profile - drop them before save.
        foreach (self::OWNER_LOCKED_FIELDS as $field) {
            unset($override[$field]);
        }
        $this->setOverride->execute($runId, $override);

        return ['found' => true, 'authorized' => true, 'override' => $this->stripLocked($this->query->execute($runId)), 'profile' => $this->privateProfile()];
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

    /**
     * Drop owner-locked fields from a stored override before echoing it back to the owner.
     *
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function stripLocked(array $override): array
    {
        foreach (self::OWNER_LOCKED_FIELDS as $field) {
            unset($override[$field]);
        }

        return $override;
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
