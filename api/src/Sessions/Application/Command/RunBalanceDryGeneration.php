<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\GameSelection\Application\Support\GenerationFiles;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\SessionConfig\Application\Service\SessionConfigResolver;
use App\SessionConfig\Domain\Enum\SessionType;
use App\SessionConfig\Domain\Enum\SpoilerLevel;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Sessions\Application\Service\BalanceInputs;
use App\Sessions\Application\Support\SlotNameGenerator;
use App\Sessions\Domain\Entity\BalanceReport;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\BalanceReportRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Shared\Application\Support\SlotYamlNameReader;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Story 56.3: generates a whole event or private run without launching it, to measure its balance before the day.
 *
 * Same path as a real generation (registrations' or participants' slots, saved YAML or the game's default, current
 * apworlds, the scope's generation settings, progression cap included) on an orchestrator session of its own,
 * `balance-{scope}-{id}`: no API session is created, no server is started. The orchestrator reports back on its
 * webhook (`RecordBalanceDryGeneration`). A world that reads a file at generation (a ROM) cannot be generated
 * without it: the report says so rather than failing.
 */
final readonly class RunBalanceDryGeneration
{
    public const string ORCHESTRATOR_SESSION_PREFIX = 'balance-';

    /** A dry generation older than this is taken as lost (orchestrator restarted, webhook missed). */
    private const int STALE_GENERATION_SECONDS = 3600;

    public function __construct(
        private BalanceReportRepositoryInterface $reports,
        private BalanceInputs $inputs,
        private EventRepositoryInterface $events,
        private SessionRepositoryInterface $sessions,
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private RunnerGatewayInterface $runner,
        private SlotNameGenerator $slotNames,
        private SessionConfigResolver $configResolver,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** An admin asks for the dry generation of an event. */
    public function forEvent(string $eventId): BalanceDryGenerationOutcome
    {
        if (!$this->events->findById($eventId) instanceof Event) {
            return BalanceDryGenerationOutcome::NotFound;
        }

        // Event sessions are configured per session (epic 27): the latest one says how the event is generated.
        $sessions = $this->sessions->findByEventId($eventId);
        usort($sessions, static fn (Session $a, Session $b): int => $b->getCreatedAt() <=> $a->getCreatedAt());
        $scopeKey = [] !== $sessions ? $sessions[0]->getId() : $eventId;

        return $this->run(BalanceReport::SCOPE_EVENT, $eventId, SessionType::Event, $scopeKey);
    }

    /** The owner of a private run (or a co-organiser, story 16.27, or an admin) asks for its dry generation. */
    public function forRun(string $runId, string $callerId, bool $isAdmin): BalanceDryGenerationOutcome
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return BalanceDryGenerationOutcome::NotFound;
        }
        $participant = $run->isOwnedBy($callerId) ? null : $this->participants->findByRunAndUser($runId, $callerId);
        if (!$isAdmin && !$run->isAdministeredBy($callerId, $participant instanceof RunParticipant && $participant->isCoOwner())) {
            return BalanceDryGenerationOutcome::Forbidden;
        }
        if ($run->isImportedSeed()) {
            return BalanceDryGenerationOutcome::ImportedSeed;
        }

        return $this->run(BalanceReport::SCOPE_RUN, $runId, SessionType::Private, $runId);
    }

    private function run(string $scopeType, string $scopeId, SessionType $type, string $configScope): BalanceDryGenerationOutcome
    {
        $now = $this->clock->now();
        $report = $this->reports->find($scopeType, $scopeId);
        if ($report instanceof BalanceReport && $report->isGenerating()
            && $now->getTimestamp() - $report->getRequestedAt()->getTimestamp() < self::STALE_GENERATION_SECONDS) {
            return BalanceDryGenerationOutcome::AlreadyGenerating;
        }

        $inputs = $this->inputs->forScope($scopeType, $scopeId);
        if ([] === $inputs['slots']) {
            return BalanceDryGenerationOutcome::NoSlots;
        }

        $names = $this->slotNames->generate(array_map(static fn (array $s): array => [
            'playerName' => $s['playerName'],
            'archipelagoGameName' => $s['archipelagoGameName'],
            'preferredName' => SlotYamlNameReader::read($s['playerYaml']),
        ], $inputs['slots']));

        $slots = [];
        foreach ($inputs['slots'] as $i => $slot) {
            $slots[] = ['slotName' => $names[$i], 'playerName' => $slot['playerName'], 'userId' => $slot['userId'], 'gameId' => $slot['gameId'], 'gameName' => $slot['gameName']];
        }
        $fingerprint = BalanceInputs::fingerprint($inputs['slots']);

        if ($report instanceof BalanceReport) {
            $report->restart($slots, $fingerprint, $now);
        } else {
            $report = BalanceReport::start($scopeType, $scopeId, $slots, $fingerprint, $now);
        }

        $needed = array_values(array_filter(GenerationFiles::forGames($inputs['games']), static fn (array $f): bool => $f['neededAtGeneration']));
        if ([] !== $needed) {
            $games = array_values(array_unique(array_merge(...array_map(static fn (array $f): array => $f['games'], $needed))));
            $report->needFiles($games, $now);
            $this->reports->save($report);

            return BalanceDryGenerationOutcome::NeedsFiles;
        }
        $this->reports->save($report);

        $orchestratorSessionId = self::ORCHESTRATOR_SESSION_PREFIX.$report->getId();
        try {
            $configured = $this->runner->configureSession($orchestratorSessionId, array_map(
                static fn (array $s, string $name): array => ['slotName' => $name, 'apworldHash' => $s['apworldHash'], 'playerYaml' => $s['playerYaml']],
                $inputs['slots'],
                $names,
            ));
            if (!$configured['valid'] || [] !== $configured['errors']) {
                $lines = array_map(static fn (array $e): string => $e['playerName'].' : '.implode(' ; ', $e['errors']), $configured['errors']);
                $report->fail('' !== implode("\n", $lines) ? implode("\n", $lines) : 'Les YAML ont été refusés.', $this->clock->now());
                $this->reports->save($report);

                return BalanceDryGenerationOutcome::Failed;
            }

            $options = $this->configResolver->resolve($type, $configScope)->generation->toGenerationParams();
            // Story 56.4: the simulation reads the playthrough; no player ever sees this spoiler.
            $options['spoiler'] = max(SpoilerLevel::WithPlaythrough->value, $options['spoiler']);
            $this->runner->generateSession($orchestratorSessionId, bin2hex(random_bytes(16)), null, $options);
        } catch (\Throwable $e) {
            $this->logger->warning('sessions.balance.dry_generation_unavailable', ['reportId' => $report->getId(), 'error' => $e->getMessage()]);
            $report->fail('Le générateur est indisponible. Réessaie dans un instant.', $this->clock->now());
            $this->reports->save($report);

            return BalanceDryGenerationOutcome::Failed;
        }

        $this->logger->info('sessions.balance.dry_generation_started', ['reportId' => $report->getId(), 'slots' => count($slots)]);

        return BalanceDryGenerationOutcome::Started;
    }
}
