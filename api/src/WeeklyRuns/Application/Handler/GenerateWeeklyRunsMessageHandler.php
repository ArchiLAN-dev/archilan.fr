<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Handler;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\SessionConfig\Application\SessionConfigResolver;
use App\SessionConfig\Domain\SessionType;
use App\WeeklyRuns\Application\Message\GenerateWeeklyRunsMessage;
use App\WeeklyRuns\Application\WeeklyRunGeneratorInterface;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyRunRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyTemplateRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GenerateWeeklyRunsMessageHandler
{
    public function __construct(
        private WeeklyRunRepositoryInterface $runs,
        private WeeklyTemplateRepositoryInterface $templates,
        private GameRepositoryInterface $games,
        private WeeklyRunGeneratorInterface $generator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private SessionConfigResolver $configResolver,
    ) {
    }

    public function __invoke(GenerateWeeklyRunsMessage $message): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $weekYear = (int) $now->format('o');
        $weekNumber = (int) $now->format('W');
        $seed = (string) random_int(1, 2_147_483_647);

        $activeTemplates = $this->templates->findAllActive();

        foreach ($activeTemplates as $template) {
            $templateId = $template->getId();

            if ($this->runs->existsByTemplateAndWeek($templateId, $weekYear, $weekNumber)) {
                continue;
            }

            $yamlConfig = $template->getYamlConfig();
            $game = $this->games->findById($template->getGameId());

            if (!$game instanceof Game) {
                $this->logger->warning('weekly_runs.generate.template_not_found', ['templateId' => $templateId]);
                continue;
            }

            $apworldStorageKey = $game->getApworldStorageKey();

            if ('' === $yamlConfig || null === $apworldStorageKey || '' === $apworldStorageKey) {
                $this->logger->warning('weekly_runs.generate.template_incomplete', ['templateId' => $templateId]);
                continue;
            }

            $run = new WeeklyRun(
                id: bin2hex(random_bytes(8)),
                templateId: $templateId,
                weekYear: $weekYear,
                weekNumber: $weekNumber,
                seed: $seed,
                status: WeeklyRun::STATUS_ACTIVE,
                startedAt: $now,
                createdAt: $now,
            );

            // Persist the run first: it is created not-launchable (generatedOutputKey = null).
            // Generation runs asynchronously on the orchestrator; the `session.generated`
            // webhook later marks the run launchable (see MarkWeeklyRunGenerated).
            $this->runs->save($run);

            try {
                $this->generator->generate(
                    $run->getId(),
                    $apworldStorageKey,
                    $yamlConfig,
                    $run->getSeed(),
                    $this->configResolver->resolve(SessionType::Weekly)->generation->toGenerationParams(),
                );

                $this->logger->info('weekly_runs.generate.dispatched', [
                    'weeklyRunId' => $run->getId(),
                    'templateId' => $templateId,
                ]);
            } catch (\Throwable $e) {
                // A failed dispatch leaves this run not-launchable; an admin can re-trigger
                // via "Générer maintenant". It must not abort the other templates.
                $this->logger->error('weekly_runs.generate.dispatch_failed', [
                    'weeklyRunId' => $run->getId(),
                    'templateId' => $templateId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
