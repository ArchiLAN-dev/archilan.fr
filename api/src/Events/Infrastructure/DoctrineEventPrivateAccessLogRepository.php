<?php

declare(strict_types=1);

namespace App\Events\Infrastructure;

use App\Events\Domain\EventPrivateAccessLog;
use App\Events\Domain\EventPrivateAccessLogRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEventPrivateAccessLogRepository implements EventPrivateAccessLogRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(EventPrivateAccessLog $log): void
    {
        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }
}
