<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;

/**
 * Records the MinIO key of a session's generated output archive
 * (`{sessionId}/output/archive.zip`), reported by the orchestrateur on `session.generated`.
 * The key lets owners/admins later download the spoiler from durable storage regardless of
 * the run's runtime state (story 16.8). No-op when the session is unknown or the key is empty.
 */
final readonly class RecordSessionGeneratedOutput
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
    ) {
    }

    public function execute(string $sessionId, string $outputKey): void
    {
        if ('' === $outputKey) {
            return;
        }

        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return;
        }

        $session->markGenerated($outputKey);
        $this->sessions->persist($session);
        $this->sessions->flush();
    }
}
