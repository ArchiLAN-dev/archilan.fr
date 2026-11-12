<?php

declare(strict_types=1);

namespace App\Streaming\Application\Service;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Streaming\Application\Support\MediaPublishCredentials;
use App\Streaming\Application\Support\MediaPublishEligibility;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Story 47.5: answers the LAN media server (MediaMTX `authHTTPAddress`) for each publication. Only a confirmed
 * registrant of an event open for local broadcasting, with their current key, gets through. Each decision is logged
 * with its reason, never with a key.
 *
 * v0.66.1: reads come here too. A stream of the event is read with the event's régie token only (the régie screen
 * sends it as WHEP Bearer token), so a player on the LAN cannot watch another's screen.
 *
 * MediaMTX 1.21 sends the key in `token` (OBS WHIP "Bearer Token", WHEP too) or `password` (SRT stream id, the
 * built-in player's login), spike 47.4.
 */
final readonly class MediaPublishAuthorization
{
    public function __construct(
        private EventRepositoryInterface $events,
        private RegistrationRepositoryInterface $registrations,
        private UserRepositoryInterface $users,
        private MediaPublishCredentials $credentials,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<mixed> $request the JSON body MediaMTX posts
     */
    public function allows(array $request): bool
    {
        $action = self::text($request, 'action');
        $path = self::text($request, 'path');
        $refusal = $this->refusal($action, $path, self::text($request, 'token'), self::text($request, 'password'));

        $this->logger->info('streaming.media_auth', [
            'allowed' => null === $refusal,
            'refusal' => $refusal,
            'action' => $action,
            'path' => $path,
            'protocol' => self::text($request, 'protocol'),
            'ip' => self::text($request, 'ip'),
        ]);

        return null === $refusal;
    }

    private function refusal(string $action, string $path, string $token, string $password): ?string
    {
        if ('publish' !== $action && 'read' !== $action) {
            return 'action_not_allowed';
        }
        if (1 !== preg_match(MediaPublishCredentials::PATH_PATTERN, $path)) {
            return 'bad_path';
        }

        $now = $this->clock->now();
        [$event, $registration] = $this->findPublisher($path, $now);
        if (!$event instanceof Event || !$registration instanceof Registration) {
            return 'unknown_path';
        }

        if ('read' === $action) {
            $readToken = '' !== $token ? $token : $password;

            return $this->credentials->isReadToken($event->getId(), $readToken) ? null : 'bad_read_token';
        }

        $refusal = MediaPublishEligibility::refusal($event, $registration, $this->users->findById($registration->getUserId()), $now);
        if (null !== $refusal) {
            return $refusal;
        }

        $key = $this->credentials->keyFor($event->getId(), $registration->getId(), $registration->getMediaKeyVersion());
        $matches = ('' !== $token && hash_equals($key, $token)) || ('' !== $password && hash_equals($key, $password));

        return $matches ? null : 'bad_key';
    }

    /**
     * The path is derived, not stored: look among the registrants of the events open for local broadcasting now. A
     * LAN has one such event at a time, a few dozen registrants.
     *
     * @return array{0: Event|null, 1: Registration|null}
     */
    private function findPublisher(string $path, \DateTimeImmutable $now): array
    {
        foreach ($this->events->findByStatuses([Event::STATUS_PUBLISHED, Event::STATUS_IN_PROGRESS]) as $event) {
            if (null === $event->getMediaServerHost() || $now < MediaPublishEligibility::opensAt($event) || $now > MediaPublishEligibility::closesAt($event)) {
                continue;
            }
            foreach ($this->registrations->findBy(['eventId' => $event->getId(), 'status' => Registration::STATUS_RESERVED]) as $registration) {
                if (hash_equals($this->credentials->pathFor($registration->getId()), $path)) {
                    return [$event, $registration];
                }
            }
        }

        return [null, null];
    }

    /** @param array<mixed> $request */
    private static function text(array $request, string $key): string
    {
        $value = $request[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
