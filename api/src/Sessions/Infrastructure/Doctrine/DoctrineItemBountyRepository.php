<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Doctrine;

use App\Sessions\Domain\Entity\ItemBounty;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\ItemBountyRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineItemBountyRepository implements ItemBountyRepositoryInterface
{
    private const array ENDED_STATUSES = [Session::STATUS_FINISHED, Session::STATUS_STOPPED, Session::STATUS_FAILED, Session::STATUS_CRASHED];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findById(string $id): ?ItemBounty
    {
        return $this->entityManager->find(ItemBounty::class, $id);
    }

    public function findOpenFor(string $sessionId, string $slotName, string $itemName): ?ItemBounty
    {
        $bounty = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(ItemBounty::class, 'b')
            ->where('b.sessionId = :sessionId')
            ->andWhere('b.slotName = :slotName')
            ->andWhere('LOWER(b.itemName) = LOWER(:itemName)')
            ->andWhere('b.status = :open')
            ->orderBy('b.createdAt', 'ASC')
            ->setMaxResults(1)
            ->setParameter('sessionId', $sessionId)
            ->setParameter('slotName', $slotName)
            ->setParameter('itemName', $itemName)
            ->setParameter('open', ItemBounty::STATUS_OPEN)
            ->getQuery()
            ->getOneOrNullResult();

        return $bounty instanceof ItemBounty ? $bounty : null;
    }

    public function findOpenBySession(string $sessionId): array
    {
        return $this->entityManager->getRepository(ItemBounty::class)->findBy(
            ['sessionId' => $sessionId, 'status' => ItemBounty::STATUS_OPEN],
            ['createdAt' => 'ASC'],
        );
    }

    public function findOpenOfEndedSessions(): array
    {
        /** @var list<ItemBounty> $bounties */
        $bounties = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(ItemBounty::class, 'b')
            ->join(Session::class, 's', 'WITH', 's.id = b.sessionId')
            ->where('b.status = :open')
            ->andWhere('s.status IN (:ended)')
            ->setParameter('open', ItemBounty::STATUS_OPEN)
            ->setParameter('ended', self::ENDED_STATUSES)
            ->getQuery()
            ->getResult();

        return $bounties;
    }

    public function save(ItemBounty $bounty): void
    {
        $this->entityManager->persist($bounty);
        $this->entityManager->flush();
    }
}
