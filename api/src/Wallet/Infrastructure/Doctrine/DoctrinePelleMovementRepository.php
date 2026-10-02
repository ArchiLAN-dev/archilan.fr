<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Doctrine;

use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Repository\PelleMovementRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePelleMovementRepository implements PelleMovementRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function beginTransaction(): void
    {
        $this->entityManager->getConnection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->entityManager->getConnection()->commit();
    }

    public function rollBack(): void
    {
        $this->entityManager->getConnection()->rollBack();
    }

    public function lockMember(string $userId): void
    {
        $this->entityManager->getConnection()->executeQuery('SELECT id FROM "user" WHERE id = :id FOR UPDATE', ['id' => $userId]);
    }

    public function balance(string $userId, PelleKind $kind, ?string $eventId): int
    {
        $sql = 'SELECT COALESCE(SUM(amount), 0) FROM pelle_movement WHERE user_id = :userId AND kind = :kind';
        $params = ['userId' => $userId, 'kind' => $kind->value];
        if (null !== $eventId) {
            $sql .= ' AND event_id = :eventId';
            $params['eventId'] = $eventId;
        }

        $sum = $this->entityManager->getConnection()->fetchOne($sql, $params);

        return is_numeric($sum) ? (int) $sum : 0;
    }

    public function findByUniqueKey(string $uniqueKey): ?PelleMovement
    {
        return $this->entityManager->getRepository(PelleMovement::class)->findOneBy(['uniqueKey' => $uniqueKey]);
    }

    public function save(PelleMovement $movement): void
    {
        $this->entityManager->persist($movement);
        $this->entityManager->flush();
    }
}
