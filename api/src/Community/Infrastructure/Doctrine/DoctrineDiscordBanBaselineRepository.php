<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Entity\DiscordBanBaseline;
use App\Community\Domain\Repository\DiscordBanBaselineRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineDiscordBanBaselineRepository implements DiscordBanBaselineRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function isTaken(): bool
    {
        return null !== $this->entityManager->find(DiscordBanBaseline::class, DiscordBanBaseline::SINGLETON);
    }

    public function markTaken(\DateTimeImmutable $now): void
    {
        if ($this->isTaken()) {
            return;
        }
        $this->entityManager->persist(DiscordBanBaseline::take($now));
        $this->entityManager->flush();
    }
}
