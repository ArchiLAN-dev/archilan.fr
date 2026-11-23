<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Sessions\Application\Support\SessionRecapAudience;
use App\Sessions\Domain\Entity\PasseurOpening;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;

/**
 * Read facade for a session's persisted game feed (story 32.6): the item, hint and goal events
 * (story 32.12) and the DeathLink deaths (story 32.25), oldest first.
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
        private SessionFeedEntriesQueryInterface $feed,
        private SessionRecapAudience $audience,
        private PasseurOpeningQueryInterface $passeur,
    ) {
    }

    /**
     * The entries come one at a time ({@see SessionFeedEntriesQueryInterface}): the access check runs now, the
     * rows are read while the response is encoded.
     *
     * @return iterable<int, array<string, mixed>>|null
     */
    public function execute(string $sessionId, ?string $viewerId = null): ?iterable
    {
        $session = $this->sessions->findById($sessionId);
        if (null === $session || !$this->audience->canView($session, $viewerId)) {
            return null;
        }

        // Story 52.5: the chests the Passeur opened, by "finder:location" - the item they sent is marked.
        $opened = [];
        foreach ($this->passeur->forSession($sessionId) as $opening) {
            if (PasseurOpening::STATUS_OPENED === $opening->status) {
                $opened[$opening->findingSlot.':'.$opening->locationId] = true;
            }
        }

        return $this->entries($sessionId, $opened);
    }

    /**
     * @param array<string, true> $opened
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function entries(string $sessionId, array $opened): \Generator
    {
        foreach ($this->feed->forSession($sessionId) as $entry) {
            $entry['passeur'] = SessionFeedEvent::TYPE_ITEM_RECEIVED === $entry['type']
                && isset($opened[$entry['sender']['slot'].':'.$entry['location']['id']]);

            yield $entry;
        }
    }
}
