<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineUserRepository implements UserRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findById(string $id): ?User
    {
        return $this->entityManager->find(User::class, $id);
    }

    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /* @var list<User> */
        return $this->entityManager->getRepository(User::class)->findBy(['id' => $ids]);
    }

    public function findByEmailCanonical(string $emailCanonical): ?User
    {
        /* @var User|null */
        return $this->entityManager->getRepository(User::class)->findOneBy(['emailCanonical' => $emailCanonical]);
    }

    public function findBySlug(string $slug): ?User
    {
        /* @var User|null */
        return $this->entityManager->getRepository(User::class)->findOneBy(['slug' => $slug]);
    }

    public function findByDiscordId(string $discordId): ?User
    {
        /* @var User|null */
        return $this->entityManager->getRepository(User::class)->findOneBy(['discordId' => $discordId]);
    }

    public function existsBySlug(string $slug): bool
    {
        return null !== $this->entityManager->getRepository(User::class)->findOneBy(['slug' => $slug]);
    }

    public function findAllNotDeleted(): array
    {
        /* @var list<User> */
        return $this->entityManager->getRepository(User::class)->findBy(['deletedAt' => null]);
    }

    public function save(User $user): void
    {
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
