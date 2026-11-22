<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Application\Message\MeasureSessionSlotsJob;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Records the MinIO key of a session's generated output archive
 * (`{sessionId}/output/archive.zip`), reported by the orchestrateur on `session.generated`.
 * The key lets owners/admins later download the spoiler from durable storage regardless of
 * the run's runtime state (story 16.8). No-op when the session is unknown or the key is empty.
 *
 * Once the key is stored, the slots are measured from the archive on the worker (story 56.1).
 */
final readonly class RecordSessionGeneratedOutput
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private MessageBusInterface $messageBus,
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

        $this->messageBus->dispatch(new MeasureSessionSlotsJob($sessionId));
    }
}
