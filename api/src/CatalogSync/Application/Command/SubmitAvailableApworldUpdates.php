<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Command;

use App\CatalogSync\Application\Exception\GithubRateLimitException;
use App\CatalogSync\Application\Message\SubmitAutoApworldUpdateJob;
use App\CatalogSync\Application\Service\ApworldVersionChecker;
use App\GameSelection\Application\Command\ApworldIncidentRecordOutcome;
use App\GameSelection\Application\Command\RecordApworldIncident;
use App\GameSelection\Application\Message\NotifyApworldIncidentAdminsJob;
use App\GameSelection\Application\Message\PostApworldIncidentToStaffChannelJob;
use App\GameSelection\Application\Message\StaffAlertEvent;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\GameSelection\Domain\Repository\ApworldCandidateRepositoryInterface;
use App\GameSelection\Domain\Repository\ApworldIncidentRepositoryInterface;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * The nightly automatic update (story 38.6): every game the version check found behind gets its new
 * apworld submitted as a candidate - tested before it serves anyone, like a manual import.
 *
 * What it will not do:
 * - submit more than `autoUpdateBatchSize` a night (10 by default, Jean's choice on 2026-09-25): the
 *   first real check found 233 pending updates, which would all have hit the orchestrator and Discord
 *   at once. The others stay "update available" and are taken the following nights;
 * - touch a game whose candidate is already in test - an admin may have just imported one;
 * - retry a version that was rejected (AC 7): it waits for the next release;
 * - guess between several apworld files in one release (AC 8): it opens an incident for an admin.
 *
 * The downloads and uploads themselves run in one job per game.
 */
final readonly class SubmitAvailableApworldUpdates
{
    public function __construct(
        private GameRepositoryInterface $games,
        private ApworldCandidateRepositoryInterface $candidates,
        private ApworldVersionChecker $checker,
        private RecordApworldIncident $recordIncident,
        private ApworldIncidentRepositoryInterface $incidents,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private int $autoUpdateBatchSize,
    ) {
    }

    /**
     * @param list<ApworldUpdateAvailable> $updates   in the order the check produced them
     * @param int|null                     $batchSize replaces the nightly cap for one run (story 38.11:
     *                                                the on-demand pass of `app:check-apworld-updates`)
     */
    public function submit(array $updates, ?int $batchSize = null): AutoUpdateSubmissionReport
    {
        $cap = $batchSize ?? $this->autoUpdateBatchSize;
        $jobs = [];
        $skipped = 0;
        $failed = 0;
        $opened = [];
        $considered = 0;

        foreach ($updates as $update) {
            if (\count($jobs) >= $cap) {
                break;
            }
            ++$considered;

            $game = $this->games->findById($update->gameId);
            if (!$game instanceof Game
                || null !== $this->candidates->findPendingForGame($update->gameId)
                || $this->candidates->hasRejectedVersion($update->gameId, $update->latestTag)) {
                ++$skipped;
                continue;
            }

            try {
                $assets = $this->checker->listAssets($game);
            } catch (GithubRateLimitException $e) {
                $this->logger->warning('catalog_sync.auto_update_rate_limit_hit', ['message' => $e->getMessage()]);
                break;
            } catch (HttpClientExceptionInterface $e) {
                ++$failed;
                $this->logger->warning('catalog_sync.auto_update_listing_failed', ['game' => $game->getName(), 'error' => $e->getMessage()]);
                continue;
            }

            // Only the assets of the release the check saw. Both sides strip a leading v/V, but they come
            // from two different calls: compare the normalized forms rather than trust they match.
            $wanted = ltrim($update->latestTag, 'vV');
            $releaseAssets = array_values(array_filter($assets ?? [], static fn (array $a): bool => null !== $a['tag'] && ltrim($a['tag'], 'vV') === $wanted));
            if (1 !== \count($releaseAssets)) {
                if ([] === $releaseAssets) {
                    ++$skipped;
                    continue;
                }
                $incidentId = $this->openAmbiguityIncident($update, $releaseAssets);
                if (null !== $incidentId) {
                    $opened[] = $incidentId;
                }
                continue;
            }

            $jobs[] = new SubmitAutoApworldUpdateJob($update->gameId, $update->latestTag, $releaseAssets[0]['downloadUrl'], $releaseAssets[0]['name']);
        }

        // Incidents first: they are committed before any alert or job leaves.
        $this->incidents->flush();
        foreach ($opened as $incidentId) {
            $this->messageBus->dispatch(new NotifyApworldIncidentAdminsJob($incidentId));
            $this->messageBus->dispatch(new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Opened));
        }
        foreach ($jobs as $job) {
            $this->messageBus->dispatch($job);
        }

        $report = new AutoUpdateSubmissionReport(\count($jobs), \count($updates) - $considered, $skipped, $failed, $opened);
        $this->logger->info('catalog_sync.auto_update_submitted', [
            'queued' => $report->queued,
            'deferred' => $report->deferred,
            'skipped' => $report->skipped,
            'failed' => $report->failed,
            'ambiguous' => \count($opened),
        ]);

        return $report;
    }

    /**
     * Keyed on the release rather than a hash: nothing was downloaded, and a later release is a new
     * question for the admin.
     *
     * @param list<array{name: string, downloadUrl: string, size: int, tag: ?string}> $assets
     */
    private function openAmbiguityIncident(ApworldUpdateAvailable $update, array $assets): ?string
    {
        $recording = $this->recordIncident->record(
            $update->gameId,
            'release:'.$update->latestTag,
            ApworldIncidentType::UpdateAmbiguous,
            sprintf(
                'La release %s contient %d apworlds (%s) : impossible de choisir automatiquement. Importe le bon fichier depuis la page du jeu.',
                $update->latestTag,
                \count($assets),
                implode(', ', array_column($assets, 'name')),
            ),
        );

        return ApworldIncidentRecordOutcome::Opened === $recording->outcome ? $recording->incidentId : null;
    }
}
