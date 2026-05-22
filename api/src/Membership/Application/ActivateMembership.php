<?php

declare(strict_types=1);

namespace App\Membership\Application;

use App\Identity\Application\Message\SyncDiscordRoleMessage;
use App\Membership\Application\Message\MembershipActivatedNotificationMessage;
use App\Membership\Application\Message\SyncMemberToDolibarrMessage;
use App\Membership\Domain\Membership;
use App\Membership\Domain\MembershipRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ActivateMembership implements ActivateMembershipInterface
{
    public function __construct(
        private MembershipRepositoryInterface $memberships,
        private UserRoleGatewayInterface $userRoleGateway,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function activate(
        string $userId,
        \DateTimeImmutable $startedAt,
        string $source,
        ?string $helloassoOrderId = null,
        ?string $adminNote = null,
    ): void {
        $existing = $this->memberships->findActiveByUserId($userId);
        $now = new \DateTimeImmutable();
        $notificationExpiresAt = null;
        $activatedMembershipId = '';

        if ($existing instanceof Membership) {
            $base = $existing->getExpiresAt() > $startedAt ? $existing->getExpiresAt() : $startedAt;
            $newExpiresAt = $base->add(new \DateInterval('P12M'));
            $existing->renew($startedAt, $newExpiresAt, $source, $helloassoOrderId, $adminNote, $now);
            $notificationExpiresAt = $newExpiresAt;
            $activatedMembershipId = $existing->getId();
            $this->memberships->flush();
        } else {
            $expiresAt = $startedAt->add(new \DateInterval('P12M'));
            $membership = Membership::create($userId, $startedAt, $expiresAt, $source, $helloassoOrderId, $adminNote, $now);
            $notificationExpiresAt = $expiresAt;
            $activatedMembershipId = $membership->getId();
            $this->memberships->save($membership);
        }

        $discordInfo = $this->userRoleGateway->getUserDiscordInfo($userId);
        if (null !== $discordInfo['discordId']) {
            $this->dispatchDiscordSync(new SyncDiscordRoleMessage($userId, $discordInfo['discordId'], $discordInfo['roles']));
        }

        $this->dispatchEmailNotification(new MembershipActivatedNotificationMessage($userId, $notificationExpiresAt));
        $this->dispatchDolibarrSync(new SyncMemberToDolibarrMessage($activatedMembershipId));
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
