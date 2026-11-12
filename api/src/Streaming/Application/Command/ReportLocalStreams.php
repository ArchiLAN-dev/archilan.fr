<?php

declare(strict_types=1);

namespace App\Streaming\Application\Command;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Streaming\Application\Exception\MediaAgentSignatureException;
use App\Streaming\Application\Support\MediaPublishCredentials;
use App\Streaming\Application\Support\MediaPublishEligibility;
use App\Streaming\Domain\Entity\LocalMediaServer;
use App\Streaming\Domain\Entity\LocalStream;
use App\Streaming\Domain\Repository\LocalStreamRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Story 47.6: the régie agent reports the streams the LAN media server has online for an event. Each report replaces
 * the state: a listed path of a registrant allowed to publish is live, any other stream of the event is cut. Paths of
 * no registrant are ignored (a test pattern, a stale player).
 *
 * v0.66.1: the signature is checked before anything is read, and the agent is told which paths to cut: a registrant
 * no longer allowed to publish, or a session started with a key replaced since (MediaMTX checks a key only when a
 * publication starts).
 */
final readonly class ReportLocalStreams
{
    public const int MAX_STREAMS = 200;

    public function __construct(
        private EventRepositoryInterface $events,
        private RegistrationRepositoryInterface $registrations,
        private UserRepositoryInterface $users,
        private LocalStreamRepositoryInterface $streams,
        private MediaPublishCredentials $credentials,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws MediaAgentSignatureException when the signature or the timestamp does not hold
     * @throws NotFoundException            when the event is unknown or has no media server
     * @throws ValidationException          when the body is not a list of streams
     */
    public function report(string $eventId, string $timestamp, string $signature, string $body): LocalStreamsReport
    {
        $now = $this->clock->now();
        if (!$this->credentials->verifyAgentRequest($eventId, $timestamp, $signature, $body, $now)) {
            throw new MediaAgentSignatureException();
        }
        $event = $this->events->findById($eventId);
        if (!$event instanceof Event || null === $event->getMediaServerHost()) {
            throw new NotFoundException('Événement introuvable.', 'event_not_found');
        }

        $listed = self::parse($body);
        $registrationsByPath = [];
        foreach ($this->registrations->findBy(['eventId' => $eventId]) as $registration) {
            $registrationsByPath[$this->credentials->pathFor($registration->getId())] = $registration;
        }

        $known = [];
        foreach ($this->streams->streamsOf($eventId) as $stream) {
            $known[$stream->getRegistrationId()] = $stream;
        }

        $live = 0;
        $ignored = 0;
        $kick = [];
        $seen = [];
        foreach ($listed as $path => ['onlineSince' => $onlineSince, 'session' => $session]) {
            $registration = $registrationsByPath[$path] ?? null;
            if (!$registration instanceof Registration) {
                ++$ignored;
                continue;
            }
            if (null !== MediaPublishEligibility::refusal($event, $registration, $this->users->findById($registration->getUserId()), $now)) {
                $kick[] = $path;
                continue;
            }
            $stream = $known[$registration->getId()] ??= new LocalStream(bin2hex(random_bytes(16)), $eventId, $registration->getId(), $path, $now);
            $stream->trackSession($session, $registration->getMediaKeyVersion());
            if ($stream->startedWithAnOldKey($registration->getMediaKeyVersion())) {
                $kick[] = $path;
                continue;
            }
            $stream->seenOnline($onlineSince, $now);
            $seen[$registration->getId()] = true;
            ++$live;
        }
        foreach ($known as $registrationId => $stream) {
            if (!isset($seen[$registrationId])) {
                $stream->seenOffline();
            }
        }

        $server = $this->streams->serverOf($eventId) ?? new LocalMediaServer($eventId, $now);
        $server->reported($live, $now);
        $this->streams->saveReport($server, array_values($known));

        $this->logger->info('streaming.local_streams_report', ['eventId' => $eventId, 'live' => $live, 'ignored' => $ignored, 'kicked' => count($kick)]);

        return new LocalStreamsReport($live, $ignored, $kick);
    }

    /**
     * The listed paths, each with when MediaMTX says it went online and its publishing session (null when absent or
     * unreadable).
     *
     * @return array<string, array{onlineSince: \DateTimeImmutable|null, session: string|null}>
     */
    private static function parse(string $body): array
    {
        $payload = json_decode($body, true);
        $streams = is_array($payload) ? ($payload['streams'] ?? null) : null;
        if (!is_array($streams) || !array_is_list($streams) || count($streams) > self::MAX_STREAMS) {
            throw new ValidationException('Liste de flux invalide.', ['streams' => ['Une liste de 200 flux au plus est attendue.']]);
        }

        $listed = [];
        foreach ($streams as $stream) {
            $path = is_array($stream) ? ($stream['path'] ?? null) : null;
            if (!is_string($path) || '' === $path) {
                continue;
            }
            $since = $stream['onlineSince'] ?? null;
            $session = $stream['session'] ?? null;
            $listed[$path] = [
                'onlineSince' => is_string($since) ? self::parseDate($since) : null,
                'session' => is_string($session) && '' !== $session ? substr($session, 0, 64) : null,
            ];
        }

        return $listed;
    }

    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
