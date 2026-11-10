<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

use App\Community\Application\Query\CommunityAdminIdsQueryInterface;
use App\Community\Application\Support\Notifier;
use App\Sessions\Domain\Entity\DepartureRequest;
use App\Sessions\Domain\Entity\SessionPlayersSnapshot;
use App\Sessions\Domain\Repository\SessionPlayersSnapshotRepositoryInterface;
use App\Sessions\Domain\Service\SlotBlockRule;
use Psr\Log\LoggerInterface;

/**
 * Story 52.6: what the departures share - who decides on them (the staff of an event, the owner of a private run, an
 * admin anyway), the slots of a session by number, and telling the deciders a player left.
 */
final readonly class DepartureDesk
{
    public const string NOTIFICATION_TYPE = 'departure_requested';

    public function __construct(
        private PasseurTermsResolver $terms,
        private SessionPlayersSnapshotRepositoryInterface $snapshots,
        private CommunityAdminIdsQueryInterface $admins,
        private Notifier $notifier,
        private LoggerInterface $logger,
    ) {
    }

    /** The session's event or private run; null for a weekly or an unknown session (no departures there). */
    public function context(string $sessionId): ?PasseurTerms
    {
        return $this->terms->of($sessionId);
    }

    public function canDecide(PasseurTerms $context, string $userId, bool $isAdmin): bool
    {
        return $isAdmin || $context->run?->getOwnerId() === $userId;
    }

    /**
     * The players' slots by Archipelago number, from the last players state the bridge pushed.
     *
     * @return array<int, string>
     */
    public function slotNames(string $sessionId): array
    {
        $snapshot = $this->snapshots->findBySessionId($sessionId);
        $slots = $snapshot instanceof SessionPlayersSnapshot ? ($snapshot->getPayload()['slots'] ?? null) : null;
        if (!is_array($slots)) {
            return [];
        }

        $names = [];
        foreach ($slots as $index => $slot) {
            $name = is_array($slot) ? ($slot['slot_name'] ?? null) : null;
            if (is_int($index) && is_string($name) && SlotBlockRule::isPlayerSlot($name)) {
                $names[$index] = $name;
            }
        }

        return $names;
    }

    /** The staff of an event, or the owner of a private run, learn that a player left. */
    public function tellDeciders(PasseurTerms $context, DepartureRequest $request): void
    {
        $deciders = null !== $context->run ? [$context->run->getOwnerId()] : $this->admins->adminUserIds();
        $url = null !== $context->run
            ? '/runs/'.$context->run->getId()
            : sprintf('/admin/evenements/%s/session/%s', $context->event?->getId() ?? '', $request->getSessionId());
        $payload = [
            'sessionTitle' => $context->run?->getTitle() ?? $context->event?->getTitle(),
            'playerName' => $request->getSlotName(),
            'origin' => $request->getOrigin(),
            'url' => $url,
        ];
        foreach (array_unique($deciders) as $userId) {
            try {
                $this->notifier->notify($userId, self::NOTIFICATION_TYPE, $payload);
            } catch (\Throwable $e) {
                $this->logger->warning('departure.decider_not_notified', ['request' => $request->getId(), 'error' => $e->getMessage()]);
            }
        }
    }
}
