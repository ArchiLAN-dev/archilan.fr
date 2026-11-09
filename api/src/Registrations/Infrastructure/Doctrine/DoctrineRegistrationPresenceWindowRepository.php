<?php

declare(strict_types=1);

namespace App\Registrations\Infrastructure\Doctrine;

use App\Registrations\Domain\Entity\RegistrationPresenceWindow;
use App\Registrations\Domain\Repository\RegistrationPresenceWindowRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineRegistrationPresenceWindowRepository implements RegistrationPresenceWindowRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findByRegistration(string $registrationId): array
    {
        return $this->entityManager->getRepository(RegistrationPresenceWindow::class)->findBy(
            ['registrationId' => $registrationId],
            ['startsAt' => 'ASC'],
        );
    }

    public function replaceForRegistration(string $registrationId, array $windows): void
    {
        $this->entityManager->wrapInTransaction(function () use ($registrationId, $windows): void {
            foreach ($this->findByRegistration($registrationId) as $existing) {
                $this->entityManager->remove($existing);
            }
            $this->entityManager->flush();
            foreach ($windows as $window) {
                $this->entityManager->persist($window);
            }
            $this->entityManager->flush();
        });
    }
}
