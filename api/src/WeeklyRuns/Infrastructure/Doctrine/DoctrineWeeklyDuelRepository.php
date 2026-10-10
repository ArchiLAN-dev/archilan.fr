<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure\Doctrine;

use App\WeeklyRuns\Domain\Entity\WeeklyDuel;
use App\WeeklyRuns\Domain\Entity\WeeklyDuelParticipant;
use App\WeeklyRuns\Domain\Repository\WeeklyDuelRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineWeeklyDuelRepository implements WeeklyDuelRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $duelId): ?WeeklyDuel
    {
        return $this->entityManager->find(WeeklyDuel::class, $duelId);
    }

    public function openDuelsOf(string $userId): array
    {
        $rows = $this->entityManager->getRepository(WeeklyDuelParticipant::class)->findBy([
            'userId' => $userId,
            'status' => [WeeklyDuelParticipant::PENDING, WeeklyDuelParticipant::ACCEPTED],
        ]);
        $duelIds = array_values(array_unique(array_map(static fn (WeeklyDuelParticipant $p): string => $p->getDuelId(), $rows)));
        if ([] === $duelIds) {
            return [];
        }

        /* @var list<WeeklyDuel> */
        return $this->entityManager->getRepository(WeeklyDuel::class)->findBy(['id' => $duelIds, 'resolvedAt' => null], ['createdAt' => 'DESC']);
    }

    public function unresolvedForRun(string $weeklyRunId): array
    {
        /* @var list<WeeklyDuel> */
        return $this->entityManager->getRepository(WeeklyDuel::class)->findBy(['weeklyRunId' => $weeklyRunId, 'resolvedAt' => null], ['createdAt' => 'ASC']);
    }

    public function participantsByDuel(array $duelIds): array
    {
        if ([] === $duelIds) {
            return [];
        }
        $byDuel = [];
        foreach ($this->entityManager->getRepository(WeeklyDuelParticipant::class)->findBy(['duelId' => $duelIds], ['invitedAt' => 'ASC']) as $participant) {
            $byDuel[$participant->getDuelId()][] = $participant;
        }

        return $byDuel;
    }

    public function countCreatedSince(string $creatorId, \DateTimeImmutable $since): int
    {
        return \count(array_filter(
            $this->entityManager->getRepository(WeeklyDuel::class)->findBy(['creatorId' => $creatorId]),
            static fn (WeeklyDuel $duel): bool => $duel->getCreatedAt() >= $since,
        ));
    }

    public function save(WeeklyDuel $duel): void
    {
        $this->entityManager->persist($duel);
    }

    public function saveParticipant(WeeklyDuelParticipant $participant): void
    {
        $this->entityManager->persist($participant);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
