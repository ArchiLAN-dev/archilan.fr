<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\SessionConfig\Application\SessionConfigResolver;
use App\SessionConfig\Domain\SessionType;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyRunRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyTemplateRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * On-demand generation of the current ISO week's run for a single template.
 *
 * Mirrors the per-template body of {@see Handler\GenerateWeeklyRunsMessageHandler}, but with
 * throw-based validation instead of the bulk handler's silent skip: the admin clicked a button
 * and expects a concrete result (404/409/422), not a no-op.
 */
final readonly class GenerateWeeklyRunForTemplate
{
    public function __construct(
        private WeeklyTemplateRepositoryInterface $templates,
        private WeeklyRunRepositoryInterface $runs,
        private GameRepositoryInterface $games,
        private WeeklyRunGeneratorInterface $generator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private SessionConfigResolver $configResolver,
    ) {
    }

    public function generate(string $templateId): void
    {
        $template = $this->templates->findById($templateId);
        if (null === $template) {
            throw new \DomainException('template_not_found');
        }

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $weekYear = (int) $now->format('o');
        $weekNumber = (int) $now->format('W');

        if ($this->runs->existsByTemplateAndWeek($templateId, $weekYear, $weekNumber)) {
            throw new \DomainException('run_already_exists');
        }

        $yamlConfig = $template->getYamlConfig();
        $game = $this->games->findById($template->getGameId());
        $apworldStorageKey = $game instanceof Game ? $game->getApworldStorageKey() : null;

        if ('' === $yamlConfig || null === $apworldStorageKey || '' === $apworldStorageKey) {
            throw new \DomainException('template_incomplete');
        }

        $run = new WeeklyRun(
            id: bin2hex(random_bytes(8)),
            templateId: $templateId,
            weekYear: $weekYear,
            weekNumber: $weekNumber,
            seed: (string) random_int(1, 2_147_483_647),
            status: WeeklyRun::STATUS_ACTIVE,
            startedAt: $now,
            createdAt: $now,
        );

        // Persist first: the run is created not-launchable (generatedOutputKey = null).
        // Generation runs asynchronously on the orchestrator; the `session.generated` webhook
        // later marks the run launchable (see MarkWeeklyRunGenerated).
        $this->runs->save($run);

        $this->generator->generate(
            $run->getId(),
            $apworldStorageKey,
            $yamlConfig,
            $run->getSeed(),
            $this->configResolver->resolve(SessionType::Weekly)->generation->toGenerationParams(),
        );

        $this->logger->info('weekly_runs.generate.dispatched_single', [
            'weeklyRunId' => $run->getId(),
            'templateId' => $templateId,
        ]);
    }
}
