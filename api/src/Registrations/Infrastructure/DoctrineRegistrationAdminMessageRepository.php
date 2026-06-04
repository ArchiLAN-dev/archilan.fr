<?php

declare(strict_types=1);

namespace App\Registrations\Infrastructure;

use App\Registrations\Domain\RegistrationAdminMessage;
use App\Registrations\Domain\RegistrationAdminMessageRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineRegistrationAdminMessageRepository implements RegistrationAdminMessageRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(RegistrationAdminMessage $message): void
    {
        $this->entityManager->persist($message);
        $this->entityManager->flush();
    }
}
