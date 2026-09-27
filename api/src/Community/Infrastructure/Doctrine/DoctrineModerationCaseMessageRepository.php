<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Entity\ModerationCaseMessage;
use App\Community\Domain\Repository\ModerationCaseMessageRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineModerationCaseMessageRepository implements ModerationCaseMessageRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(ModerationCaseMessage $message): void
    {
        $this->entityManager->persist($message);
    }

    public function findById(string $id): ?ModerationCaseMessage
    {
        return $this->entityManager->find(ModerationCaseMessage::class, $id);
    }

    public function findByDiscordMessageId(string $discordMessageId): ?ModerationCaseMessage
    {
        return $this->entityManager->getRepository(ModerationCaseMessage::class)->findOneBy(['discordMessageId' => $discordMessageId]);
    }

    public function forCase(string $caseId, int $limit): array
    {
        /** @var list<ModerationCaseMessage> $latest */
        $latest = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(ModerationCaseMessage::class, 'm')
            ->where('m.caseId = :caseId')
            ->setParameter('caseId', $caseId)
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_reverse($latest);
    }

    public function countFromMemberSince(string $caseId, \DateTimeImmutable $since, string $source): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(ModerationCaseMessage::class, 'm')
            ->where('m.caseId = :caseId')
            ->andWhere('m.authorRole = :member')
            ->andWhere('m.createdAt >= :since')
            ->andWhere('m.source = :source')
            ->setParameter('source', $source)
            ->setParameter('caseId', $caseId)
            ->setParameter('member', ModerationCaseMessage::AUTHOR_MEMBER)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
