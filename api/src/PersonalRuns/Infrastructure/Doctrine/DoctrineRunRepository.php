<?php

declare(strict_types=1);

namespace App\PersonalRuns\Infrastructure\Doctrine;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineRunRepository implements RunRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findById(string $id): ?Run
    {
        return $this->entityManager->find(Run::class, $id);
    }

    public function findByOwnerId(string $ownerId): array
    {
        /* @var list<Run> */
        return $this->entityManager->getRepository(Run::class)->findBy(
            ['ownerId' => $ownerId],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
        );
    }

    public function findJoinedByUserId(string $userId): array
    {
        $qb = $this->entityManager->getConnection()->createQueryBuilder();
        $ids = $qb
            ->select('rp.personal_run_id')
            ->from('run_participant', 'rp')
            ->innerJoin('rp', 'run', 'r', $qb->expr()->eq('r.id', 'rp.personal_run_id'))
            ->where($qb->expr()->eq('rp.user_id', ':userId'))
            ->andWhere($qb->expr()->neq('r.owner_id', ':userId'))
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchFirstColumn();

        $ids = array_values(array_unique(array_filter($ids, is_string(...))));
        if ([] === $ids) {
            return [];
        }

        /* @var list<Run> */
        return $this->entityManager->getRepository(Run::class)->findBy(
            ['id' => $ids],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
        );
    }

    public function findByInviteToken(string $inviteToken): ?Run
    {
        /* @var Run|null */
        return $this->entityManager->getRepository(Run::class)->findOneBy(['inviteToken' => $inviteToken]);
    }

    public function findBySessionId(string $sessionId): ?Run
    {
        /* @var Run|null */
        return $this->entityManager->getRepository(Run::class)->findOneBy(['sessionId' => $sessionId]);
    }

    public function findByStatuses(array $statuses): array
    {
        if ([] === $statuses) {
            return [];
        }

        /* @var list<Run> */
        return $this->entityManager->getRepository(Run::class)->findBy(['status' => $statuses]);
    }

    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $runs = [];
        foreach ($this->entityManager->getRepository(Run::class)->findBy(['id' => $ids]) as $run) {
            $runs[$run->getId()] = $run;
        }

        return $runs;
    }

    public function findListed(): array
    {
        /* @var list<Run> */
        return $this->entityManager->getRepository(Run::class)->findBy(['openness' => Run::OPEN_MEMBERS, 'status' => Run::STATUS_DRAFT]);
    }

    public function findWithExclusiveLock(string $id): ?Run
    {
        $run = $this->entityManager->find(Run::class, $id, LockMode::PESSIMISTIC_WRITE);
        if (!$run instanceof Run) {
            return null;
        }
        // Already managed: find() hands back the cached copy, the refresh reads it again under the lock.
        $this->entityManager->refresh($run, LockMode::PESSIMISTIC_WRITE);

        return $run;
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

    public function save(Run $run): void
    {
        $this->entityManager->persist($run);
        $this->entityManager->flush();
    }

    public function delete(Run $run): void
    {
        $this->entityManager->remove($run);
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
