<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\RoleChangeAudit;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class AdminChangeUserRole
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
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

        $target = $this->entityManager->find(User::class, $targetUserId);
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
        $now = new \DateTimeImmutable();

        if ('member' === $normalizedRole) {
            $target->promoteToMember($now);
        } else {
            $target->demoteToLambda($now);
        }

        $newRole = $this->primaryRole($target);

        $this->entityManager->persist(RoleChangeAudit::record(
            $target->getId(),
            $admin->getId(),
            $previousRole,
            $newRole,
            $now,
        ));
        $this->entityManager->flush();

        $this->logger->info('user.role_changed', ['targetUserId' => $target->getId(), 'adminId' => $admin->getId(), 'from' => $previousRole, 'to' => $newRole]);

        return ['user' => $this->userPayload($target), 'errors' => []];
    }

    private function normalizeRole(string $targetRole): ?string
    {
        return match (mb_strtolower(trim($targetRole))) {
            'lambda', 'user' => 'lambda',
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

        return 'lambda';
    }
}
