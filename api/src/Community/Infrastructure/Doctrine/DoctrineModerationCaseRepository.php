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

    public function withDirectMessagesToRead(\DateTimeImmutable $closedSince): array
    {
        /** @var list<ModerationCase> $cases */
        $cases = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(ModerationCase::class, 'c')
            ->where('c.status = :open OR c.updatedAt >= :closedSince')
            ->andWhere('c.directMessageChannelId IS NOT NULL')
            ->andWhere('c.directMessageCursor IS NOT NULL')
            ->setParameter('open', ModerationCase::STATUS_OPEN)
            ->setParameter('closedSince', $closedSince)
            ->getQuery()
            ->getResult();

        return $cases;
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
