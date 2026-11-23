<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Application\Service\BalanceInputs;
use App\Sessions\Application\Service\SlotDurationEstimate;
use App\Sessions\Domain\Entity\BalanceReport;
use App\Sessions\Domain\Repository\BalanceReportRepositoryInterface;

/**
 * Story 56.3: the balance report of the last dry generation of an event or a private run.
 *
 * Per slot: size, estimated duration and what it rests on (story 56.2), its gap to the party's median, the share of
 * the others' progression it hosts and its fair share (`locations ÷ all locations`, story 56.1). Alerts, never a
 * block:
 * - `duration_over_median`: more than twice the party's median duration;
 * - `duration_over_target`: longer than the target (the event's length minus 20 % until story 56.5);
 * - `hosted_over_fair`: hosts more than twice its fair share;
 * - `hosted_over_half`: hosts more than half of the others' progression.
 *
 * `stale`: the registrations no longer match what was generated (a YAML saved, a slot or a registration added).
 */
final readonly class BalanceReportQuery
{
    public const float MEDIAN_ALERT_RATIO = 2.0;
    public const float FAIR_SHARE_ALERT_RATIO = 2.0;
    public const float OTHERS_SHARE_ALERT = 0.5;
    /** Until story 56.5: the target is the event's length minus this share. */
    public const float TARGET_MARGIN = 0.2;

    public function __construct(
        private BalanceReportRepositoryInterface $reports,
        private BalanceInputs $inputs,
        private SlotDurationEstimate $durations,
        private EventRepositoryInterface $events,
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private RegistrationRepositoryInterface $registrations,
    ) {
    }

    /**
     * An admin's view of an event; null when the event does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function forEvent(string $eventId): ?array
    {
        $event = $this->events->findById($eventId);
        if (!$event instanceof Event) {
            return null;
        }

        return $this->build(BalanceReport::SCOPE_EVENT, $eventId, self::targetOf($event), null);
    }

    /**
     * The owner's (or a co-organiser's, or an admin's) view of a private run; `found` false when the run does not
     * exist, `authorized` false for anyone else.
     *
     * @return array{found: bool, authorized: bool, report?: array<string, mixed>}
     */
    public function forRun(string $runId, string $callerId, bool $isAdmin): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return ['found' => false, 'authorized' => false];
        }
        $participant = $run->isOwnedBy($callerId) ? null : $this->participants->findByRunAndUser($runId, $callerId);
        if (!$isAdmin && !$run->isAdministeredBy($callerId, $participant instanceof RunParticipant && $participant->isCoOwner())) {
            return ['found' => true, 'authorized' => false];
        }

        return ['found' => true, 'authorized' => true, 'report' => $this->build(BalanceReport::SCOPE_RUN, $runId, null, null)];
    }

    /**
     * A registered player's own lines of the event report, never the others'; null when they are not registered.
     *
     * @return array<string, mixed>|null
     */
    public function forPlayer(string $eventId, string $userId): ?array
    {
        $event = $this->events->findById($eventId);
        $registration = $this->registrations->findByEventAndUser($eventId, $userId);
        if (!$event instanceof Event || !$registration instanceof Registration || !$registration->isReserved()) {
            return null;
        }

        return $this->build(BalanceReport::SCOPE_EVENT, $eventId, self::targetOf($event), $userId);
    }

    public static function targetOf(Event $event): ?float
    {
        $hours = ($event->getEndsAt()->getTimestamp() - $event->getStartsAt()->getTimestamp()) / 3600;

        return $hours > 0 ? $hours * (1 - self::TARGET_MARGIN) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function build(string $scopeType, string $scopeId, ?float $targetHours, ?string $onlyUserId): array
    {
        $report = $this->reports->find($scopeType, $scopeId);
        if (!$report instanceof BalanceReport) {
            return ['status' => 'none', 'targetHours' => self::round($targetHours), 'slots' => []];
        }

        $current = $this->inputs->forScope($scopeType, $scopeId)['slots'];
        $stale = BalanceInputs::fingerprint($current) !== $report->getFingerprint();

        $rows = $this->rows($report, $targetHours);
        if (null !== $onlyUserId) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['userId'] === $onlyUserId));
        }

        return [
            'status' => $report->getStatus(),
            'requestedAt' => $report->getRequestedAt()->format(\DateTimeInterface::ATOM),
            'finishedAt' => $report->getFinishedAt()?->format(\DateTimeInterface::ATOM),
            'stale' => $stale,
            // A player reads their own reason only from their lines; the generation's error may name the others.
            'error' => null === $onlyUserId ? $report->getError() : null,
            'missingFiles' => $report->getMissingFiles(),
            'targetHours' => self::round($targetHours),
            'medianHours' => self::round($this->medianHours($report)),
            'slotCount' => count($report->getSlots()),
            'slots' => $rows,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(BalanceReport $report, ?float $targetHours): array
    {
        $measures = [];
        foreach ($report->getMeasures() ?? [] as $measure) {
            $measures[$measure['name']] = $measure;
        }
        $totalLocations = array_sum(array_column($measures, 'locations'));
        $totalHosted = array_sum(array_column($measures, 'hostedProgression'));
        $totalOwn = array_sum(array_column($measures, 'ownProgression'));
        $median = $this->medianHours($report);

        $rows = [];
        foreach ($report->getSlots() as $slot) {
            $measure = $measures[$slot['slotName']] ?? null;
            $locations = $measure['locations'] ?? null;
            $duration = $this->durations->forSlot($slot['gameId'], [$slot['userId']], $locations);
            $hosted = $measure['hostedProgression'] ?? null;
            $othersOwn = null === $measure ? 0 : $totalOwn - $measure['ownProgression'];

            $hostedShare = null !== $hosted && $totalHosted > 0 ? $hosted / $totalHosted : null;
            $fairShare = null !== $locations && $totalLocations > 0 ? $locations / $totalLocations : null;
            $othersShare = null !== $hosted && $othersOwn > 0 ? $hosted / $othersOwn : null;
            $medianGap = null !== $duration->hours && null !== $median && $median > 0 ? $duration->hours / $median : null;

            $alerts = [];
            if (null !== $medianGap && $medianGap > self::MEDIAN_ALERT_RATIO) {
                $alerts[] = 'duration_over_median';
            }
            if (null !== $duration->hours && null !== $targetHours && $duration->hours > $targetHours) {
                $alerts[] = 'duration_over_target';
            }
            if (null !== $hostedShare && null !== $fairShare && $fairShare > 0 && $hostedShare > self::FAIR_SHARE_ALERT_RATIO * $fairShare) {
                $alerts[] = 'hosted_over_fair';
            }
            if (null !== $othersShare && $othersShare > self::OTHERS_SHARE_ALERT) {
                $alerts[] = 'hosted_over_half';
            }

            $rows[] = [
                'slotName' => $slot['slotName'],
                'playerName' => $slot['playerName'],
                'userId' => $slot['userId'],
                'gameId' => $slot['gameId'],
                'gameName' => $slot['gameName'],
                'measured' => null !== $measure,
                'locations' => $locations,
                'ownProgression' => $measure['ownProgression'] ?? null,
                'hostedProgression' => $hosted,
                'hostedShare' => self::round($hostedShare, 3),
                'fairShare' => self::round($fairShare, 3),
                'othersShare' => self::round($othersShare, 3),
                'duration' => $duration->payload(),
                'medianGap' => self::round($medianGap),
                'alerts' => $alerts,
            ];
        }

        return $rows;
    }

    private function medianHours(BalanceReport $report): ?float
    {
        $byName = [];
        foreach ($report->getMeasures() ?? [] as $measure) {
            $byName[$measure['name']] = $measure['locations'];
        }
        $hours = [];
        foreach ($report->getSlots() as $slot) {
            $h = $this->durations->forSlot($slot['gameId'], [$slot['userId']], $byName[$slot['slotName']] ?? null)->hours;
            if (null !== $h) {
                $hours[] = $h;
            }
        }
        if ([] === $hours) {
            return null;
        }
        sort($hours);
        $n = count($hours);

        return 0 === $n % 2 ? ($hours[intdiv($n, 2) - 1] + $hours[intdiv($n, 2)]) / 2 : $hours[intdiv($n, 2)];
    }

    private static function round(?float $value, int $precision = 2): ?float
    {
        return null === $value ? null : round($value, $precision);
    }
}
