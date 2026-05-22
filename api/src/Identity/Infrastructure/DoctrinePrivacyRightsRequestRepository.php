<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\PrivacyRightsRequest;
use App\Identity\Domain\PrivacyRightsRequestRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePrivacyRightsRequestRepository implements PrivacyRightsRequestRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(PrivacyRightsRequest $request): void
    {
        $this->entityManager->persist($request);
        $this->entityManager->flush();
    }
}
