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
use App\Sessions\Domain\Service\PartySimulation;
use App\Sessions\Domain\ValueObject\PartySimulationResult;

/**
 * Story 56.3: the balance report of the last dry generation of an event or a private run.
 *
 * Per slot: size, estimated duration and what it rests on (story 56.2), its gap to the party's median, the share of
 * the others' progression it hosts and its fair share (`locations ÷ all locations`, story 56.1). Alerts, never a
 * block:
 * - `duration_over_median`: more than twice the party's median duration;
 * - `duration_over_target`: longer than the event's target (story 56.5: the admin's, or its length minus 20 %);
 * - `hosted_over_fair`: hosts more than twice its fair share;
 * - `hosted_over_half`: hosts more than half of the others' progression.
 *
 * `stale`: the registrations no longer match what was generated (a YAML saved, a slot or a registration added).
 *
 * Story 56.4: the party simulation (`PartySimulation`) from the playthrough kept with the report - per slot, when it
 * would end, its time in BK and the slots it waits on most; for the party, its end and its critical slot. A slot
 * whose pace is unknown plays at the party's median pace (`paceAssumed`). `simulation.status`: `done`, `missing` (no
 * playthrough: a report from before 56.4, or a spoiler without one) or `no_pace` (no slot has a known pace).
 */
final readonly class BalanceReportQuery
{
    public const float MEDIAN_ALERT_RATIO = 2.0;
    public const float FAIR_SHARE_ALERT_RATIO = 2.0;
    public const float OTHERS_SHARE_ALERT = 0.5;

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

        return ['found' => true, 'authorized' => true, 'report' => $this->build(BalanceReport::SCOPE_RUN, $runId, null === $run->getTargetHours() ? null : (float) $run->getTargetHours(), null)];
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
        return $event->targetHours();
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

        [$simulation, $assumed, $simulationStatus] = $this->simulate($report);
        $rows = $this->rows($report, $targetHours, $simulation, $assumed);
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
            // A player reads their own course, not the party's critical slot.
            'simulation' => null === $onlyUserId ? [
                'status' => $simulationStatus,
                'endHours' => $simulation instanceof PartySimulationResult ? self::round($simulation->endHours) : null,
                'criticalSlot' => $simulation?->criticalSlot,
            ] : null,
            'slots' => $rows,
        ];
    }

    /**
     * @return array{PartySimulationResult|null, array<string, true>, string} the simulation, the slots played at the
     *                                                                        median pace, its status
     */
    private function simulate(BalanceReport $report): array
    {
        $playthrough = $report->getPlaythrough();
        if (BalanceReport::STATUS_DONE !== $report->getStatus() || null === $playthrough) {
            return [null, [], 'missing'];
        }
        $locations = [];
        foreach ($report->getMeasures() ?? [] as $measure) {
            $locations[$measure['name']] = $measure['locations'];
        }

        $hours = [];
        $paces = [];
        foreach ($report->getSlots() as $slot) {
            $count = $locations[$slot['slotName']] ?? null;
            $h = $this->durations->forSlot($slot['gameId'], [$slot['userId']], $count)->hours;
            $hours[$slot['slotName']] = $h;
            if (null !== $h && $h > 0 && null !== $count) {
                $paces[] = $count / $h;
            }
        }
        $medianPace = self::median($paces);
        if (null === $medianPace || $medianPace <= 0) {
            return [null, [], 'no_pace'];
        }

        $work = [];
        $assumed = [];
        foreach ($hours as $name => $h) {
            if (null !== $h) {
                $work[$name] = $h;
                continue;
            }
            $assumed[$name] = true;
            $work[$name] = ($locations[$name] ?? 0) / $medianPace;
        }

        return [PartySimulation::run($work, $playthrough), $assumed, 'done'];
    }

    /** @param list<float> $values */
    private static function median(array $values): ?float
    {
        if ([] === $values) {
            return null;
        }
        sort($values);
        $n = count($values);

        return 0 === $n % 2 ? ($values[intdiv($n, 2) - 1] + $values[intdiv($n, 2)]) / 2 : $values[intdiv($n, 2)];
    }

    /**
     * @param array<string, true> $assumed
     *
     * @return list<array<string, mixed>>
     */
    private function rows(BalanceReport $report, ?float $targetHours, ?PartySimulationResult $simulation, array $assumed): array
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
                'simulation' => self::simulatedRow($simulation, $slot['slotName'], isset($assumed[$slot['slotName']])),
            ];
        }

        return $rows;
    }

    /**
     * @return array{finishHours: float|null, bkHours: float|null, waitsOn: list<array{slotName: string, hours: float|null}>, paceAssumed: bool}|null
     */
    private static function simulatedRow(?PartySimulationResult $simulation, string $slotName, bool $assumed): ?array
    {
        $slot = $simulation?->slots[$slotName] ?? null;
        if (null === $slot) {
            return null;
        }
        $waits = [];
        foreach ($slot->waitsOn as $name => $hours) {
            $waits[] = ['slotName' => $name, 'hours' => self::round($hours)];
        }

        return ['finishHours' => self::round($slot->finishHours), 'bkHours' => self::round($slot->bkHours), 'waitsOn' => $waits, 'paceAssumed' => $assumed];
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
