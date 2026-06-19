<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

use App\GameSelection\Domain\ArchipelagoClientInfo;
use App\GameSelection\Domain\ArchipelagoClientInfoRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineArchipelagoClientInfoRepository implements ArchipelagoClientInfoRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function get(): ?ArchipelagoClientInfo
    {
        return $this->entityManager->find(ArchipelagoClientInfo::class, ArchipelagoClientInfo::SINGLETON_ID);
    }

    public function save(ArchipelagoClientInfo $info): void
    {
        $this->entityManager->persist($info);
        $this->entityManager->flush();
    }
}
