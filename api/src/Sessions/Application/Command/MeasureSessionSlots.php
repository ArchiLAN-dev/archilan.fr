<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Application\Port\SeedArchiveGatewayInterface;
use App\Sessions\Application\Port\SessionOutputArtifactReaderInterface;
use App\Sessions\Application\Query\SlotForecastQueryInterface;
use App\Sessions\Application\Service\SlotDurationEstimate;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Story 56.1: records on each slot of a generated session the size of its world and the progression it carries.
 *
 * The multidata is taken out of the session's output archive and read by the orchestrator's network-disabled
 * Archipelago container (the API never unpickles). Slots are matched by name, the one thing a multidata and a
 * session share. An archive that cannot be read leaves the slots unmeasured, with a warning: a measure never
 * stands in the way of a generation.
 *
 * Story 56.8: the duration each slot is expected to last is frozen at the same time, for the comparison with the real
 * one at the end.
 */
final readonly class MeasureSessionSlots
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private SessionSlotRepositoryInterface $slots,
        private SessionOutputArtifactReaderInterface $outputs,
        private SeedArchiveGatewayInterface $archives,
        private LoggerInterface $logger,
        private SlotDurationEstimate $durations,
        private SlotForecastQueryInterface $forecasts,
    ) {
    }

    public function execute(string $sessionId): SessionSlotsMeasureOutcome
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return SessionSlotsMeasureOutcome::UnknownSession;
        }

        $outputKey = $session->getGeneratedOutputKey() ?? '';
        $multidataName = '' === $outputKey ? null : $this->multidataEntry($outputKey);
        $multidata = null === $multidataName ? null : $this->outputs->extractEntry($outputKey, $multidataName);
        if (null === $multidata) {
            $this->logger->warning('sessions.measure_slots.no_output', ['sessionId' => $sessionId]);

            return SessionSlotsMeasureOutcome::NoOutput;
        }

        $measures = $this->archives->measure($multidata->contents, $multidata->filename);
        if (null === $measures) {
            $this->logger->warning('sessions.measure_slots.unreadable', ['sessionId' => $sessionId]);

            return SessionSlotsMeasureOutcome::Unreadable;
        }

        $byName = [];
        foreach ($measures as $measure) {
            $byName[$measure['name']] = $measure;
        }

        $players = $this->forecasts->slotPlayers($sessionId);
        foreach ($this->slots->findBySessionId($sessionId) as $slot) {
            $measure = $byName[$slot->getSlotName()] ?? null;
            if (null === $measure) {
                continue;
            }
            $slot->recordMeasures(
                max(0, $measure['locations']),
                max(0, $measure['ownProgression']),
                max(0, $measure['hostedProgression']),
            );
            $slot->recordEstimatedHours(
                $this->durations->forSlot($slot->getGameId(), $players[$slot->getId()] ?? [], $slot->getLocationsTotal())->hours,
            );
            $this->slots->persist($slot);
        }
        $this->slots->flush();

        return SessionSlotsMeasureOutcome::Measured;
    }

    private function multidataEntry(string $outputKey): ?string
    {
        foreach ($this->outputs->listEntries($outputKey) as $entry) {
            if (str_ends_with(strtolower($entry), '.archipelago')) {
                return $entry;
            }
        }

        return null;
    }
}
