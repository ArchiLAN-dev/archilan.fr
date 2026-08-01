<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Sessions\Application\Support\SessionRecapAudience;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Repository\SessionFeedEventRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;

/**
 * Read facade for a session's persisted game feed (story 32.6): the item, hint and goal events
 * (story 32.12), oldest first.
 *
 * Unlike the recap, there is **no finished check** - the feed accumulates during the game, so it is
 * readable live (a participant watching, an owner reloading) as well as after the run. Access is the
 * same shared rule as the recap ({@see SessionRecapAudience}): public event -> anyone; personal run ->
 * owner/participant, or anyone once published. Returns null (controller 404s) when the session does
 * not exist or the viewer may not see it.
 */
final readonly class SessionFeedQuery
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private SessionFeedEventRepositoryInterface $events,
        private SessionRecapAudience $audience,
    ) {
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function execute(string $sessionId, ?string $viewerId = null): ?array
    {
        $session = $this->sessions->findById($sessionId);
        if (null === $session || !$this->audience->canView($session, $viewerId)) {
            return null;
        }

        return array_map(
            static fn (SessionFeedEvent $event): array => [
                'id' => $event->getId(),
                'type' => $event->getType(),
                'text' => $event->getText(),
                'occurredAt' => $event->getOccurredAt()->format(\DateTimeInterface::ATOM),
                'item' => ['id' => $event->getItemId(), 'name' => $event->getItemName(), 'flags' => $event->getItemFlags()],
                'location' => ['id' => $event->getLocationId(), 'name' => $event->getLocationName()],
                'sender' => [
                    'slot' => $event->getSenderSlot(),
                    'name' => $event->getSenderName(),
                    'game' => $event->getSenderGame(),
                ],
                'receiver' => [
                    'slot' => $event->getReceiverSlot(),
                    'name' => $event->getReceiverName(),
                    'game' => $event->getReceiverGame(),
                ],
            ],
            $this->events->findBySessionId($sessionId),
        );
    }
}
