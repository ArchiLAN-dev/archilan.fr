<?php

declare(strict_types=1);

namespace App\Streaming\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Story 47.6: what the régie agent last said about one registrant's stream on the LAN media server. The row stays
 * once seen: a cut stream keeps when it was last seen (story 47.9 shows « coupé il y a X min »).
 */
#[ORM\Entity]
#[ORM\Table(name: 'streaming_local_stream')]
#[ORM\UniqueConstraint(name: 'uniq_streaming_local_stream_registration', columns: ['event_id', 'registration_id'])]
final class LocalStream
{
    /** Without a report for this long, a stream no longer counts as live. */
    public const int STALE_AFTER_SECONDS = 30;

    #[ORM\Column(name: 'online_since', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $onlineSince = null;

    /** v0.66.1: the MediaMTX publishing session last reported on this path (its source id). */
    #[ORM\Column(name: 'session_id', type: 'string', length: 64, nullable: true)]
    private ?string $sessionId = null;

    /** v0.66.1: the registrant's key version when that session was first reported. */
    #[ORM\Column(name: 'session_key_version', type: 'integer', nullable: true)]
    private ?int $sessionKeyVersion = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'event_id', type: 'string', length: 32)]
        private string $eventId,
        #[ORM\Column(name: 'registration_id', type: 'string', length: 32)]
        private string $registrationId,
        #[ORM\Column(type: 'string', length: 64)]
        private string $path,
        #[ORM\Column(name: 'last_seen_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $lastSeenAt,
    ) {
    }

    /**
     * The agent sees the stream online. A stream already live keeps its start; otherwise it starts when MediaMTX
     * says (never in the future), or now.
     */
    public function seenOnline(?\DateTimeImmutable $onlineSince, \DateTimeImmutable $now): void
    {
        if (!$this->isLiveAt($now)) {
            $this->onlineSince = null !== $onlineSince && $onlineSince <= $now ? $onlineSince : $now;
        }
        $this->lastSeenAt = $now;
    }

    /**
     * v0.66.1: the agent names the publishing session. A new session is stamped with the registrant's key version of
     * the moment: MediaMTX only checks a key when a publication starts, so a session stamped with an older version
     * started with a key the registrant has since replaced. An agent that names no session changes nothing.
     */
    public function trackSession(?string $sessionId, int $keyVersion): void
    {
        if (null === $sessionId || '' === $sessionId || $sessionId === $this->sessionId) {
            return;
        }
        $this->sessionId = $sessionId;
        $this->sessionKeyVersion = $keyVersion;
    }

    /** v0.66.1: whether the current session started before the registrant's last new key. */
    public function startedWithAnOldKey(int $currentKeyVersion): bool
    {
        return null !== $this->sessionKeyVersion && $this->sessionKeyVersion < $currentKeyVersion;
    }

    /** The agent no longer lists the stream: it was cut, last seen at the previous report. */
    public function seenOffline(): void
    {
        $this->onlineSince = null;
    }

    public function isLiveAt(\DateTimeImmutable $now): bool
    {
        return null !== $this->onlineSince && $now->getTimestamp() - $this->lastSeenAt->getTimestamp() <= self::STALE_AFTER_SECONDS;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getRegistrationId(): string
    {
        return $this->registrationId;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getOnlineSince(): ?\DateTimeImmutable
    {
        return $this->onlineSince;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }
}
