<?php

declare(strict_types=1);

namespace App\Events\Application\Command;

use App\Events\Domain\Entity\EventPrivateAccessLog;
use App\Events\Domain\Repository\EventPrivateAccessLogRepositoryInterface;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Registrations\Application\Query\RegistrationCounter;
use Psr\Log\LoggerInterface;

final readonly class VerifyPrivateEventAccess
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private EventPrivateAccessLogRepositoryInterface $accessLogRepository,
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
        $event = $this->eventRepository->findById($eventId);
        if (null === $event) {
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

        $this->accessLogRepository->save(new EventPrivateAccessLog(
            bin2hex(random_bytes(16)),
            $event->getId(),
            $userId,
            $granted,
            new \DateTimeImmutable(),
        ));

        $this->logger->info('event.private_access_attempt', ['eventId' => $eventId, 'userId' => $userId, 'granted' => $granted]);

        return ['granted' => $granted];
    }

    private function canUnlockPrivateRegistration(\App\Events\Domain\Entity\Event $event, int $confirmedCount, \DateTimeImmutable $now): bool
    {
        return !$event->isPublic()
            && \App\Events\Domain\Entity\Event::STATUS_PUBLISHED === $event->getStatus()
            && $now >= $event->getRegistrationOpensAt()
            && $now <= $event->getRegistrationClosesAt()
            && !$event->isAtCapacity($confirmedCount);
    }
}
