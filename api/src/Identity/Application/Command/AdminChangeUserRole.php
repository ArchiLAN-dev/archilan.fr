<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Identity\Application\Message\SyncDiscordRoleMessage;
use App\Identity\Application\Support\ValidationErrors;
use App\Identity\Domain\Entity\RoleChangeAudit;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleChangeAuditRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class AdminChangeUserRole
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private RoleChangeAuditRepositoryInterface $auditRepository,
        private LoggerInterface $logger,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ValidationException when the role change is invalid
     */
    public function change(User $admin, string $targetUserId, string $targetRole, bool $confirmed): AdminUserView
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
            throw new ValidationException('Le changement de rôle est invalide.', $errors->toArray());
        }

        // PHPStan type-narrowing: $target and $normalizedRole are non-null here because
        // any null would have added errors above, causing an early return.
        if (!$target instanceof User || null === $normalizedRole) {
            throw new ValidationException('Le changement de rôle est invalide.', $errors->toArray());
        }

        if ($target->isDeleted()) {
            throw new ValidationException('Le changement de rôle est invalide.', ['user' => ['Ce compte est supprimé et ne peut plus être modifié.']]);
        }

        // An admin never changes their own role: the only way back from a mistake would be another
        // admin, and there may be none.
        //
        // This single rule is also what guarantees the site always keeps an administrator, so no
        // separate "last admin" check is needed: demoting the last admin requires the last admin to be
        // the target, and the last admin can only be the acting one - which this refuses. By induction,
        // the admin count never reaches zero through this command. (Account *deletion* is a different
        // path and does not go through here.)
        if ($target->getId() === $admin->getId()) {
            throw new ValidationException('Le changement de rôle est invalide.', ['role' => ['Tu ne peux pas modifier ton propre rôle.']]);
        }

        $previousRole = $this->primaryRole($target);
        if ($previousRole === $normalizedRole) {
            return $this->userPayload($target);
        }

        $now = $this->clock->now();

        // Admin rights are added and removed by their own transitions; promoteToMember/demoteToUser
        // still refuse an admin account on purpose, so the order here matters.
        if ('admin' === $previousRole) {
            $target->demoteFromAdmin($now);
        }

        if ('admin' === $normalizedRole) {
            $target->promoteToAdmin($now);
        } elseif ('member' === $normalizedRole) {
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

        return $this->userPayload($target);
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
            'admin' => 'admin',
            default => null,
        };
    }

    private function userPayload(User $user): AdminUserView
    {
        return new AdminUserView(
            $user->getId(),
            $user->getEmail(),
            $user->getDisplayName(),
            $this->primaryRole($user),
            $user->getRoles(),
            $user->isDeleted() ? 'deleted' : 'active',
            $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            $user->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            $user->getDeletedAt()?->format(\DateTimeInterface::ATOM),
        );
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
