<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\DeletionAudit;
use App\Identity\Domain\DeletionAuditRepositoryInterface;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class DeleteAccount
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private DeletionAuditRepositoryInterface $auditRepository,
        private string $emailHashSecret,
        private LoggerInterface $logger,
    ) {
    }

    public function delete(User $user, string $reason = 'user_request'): DeletionAudit
    {
        $now = new \DateTimeImmutable();
        $audit = DeletionAudit::record(
            $user->getId(),
            $user->getEmailHash($this->emailHashSecret),
            $reason,
            $now,
        );

        $user->anonymizeForDeletion($now);

        $this->auditRepository->save($audit);
        $this->userRepository->save($user);

        $this->logger->info('user.deleted', ['userId' => $user->getId(), 'reason' => $reason]);

        return $audit;
    }
}
