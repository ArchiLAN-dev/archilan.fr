<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\SessionConfig\Application\Service\SessionConfigResolver;
use App\SessionConfig\Domain\Enum\SessionType;

/**
 * Whether a player sees the spheres of a slot in one session (story 27.12), read from the session config the way
 * the launch reads it: a personal run's session uses the "private" profile and the run's override, an event session
 * the "event" profile and its own override. The owner of a private run always sees them: the setting is theirs.
 */
final readonly class SlotSpheresVisibilityQuery
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private SessionConfigResolver $configResolver,
    ) {
    }

    public function visibleTo(string $sessionId, ?string $userId): bool
    {
        $run = $this->runs->findBySessionId($sessionId);
        if ($run instanceof Run) {
            return $run->getOwnerId() === $userId
                || $this->configResolver->resolve(SessionType::Private, $run->getId())->server->spheresVisible;
        }

        return $this->configResolver->resolve(SessionType::Event, $sessionId)->server->spheresVisible;
    }
}
