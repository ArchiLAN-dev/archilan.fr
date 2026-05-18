<?php

declare(strict_types=1);

namespace App\Membership\Application;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final readonly class AdminReconcileHelloAssoOrder
{
    public function __construct(
        private Connection $connection,
        private ActivateMembershipInterface $activateMembership,
        private LoggerInterface $logger,
    ) {
    }

    public function reconcile(int $helloassoOrderId, string $userId): void
    {
        $qb = $this->connection->createQueryBuilder();
        $row = $qb
            ->select('o.paid_at')
            ->from('hello_asso_order', 'o')
            ->where($qb->expr()->eq('o.helloasso_order_id', ':orderId'))
            ->andWhere($qb->expr()->eq('o.status', ':status'))
            ->setParameter('orderId', $helloassoOrderId)
            ->setParameter('status', 'Processed')
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            throw new \RuntimeException(sprintf('HelloAsso order %d not found or not processed.', $helloassoOrderId));
        }

        $paidAtRaw = $row['paid_at'];
        if (!is_string($paidAtRaw) || '' === $paidAtRaw) {
            throw new \RuntimeException(sprintf('HelloAsso order %d has no paid_at date.', $helloassoOrderId));
        }

        try {
            $paidAt = new \DateTimeImmutable($paidAtRaw);
        } catch (\Exception $e) {
            throw new \RuntimeException(sprintf('Invalid paid_at for order %d: %s', $helloassoOrderId, $e->getMessage()));
        }

        $userTable = $this->connection->quoteSingleIdentifier('user');
        $qb2 = $this->connection->createQueryBuilder();
        $userExists = $qb2
            ->select('u.id')
            ->from($userTable, 'u')
            ->where($qb2->expr()->eq('u.id', ':userId'))
            ->andWhere($qb2->expr()->isNull('u.deleted_at'))
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();

        if (false === $userExists) {
            throw new \RuntimeException(sprintf('User %s not found.', $userId));
        }

        $this->activateMembership->activate($userId, $paidAt, 'helloasso_reconciled', (string) $helloassoOrderId);

        $this->logger->info('membership.helloasso_reconciled', [
            'helloassoOrderId' => $helloassoOrderId,
            'userId' => $userId,
        ]);
    }
}
