<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use Doctrine\DBAL\Types\Types;
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

    public function isSlugReserved(string $slug, \DateTimeImmutable $cutoff, string $exceptUserId): bool
    {
        $qb = $this->entityManager->getConnection()->createQueryBuilder();
        $count = $qb->select('COUNT(*)')
            ->from('"user"', 'u')
            ->where($qb->expr()->eq('u.previous_slug', ':slug'))
            ->andWhere($qb->expr()->gt('u.slug_changed_at', ':cutoff'))
            ->andWhere($qb->expr()->neq('u.id', ':exceptId'))
            ->setParameter('slug', $slug)
            ->setParameter('cutoff', $cutoff, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('exceptId', $exceptUserId)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) && (int) $count > 0;
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
