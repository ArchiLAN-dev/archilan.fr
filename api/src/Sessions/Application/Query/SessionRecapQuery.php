<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Events\Domain\Repository\EventRepositoryInterface;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRecapRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;

/**
 * Read facade for a finished session's recap.
 *
 * Composes the persisted exchange-graph projection with the live podium
 * (RunResultsQuery, reused - handles ranking/released/invalidated) and the
 * event VOD. Returns null - i.e. the controller 404s - when the session is not
 * finished, no projection has been built yet, or the viewer is not allowed to see it.
 *
 * Access (story 32.5): a session's `eventId` is overloaded - a real Event for event sessions, a
 * personal Run id otherwise. Event recaps are served for public events only, to anyone. Personal-run
 * recaps are private to the run owner + participants, unless the owner has published them (then public
 * like an event). Weekly runs never reach this (no finished session).
 */
final readonly class SessionRecapQuery
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private EventRepositoryInterface $events,
        private SessionRecapRepositoryInterface $recaps,
        private RunResultsQuery $runResults,
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
    ) {
    }

    /**
     * @return array{
     *     sessionId: string,
     *     eventName: string,
     *     startedAt: string|null,
     *     finishedAt: string|null,
     *     durationSeconds: int|null,
     *     vodUrl: string|null,
     *     generatedAt: string,
     *     podium: list<array<string, mixed>>,
     *     graph: array{
     *         nodes: list<array{slotId: string, slotName: string, game: string}>,
     *         edges: list<array{fromSlotId: string, toSlotId: string, count: int}>,
     *         localItems: list<array{slotId: string, count: int}>
     *     },
     *     superlatives: list<array{key: string, label: string, slotId: string, value: int|string}>
     * }|null
     */
    public function execute(string $sessionId, ?string $viewerId = null): ?array
    {
        $session = $this->sessions->findById($sessionId);
        if (null === $session || Session::STATUS_FINISHED !== $session->getStatus()) {
            return null;
        }

        // eventId is overloaded: a real Event, else a personal Run id.
        $vodUrl = null;
        $event = $this->events->findById($session->getEventId());
        if (null !== $event) {
            // Event recap: public events only, served to anyone.
            if (!$event->isPublic()) {
                return null;
            }
            $vodUrl = $event->getVodUrl();
        } else {
            // Personal-run recap: private to owner + participants unless published (story 32.5).
            $run = $this->runs->findById($session->getEventId());
            if (null === $run || !$this->mayViewRunRecap($run, $viewerId)) {
                return null;
            }
        }

        $recap = $this->recaps->findBySessionId($sessionId);
        if (null === $recap) {
            return null;
        }

        // Podium already resolves the display name for both event and personal-run sessions.
        $podium = $this->runResults->execute($sessionId);
        if (null === $podium) {
            return null;
        }

        return [
            'sessionId' => $sessionId,
            'eventName' => $podium['eventName'],
            'startedAt' => $podium['startedAt'],
            'finishedAt' => $podium['finishedAt'],
            'durationSeconds' => $podium['durationSeconds'],
            'vodUrl' => $vodUrl,
            'generatedAt' => $recap->getGeneratedAt()->format(\DateTimeInterface::ATOM),
            'podium' => $podium['slots'],
            'graph' => [
                'nodes' => $recap->getNodes(),
                'edges' => $recap->getEdges(),
                'localItems' => $recap->getLocalItems(),
            ],
            'superlatives' => $recap->getSuperlatives(),
        ];
    }

    /**
     * A personal-run recap is visible to anyone once published; otherwise only to the owner or a
     * participant. An anonymous viewer ($viewerId null) only ever sees a published recap.
     */
    private function mayViewRunRecap(Run $run, ?string $viewerId): bool
    {
        if ($run->isRecapPublic()) {
            return true;
        }
        if (null === $viewerId) {
            return false;
        }
        if ($run->isOwnedBy($viewerId)) {
            return true;
        }

        return null !== $this->participants->findByRunAndUser($run->getId(), $viewerId);
    }
}
