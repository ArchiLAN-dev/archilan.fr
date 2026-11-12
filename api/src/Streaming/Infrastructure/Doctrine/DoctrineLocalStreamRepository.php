<?php

declare(strict_types=1);

namespace App\Streaming\Infrastructure\Doctrine;

use App\Streaming\Domain\Entity\LocalMediaServer;
use App\Streaming\Domain\Entity\LocalStream;
use App\Streaming\Domain\Repository\LocalStreamRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineLocalStreamRepository implements LocalStreamRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function streamsOf(string $eventId): array
    {
        /** @var list<LocalStream> $result */
        $result = $this->entityManager->getRepository(LocalStream::class)->findBy(['eventId' => $eventId], ['path' => 'ASC']);

        return $result;
    }

    public function streamOf(string $eventId, string $registrationId): ?LocalStream
    {
        return $this->entityManager->getRepository(LocalStream::class)->findOneBy(['eventId' => $eventId, 'registrationId' => $registrationId]);
    }

    public function serverOf(string $eventId): ?LocalMediaServer
    {
        return $this->entityManager->find(LocalMediaServer::class, $eventId);
    }

    public function saveReport(LocalMediaServer $server, array $streams): void
    {
        $this->entityManager->persist($server);
        foreach ($streams as $stream) {
            $this->entityManager->persist($stream);
        }
        $this->entityManager->flush();
    }
}
