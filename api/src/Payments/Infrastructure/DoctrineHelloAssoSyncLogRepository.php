<?php

declare(strict_types=1);

namespace App\Payments\Infrastructure;

use App\Payments\Domain\HelloAssoSyncLog;
use App\Payments\Domain\HelloAssoSyncLogRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineHelloAssoSyncLogRepository implements HelloAssoSyncLogRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findRecentByFormSlug(string $formSlug, int $limit = 10): array
    {
        /* @var list<HelloAssoSyncLog> */
        return $this->entityManager->getRepository(HelloAssoSyncLog::class)->findBy(
            ['formSlug' => $formSlug],
            ['attemptAt' => 'DESC'],
            $limit,
        );
    }

    public function persist(HelloAssoSyncLog $log): void
    {
        $this->entityManager->persist($log);
    }

    public function save(HelloAssoSyncLog $log): void
    {
        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
