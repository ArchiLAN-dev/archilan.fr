<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminUserDirectory
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return list<array{id: string, email: string, displayName: string|null, role: string, roles: list<string>, status: string, createdAt: string, updatedAt: string, deletedAt: string|null}>
     */
    public function search(?string $query, ?string $role): array
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('user')
            ->from(User::class, 'user')
            ->orderBy('user.createdAt', 'DESC');

        $normalizedQuery = null === $query ? '' : mb_strtolower(trim($query));

        if ('' !== $normalizedQuery) {
            $builder
                ->andWhere('LOWER(user.email) LIKE :query OR LOWER(user.displayName) LIKE :query')
                ->setParameter('query', '%'.$normalizedQuery.'%');
        }

        /** @var list<User> $users */
        $users = $builder->setMaxResults(500)->getQuery()->getResult();
        $normalizedRole = null === $role ? '' : mb_strtolower(trim($role));

        return array_values(array_map(
            fn (User $user): array => $this->userPayload($user),
            array_filter(
                $users,
                fn (User $user): bool => $this->matchesRoleFilter($user, $normalizedRole),
            ),
        ));
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

    private function matchesRoleFilter(User $user, string $role): bool
    {
        return match ($role) {
            '', 'all' => true,
            'admin', 'role_admin' => in_array('ROLE_ADMIN', $user->getRoles(), true),
            'member', 'membre', 'role_member' => in_array('ROLE_MEMBER', $user->getRoles(), true)
                && !in_array('ROLE_ADMIN', $user->getRoles(), true),
            'lambda', 'user', 'role_user' => !in_array('ROLE_MEMBER', $user->getRoles(), true)
                && !in_array('ROLE_ADMIN', $user->getRoles(), true),
            default => false,
        };
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
