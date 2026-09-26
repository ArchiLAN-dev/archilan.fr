<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Handler;

use App\Community\Application\Query\CommunityAdminIdsQueryInterface;
use App\Community\Application\Support\Notifier;
use App\GameSelection\Application\Message\NotifyApworldIncidentAdminsJob;
use App\GameSelection\Domain\Repository\ApworldIncidentRepositoryInterface;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class NotifyApworldIncidentAdminsHandler
{
    public function __construct(
        private ApworldIncidentRepositoryInterface $incidents,
        private GameRepositoryInterface $games,
        private CommunityAdminIdsQueryInterface $admins,
        private Notifier $notifier,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(NotifyApworldIncidentAdminsJob $job): void
    {
        $incident = $this->incidents->findById($job->incidentId);
        if (null === $incident) {
            return;
        }

        // The game may have been removed since: its id still tells an admin which one it was.
        $gameName = $this->games->findById($incident->getGameId())?->getName() ?? $incident->getGameId();

        $payload = [
            'incidentId' => $incident->getId(),
            'gameId' => $incident->getGameId(),
            'gameName' => $gameName,
            'incidentType' => $incident->getType()->value,
        ];

        // Notifier is best-effort per recipient (it logs and swallows): one failing admin does not
        // deprive the others.
        foreach ($this->admins->adminUserIds() as $adminId) {
            $this->notifier->notify($adminId, NotifyApworldIncidentAdminsJob::NOTIFICATION_TYPE, $payload);
        }

        $this->logger->info('apworld_incidents.admins_notified', ['incidentId' => $incident->getId()]);
    }
}
