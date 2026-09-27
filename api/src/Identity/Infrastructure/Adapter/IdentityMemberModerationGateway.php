<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Adapter;

use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Port\MemberModerationState;
use App\Community\Application\Port\SuspendedMember;
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

    public function discordIdOf(string $userId): ?string
    {
        $discordId = $this->load($userId)?->getDiscordId();

        return null === $discordId || '' === $discordId ? null : $discordId;
    }

    public function currentlySuspended(\DateTimeImmutable $now): array
    {
        $suspended = [];
        foreach ($this->users->findSuspendedAt($now) as $user) {
            $until = $user->getSuspendedUntil();
            if (null === $until) {
                continue;
            }
            $discordId = $user->getDiscordId();
            $suspended[] = new SuspendedMember(
                $user->getId(),
                null === $discordId || '' === $discordId ? null : $discordId,
                $until->format(\DateTimeInterface::ATOM),
                $user->getModerationReason(),
            );
        }

        return $suspended;
    }

    private function load(string $userId): ?User
    {
        $user = $this->users->findById($userId);

        return $user instanceof User && !$user->isDeleted() ? $user : null;
    }
}
