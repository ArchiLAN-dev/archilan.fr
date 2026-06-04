<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\EmailConfirmationToken;
use App\Identity\Domain\EmailConfirmationTokenRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEmailConfirmationTokenRepository implements EmailConfirmationTokenRepositoryInterface
{
    private string $table;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
        $this->table = $entityManager->getClassMetadata(EmailConfirmationToken::class)->getTableName();
    }

    public function findByTokenHash(string $hash): ?EmailConfirmationToken
    {
        /* @var EmailConfirmationToken|null */
        return $this->entityManager->getRepository(EmailConfirmationToken::class)->findOneBy(['tokenHash' => $hash]);
    }

    public function save(EmailConfirmationToken $token): void
    {
        $this->entityManager->persist($token);
        $this->entityManager->flush();
    }

    public function revokeExistingForUser(string $userId, \DateTimeImmutable $now): void
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->update($this->table)
            ->set('confirmed_at', ':now')
            ->where($qb->expr()->eq('user_id', ':userId'))
            ->andWhere($qb->expr()->isNull('confirmed_at'))
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('userId', $userId)
            ->executeStatement();
    }
}
