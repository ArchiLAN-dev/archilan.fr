<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

use App\GameSelection\Domain\GameRequest;
use App\GameSelection\Domain\GameRequestRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineGameRequestRepository implements GameRequestRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findByNormalizedNameAndUserId(string $normalizedName, string $userId): ?GameRequest
    {
        /* @var GameRequest|null */
        return $this->entityManager->getRepository(GameRequest::class)->findOneBy([
            'normalizedName' => $normalizedName,
            'userId' => $userId,
        ]);
    }

    public function save(GameRequest $request): void
    {
        $this->entityManager->persist($request);
        $this->entityManager->flush();
    }

    public function remove(GameRequest $request): void
    {
        $this->entityManager->remove($request);
        $this->entityManager->flush();
    }
}
