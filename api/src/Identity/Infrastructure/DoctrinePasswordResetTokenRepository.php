<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\PasswordResetTokenRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePasswordResetTokenRepository implements PasswordResetTokenRepositoryInterface
{
    private string $table;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
        $this->table = $entityManager->getClassMetadata(PasswordResetToken::class)->getTableName();
    }

    public function findByTokenHash(string $hash): ?PasswordResetToken
    {
        /* @var PasswordResetToken|null */
        return $this->entityManager->getRepository(PasswordResetToken::class)->findOneBy(['tokenHash' => $hash]);
    }

    public function save(PasswordResetToken $token): void
    {
        $this->entityManager->persist($token);
        $this->entityManager->flush();
    }

    public function revokeExistingForUser(string $userId, \DateTimeImmutable $now): void
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->update($this->table)
            ->set('used_at', ':now')
            ->where($qb->expr()->eq('user_id', ':userId'))
            ->andWhere($qb->expr()->isNull('used_at'))
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('userId', $userId)
            ->executeStatement();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function deleteStale(\DateTimeImmutable $now, \DateTimeImmutable $consumedBefore): int
    {
        $qb = $this->connection->createQueryBuilder();

        return (int) $qb->delete($this->table)
            ->where('expires_at < :now OR (used_at IS NOT NULL AND used_at < :consumedBefore)')
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('consumedBefore', $consumedBefore, Types::DATETIMETZ_IMMUTABLE)
            ->executeStatement();
    }
}
