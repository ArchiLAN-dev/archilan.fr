<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\SessionConfig\Application\Service\SessionConfigResolver;
use App\SessionConfig\Domain\Enum\SessionType;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;

/**
 * Story 52.4: the Passeur's rules of a session, read from its config the way the launch reads it - a private run's
 * profile and override, an event session's. A weekly (no run, no event) has no Passeur: null, as an unknown session.
 */
final readonly class PasseurTermsResolver
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private RunRepositoryInterface $runs,
        private EventRepositoryInterface $events,
        private SessionConfigResolver $configResolver,
    ) {
    }

    public function of(string $sessionId): ?PasseurTerms
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return null;
        }

        $run = $this->runs->findBySessionId($sessionId);
        if ($run instanceof Run) {
            return new PasseurTerms($session, $run, null, $this->configResolver->resolve(SessionType::Private, $run->getId())->server, 'bronze');
        }

        $event = $this->events->findById($session->getEventId());
        if (!$event instanceof Event) {
            return null;
        }

        return new PasseurTerms($session, null, $event, $this->configResolver->resolve(SessionType::Event, $sessionId)->server, 'event');
    }
}
