<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Handler;

use App\Community\Application\Support\Notifier;
use App\GameSelection\Application\Message\ApworldPromoted;
use App\GameSelection\Application\Service\PromotedSlotYaml;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\PersonalRuns\Application\Message\RunSlotPreflightJob;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Brings the slots of personal runs not yet launched along when their game switches apworld (story 38.7).
 *
 * A slot kept the apworld it was created with (story 3.10), so a slot created before a fix stayed on
 * the broken version - Crystal Project v0.17.0 for instance. Until its run is launched, a slot now
 * follows its game; from the launch, it is frozen as before.
 *
 * Its YAML follows {@see PromotedSlotYaml}: never touched, it takes the new default; customised and
 * still valid, it is kept byte for byte; customised and no longer valid, it is kept and marked for
 * review, and the player is told. Every upgraded slot gets a new test generation.
 *
 * One unit of work per run: a run that fails is logged and the others go on.
 */
#[AsMessageHandler]
final readonly class UpgradeRunSlotsAfterApworldPromotionHandler
{
    public const string NOTIFICATION_TYPE = 'slot_yaml_needs_review';

    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private GameRepositoryInterface $games,
        private Notifier $notifier,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApworldPromoted $promotion): void
    {
        $game = $this->games->findById($promotion->gameId);
        if (!$game instanceof Game) {
            return;
        }
        $plan = PromotedSlotYaml::for($promotion, $game);

        foreach ($this->runs->findByStatuses([Run::STATUS_DRAFT]) as $run) {
            if ($run->isLockedForEditing()) {
                continue;
            }
            try {
                $this->upgradeRun($run, $game, $plan);
            } catch (\Throwable $e) {
                $this->logger->error('personal_run.slot_upgrade_failed', ['runId' => $run->getId(), 'gameId' => $promotion->gameId, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Two steps (story 38.7 review): every slot of the run is decided first, touching nothing, so a
     * failure leaves nothing half applied in memory for a later flush to write; then everything is
     * applied and saved at once, even a slot that changed apworld without any test or review.
     */
    private function upgradeRun(Run $run, Game $game, PromotedSlotYaml $plan): void
    {
        $upgrades = [];
        foreach ($this->participants->findByRunId($run->getId()) as $participant) {
            foreach ($participant->getGameSlots() as $slot) {
                if ($plan->concerns($slot['gameId'], $slot['apworldHash'] ?? null)) {
                    $upgrades[] = [$participant, $slot['slotId'], $slot['playerYaml'] ?? null, $plan->decide($slot['playerYaml'] ?? null)];
                }
            }
        }
        if ([] === $upgrades) {
            return;
        }

        $preflights = [];
        $notifications = [];
        $now = $this->clock->now();
        foreach ($upgrades as [$participant, $slotId, $playerYaml, $decision]) {
            $participant->upgradeSlotApworld($slotId, $plan->promotion->newHash, $decision->replacementYaml, $decision->reviewReasons);

            // Every upgraded slot is tested again, like a saved YAML (story 9.42).
            $effectiveYaml = $decision->replacementYaml ?? $playerYaml;
            if (null !== $effectiveYaml && '' !== $effectiveYaml) {
                $yamlSha = hash('sha256', $effectiveYaml);
                $participant->recordSlotPreflight($slotId, 'pending', '', $yamlSha, $now);
                $preflights[] = new RunSlotPreflightJob($run->getId(), $participant->getUserId(), $slotId, $yamlSha);
            }
            if ($decision->needsReview()) {
                $notifications[] = [$participant->getUserId(), [
                    'runId' => $run->getId(),
                    'runTitle' => $run->getTitle(),
                    'gameId' => $game->getId(),
                    'gameName' => $game->getName(),
                    'slotId' => $slotId,
                    'reasons' => $decision->reviewReasons,
                ]];
            }
        }

        // The run's unit of work, then what it triggers.
        $this->participants->flush();
        foreach ($preflights as $job) {
            $this->messageBus->dispatch($job);
        }
        foreach ($notifications as [$userId, $payload]) {
            $this->notifier->notify($userId, self::NOTIFICATION_TYPE, $payload);
        }

        $this->logger->info('personal_run.slots_upgraded', [
            'runId' => $run->getId(),
            'gameId' => $game->getId(),
            'upgraded' => \count($upgrades),
            'tested' => \count($preflights),
            'needsReview' => \count($notifications),
        ]);
    }
}
