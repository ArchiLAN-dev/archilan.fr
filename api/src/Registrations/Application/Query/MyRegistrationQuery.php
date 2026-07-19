<?php

declare(strict_types=1);

namespace App\Registrations\Application\Query;

use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;

final readonly class MyRegistrationQuery
{
    public function __construct(private RegistrationRepositoryInterface $registrationRepository)
    {
    }

    /**
     * @return array{registrationId: string, status: string}|null
     */
    public function findActiveByEventAndUser(string $eventId, string $userId): ?array
    {
        $registration = $this->registrationRepository->findByEventAndUser($eventId, $userId);

        if (!$registration instanceof Registration || Registration::STATUS_CANCELLED === $registration->getStatus()) {
            return null;
        }

        return ['registrationId' => $registration->getId(), 'status' => $registration->getStatus()];
    }
}
