<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Sessions\Application\Port\SessionSaveStorageInterface;
use App\Sessions\Application\Port\SessionSpoilerArtifactReaderInterface;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Exception\SessionNotFoundException;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;

/**
 * The spoiler and the save an admin downloads from a session (story 17.37). Both come from durable storage: the
 * spoiler out of the generated output archive, the save where the orchestrateur copies it at each stop. They used to
 * be read from local copies an archive job never managed to make.
 */
final readonly class SessionArchiveDownloads
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private SessionSpoilerArtifactReaderInterface $spoilers,
        private SessionSaveStorageInterface $saves,
    ) {
    }

    /** @throws SessionNotFoundException */
    public function spoiler(string $sessionId): ?ArchivedFile
    {
        $outputKey = $this->session($sessionId)->getGeneratedOutputKey();
        $spoiler = null === $outputKey ? null : $this->spoilers->extractSpoiler($outputKey);

        return null === $spoiler ? null : new ArchivedFile($spoiler->filename, $spoiler->contents);
    }

    /** @throws SessionNotFoundException */
    public function save(string $sessionId): ?ArchivedFile
    {
        $this->session($sessionId);
        $contents = $this->saves->read($sessionId);

        return null === $contents ? null : new ArchivedFile($sessionId.'.apsave', $contents);
    }

    private function session(string $sessionId): Session
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            throw new SessionNotFoundException($sessionId);
        }

        return $session;
    }
}
