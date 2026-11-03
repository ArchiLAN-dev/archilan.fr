<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One Minimapperz code of an event (story 49.2): given by Minimapperz, imported by an admin, then handed to one
 * registrant, who types it when joining the LAN there. The games Minimapperz reports carry the code, which is how the
 * site knows whom to credit (story 49.3). A member holds at most one code per event; a code goes to one member.
 */
#[ORM\Entity]
#[ORM\Table(name: 'minimapperz_code')]
#[ORM\UniqueConstraint(name: 'uniq_minimapperz_code_member', columns: ['event_id', 'user_id'])]
final class MinimapperzCode
{
    public const int MAX_LENGTH = 100;

    #[ORM\Column(name: 'user_id', type: 'string', length: 32, nullable: true)]
    private ?string $userId = null;

    #[ORM\Column(name: 'assigned_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $assignedAt = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'event_id', type: 'string', length: 32)]
        private string $eventId,
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: self::MAX_LENGTH)]
        private string $code,
        #[ORM\Column(name: 'imported_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $importedAt,
    ) {
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getAssignedAt(): ?\DateTimeImmutable
    {
        return $this->assignedAt;
    }
}
