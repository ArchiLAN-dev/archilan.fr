<?php

declare(strict_types=1);

namespace App\Events\Application;

use App\Events\Domain\Event;
use App\Events\Domain\EventPrivateAccessLog;
use App\Registrations\Application\RegistrationCounter;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class VerifyPrivateEventAccess
{
    use EntityFinderTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RegistrationCounter $registrationCounter,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Verifies a private event access password for an authenticated user.
     * Returns null if the event does not exist or is not publicly visible.
     *
     * @return array{granted: bool}|null
     */
    public function verify(string $eventId, mixed $password, string $userId): ?array
    {
        try {
            $event = $this->findOrFail(Event::class, $eventId);
        } catch (\RuntimeException) {
            return null;
        }

        if (!$event->isVisiblePublicly()) {
            return null;
        }

        if (!$event->hasPrivateAccessPassword()) {
            return ['granted' => false];
        }

        $confirmedCount = $this->registrationCounter->countConfirmed($event->getId());
        $granted = is_string($password) && '' !== $password
            && $this->canUnlockPrivateRegistration($event, $confirmedCount, new \DateTimeImmutable())
            && $event->verifyPrivateAccessPassword($password);

        $this->entityManager->persist(new EventPrivateAccessLog(
            bin2hex(random_bytes(16)),
            $event->getId(),
            $userId,
            $granted,
            new \DateTimeImmutable(),
        ));
        $this->entityManager->flush();

        $this->logger->info('event.private_access_attempt', ['eventId' => $eventId, 'userId' => $userId, 'granted' => $granted]);

        return ['granted' => $granted];
    }

    private function canUnlockPrivateRegistration(Event $event, int $confirmedCount, \DateTimeImmutable $now): bool
    {
        return !$event->isPublic()
            && Event::STATUS_PUBLISHED === $event->getStatus()
            && $now >= $event->getRegistrationOpensAt()
            && $now <= $event->getRegistrationClosesAt()
            && !$event->isAtCapacity($confirmedCount);
    }
}
