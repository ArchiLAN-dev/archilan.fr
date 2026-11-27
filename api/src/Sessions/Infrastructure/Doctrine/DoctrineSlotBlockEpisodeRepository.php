<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Doctrine;

use App\Sessions\Domain\Entity\SlotBlockEpisode;
use App\Sessions\Domain\Repository\SlotBlockEpisodeRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSlotBlockEpisodeRepository implements SlotBlockEpisodeRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findBySessionId(string $sessionId): array
    {
        return $this->entityManager->getRepository(SlotBlockEpisode::class)->findBy(['sessionId' => $sessionId]);
    }

    public function add(SlotBlockEpisode $episode): void
    {
        $this->entityManager->persist($episode);
    }

    public function remove(SlotBlockEpisode $episode): void
    {
        $this->entityManager->remove($episode);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function withSessionLock(string $sessionId, \Closure $work): mixed
    {
        return $this->entityManager->wrapInTransaction(function () use ($sessionId, $work): mixed {
            // Released by the commit (or the rollback): a transaction-scoped advisory lock, one per session.
            $this->entityManager->getConnection()->createQueryBuilder()
                ->select('pg_advisory_xact_lock(hashtext(:lock))')
                ->setParameter('lock', 'slot_block_episode:'.$sessionId)
                ->executeQuery();

            return $work();
        });
    }
}
