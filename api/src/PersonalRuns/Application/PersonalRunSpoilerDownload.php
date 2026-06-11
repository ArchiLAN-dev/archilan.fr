<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application;

use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Sessions\Application\SessionSpoilerArtifactReaderInterface;
use App\Sessions\Application\SpoilerArtifact;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;

/**
 * Resolves the spoiler log of a private run for download, from durable storage (MinIO),
 * so it works regardless of the run's runtime state (running / idle / stopped).
 *
 * Authorization: the run **owner** or an **admin** (admins need not be participants).
 * Only the spoiler entry is returned - never the multidata or other players' patches
 * (the reader enforces that).
 */
final readonly class PersonalRunSpoilerDownload
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private SessionRepositoryInterface $sessions,
        private SessionSpoilerArtifactReaderInterface $reader,
    ) {
    }

    /**
     * @return array{found: bool, authorized: bool, spoiler: SpoilerArtifact|null}
     */
    public function execute(string $runId, string $userId, bool $isAdmin): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return ['found' => false, 'authorized' => false, 'spoiler' => null];
        }

        if (!$run->isOwnedBy($userId) && !$isAdmin) {
            return ['found' => true, 'authorized' => false, 'spoiler' => null];
        }

        $sessionId = $run->getSessionId();
        if (null === $sessionId) {
            return ['found' => true, 'authorized' => true, 'spoiler' => null];
        }

        $session = $this->sessions->findById($sessionId);
        $outputKey = ($session instanceof Session ? $session->getGeneratedOutputKey() : null)
            ?? $sessionId.'/output/archive.zip';

        return ['found' => true, 'authorized' => true, 'spoiler' => $this->reader->extractSpoiler($outputKey)];
    }
}
