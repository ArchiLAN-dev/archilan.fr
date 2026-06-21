<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\Sessions\Domain\SessionSlot;
use App\Sessions\Domain\SessionSlotRepositoryInterface;
use App\WeeklyRuns\Application\RecordWeeklyGoal;
use Psr\Log\LoggerInterface;

/**
 * Handles the generic slot-goal callback fired by the bridge when a slot reaches its goal, dispatching
 * by session type:
 *
 * - Weekly runs capture their goal stats in their own `weekly_entries` table (RecordWeeklyGoal).
 * - Event / personal runs capture them onto the matching `session_slot` **at goal time**. This is the
 *   robust moment to record: the bridge is alive and sending the data. The later archival
 *   (ArchiveRunJobHandler) re-reads bridge state, but the bridge container may already be stopped by
 *   then (idle=stop), leaving the slot at its defaults - hence bug #6.
 *
 * The slot is matched by name (consistent with the archival path), never by the AP slot index, which
 * has no reliable mapping to `session_slot.slot_order`.
 */
final readonly class RecordSlotGoal
{
    public function __construct(
        private RecordWeeklyGoal $recordWeeklyGoal,
        private SessionSlotRepositoryInterface $slots,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{entryId: string}|null the weekly result when the session is a weekly entry, else null
     */
    public function execute(
        string $sessionId,
        ?string $slotName,
        int $checksTotal,
        int $itemsTotal,
        \DateTimeImmutable $goalReachedAt,
    ): ?array {
        $weekly = $this->recordWeeklyGoal->execute($sessionId, $checksTotal, $itemsTotal, $goalReachedAt);
        if (null !== $weekly) {
            return $weekly;
        }

        // Not a weekly run: record onto the session_slot. Without a slot name we can't match safely.
        if (null === $slotName || '' === $slotName) {
            return null;
        }

        $slot = $this->slots->findBySessionAndSlotName($sessionId, $slotName);
        if (!$slot instanceof SessionSlot) {
            $this->logger->warning('slot_goal_callback.slot_not_found', [
                'sessionId' => $sessionId,
                'slotName' => $slotName,
            ]);

            return null;
        }

        // Idempotent: the goal-reached instant is captured once (the callback may fire more than once).
        if (null !== $slot->getGoalReachedAt()) {
            return null;
        }

        $slot->setChecksDone($checksTotal);
        $slot->setItemsReceived($itemsTotal);
        $slot->setGoalReachedAt($goalReachedAt);
        $this->slots->flush();

        return null;
    }
}
