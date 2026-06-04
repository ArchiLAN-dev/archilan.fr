<?php

declare(strict_types=1);

namespace App\Membership\Application;

use App\Identity\Application\Message\SyncDiscordRoleMessage;
use App\Membership\Domain\Membership;
use App\Membership\Domain\MembershipRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class AdminDeleteMembership
{
    public function __construct(
        private MembershipRepositoryInterface $memberships,
        private UserRoleGatewayInterface $userRoleGateway,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function delete(string $membershipId): bool
    {
        $membership = $this->memberships->findById($membershipId);
        if (!$membership instanceof Membership) {
            return false;
        }

        $now = new \DateTimeImmutable();
        $wasActive = 'active' === $membership->getStatus() && $membership->getExpiresAt() >= $now;
        $userId = $membership->getUserId();

        $membership->cancel($now);
        $this->memberships->flush();

        if ($wasActive) {
            $discordInfo = $this->userRoleGateway->getUserDiscordInfo($userId);
            if (null !== $discordInfo['discordId']) {
                $this->dispatchDiscordSync(new SyncDiscordRoleMessage($userId, $discordInfo['discordId'], $discordInfo['roles']));
            }
        }

        return true;
    }

    private function dispatchDiscordSync(SyncDiscordRoleMessage $message): void
    {
        try {
            $this->bus->dispatch($message);
        } catch (\Throwable $e) {
            $this->logger->error('membership.delete_discord_sync_dispatch_failed', [
                'userId' => $message->userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
