<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Entity\FriendLink;
use App\Community\Domain\Repository\FriendLinkRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineFriendLinkRepository implements FriendLinkRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findByUserId(string $userId): ?FriendLink
    {
        return $this->entityManager->getRepository(FriendLink::class)->findOneBy(['userId' => $userId]);
    }

    public function findByCode(string $code): ?FriendLink
    {
        return $this->entityManager->getRepository(FriendLink::class)->findOneBy(['code' => $code]);
    }

    public function save(FriendLink $link): void
    {
        $this->entityManager->persist($link);
        $this->entityManager->flush();
    }
}
