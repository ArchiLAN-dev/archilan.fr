<?php

declare(strict_types=1);

namespace App\Membership\Application;

use App\Identity\Application\Message\SyncDiscordRoleMessage;
use App\Membership\Application\Message\MembershipExpiredNotificationMessage;
use App\Membership\Application\Message\SyncMemberToDolibarrMessage;
use App\Membership\Domain\Membership;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ExpireMembership implements ExpireMembershipInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRoleGatewayInterface $userRoleGateway,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function expire(string $membershipId): void
    {
        $membership = $this->entityManager->find(Membership::class, $membershipId);
        if (!$membership instanceof Membership) {
            return;
        }

        if ('expired' === $membership->getStatus()) {
            return;
        }

        $now = new \DateTimeImmutable();
        $membership->expire($now);
        $membershipId = $membership->getId();
        $userId = $membership->getUserId();

        $this->entityManager->flush();

        $discordInfo = $this->userRoleGateway->getUserDiscordInfo($userId);
        if (null !== $discordInfo['discordId']) {
            $this->dispatchDiscordSync(new SyncDiscordRoleMessage($userId, $discordInfo['discordId'], $discordInfo['roles']));
        }

        $this->dispatchEmailNotification(new MembershipExpiredNotificationMessage($userId));
        $this->dispatchDolibarrSync(new SyncMemberToDolibarrMessage($membershipId));
    }

    private function dispatchDiscordSync(SyncDiscordRoleMessage $message): void
    {
        try {
            $this->bus->dispatch($message);
        } catch (\Throwable $e) {
            $this->logger->error('membership.discord_sync_dispatch_failed', [
                'userId' => $message->userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dispatchEmailNotification(MembershipExpiredNotificationMessage $message): void
    {
        try {
            $this->bus->dispatch($message);
        } catch (\Throwable $e) {
            $this->logger->error('membership.expiry_notification_dispatch_failed', [
                'userId' => $message->userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dispatchDolibarrSync(SyncMemberToDolibarrMessage $message): void
    {
        try {
            $this->bus->dispatch($message);
        } catch (\Throwable $e) {
            $this->logger->error('membership.dolibarr_sync_dispatch_failed', [
                'membershipId' => $message->membershipId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
