<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Service;

use App\Identity\Domain\Entity\AdminUserActionAudit;
use App\PersonalRuns\Application\Support\AdminRunActionTrace;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\SessionConfig\Application\Command\ClearSessionConfigOverride;
use App\SessionConfig\Application\Command\SetSessionConfigOverride;
use App\SessionConfig\Application\Query\SessionConfigOverrideQuery;
use App\SessionConfig\Domain\Enum\SessionType;
use App\SessionConfig\Domain\Repository\SessionConfigProfileRepositoryInterface;

/**
 * Owner-managed config override for a private run. The override is keyed by the run id (the stable
 * key the resolver uses for private sessions), and only the run's owner, or a co-organiser (story 16.27), may
 * read/change it.
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
        private AdminRunActionTrace $trace,
        private RunParticipantRepositoryInterface $participants,
    ) {
    }

    /**
     * @return array{found: bool, authorized: bool, override?: array<string, mixed>, profile?: array<string, mixed>}
     */
    public function get(string $runId, string $userId, bool $isAdmin = false): array
    {
        [$run] = $this->guard($runId, $userId, $isAdmin);
        if (!$run instanceof Run) {
            return $this->denial($runId, $userId);
        }

        return ['found' => true, 'authorized' => true, 'override' => $this->stripLocked($this->query->execute($runId)), 'profile' => $this->privateProfile()];
    }

    /**
     * @param array<array-key, mixed> $override
     *
     * @return array{found: bool, authorized: bool, blocked?: bool, override?: array<string, mixed>, profile?: array<string, mixed>}
     *
     * @throws \DomainException on an invalid override field
     */
    public function set(string $runId, string $userId, array $override, bool $isAdmin = false): array
    {
        [$run, $asAdmin] = $this->guard($runId, $userId, $isAdmin);
        if (!$run instanceof Run) {
            return $this->denial($runId, $userId);
        }

        // A finished or cancelled run is read-only: its session parameters can no longer change (#338).
        if ($run->isTerminal()) {
            return ['found' => true, 'authorized' => true, 'blocked' => true];
        }

        // Owner-locked fields stay inherited from the admin "private" profile - drop them before save.
        foreach (self::OWNER_LOCKED_FIELDS as $field) {
            unset($override[$field]);
        }
        $this->setOverride->execute($runId, $override);
        if ($asAdmin) {
            $this->trace->record($run, $userId, AdminUserActionAudit::ACTION_RUN_CONFIG_OVERRIDE);
        }

        return ['found' => true, 'authorized' => true, 'override' => $this->stripLocked($this->query->execute($runId)), 'profile' => $this->privateProfile()];
    }

    /**
     * @return array{found: bool, authorized: bool, blocked?: bool}
     */
    public function clear(string $runId, string $userId, bool $isAdmin = false): array
    {
        [$run, $asAdmin] = $this->guard($runId, $userId, $isAdmin);
        if (!$run instanceof Run) {
            return $this->denial($runId, $userId);
        }

        // A finished or cancelled run is read-only (#338).
        if ($run->isTerminal()) {
            return ['found' => true, 'authorized' => true, 'blocked' => true];
        }

        $this->clearOverride->execute($runId);
        if ($asAdmin) {
            $this->trace->record($run, $userId, AdminUserActionAudit::ACTION_RUN_CONFIG_OVERRIDE);
        }

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

    /**
     * Story 16.19 : un administrateur règle la partie d'un autre membre pour le dépanner. Le rôle
     * ouvre la porte, il ne lève pas les règles d'état - `isTerminal()` bloque les deux pareil.
     *
     * Renvoie la partie (null si refusé) et si l'accès passe par le rôle admin seul : c'est ce cas, et lui seul,
     * qui laisse une trace. Le co-organisateur est lu une seule fois (corrigé après la revue de la 1.1.0).
     *
     * @return array{0: ?Run, 1: bool}
     */
    private function guard(string $runId, string $userId, bool $isAdmin = false): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return [null, false];
        }
        if ($run->isAdministeredBy($userId, $this->isCoOwner($run, $userId))) {
            return [$run, false];
        }

        return $isAdmin ? [$run, true] : [null, false];
    }

    private function isCoOwner(Run $run, string $userId): bool
    {
        if ($run->isOwnedBy($userId)) {
            return false;
        }
        $participant = $this->participants->findByRunAndUser($run->getId(), $userId);

        return $participant instanceof RunParticipant && $participant->isCoOwner();
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
