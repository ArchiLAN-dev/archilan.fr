<?php

declare(strict_types=1);

namespace App\Registrations\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(name: 'idx_registrations_admin_messages_registration', columns: ['registration_id', 'sent_at'])]
final class RegistrationAdminMessage
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'event_id', type: 'string', length: 32)]
        private string $eventId,
        #[ORM\Column(name: 'registration_id', type: 'string', length: 32)]
        private string $registrationId,
        #[ORM\Column(name: 'admin_id', type: 'string', length: 32)]
        private string $adminId,
        #[ORM\Column(type: 'string', length: 160)]
        private string $subject,
        #[ORM\Column(name: 'sent_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $sentAt,
    ) {
    }

    public static function record(
        string $eventId,
        string $registrationId,
        string $adminId,
        string $subject,
        \DateTimeImmutable $sentAt,
    ): self {
        return new self(
            bin2hex(random_bytes(16)),
            $eventId,
            $registrationId,
            $adminId,
            mb_substr($subject, 0, 160),
            $sentAt,
        );
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

    public function getAdminId(): string
    {
        return $this->adminId;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }
}
