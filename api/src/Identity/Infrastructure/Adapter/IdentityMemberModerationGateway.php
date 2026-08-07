<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Adapter;

use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Port\MemberModerationState;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;

/**
 * Identity-side adapter for Community's {@see MemberModerationGatewayInterface} (story 30.29): loads the
 * `User`, applies the named domain method, persists. A deleted user is treated as absent.
 */
final readonly class IdentityMemberModerationGateway implements MemberModerationGatewayInterface
{
    public function __construct(private UserRepositoryInterface $users)
    {
    }

    public function suspendUntil(string $userId, \DateTimeImmutable $until, string $reason): bool
    {
        $user = $this->load($userId);
        if (null === $user) {
            return false;
        }

        $user->suspendUntil($until, $reason, new \DateTimeImmutable());
        $this->users->flush();

        return true;
    }

    public function ban(string $userId, string $reason): bool
    {
        $user = $this->load($userId);
        if (null === $user) {
            return false;
        }

        $user->ban($reason, new \DateTimeImmutable());
        $this->users->flush();

        return true;
    }

    public function lift(string $userId): bool
    {
        $user = $this->load($userId);
        if (null === $user) {
            return false;
        }

        $user->lift(new \DateTimeImmutable());
        $this->users->flush();

        return true;
    }

    public function currentState(string $userId): ?MemberModerationState
    {
        $user = $this->load($userId);
        if (null === $user) {
            return null;
        }

        return new MemberModerationState(
            $user->getSuspendedUntil()?->format(\DateTimeInterface::ATOM),
            $user->getBannedAt()?->format(\DateTimeInterface::ATOM),
            $user->getModerationReason(),
        );
    }

    private function load(string $userId): ?User
    {
        $user = $this->users->findById($userId);

        return $user instanceof User && !$user->isDeleted() ? $user : null;
    }
}
