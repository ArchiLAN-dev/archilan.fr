<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Handler;

use App\GameSelection\Application\Exception\StaffAlertDeliveryException;
use App\GameSelection\Application\Message\PostApworldIncidentToStaffChannelJob;
use App\GameSelection\Application\Message\StaffAlertEvent;
use App\GameSelection\Application\Port\StaffAlertChannelInterface;
use App\GameSelection\Application\Support\StaffAlertFactory;
use App\GameSelection\Domain\Repository\ApworldIncidentRepositoryInterface;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PostApworldIncidentToStaffChannelHandler
{
    public function __construct(
        private ApworldIncidentRepositoryInterface $incidents,
        private GameRepositoryInterface $games,
        private UserRepositoryInterface $users,
        private StaffAlertFactory $alerts,
        private StaffAlertChannelInterface $channel,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Never throws: the alert is a side effect of a transition already committed, and a Discord
     * outage must not send the job round the failure transport forever. A lost message is logged.
     */
    public function __invoke(PostApworldIncidentToStaffChannelJob $job): void
    {
        $incident = $this->incidents->findById($job->incidentId);
        if (null === $incident) {
            return;
        }

        $gameName = $this->games->findById($incident->getGameId())?->getName() ?? $incident->getGameId();

        try {
            $alert = match ($job->event) {
                StaffAlertEvent::Opened => $this->alerts->opened($incident, $gameName),
                StaffAlertEvent::Acknowledged => $this->alerts->acknowledged($incident, $gameName, $this->adminName($incident->getAcknowledgedBy())),
                StaffAlertEvent::Resolved, StaffAlertEvent::Ignored => $this->alerts->closed(
                    $incident,
                    $gameName,
                    null === $incident->getClosedBy() ? null : $this->adminName($incident->getClosedBy()),
                ),
            };
            $this->channel->post($alert);
        } catch (StaffAlertDeliveryException|\LogicException $e) {
            $this->logger->warning('apworld_incidents.staff_alert_not_posted', [
                'incidentId' => $incident->getId(),
                'event' => $job->event->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function adminName(?string $userId): string
    {
        return (null === $userId ? null : $this->users->findById($userId)?->getDisplayName()) ?? 'Un admin';
    }
}
