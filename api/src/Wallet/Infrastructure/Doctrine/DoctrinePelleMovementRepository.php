<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Doctrine;

use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Repository\PelleMovementRepositoryInterface;
use Doctrine\DBAL\Query\QueryBuilder;
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

    public function balance(string $userId, PelleKind $kind, ?string $eventId, ?string $runId = null): int
    {
        $sql = 'SELECT COALESCE(SUM(amount), 0) FROM pelle_movement WHERE user_id = :userId AND kind = :kind';
        $params = ['userId' => $userId, 'kind' => $kind->value];
        if (null !== $eventId) {
            $sql .= ' AND event_id = :eventId';
            $params['eventId'] = $eventId;
        }
        if (null !== $runId) {
            $sql .= ' AND run_id = :runId';
            $params['runId'] = $runId;
        }

        $sum = $this->entityManager->getConnection()->fetchOne($sql, $params);

        return is_numeric($sum) ? (int) $sum : 0;
    }

    public function bronzeBalancesOfRun(string $runId): array
    {
        $rows = $this->bronzeOfRun($runId)
            ->select('m.user_id', 'SUM(m.amount) AS balance')
            ->groupBy('m.user_id')
            ->having('SUM(m.amount) > 0')
            ->executeQuery()
            ->fetchAllAssociative();

        $balances = [];
        foreach ($rows as $row) {
            if (is_string($row['user_id']) && is_numeric($row['balance'])) {
                $balances[$row['user_id']] = (int) $row['balance'];
            }
        }

        return $balances;
    }

    public function bronzeBurnsOfRun(string $runId): array
    {
        $rows = $this->bronzeOfRun($runId)
            ->select(
                'm.user_id',
                'COALESCE(-SUM(m.amount) FILTER (WHERE m.reason = :expired), 0) AS burned',
                'COUNT(*) FILTER (WHERE m.reason = :expired) AS burns',
                'COALESCE(SUM(m.amount) FILTER (WHERE m.reason = :restored), 0) AS restored',
                'COUNT(*) FILTER (WHERE m.reason = :restored) AS restores',
            )
            ->andWhere('m.reason IN (:expired, :restored)')
            ->setParameter('expired', PelleReason::BronzeExpired->value)
            ->setParameter('restored', PelleReason::BronzeRestored->value)
            ->groupBy('m.user_id')
            ->executeQuery()
            ->fetchAllAssociative();

        $burns = [];
        foreach ($rows as $row) {
            if (is_string($row['user_id']) && is_numeric($row['burned']) && is_numeric($row['burns']) && is_numeric($row['restored']) && is_numeric($row['restores'])) {
                $burns[$row['user_id']] = ['burned' => (int) $row['burned'], 'burns' => (int) $row['burns'], 'restored' => (int) $row['restored'], 'restores' => (int) $row['restores']];
            }
        }

        return $burns;
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

    private function bronzeOfRun(string $runId): QueryBuilder
    {
        return $this->entityManager->getConnection()->createQueryBuilder()
            ->from('pelle_movement', 'm')
            ->where('m.run_id = :runId')
            ->andWhere('m.kind = :kind')
            ->setParameter('runId', $runId)
            ->setParameter('kind', PelleKind::Bronze->value);
    }
}
