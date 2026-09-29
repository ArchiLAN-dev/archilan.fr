<?php

declare(strict_types=1);

namespace App\Sessions\Application\Service;

use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Application\Message\NotifySlotUnblockedJob;
use App\Sessions\Domain\Entity\SlotBlockEpisode;
use App\Sessions\Domain\Enum\SlotBlockDecision;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Sessions\Domain\Repository\SlotBlockEpisodeRepositoryInterface;
use App\Sessions\Domain\Service\SlotBlockRule;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Story 40.1: follows the BK episodes of a private run's slots from the bridge's players push, which
 * arrives on every state change and after every reachability recompute (story 9.23). A slot that
 * leaves a real block dispatches one notification job, after the episodes are written.
 *
 * Private runs only, and not an imported seed (no reachability is computed for one). Event and
 * weekly sessions have no run and are left alone.
 */
final readonly class SlotBlockTracker
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private SessionSlotRepositoryInterface $slots,
        private SlotBlockEpisodeRepositoryInterface $episodes,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload the players push, `{"slots": {"<slot number>": {...}}}`
     */
    public function track(string $sessionId, array $payload): void
    {
        $slots = $payload['slots'] ?? null;
        if (!is_array($slots) || [] === $slots) {
            return;
        }

        $run = $this->runs->findBySessionId($sessionId);
        if (null === $run || $run->isImportedSeed()) {
            return;
        }

        $now = $this->clock->now();
        $open = [];
        foreach ($this->episodes->findBySessionId($sessionId) as $episode) {
            $open[$episode->getSlotIndex()] = $episode;
        }
        $released = $this->releasedSlotNames($sessionId);

        $unblocked = [];
        foreach ($slots as $slotIndex => $slot) {
            $slotName = is_array($slot) ? ($slot['slot_name'] ?? null) : null;
            if (!is_array($slot) || !is_string($slotName) || !SlotBlockRule::isPlayerSlot($slotName)) {
                continue;
            }

            $key = (string) $slotIndex;
            $episode = $open[$key] ?? null;
            $decision = SlotBlockRule::decide($episode, SlotBlockRule::stateOf($slot, in_array($slotName, $released, true)), $now);

            if (SlotBlockDecision::Open === $decision) {
                $this->episodes->add(SlotBlockEpisode::open($sessionId, $key, $slotName, $now));
            } elseif (null !== $episode && (SlotBlockDecision::CloseSilently === $decision || SlotBlockDecision::CloseAndNotify === $decision)) {
                $this->episodes->remove($episode);
                if (SlotBlockDecision::CloseAndNotify === $decision) {
                    $reachableNow = $slot['reachable_now'] ?? 0;
                    $unblocked[] = new NotifySlotUnblockedJob($sessionId, $slotName, is_int($reachableNow) ? $reachableNow : 0);
                }
            }
        }

        $this->episodes->flush();

        foreach ($unblocked as $job) {
            $this->messageBus->dispatch($job);
        }
    }

    /**
     * @return list<string>
     */
    private function releasedSlotNames(string $sessionId): array
    {
        $names = [];
        foreach ($this->slots->findBySessionId($sessionId) as $slot) {
            if ($slot->isWasReleased()) {
                $names[] = $slot->getSlotName();
            }
        }

        return $names;
    }
}
