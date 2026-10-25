<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A one-time code that lets the blind test app learn who a member is (story 41.45). Only its hash is kept; it is
 * served once, within a minute, then deleted. The `state` is the app's own, so a code is only good for the login the
 * app started. A code tied to no event opens the blind test's animation for an admin (story 41.53).
 */
#[ORM\Entity]
#[ORM\Table(name: 'blind_test_login_code')]
final class BlindTestLoginCode
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'code_hash', type: 'string', length: 64)]
        private string $codeHash,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(name: 'event_id', type: 'string', length: 32, nullable: true)]
        private ?string $eventId,
        #[ORM\Column(type: 'string', length: 64)]
        private string $state,
        #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $expiresAt,
    ) {
    }

    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getEventId(): ?string
    {
        return $this->eventId;
    }

    /** An animation code (story 41.53): it lets an admin into the console, the régie and the room screen. */
    public function isAnimation(): bool
    {
        return null === $this->eventId;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
