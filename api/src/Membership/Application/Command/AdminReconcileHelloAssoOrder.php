<?php

declare(strict_types=1);

namespace App\Membership\Application\Command;

use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Membership\Application\Port\ActivateMembershipInterface;
use App\Payments\Domain\Entity\HelloAssoOrder;
use App\Payments\Domain\Repository\HelloAssoOrderRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class AdminReconcileHelloAssoOrder
{
    public function __construct(
        private HelloAssoOrderRepositoryInterface $helloAssoOrders,
        private UserRepositoryInterface $users,
        private ActivateMembershipInterface $activateMembership,
        private LoggerInterface $logger,
    ) {
    }

    public function reconcile(int $helloassoOrderId, string $userId): void
    {
        $order = $this->helloAssoOrders->findByHelloAssoOrderId($helloassoOrderId);
        if (!$order instanceof HelloAssoOrder || 'Processed' !== $order->getStatus()) {
            throw new \RuntimeException(sprintf('HelloAsso order %d not found or not processed.', $helloassoOrderId));
        }

        $paidAt = $order->getPaidAt();
        if (null === $paidAt) {
            throw new \RuntimeException(sprintf('HelloAsso order %d has no paid_at date.', $helloassoOrderId));
        }

        $user = $this->users->findById($userId);
        if (!$user instanceof User || null !== $user->getDeletedAt()) {
            throw new \RuntimeException(sprintf('User %s not found.', $userId));
        }

        $this->activateMembership->activate($userId, $paidAt, 'helloasso_reconciled', (string) $helloassoOrderId);

        $this->logger->info('membership.helloasso_reconciled', [
            'helloassoOrderId' => $helloassoOrderId,
            'userId' => $userId,
        ]);
    }
}
