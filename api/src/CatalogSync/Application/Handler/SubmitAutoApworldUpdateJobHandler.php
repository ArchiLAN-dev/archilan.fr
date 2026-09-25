<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Handler;

use App\CatalogSync\Application\Message\SubmitAutoApworldUpdateJob;
use App\CatalogSync\Application\Service\ApworldVersionChecker;
use App\GameSelection\Application\Command\SubmitApworldCandidate;
use App\GameSelection\Domain\Enum\ApworldCandidateOrigin;
use App\GameSelection\Domain\Repository\ApworldCandidateRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Downloads one release found by the nightly check and submits it as an automatic candidate (story
 * 38.6). It then goes through the same test as a manual import before serving anyone.
 *
 * Never throws: a failed download or upload is logged and the game is simply picked up again the next
 * night, since it is still "update available".
 */
#[AsMessageHandler]
final readonly class SubmitAutoApworldUpdateJobHandler
{
    public function __construct(
        private ApworldVersionChecker $checker,
        private SubmitApworldCandidate $submitCandidate,
        private ApworldCandidateRepositoryInterface $candidates,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SubmitAutoApworldUpdateJob $job): void
    {
        // An admin may have submitted a version by hand since the job was queued: a submission
        // supersedes the candidate in test, and the night must not overwrite the admin's choice.
        if (null !== $this->candidates->findTestingForGame($job->gameId)) {
            $this->logger->info('catalog_sync.auto_update_skipped_candidate_in_test', ['gameId' => $job->gameId]);

            return;
        }

        try {
            $contents = $this->checker->downloadAsset($job->assetDownloadUrl);
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->warning('catalog_sync.auto_update_failed', ['gameId' => $job->gameId, 'step' => 'download', 'error' => $e->getMessage()]);

            return;
        }

        $submission = $this->submitCandidate->submit($job->gameId, $contents, $job->assetName, $job->versionTag, ApworldCandidateOrigin::Auto, null);
        if (null === $submission->candidateId) {
            $this->logger->warning('catalog_sync.auto_update_failed', ['gameId' => $job->gameId, 'step' => 'submit', 'errors' => $submission->errors]);
        }
    }
}
