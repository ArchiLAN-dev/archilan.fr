<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;

/**
 * Reads one user for the admin sheet (story 36.1). Returns null when no such account exists, letting
 * the controller answer 404 without the query knowing about HTTP.
 */
final readonly class AdminUserDetailQuery
{
    public function __construct(private UserRepositoryInterface $users)
    {
    }

    public function forUserId(string $userId): ?AdminUserDetail
    {
        $user = $this->users->findById($userId);
        if (!$user instanceof User) {
            return null;
        }

        return new AdminUserDetail(
            $user->getId(),
            $user->getEmail(),
            $user->getDisplayName(),
            $user->getSlug(),
            $this->primaryRole($user),
            $user->getRoles(),
            $user->isDeleted() ? 'deleted' : 'active',
            $user->isEmailVerified(),
            $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            $user->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            $user->getDeletedAt()?->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * Same ladder the directory and the role command use, so a member never reads as two roles at once.
     */
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
