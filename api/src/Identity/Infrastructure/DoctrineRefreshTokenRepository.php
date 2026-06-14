<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\RefreshToken;
use App\Identity\Domain\RefreshTokenRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineRefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    private string $table;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
        $this->table = $entityManager->getClassMetadata(RefreshToken::class)->getTableName();
    }

    public function save(RefreshToken $token): void
    {
        $this->entityManager->persist($token);
        $this->entityManager->flush();
    }

    public function persist(RefreshToken $token): void
    {
        $this->entityManager->persist($token);
    }

    public function findByTokenHash(string $hash): ?RefreshToken
    {
        /* @var RefreshToken|null */
        return $this->entityManager->getRepository(RefreshToken::class)->findOneBy(['tokenHash' => $hash]);
    }

    public function revokeAllForUser(string $userId): void
    {
        $now = new \DateTimeImmutable();
        $qb = $this->connection->createQueryBuilder();
        $qb->update($this->table)
            ->set('revoked_at', ':now')
            ->where($qb->expr()->eq('user_id', ':userId'))
            ->andWhere($qb->expr()->isNull('revoked_at'))
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('userId', $userId)
            ->executeStatement();
    }

    public function revokeFamily(string $familyId): void
    {
        $now = new \DateTimeImmutable();
        $qb = $this->connection->createQueryBuilder();
        $qb->update($this->table)
            ->set('revoked_at', ':now')
            ->where($qb->expr()->eq('family_id', ':familyId'))
            ->andWhere($qb->expr()->isNull('revoked_at'))
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('familyId', $familyId)
            ->executeStatement();
    }

    public function deleteExpiredBefore(\DateTimeImmutable $threshold): int
    {
        $qb = $this->connection->createQueryBuilder();

        return (int) $qb
            ->delete($this->table)
            ->where($qb->expr()->lt('expires_at', ':threshold'))
            ->setParameter('threshold', $threshold, Types::DATETIMETZ_IMMUTABLE)
            ->executeStatement();
    }

    public function deleteStale(\DateTimeImmutable $now): int
    {
        $grace = $now->modify('-7 days');

        $qb = $this->connection->createQueryBuilder();

        return (int) $qb
            ->delete($this->table)
            ->where($qb->expr()->or(
                $qb->expr()->lt('expires_at', ':now'),
                $qb->expr()->and(
                    $qb->expr()->isNotNull('revoked_at'),
                    $qb->expr()->lt('revoked_at', ':grace'),
                ),
            ))
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('grace', $grace, Types::DATETIMETZ_IMMUTABLE)
            ->executeStatement();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
