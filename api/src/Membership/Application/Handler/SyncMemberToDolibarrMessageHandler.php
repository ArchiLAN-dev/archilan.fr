<?php

declare(strict_types=1);

namespace App\Membership\Application\Handler;

use App\Membership\Application\DolibarrClientInterface;
use App\Membership\Application\Message\SyncMemberToDolibarrMessage;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncMemberToDolibarrMessageHandler
{
    public function __construct(
        private Connection $connection,
        private DolibarrClientInterface $dolibarrClient,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncMemberToDolibarrMessage $message): void
    {
        $userTable = $this->connection->quoteSingleIdentifier('user');
        $qb = $this->connection->createQueryBuilder();
        $row = $qb
            ->select('u.email', 'u.display_name', 'm.status', 'm.expires_at')
            ->from('memberships', 'm')
            ->innerJoin('m', $userTable, 'u', $qb->expr()->eq('u.id', 'm.user_id'))
            ->where($qb->expr()->eq('m.id', ':membershipId'))
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            ->setParameter('membershipId', $message->membershipId)
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            $this->logger->error('dolibarr.sync.membership_not_found', [
                'membershipId' => $message->membershipId,
            ]);

            return;
        }

        $email = is_string($row['email']) ? $row['email'] : '';
        $displayName = is_string($row['display_name']) ? $row['display_name'] : '';
        $status = is_string($row['status']) ? $row['status'] : 'expired';
        $expiresAt = null;
        if (is_string($row['expires_at']) && '' !== $row['expires_at']) {
            try {
                $expiresAt = new \DateTimeImmutable($row['expires_at']);
            } catch (\Throwable) {
                $expiresAt = null;
            }
        }

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
