<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Doctrine;

use App\Content\Domain\Entity\Partner;
use App\Content\Domain\Repository\PartnerRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePartnerRepository implements PartnerRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $id): ?Partner
    {
        return $this->entityManager->find(Partner::class, $id);
    }

    public function all(): array
    {
        return $this->entityManager->getRepository(Partner::class)->findBy([], ['position' => 'ASC', 'createdAt' => 'ASC']);
    }

    public function save(Partner $partner): void
    {
        $this->entityManager->persist($partner);
        $this->entityManager->flush();
    }

    public function saveAll(array $partners): void
    {
        foreach ($partners as $partner) {
            $this->entityManager->persist($partner);
        }
        $this->entityManager->flush();
    }

    public function remove(Partner $partner): void
    {
        $this->entityManager->remove($partner);
        $this->entityManager->flush();
    }
}
