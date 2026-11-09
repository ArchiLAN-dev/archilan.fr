<?php

declare(strict_types=1);

namespace App\Registrations\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Story 52.2: a stretch of the event during which the member is there (arrival, departure). A registration without
 * any window is there the whole event.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_registration_presence_window_registration', columns: ['registration_id', 'starts_at'])]
final class RegistrationPresenceWindow
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'registration_id', type: 'string', length: 32)]
        private string $registrationId,
        #[ORM\Column(name: 'starts_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $startsAt,
        #[ORM\Column(name: 'ends_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $endsAt,
    ) {
    }

    public static function create(string $registrationId, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): self
    {
        return new self(bin2hex(random_bytes(16)), $registrationId, $startsAt, $endsAt);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getRegistrationId(): string
    {
        return $this->registrationId;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }
}
