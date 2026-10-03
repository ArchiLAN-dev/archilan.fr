<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Doctrine;

use App\Community\Application\Port\CosmeticOwnershipInterface;
use App\Wallet\Domain\Entity\OwnedCosmetic;
use App\Wallet\Domain\Entity\ShopItem;
use App\Wallet\Domain\Repository\ShopRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The shop's storage (story 41.7), and Community's view of what a member bought: the profile editor checks a shop
 * frame or banner against it.
 */
final readonly class DoctrineShopRepository implements ShopRepositoryInterface, CosmeticOwnershipInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findItem(string $id): ?ShopItem
    {
        return $this->entityManager->find(ShopItem::class, $id);
    }

    public function allItems(): array
    {
        return $this->entityManager->getRepository(ShopItem::class)->findBy([], ['createdAt' => 'DESC']);
    }

    public function owns(string $userId, string $type, string $cosmeticKey): bool
    {
        return null !== $this->entityManager->getRepository(OwnedCosmetic::class)->findOneBy([
            'userId' => $userId,
            'type' => $type,
            'cosmeticKey' => $cosmeticKey,
        ]);
    }

    public function ownedKeys(string $userId, string $type): array
    {
        return array_map(
            static fn (OwnedCosmetic $owned): string => $owned->getCosmeticKey(),
            $this->entityManager->getRepository(OwnedCosmetic::class)->findBy(['userId' => $userId, 'type' => $type]),
        );
    }

    public function saveItem(ShopItem $item): void
    {
        $this->entityManager->persist($item);
        $this->entityManager->flush();
    }

    public function saveOwned(OwnedCosmetic $owned): void
    {
        $this->entityManager->persist($owned);
        $this->entityManager->flush();
    }
}
