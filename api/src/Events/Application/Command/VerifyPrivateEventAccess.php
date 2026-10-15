<?php

declare(strict_types=1);

namespace App\Events\Application\Command;

use App\Events\Domain\Entity\EventPrivateAccessLog;
use App\Events\Domain\Repository\EventPrivateAccessLogRepositoryInterface;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Registrations\Application\Query\RegistrationCounter;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class VerifyPrivateEventAccess
{
    /** Story 4.24: refused codes allowed per member, event and sliding hour. */
    public const int MAX_DENIED_PER_HOUR = 10;

    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private EventPrivateAccessLogRepositoryInterface $accessLogRepository,
        private RegistrationCounter $registrationCounter,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Verifies a private event access password for an authenticated user.
     * Returns null if the event does not exist or is not publicly visible.
     */
    public function verify(string $eventId, mixed $password, string $userId): ?PrivateAccessResult
    {
        $event = $this->eventRepository->findById($eventId);
        if (null === $event) {
            return null;
        }

        if (!$event->isVisiblePublicly()) {
            return null;
        }

        if (!$event->hasPrivateAccessPassword()) {
            return new PrivateAccessResult(false);
        }

        $now = $this->clock->now();
        if ($this->accessLogRepository->countDeniedSince($event->getId(), $userId, $now->modify('-1 hour')) >= self::MAX_DENIED_PER_HOUR) {
            $this->logger->info('event.private_access_limited', ['eventId' => $eventId, 'userId' => $userId]);

            return new PrivateAccessResult(false, limited: true);
        }

        $confirmedCount = $this->registrationCounter->countConfirmed($event->getId());
        $granted = is_string($password) && '' !== $password
            && $this->canUnlockPrivateRegistration($event, $confirmedCount, $this->clock->now())
            && $event->verifyPrivateAccessPassword($password);

        $this->accessLogRepository->save(new EventPrivateAccessLog(
            bin2hex(random_bytes(16)),
            $event->getId(),
            $userId,
            $granted,
            $this->clock->now(),
        ));

        $this->logger->info('event.private_access_attempt', ['eventId' => $eventId, 'userId' => $userId, 'granted' => $granted]);

        return new PrivateAccessResult($granted);
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
