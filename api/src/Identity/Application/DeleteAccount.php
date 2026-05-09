<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AccountDeletionAudit;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class DeleteAccount
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $emailHashSecret,
        private LoggerInterface $logger,
    ) {
    }

    public function delete(User $user, string $reason = 'user_request'): AccountDeletionAudit
    {
        $now = new \DateTimeImmutable();
        $audit = AccountDeletionAudit::record(
            $user->getId(),
            $user->getEmailHash($this->emailHashSecret),
            $reason,
            $now,
        );

        $user->anonymizeForDeletion($now);

        $this->entityManager->persist($audit);
        $this->entityManager->flush();

        $this->logger->info('user.deleted', ['userId' => $user->getId(), 'reason' => $reason]);

        return $audit;
    }
}
