<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Registrations\Domain\Registration;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MyRegistrationQuery
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{registrationId: string, status: string}|null
     */
    public function findActiveByEventAndUser(string $eventId, string $userId): ?array
    {
        $registration = $this->entityManager->getRepository(Registration::class)->findOneBy([
            'eventId' => $eventId,
            'userId' => $userId,
        ]);

        if (!$registration instanceof Registration || Registration::STATUS_CANCELLED === $registration->getStatus()) {
            return null;
        }

        return ['registrationId' => $registration->getId(), 'status' => $registration->getStatus()];
    }
}
