<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\Entity\PrivacyRightsRequest;
use App\Identity\Domain\Repository\PrivacyRightsRequestRepositoryInterface;
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
