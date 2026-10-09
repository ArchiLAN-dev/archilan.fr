<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Entity\FriendFavorite;
use App\Community\Domain\Repository\FriendFavoriteRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineFriendFavoriteRepository implements FriendFavoriteRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function favoriteIds(string $userId): array
    {
        $ids = [];
        foreach ($this->entityManager->getRepository(FriendFavorite::class)->findBy(['userId' => $userId]) as $favorite) {
            $ids[$favorite->getFavoriteUserId()] = true;
        }

        return $ids;
    }

    public function find(string $userId, string $favoriteUserId): ?FriendFavorite
    {
        return $this->entityManager->getRepository(FriendFavorite::class)
            ->findOneBy(['userId' => $userId, 'favoriteUserId' => $favoriteUserId]);
    }

    public function count(string $userId): int
    {
        return $this->entityManager->getRepository(FriendFavorite::class)->count(['userId' => $userId]);
    }

    public function save(FriendFavorite $favorite): void
    {
        $this->entityManager->persist($favorite);
        $this->entityManager->flush();
    }

    public function remove(FriendFavorite $favorite): void
    {
        $this->entityManager->remove($favorite);
        $this->entityManager->flush();
    }

    public function removeBetween(string $a, string $b): void
    {
        foreach ([$this->find($a, $b), $this->find($b, $a)] as $favorite) {
            if ($favorite instanceof FriendFavorite) {
                $this->entityManager->remove($favorite);
            }
        }
        $this->entityManager->flush();
    }
}
