<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Entity\NotificationPreference;
use App\Community\Domain\Repository\NotificationPreferenceRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineNotificationPreferenceRepository implements NotificationPreferenceRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $userId, string $type): ?NotificationPreference
    {
        return $this->entityManager->getRepository(NotificationPreference::class)->findOneBy(['userId' => $userId, 'type' => $type]);
    }

    public function forUser(string $userId): array
    {
        return $this->entityManager->getRepository(NotificationPreference::class)->findBy(['userId' => $userId]);
    }

    public function save(NotificationPreference $preference): void
    {
        $this->entityManager->persist($preference);
        $this->entityManager->flush();
    }
}
