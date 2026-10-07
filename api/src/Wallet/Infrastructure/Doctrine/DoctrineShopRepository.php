<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Doctrine;

use App\Community\Application\Port\CosmeticOwnershipInterface;
use App\Wallet\Domain\Entity\OwnedCosmetic;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Entity\ShopItem;
use App\Wallet\Domain\Enum\PelleReason;
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

    public function grant(string $userId, string $type, string $key, string $source, string $sourceLabel): bool
    {
        if ($this->owns($userId, $type, $key)) {
            return false;
        }
        $this->saveOwned(OwnedCosmetic::acquire($userId, $type, $key, new \DateTimeImmutable(), $source, $sourceLabel));

        return true;
    }

    public function origins(string $userId): array
    {
        $origins = [];
        foreach ($this->entityManager->getRepository(OwnedCosmetic::class)->findBy(['userId' => $userId]) as $owned) {
            $origins[$owned->getType().':'.$owned->getCosmeticKey()] = [
                'source' => $owned->getSource(),
                'label' => $owned->getSourceLabel(),
                'acquiredAt' => $owned->getAcquiredAt()->format(\DATE_ATOM),
            ];
        }

        return $origins;
    }

    public function saveOwned(OwnedCosmetic $owned): void
    {
        $this->entityManager->persist($owned);
        $this->entityManager->flush();
    }

    public function deleteItem(ShopItem $item): void
    {
        $this->entityManager->remove($item);
        $this->entityManager->flush();
    }

    public function sales(): array
    {
        /** @var list<array{uniqueKey: string|null, amount: int}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('m.uniqueKey', 'm.amount')
            ->from(PelleMovement::class, 'm')
            ->where('m.reason = :reason')
            ->setParameter('reason', PelleReason::ShopPurchase)
            ->getQuery()
            ->getArrayResult();

        $sales = [];
        foreach ($rows as $row) {
            $parts = explode(':', (string) $row['uniqueKey']);
            if (3 !== \count($parts) || 'shop' !== $parts[0]) {
                continue;
            }
            $sale = $sales[$parts[2]] ?? ['count' => 0, 'pelles' => 0];
            $sales[$parts[2]] = ['count' => $sale['count'] + 1, 'pelles' => $sale['pelles'] - $row['amount']];
        }

        return $sales;
    }
}
