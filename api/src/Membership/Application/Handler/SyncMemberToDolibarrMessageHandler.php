<?php

declare(strict_types=1);

namespace App\Membership\Application\Handler;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use App\Membership\Application\DolibarrClientInterface;
use App\Membership\Application\Message\SyncMemberToDolibarrMessage;
use App\Membership\Domain\Membership;
use App\Membership\Domain\MembershipRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncMemberToDolibarrMessageHandler
{
    public function __construct(
        private MembershipRepositoryInterface $memberships,
        private UserRepositoryInterface $users,
        private DolibarrClientInterface $dolibarrClient,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncMemberToDolibarrMessage $message): void
    {
        $membership = $this->memberships->findById($message->membershipId);

        if (!$membership instanceof Membership) {
            $this->logger->error('dolibarr.sync.membership_not_found', [
                'membershipId' => $message->membershipId,
            ]);

            return;
        }

        $user = $this->users->findById($membership->getUserId());

        if (!$user instanceof User || null !== $user->getDeletedAt()) {
            $this->logger->error('dolibarr.sync.membership_not_found', [
                'membershipId' => $message->membershipId,
            ]);

            return;
        }

        $email = $user->getEmail();
        $displayName = $user->getDisplayName();
        $status = $membership->getStatus();
        $expiresAt = $membership->getExpiresAt();

        try {
            $this->dolibarrClient->upsertMember($email, $displayName, $status, $expiresAt);
            $this->logger->info('dolibarr.sync.member_synced', [
                'membershipId' => $message->membershipId,
                'email' => $email,
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('dolibarr.sync.upsert_failed', [
                'membershipId' => $message->membershipId,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
