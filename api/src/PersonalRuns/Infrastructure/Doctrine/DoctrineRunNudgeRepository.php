<?php

declare(strict_types=1);

namespace App\PersonalRuns\Infrastructure\Doctrine;

use App\PersonalRuns\Domain\Entity\RunNudge;
use App\PersonalRuns\Domain\Repository\RunNudgeRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineRunNudgeRepository implements RunNudgeRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $runId, string $recipientId): ?RunNudge
    {
        return $this->entityManager->getRepository(RunNudge::class)
            ->findOneBy(['runId' => $runId, 'recipientId' => $recipientId]);
    }

    public function findByRunId(string $runId): array
    {
        return $this->entityManager->getRepository(RunNudge::class)->findBy(['runId' => $runId]);
    }

    public function save(RunNudge $nudge): void
    {
        $this->entityManager->persist($nudge);
        $this->entityManager->flush();
    }

    public function claimNudge(string $runId, string $recipientId, string $senderId, \DateTimeImmutable $now, \DateTimeImmutable $cooldownStart): bool
    {
        $affected = $this->entityManager->getConnection()->createQueryBuilder()
            ->update('personal_run_nudge')
            ->set('last_sender_id', ':sender')
            ->set('last_nudged_at', ':now')
            ->set('updated_at', ':now')
            ->where('personal_run_id = :run')
            ->andWhere('recipient_id = :recipient')
            ->andWhere('muted = false')
            ->andWhere('(last_nudged_at IS NULL OR last_nudged_at <= :since)')
            ->setParameter('sender', $senderId)
            ->setParameter('now', $now->format(\DateTimeInterface::ATOM))
            ->setParameter('run', $runId)
            ->setParameter('recipient', $recipientId)
            ->setParameter('since', $cooldownStart->format(\DateTimeInterface::ATOM))
            ->executeStatement();

        return 1 === $affected;
    }

    public function deleteByRunId(string $runId): void
    {
        foreach ($this->findByRunId($runId) as $nudge) {
            $this->entityManager->remove($nudge);
        }
        $this->entityManager->flush();
    }
}
