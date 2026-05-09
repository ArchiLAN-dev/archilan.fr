<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\RefreshToken;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RefreshTokenRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(RefreshToken $token): void
    {
        $this->entityManager->persist($token);
        $this->entityManager->flush();
    }

    public function findByTokenHash(string $hash): ?RefreshToken
    {
        /* @var RefreshToken|null */
        return $this->entityManager->getRepository(RefreshToken::class)->findOneBy(['tokenHash' => $hash]);
    }

    public function revokeAllForUser(string $userId): void
    {
        $this->entityManager->createQueryBuilder()
            ->update(RefreshToken::class, 'rt')
            ->set('rt.revokedAt', ':now')
            ->where('rt.userId = :userId')
            ->andWhere('rt.revokedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    public function deleteExpiredBefore(\DateTimeImmutable $threshold): int
    {
        $result = $this->entityManager->createQueryBuilder()
            ->delete(RefreshToken::class, 'rt')
            ->where('rt.expiresAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();

        return is_int($result) ? $result : 0;
    }

    public function deleteStale(\DateTimeImmutable $now): int
    {
        $grace = $now->modify('-7 days');

        $result = $this->entityManager->createQueryBuilder()
            ->delete(RefreshToken::class, 'rt')
            ->where('rt.expiresAt < :now OR (rt.revokedAt IS NOT NULL AND rt.revokedAt < :grace)')
            ->setParameter('now', $now)
            ->setParameter('grace', $grace)
            ->getQuery()
            ->execute();

        return is_int($result) ? $result : 0;
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
