<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Entity\ModerationCase;
use App\Community\Domain\Repository\ModerationCaseRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineModerationCaseRepository implements ModerationCaseRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findById(string $id): ?ModerationCase
    {
        return $this->entityManager->find(ModerationCase::class, $id);
    }

    public function findByTargetUserId(string $targetUserId): ?ModerationCase
    {
        return $this->entityManager->getRepository(ModerationCase::class)->findOneBy(['targetUserId' => $targetUserId]);
    }

    public function save(ModerationCase $case): void
    {
        $this->entityManager->persist($case);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
