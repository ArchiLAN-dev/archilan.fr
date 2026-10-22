<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Handler;

use App\CatalogSync\Application\Command\ApworldUpdateAvailable;
use App\CatalogSync\Application\Command\ApworldUpdateCheckReport;
use App\CatalogSync\Application\Command\CheckApworldUpdatesService;
use App\CatalogSync\Application\Command\SubmitAvailableApworldUpdates;
use App\CatalogSync\Application\Message\CheckApworldUpdatesMessage;
use App\CatalogSync\Domain\Entity\ApworldUpdateCheckRun;
use App\CatalogSync\Domain\Repository\ApworldUpdateCheckRunRepositoryInterface;
use App\GameSelection\Application\Port\ExclusivePassLockInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A pass over the apworlds tracked on GitHub: check every one for a newer release (story 38.5) and, for the
 * nightly run only, submit what was found as candidates within the nightly cap (story 38.6). Without a GitHub
 * token the checker does nothing and says so in its own log line; the report then simply counts zero games.
 *
 * Story 38.18: the catalogue page's button queues a pass that only checks (`submit: false`). Every pass is
 * recorded (`ApworldUpdateCheckRun`) with its progress, so the page can follow it, and runs under a lock: two
 * passes never run at once, whichever worker picks them up. A pass that fails is recorded as failed and not
 * retried: what it saved stays, and the next one resumes from the games it did not reach.
 */
#[AsMessageHandler]
final readonly class CheckApworldUpdatesHandler
{
    public const string PASS = 'catalog-sync.apworld-update-check';

    public function __construct(
        private CheckApworldUpdatesService $service,
        private SubmitAvailableApworldUpdates $submitUpdates,
        private ApworldUpdateCheckRunRepositoryInterface $runs,
        private ExclusivePassLockInterface $lock,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CheckApworldUpdatesMessage $message): void
    {
        if (!$this->lock->tryAcquire(self::PASS)) {
            $this->logger->warning('catalog_sync.update_check_skipped', ['reason' => 'another pass is running', 'runId' => $message->runId]);
            $this->closeQueued($message->runId, 'Une autre vérification tournait déjà.');

            return;
        }

        try {
            $run = $this->runFor($message);
            if (null === $run) {
                return;
            }
            $this->check($run, $message->submit);
        } finally {
            $this->lock->release(self::PASS);
        }
    }

    /** The pass this message is about: the one the button recorded, or a new one for the night. */
    private function runFor(CheckApworldUpdatesMessage $message): ?ApworldUpdateCheckRun
    {
        if (null !== $message->runId) {
            $run = $this->runs->findById($message->runId);

            // Already started or closed (a redelivered message, a pass closed as stale): nothing left to do.
            return null !== $run && ApworldUpdateCheckRun::STATUS_QUEUED === $run->getStatus() ? $run : null;
        }

        $now = $this->clock->now();
        // Holding the lock, no other pass is running: an active one is a queued click the night overtook, or a
        // pass whose worker died. The night takes over, it checks everything the click would have checked.
        $active = $this->runs->findActive();
        if (null !== $active) {
            $active->fail('Remplacée par la vérification de nuit.', $now);
            $this->runs->save($active);
        }

        $run = ApworldUpdateCheckRun::queue(bin2hex(random_bytes(16)), ApworldUpdateCheckRun::TRIGGER_NIGHTLY, $now);
        if (!$this->runs->claim($run)) {
            $this->logger->error('catalog_sync.update_check_not_recorded', ['trigger' => ApworldUpdateCheckRun::TRIGGER_NIGHTLY]);

            return null;
        }

        return $this->runs->findById($run->getId());
    }

    private function check(ApworldUpdateCheckRun $run, bool $submit): void
    {
        $run->start($this->clock->now());
        $this->runs->save($run);

        try {
            $report = $this->service->checkAll(function (int $total, ApworldUpdateCheckReport $soFar) use ($run): void {
                $run->progress($total, $soFar->checked, $soFar->failed, \count($soFar->updatesAvailable), $soFar->rateLimitHit, $this->clock->now());
                $this->runs->save($run);
            });
        } catch (\Throwable $e) {
            $this->logger->error('catalog_sync.update_check_failed', ['runId' => $run->getId(), 'exception' => $e]);
            $run->fail($e->getMessage(), $this->clock->now());
            $this->runs->save($run);

            return;
        }

        $run->finish($report->checked, $report->failed, \count($report->updatesAvailable), $report->rateLimitHit, $this->clock->now());
        $this->runs->save($run);

        $this->logger->info('catalog_sync.update_check_done', [
            'trigger' => $run->getTrigger(),
            'checked' => $report->checked,
            'failed' => $report->failed,
            'rateLimitHit' => $report->rateLimitHit,
            'updatesAvailable' => array_map(
                static fn (ApworldUpdateAvailable $update): string => sprintf('%s -> %s', $update->gameName, $update->latestTag),
                $report->updatesAvailable,
            ),
        ]);

        if ($submit && [] !== $report->updatesAvailable) {
            $this->submitUpdates->submit($report->updatesAvailable);
        }
    }

    private function closeQueued(?string $runId, string $reason): void
    {
        $run = null === $runId ? null : $this->runs->findById($runId);
        if (null !== $run && ApworldUpdateCheckRun::STATUS_QUEUED === $run->getStatus()) {
            $run->fail($reason, $this->clock->now());
            $this->runs->save($run);
        }
    }
}
