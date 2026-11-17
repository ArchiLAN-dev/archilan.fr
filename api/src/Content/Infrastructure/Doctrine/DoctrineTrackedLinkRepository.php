<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Doctrine;

use App\Content\Domain\Entity\TrackedLink;
use App\Content\Domain\Repository\TrackedLinkRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineTrackedLinkRepository implements TrackedLinkRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $id): ?TrackedLink
    {
        return $this->entityManager->find(TrackedLink::class, $id);
    }

    public function findByCode(string $code): ?TrackedLink
    {
        return $this->entityManager->getRepository(TrackedLink::class)->findOneBy(['code' => $code]);
    }

    public function all(): array
    {
        return $this->entityManager->getRepository(TrackedLink::class)->findBy([], ['createdAt' => 'DESC', 'code' => 'ASC']);
    }

    public function save(TrackedLink $link): void
    {
        $this->entityManager->persist($link);
        $this->entityManager->flush();
    }

    public function countVisit(string $linkId, string $day, string $referrerHost): void
    {
        // The primary key arbitrates two visits at once: the second one adds to the first.
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO tracked_link_visit (link_id, day, referrer_host, visits) VALUES (:linkId, :day, :host, 1)
             ON CONFLICT (link_id, day, referrer_host) DO UPDATE SET visits = tracked_link_visit.visits + 1',
            ['linkId' => $linkId, 'day' => $day, 'host' => $referrerHost],
        );
    }
}
