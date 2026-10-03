<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\SessionConfig\Application\Service\SessionConfigResolver;
use App\SessionConfig\Domain\Enum\SessionType;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;

/**
 * What a hint costs in pelles in one session (story 41.3), read from the session config the same way the launch
 * reads it: a personal run's session uses the "private" profile and the run's override, an event session the
 * "event" profile and its own override. An event session also names the event whose pelles are spent first.
 */
final readonly class PelleHintTerms
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private RunRepositoryInterface $runs,
        private SessionConfigResolver $configResolver,
    ) {
    }

    /**
     * @return array{session: Session, enabled: bool, itemPrice: int, locationPrice: int, bounties: bool, eventId: string|null}|null
     */
    public function of(string $sessionId): ?array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return null;
        }

        $run = $this->runs->findBySessionId($sessionId);
        $server = $run instanceof Run
            ? $this->configResolver->resolve(SessionType::Private, $run->getId())->server
            : $this->configResolver->resolve(SessionType::Event, $sessionId)->server;

        return [
            'session' => $session,
            'enabled' => $server->pelleHints,
            'itemPrice' => $server->pelleItemHintPrice,
            'locationPrice' => $server->pelleLocationHintPrice,
            // Story 41.4.
            'bounties' => $server->pelleBounties,
            'eventId' => $run instanceof Run ? null : $session->getEventId(),
        ];
    }
}
