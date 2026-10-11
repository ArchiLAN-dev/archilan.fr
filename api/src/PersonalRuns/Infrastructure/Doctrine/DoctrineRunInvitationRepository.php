<?php

declare(strict_types=1);

namespace App\PersonalRuns\Infrastructure\Doctrine;

use App\PersonalRuns\Domain\Entity\RunInvitation;
use App\PersonalRuns\Domain\Repository\RunInvitationRepositoryInterface;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineRunInvitationRepository implements RunInvitationRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findById(string $id): ?RunInvitation
    {
        return $this->entityManager->find(RunInvitation::class, $id);
    }

    public function findByRunAndInvitee(string $runId, string $inviteeId): ?RunInvitation
    {
        return $this->entityManager->getRepository(RunInvitation::class)
            ->findOneBy(['runId' => $runId, 'inviteeId' => $inviteeId]);
    }

    public function findByRunId(string $runId): array
    {
        return $this->entityManager->getRepository(RunInvitation::class)
            ->findBy(['runId' => $runId], ['invitedAt' => 'DESC']);
    }

    public function findPendingForInvitee(string $inviteeId): array
    {
        return $this->entityManager->getRepository(RunInvitation::class)
            ->findBy(['inviteeId' => $inviteeId, 'status' => RunInvitation::PENDING], ['invitedAt' => 'DESC']);
    }

    public function countSentSince(string $runId, \DateTimeImmutable $since): int
    {
        $criteria = Criteria::create()
            ->where(Criteria::expr()->eq('runId', $runId))
            ->andWhere(Criteria::expr()->gte('invitedAt', $since));

        return $this->entityManager->getRepository(RunInvitation::class)->matching($criteria)->count();
    }

    public function save(RunInvitation $invitation): void
    {
        $this->entityManager->persist($invitation);
    }

    public function closePendingForRun(string $runId, \DateTimeImmutable $now): void
    {
        foreach ($this->findByRunId($runId) as $invitation) {
            if ($invitation->isPending()) {
                $invitation->close($now);
            }
        }
        $this->entityManager->flush();
    }

    public function deleteByRunId(string $runId): void
    {
        foreach ($this->entityManager->getRepository(RunInvitation::class)->findBy(['runId' => $runId]) as $invitation) {
            $this->entityManager->remove($invitation);
        }
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
