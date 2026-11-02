<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Doctrine;

use App\Wallet\Domain\Entity\MinimapperzSetting;
use App\Wallet\Domain\Repository\MinimapperzSettingRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineMinimapperzSettingRepository implements MinimapperzSettingRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $eventId): ?MinimapperzSetting
    {
        return $this->entityManager->find(MinimapperzSetting::class, $eventId);
    }

    public function save(MinimapperzSetting $setting): void
    {
        $this->entityManager->persist($setting);
        $this->entityManager->flush();
    }
}
