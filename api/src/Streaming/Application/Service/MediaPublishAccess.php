<?php

declare(strict_types=1);

namespace App\Streaming\Application\Service;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Streaming\Application\Support\MediaPublishCredentials;
use App\Streaming\Application\Support\MediaPublishEligibility;
use Psr\Clock\ClockInterface;

/**
 * Story 47.5: a registrant's own access to the LAN media server (read by the 47.7 card): their path, key and the
 * addresses to paste in OBS, or why they have none; and a new key on request.
 *
 * @phpstan-type MediaPublishView array{available: bool, live: bool, reason: string|null, path: string|null, key: string|null, whipUrl: string|null, srtUrl: string|null, opensAt: string|null, closesAt: string|null}
 */
final readonly class MediaPublishAccess
{
    public function __construct(
        private RegistrationRepositoryInterface $registrations,
        private EventRepositoryInterface $events,
        private UserRepositoryInterface $users,
        private MediaPublishCredentials $credentials,
        private ClockInterface $clock,
        private LocalStreamsState $localStreams,
    ) {
    }

    /** @return MediaPublishView */
    public function forOwner(string $registrationId, string $userId): array
    {
        [$registration, $event] = $this->owned($registrationId, $userId);

        return $this->view($registration, $event);
    }

    /**
     * The old key stops working at once (the media server asks at each publication).
     *
     * @return MediaPublishView
     */
    public function regenerate(string $registrationId, string $userId): array
    {
        [$registration, $event] = $this->owned($registrationId, $userId);
        $registration->regenerateMediaKey($this->clock->now());
        $this->registrations->save($registration);

        return $this->view($registration, $event);
    }

    /** @return array{0: Registration, 1: Event} */
    private function owned(string $registrationId, string $userId): array
    {
        $registration = $this->registrations->findById($registrationId);
        if (!$registration instanceof Registration || !$registration->isReserved()) {
            throw new NotFoundException('Inscription introuvable.', 'registration_not_found');
        }
        if ($registration->getUserId() !== $userId) {
            throw new ForbiddenException("Cette inscription n'est pas la tienne.");
        }
        $event = $this->events->findById($registration->getEventId());
        if (!$event instanceof Event) {
            throw new NotFoundException('Inscription introuvable.', 'registration_not_found');
        }

        return [$registration, $event];
    }

    /** @return MediaPublishView */
    private function view(Registration $registration, Event $event): array
    {
        $host = $event->getMediaServerHost();
        $refusal = MediaPublishEligibility::refusal($event, $registration, $this->users->findById($registration->getUserId()), $this->clock->now());
        $window = null === $host ? [null, null] : [
            MediaPublishEligibility::opensAt($event)->format(\DateTimeInterface::ATOM),
            MediaPublishEligibility::closesAt($event)->format(\DateTimeInterface::ATOM),
        ];

        if (null !== $refusal || null === $host) {
            return ['available' => false, 'live' => false, 'reason' => $refusal ?? MediaPublishEligibility::NO_MEDIA_SERVER, 'path' => null, 'key' => null, 'whipUrl' => null, 'srtUrl' => null, 'opensAt' => $window[0], 'closesAt' => $window[1]];
        }

        $path = $this->credentials->pathFor($registration->getId());
        $key = $this->credentials->keyFor($event->getId(), $registration->getId(), $registration->getMediaKeyVersion());

        return [
            'available' => true,
            // Story 47.7: the régie agent sees the stream (story 47.6).
            'live' => $this->localStreams->isLive($event->getId(), $registration->getId()),
            'reason' => null,
            'path' => $path,
            'key' => $key,
            'whipUrl' => MediaPublishCredentials::whipUrl($host, $path),
            'srtUrl' => MediaPublishCredentials::srtUrl($host, $path, $key),
            'opensAt' => $window[0],
            'closesAt' => $window[1],
        ];
    }
}
