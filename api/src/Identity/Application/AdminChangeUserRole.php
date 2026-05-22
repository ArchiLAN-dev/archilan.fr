<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Application\Message\SyncDiscordRoleMessage;
use App\Identity\Domain\RoleChangeAudit;
use App\Identity\Domain\RoleChangeAuditRepositoryInterface;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class AdminChangeUserRole
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private RoleChangeAuditRepositoryInterface $auditRepository,
        private LoggerInterface $logger,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * @return array{user?: array{id: string, email: string, displayName: string|null, role: string, roles: list<string>, status: string, createdAt: string, updatedAt: string, deletedAt: string|null}, errors: array<string, list<string>>}
     */
    public function change(User $admin, string $targetUserId, string $targetRole, bool $confirmed): array
    {
        $errors = new ValidationErrors();

        if (!$confirmed) {
            $errors->add('confirmed', 'Confirme explicitement le changement de rôle.');
        }

        $normalizedRole = $this->normalizeRole($targetRole);
        if (null === $normalizedRole) {
            $errors->add('role', 'Choisis un rôle cible valide.');
        }

        $target = $this->userRepository->findById($targetUserId);
        if (!$target instanceof User) {
            $errors->add('user', 'Utilisateur introuvable.');
        }

        if ([] !== $errors->toArray()) {
            return ['errors' => $errors->toArray()];
        }

        // PHPStan type-narrowing: $target and $normalizedRole are non-null here because
        // any null would have added errors above, causing an early return.
        if (!$target instanceof User || null === $normalizedRole) {
            return ['errors' => $errors->toArray()];
        }

        if ($target->isDeleted()) {
            return ['errors' => ['user' => ['Ce compte est supprimé et ne peut plus être modifié.']]];
        }

        if ($target->getId() === $admin->getId() || in_array('ROLE_ADMIN', $target->getRoles(), true)) {
            return ['errors' => ['role' => ['Les rôles admin ne sont pas modifiables dans cette action.']]];
        }

        $previousRole = $this->primaryRole($target);
        if ($previousRole === $normalizedRole) {
            return ['user' => $this->userPayload($target), 'errors' => []];
        }

        $now = new \DateTimeImmutable();

        if ('member' === $normalizedRole) {
            $target->promoteToMember($now);
        } else {
            $target->demoteToUser($now);
        }

        $newRole = $this->primaryRole($target);

        $this->auditRepository->saveAuditAndFlushUser(RoleChangeAudit::record(
            $target->getId(),
            $admin->getId(),
            $previousRole,
            $newRole,
            $now,
        ));

        $this->logger->info('user.role_changed', ['targetUserId' => $target->getId(), 'adminId' => $admin->getId(), 'from' => $previousRole, 'to' => $newRole]);

        $discordId = $target->getDiscordId();
        if (null !== $discordId) {
            $this->dispatchDiscordSync(new SyncDiscordRoleMessage(
                $target->getId(),
                $discordId,
                $target->getRoles(),
            ));
        }

        return ['user' => $this->userPayload($target), 'errors' => []];
    }

    private function dispatchDiscordSync(SyncDiscordRoleMessage $message): void
    {
        try {
            $this->bus->dispatch($message);
        } catch (\Throwable $e) {
            $this->logger->error('discord.sync_dispatch_failed', [
                'userId' => $message->userId,
                'discordUserId' => $message->discordUserId,
                'removeAll' => $message->removeAll,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function normalizeRole(string $targetRole): ?string
    {
        return match (mb_strtolower(trim($targetRole))) {
            'user' => 'user',
            'member', 'membre' => 'member',
            default => null,
        };
    }

    /**
     * @return array{id: string, email: string, displayName: string|null, role: string, roles: list<string>, status: string, createdAt: string, updatedAt: string, deletedAt: string|null}
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'role' => $this->primaryRole($user),
            'roles' => $user->getRoles(),
            'status' => $user->isDeleted() ? 'deleted' : 'active',
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $user->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'deletedAt' => $user->getDeletedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function primaryRole(User $user): string
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return 'admin';
        }

        if (in_array('ROLE_MEMBER', $user->getRoles(), true)) {
            return 'member';
        }

        return 'user';
    }
}
