<?php

declare(strict_types=1);

namespace App\Membership\Infrastructure;

use App\Membership\Application\AccountMembershipQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalAccountMembershipQuery implements AccountMembershipQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{status: 'active'|'expired'|'none', expiresAt: string|null, startedAt: string|null}
     */
    public function queryForUser(string $userId): array
    {
        $now = new \DateTimeImmutable();
        $qb = $this->connection->createQueryBuilder();
        $row = $qb
            ->select('m.expires_at', 'm.started_at')
            ->from('memberships', 'm')
            ->where($qb->expr()->eq('m.user_id', ':userId'))
            ->andWhere($qb->expr()->neq('m.status', ':cancelled'))
            ->orderBy('m.expires_at', 'DESC')
            ->setMaxResults(1)
            ->setParameter('userId', $userId)
            ->setParameter('cancelled', 'cancelled')
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            return ['status' => 'none', 'expiresAt' => null, 'startedAt' => null];
        }

        $expiresRaw = $row['expires_at'] ?? null;
        $isActive = is_string($expiresRaw) && '' !== $expiresRaw && new \DateTimeImmutable($expiresRaw) >= $now;

        return [
            'status' => $isActive ? 'active' : 'expired',
            'expiresAt' => $this->formatDate($expiresRaw),
            'startedAt' => $this->formatDate($row['started_at'] ?? null),
        ];
    }

    /**
     * @return list<array{id: string, status: string, startedAt: string|null, expiresAt: string|null, source: string}>
     */
    public function queryHistoryForUser(string $userId): array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select(
                'm.id',
                "CASE WHEN m.status = 'cancelled' THEN 'cancelled' WHEN m.expires_at >= :now THEN 'active' ELSE 'expired' END AS status",
                'm.started_at',
                'm.expires_at',
                'm.source',
            )
            ->from('memberships', 'm')
            ->where($qb->expr()->eq('m.user_id', ':userId'))
            ->orderBy('m.started_at', 'DESC')
            ->setParameter('userId', $userId)
            ->setParameter('now', $now)
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $id = is_string($row['id'] ?? null) ? $row['id'] : '';
            $status = is_string($row['status'] ?? null) ? $row['status'] : '';
            $source = is_string($row['source'] ?? null) ? $row['source'] : '';
            if ('' === $id) {
                continue;
            }
            $result[] = [
                'id' => $id,
                'status' => $status,
                'startedAt' => $this->formatDate($row['started_at'] ?? null),
                'expiresAt' => $this->formatDate($row['expires_at'] ?? null),
                'source' => $source,
            ];
        }

        return $result;
    }

    private function formatDate(mixed $raw): ?string
    {
        if (!is_string($raw) || '' === $raw) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($raw))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
