<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Doctrine;

use App\Wallet\Domain\Entity\BlindTestSetting;
use App\Wallet\Domain\Repository\BlindTestSettingRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineBlindTestSettingRepository implements BlindTestSettingRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $eventId): ?BlindTestSetting
    {
        return $this->entityManager->find(BlindTestSetting::class, $eventId);
    }

    public function save(BlindTestSetting $setting): void
    {
        $this->entityManager->persist($setting);
        $this->entityManager->flush();
    }
}
