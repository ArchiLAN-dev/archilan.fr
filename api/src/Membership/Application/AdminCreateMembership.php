<?php

declare(strict_types=1);

namespace App\Membership\Application;

use App\Identity\Application\Message\SyncDiscordRoleMessage;
use App\Membership\Application\Message\MembershipActivatedNotificationMessage;
use App\Membership\Application\Message\SyncMemberToDolibarrMessage;
use App\Membership\Domain\Membership;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class AdminCreateMembership
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRoleGatewayInterface $userRoleGateway,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{id: string, userId: string, status: string, startedAt: string, expiresAt: string, source: string, adminNote: string|null}
     */
    public function create(
        string $userId,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $expiresAt,
        ?string $adminNote,
    ): array {
        $now = new \DateTimeImmutable();
        $repo = $this->entityManager->getRepository(Membership::class);

        $existing = $repo->findOneBy(['userId' => $userId, 'status' => 'active']);
        if ($existing instanceof Membership) {
            $existing->expire($now);
            $this->entityManager->flush();
        }

        $membership = Membership::create($userId, $startedAt, $expiresAt, 'admin', null, $adminNote, $now);
        $this->entityManager->persist($membership);
        $this->entityManager->flush();

        $discordInfo = $this->userRoleGateway->getUserDiscordInfo($userId);
        if (null !== $discordInfo['discordId']) {
            $this->dispatchDiscordSync(new SyncDiscordRoleMessage($userId, $discordInfo['discordId'], $discordInfo['roles']));
        }

        $this->dispatchEmailNotification(new MembershipActivatedNotificationMessage($userId, $expiresAt));
        $this->dispatchDolibarrSync(new SyncMemberToDolibarrMessage($membership->getId()));

        return [
            'id' => $membership->getId(),
            'userId' => $userId,
            'status' => 'active',
            'startedAt' => $startedAt->format(\DateTimeInterface::ATOM),
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
            'source' => 'admin',
            'adminNote' => $adminNote,
        ];
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

    private function dispatchEmailNotification(MembershipActivatedNotificationMessage $message): void
    {
        try {
            $this->bus->dispatch($message);
        } catch (\Throwable $e) {
            $this->logger->error('membership.activation_notification_dispatch_failed', [
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
