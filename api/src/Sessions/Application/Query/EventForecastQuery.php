<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Sessions\Application\Service\SlotDurationEstimate;

/**
 * Story 56.8: for an admin, when each slot of the event's running session should finish at the pace it is going, which
 * ones should be released, and, once slots reach their goal, how far their real duration was from the expected one.
 *
 * The pace is the one of the slot's last hour of play; with too few checks in it (a slot that just started, or that
 * plays in bursts), the estimated pace of story 56.2 stands in. A slot is flagged "to release" when it should finish
 * after the event ends while its world still holds progression items of the others: nothing is done for the admin,
 * the existing actions (release, Passeur) stay theirs.
 */
final readonly class EventForecastQuery
{
    /** The window of the observed pace: the last hour of play. */
    public const int WINDOW_SECONDS = 3600;

    /** Fewer checks than this in the window, or a window shorter than this, and the observed pace is noise. */
    public const int MIN_RECENT_CHECKS = 5;
    public const int MIN_RECENT_SECONDS = 600;

    public function __construct(
        private SlotForecastQueryInterface $forecasts,
        private SlotDurationEstimate $durations,
        private EventRepositoryInterface $events,
    ) {
    }

    /**
     * Null when the event does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function forEvent(string $eventId, \DateTimeImmutable $now): ?array
    {
        $event = $this->events->findById($eventId);
        if (!$event instanceof Event) {
            return null;
        }
        $endsAt = $event->getEndsAt();
        $sessionId = $this->forecasts->runningSession($eventId);

        return [
            'endsAt' => $endsAt->format(\DateTimeInterface::ATOM),
            'live' => null === $sessionId ? null : ['sessionId' => $sessionId, 'slots' => $this->live($sessionId, $endsAt, $now)],
            'calibration' => $this->calibration($eventId),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function live(string $sessionId, \DateTimeImmutable $endsAt, \DateTimeImmutable $now): array
    {
        $players = $this->forecasts->slotPlayers($sessionId);
        $slots = [];
        foreach ($this->forecasts->liveSlots($sessionId, self::WINDOW_SECONDS) as $slot) {
            $remaining = null === $slot['locations'] ? null : max(0, $slot['locations'] - $slot['checksDone']);
            [$pace, $source] = $this->pace($slot, $players[$slot['slotId']] ?? []);
            $finishAt = null;
            if (!$slot['goalReached'] && null !== $remaining && null !== $pace && $pace > 0) {
                $finishAt = $now->modify(sprintf('+%d seconds', (int) round($remaining / $pace * 3600)));
            }
            $holding = null === $slot['hostedProgression'] ? null : max(0, $slot['hostedProgression'] - $slot['hostedSent']);
            $toRelease = !$slot['goalReached'] && !$slot['released'] && null !== $finishAt && $finishAt > $endsAt && null !== $holding && $holding > 0;

            $slots[] = [
                'slotName' => $slot['slotName'],
                'gameName' => $slot['gameName'],
                'checksDone' => $slot['checksDone'],
                'locations' => $slot['locations'],
                'goalReached' => $slot['goalReached'],
                'released' => $slot['released'],
                'checksPerHour' => null === $pace ? null : round($pace, 1),
                'paceSource' => $source,
                'finishAt' => $finishAt?->format(\DateTimeInterface::ATOM),
                'holdingProgression' => $holding,
                'toRelease' => $toRelease,
            ];
        }

        // The ones to act on first, then the latest to finish.
        usort($slots, static fn (array $a, array $b): int => [$b['toRelease'], $b['finishAt'] ?? ''] <=> [$a['toRelease'], $a['finishAt'] ?? '']);

        return $slots;
    }

    /**
     * @param array{gameId: string, locations: int|null, recentChecks: int, recentSeconds: int} $slot
     * @param list<string>                                                                      $userIds
     *
     * @return array{0: float|null, 1: string|null} the checks per hour and where they come from
     */
    private function pace(array $slot, array $userIds): array
    {
        if ($slot['recentChecks'] >= self::MIN_RECENT_CHECKS && $slot['recentSeconds'] >= self::MIN_RECENT_SECONDS) {
            return [$slot['recentChecks'] / ($slot['recentSeconds'] / 3600), 'observed'];
        }
        $estimate = $this->durations->forSlot($slot['gameId'], $userIds, $slot['locations']);
        if (null === $estimate->checksPerHour) {
            return [null, null];
        }

        return [$estimate->checksPerHour * $estimate->playerFactor, 'estimated'];
    }

    /** @return array{slots: list<array<string, mixed>>, meanGap: float|null} */
    private function calibration(string $eventId): array
    {
        $slots = [];
        $gaps = [];
        foreach ($this->forecasts->eventCalibration($eventId) as $slot) {
            $gap = $slot['actualHours'] / $slot['estimatedHours'] - 1;
            $gaps[] = $gap;
            $slots[] = [
                'slotName' => $slot['slotName'],
                'gameName' => $slot['gameName'],
                'estimatedHours' => round($slot['estimatedHours'], 2),
                'actualHours' => round($slot['actualHours'], 2),
                'gap' => round($gap, 3),
            ];
        }

        return ['slots' => $slots, 'meanGap' => [] === $gaps ? null : round(array_sum($gaps) / count($gaps), 3)];
    }
}
