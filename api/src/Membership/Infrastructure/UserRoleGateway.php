<?php

declare(strict_types=1);

namespace App\Membership\Infrastructure;

use App\Identity\Domain\User;
use App\Membership\Application\UserRoleGatewayInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UserRoleGateway implements UserRoleGatewayInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function getUserDiscordInfo(string $userId): array
    {
        $user = $this->entityManager->find(User::class, $userId);
        if (!$user instanceof User) {
            return ['discordId' => null, 'roles' => []];
        }

        return ['discordId' => $user->getDiscordId(), 'roles' => $user->getRoles()];
    }
}
