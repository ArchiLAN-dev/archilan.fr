<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Domain\Block;
use App\Community\Domain\BlockRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineBlockRepository implements BlockRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $blockerId, string $blockedId): ?Block
    {
        return $this->entityManager->getRepository(Block::class)
            ->findOneBy(['blockerId' => $blockerId, 'blockedId' => $blockedId]);
    }

    public function existsEitherWay(string $a, string $b): bool
    {
        return null !== $this->find($a, $b) || null !== $this->find($b, $a);
    }

    public function save(Block $block): void
    {
        $this->entityManager->persist($block);
        $this->entityManager->flush();
    }

    public function remove(Block $block): void
    {
        $this->entityManager->remove($block);
        $this->entityManager->flush();
    }
}
