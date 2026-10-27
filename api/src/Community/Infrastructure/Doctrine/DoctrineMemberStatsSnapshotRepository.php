<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Entity\MemberStatsSnapshot;
use App\Community\Domain\Repository\MemberStatsSnapshotRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineMemberStatsSnapshotRepository implements MemberStatsSnapshotRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findByUserId(string $userId): ?MemberStatsSnapshot
    {
        return $this->entityManager->find(MemberStatsSnapshot::class, $userId);
    }

    public function store(string $userId, array $facts, \DateTimeImmutable $now): void
    {
        // The primary key arbitrates two recomputes of the same member: the second one updates.
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO member_stats_snapshot (user_id, facts, computed_at) VALUES (:userId, :facts, :now)
             ON CONFLICT (user_id) DO UPDATE SET facts = EXCLUDED.facts, computed_at = EXCLUDED.computed_at',
            ['userId' => $userId, 'facts' => json_encode($facts, \JSON_THROW_ON_ERROR), 'now' => $now->format(\DATE_ATOM)],
        );
    }
}
