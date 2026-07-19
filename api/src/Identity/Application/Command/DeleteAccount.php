<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Identity\Domain\Entity\DeletionAudit;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\DeletionAuditRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\PersonalRuns\Domain\Repository\YamlTemplateRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class DeleteAccount
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private DeletionAuditRepositoryInterface $auditRepository,
        private YamlTemplateRepositoryInterface $yamlTemplates,
        private ClockInterface $clock,
        private string $emailHashSecret,
        private LoggerInterface $logger,
    ) {
    }

    public function delete(User $user, string $reason = 'user_request'): void
    {
        $now = $this->clock->now();
        $audit = DeletionAudit::record(
            $user->getId(),
            $user->getEmailHash($this->emailHashSecret),
            $reason,
            $now,
        );

        $user->anonymizeForDeletion($now);

        // Personal data the member created (named YAML templates) is hard-deleted on erasure.
        $this->yamlTemplates->deleteByUserId($user->getId());

        $this->auditRepository->save($audit);
        $this->userRepository->save($user);

        $this->logger->info('user.deleted', ['userId' => $user->getId(), 'reason' => $reason]);
    }
}
