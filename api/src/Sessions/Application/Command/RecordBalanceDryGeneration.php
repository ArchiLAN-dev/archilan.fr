<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Application\Message\MeasureBalanceReportJob;
use App\Sessions\Application\Port\SeedArchiveGatewayInterface;
use App\Sessions\Application\Port\SessionOutputArtifactReaderInterface;
use App\Sessions\Application\Support\GenerationFailureParser;
use App\Sessions\Domain\Entity\BalanceReport;
use App\Sessions\Domain\Repository\BalanceReportRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Story 56.3: what the orchestrator reports on a dry generation (`balance-{scope}-{id}` sessions), and its measure.
 *
 * Generated: the output key is kept and the measure is queued on the worker. Crashed: the report carries the
 * summarized reason, the one a player can act on. The measure reads the multidata like story 56.1 does.
 */
final readonly class RecordBalanceDryGeneration
{
    public function __construct(
        private BalanceReportRepositoryInterface $reports,
        private SessionOutputArtifactReaderInterface $outputs,
        private SeedArchiveGatewayInterface $archives,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** The report id behind an orchestrator session id, or null when it is not a dry generation. */
    public static function reportIdOf(string $orchestratorSessionId): ?string
    {
        return str_starts_with($orchestratorSessionId, RunBalanceDryGeneration::ORCHESTRATOR_SESSION_PREFIX)
            ? substr($orchestratorSessionId, \strlen(RunBalanceDryGeneration::ORCHESTRATOR_SESSION_PREFIX))
            : null;
    }

    public function generated(string $reportId, string $outputKey): void
    {
        $report = $this->find($reportId);
        if (!$report instanceof BalanceReport) {
            return;
        }
        if ('' === $outputKey) {
            $report->fail('La génération n\'a rien laissé à mesurer.', $this->clock->now());
            $this->reports->save($report);

            return;
        }
        $report->recordOutput($outputKey);
        $this->reports->save($report);
        $this->bus->dispatch(new MeasureBalanceReportJob($report->getId()));
    }

    public function crashed(string $reportId, ?string $reason): void
    {
        $report = $this->find($reportId);
        if (!$report instanceof BalanceReport) {
            return;
        }
        $report->fail(GenerationFailureParser::summarize($reason), $this->clock->now());
        $this->reports->save($report);
    }

    public function measure(string $reportId): void
    {
        $report = $this->find($reportId);
        $outputKey = $report?->getOutputKey();
        if (!$report instanceof BalanceReport || null === $outputKey) {
            return;
        }

        $multidata = null;
        foreach ($this->outputs->listEntries($outputKey) as $entry) {
            if (str_ends_with(strtolower($entry), '.archipelago')) {
                $multidata = $this->outputs->extractEntry($outputKey, $entry);
                break;
            }
        }
        $measures = null === $multidata ? null : $this->archives->measure($multidata->contents, $multidata->filename);
        if (null === $measures) {
            $this->logger->warning('sessions.balance.unreadable', ['reportId' => $reportId]);
            $report->fail('La partie a été générée, mais sa mesure est illisible.', $this->clock->now());
            $this->reports->save($report);

            return;
        }

        $report->complete(self::normalized($measures), $this->clock->now());
        $this->reports->save($report);
    }

    /**
     * @param list<array{name: string, locations: int, ownProgression: int, hostedProgression: int}> $measures
     *
     * @return list<array{name: string, locations: int, ownProgression: int, hostedProgression: int}>
     */
    private static function normalized(array $measures): array
    {
        $normalized = [];
        foreach ($measures as $m) {
            $normalized[] = ['name' => $m['name'], 'locations' => max(0, $m['locations']), 'ownProgression' => max(0, $m['ownProgression']), 'hostedProgression' => max(0, $m['hostedProgression'])];
        }

        return $normalized;
    }

    private function find(string $reportId): ?BalanceReport
    {
        $separator = strpos($reportId, '-');
        if (false === $separator) {
            return null;
        }

        return $this->reports->find(substr($reportId, 0, $separator), substr($reportId, $separator + 1));
    }
}
