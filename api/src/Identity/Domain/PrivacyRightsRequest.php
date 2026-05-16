<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(name: 'idx_identity_privacy_rights_requests_user_id', columns: ['user_id'])]
#[ORM\Index(name: 'idx_identity_privacy_rights_requests_status', columns: ['status'])]
class PrivacyRightsRequest
{
    public const STATUS_RECEIVED = 'received';
    public const HANDLING_MANUAL_REVIEW = 'manual_review';

    public const RIGHT_ACCESS = 'access';
    public const RIGHT_RECTIFICATION = 'rectification';
    public const RIGHT_ERASURE = 'erasure';
    public const RIGHT_PORTABILITY = 'portability';
    public const RIGHT_OPPOSITION = 'opposition';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(name: 'right_type', type: 'string', length: 20)]
        private string $rightType,
        #[ORM\Column(type: 'string', length: 20)]
        private string $status,
        #[ORM\Column(name: 'handling_mode', type: 'string', length: 30)]
        private string $handlingMode,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $details,
        #[ORM\Column(name: 'submitted_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $submittedAt,
    ) {
    }

    public static function submit(string $userId, string $rightType, ?string $details, \DateTimeImmutable $now): self
    {
        if (!in_array($rightType, self::supportedRights(), true)) {
            throw new \InvalidArgumentException('Type de droit RGPD non supporté.');
        }

        $trimmed = null === $details ? null : trim($details);

        return new self(
            bin2hex(random_bytes(16)),
            $userId,
            $rightType,
            self::STATUS_RECEIVED,
            self::HANDLING_MANUAL_REVIEW,
            '' === $trimmed ? null : $trimmed,
            $now,
        );
    }

    /**
     * @return list<string>
     */
    public static function supportedRights(): array
    {
        return [
            self::RIGHT_ACCESS,
            self::RIGHT_RECTIFICATION,
            self::RIGHT_ERASURE,
            self::RIGHT_PORTABILITY,
            self::RIGHT_OPPOSITION,
        ];
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getRightType(): string
    {
        return $this->rightType;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getHandlingMode(): string
    {
        return $this->handlingMode;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }
}
