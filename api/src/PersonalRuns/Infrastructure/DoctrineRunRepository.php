<?php

declare(strict_types=1);

namespace App\PersonalRuns\Infrastructure;

use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunRepositoryInterface;
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
