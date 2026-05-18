<?php

declare(strict_types=1);

namespace App\Membership\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'memberships')]
#[ORM\Index(columns: ['user_id'], name: 'idx_memberships_user_id')]
#[ORM\Index(columns: ['expires_at', 'status'], name: 'idx_memberships_expires_at_status')]
#[ORM\Index(columns: ['status', 'user_id'], name: 'idx_memberships_status_user_id')]
#[ORM\UniqueConstraint(name: 'uniq_memberships_active_user_id', columns: ['user_id'], options: ['where' => "status = 'active'"])]
#[ORM\UniqueConstraint(name: 'uniq_memberships_helloasso_order_id', columns: ['helloasso_order_id'])]
final class Membership
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(name: 'helloasso_order_id', type: 'string', length: 100, nullable: true)]
        private ?string $helloassoOrderId,
        #[ORM\Column(name: 'started_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $startedAt,
        #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $expiresAt,
        #[ORM\Column(type: 'string', length: 10)]
        private string $status,
        #[ORM\Column(type: 'string', length: 20)]
        private string $source,
        #[ORM\Column(name: 'admin_note', type: 'text', nullable: true)]
        private ?string $adminNote,
        #[ORM\Column(name: 'reminder_30_sent_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $reminder30SentAt,
        #[ORM\Column(name: 'reminder_7_sent_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $reminder7SentAt,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        string $userId,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $expiresAt,
        string $source,
        ?string $helloassoOrderId,
        ?string $adminNote,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            bin2hex(random_bytes(16)),
            $userId,
            $helloassoOrderId,
            $startedAt,
            $expiresAt,
            'active',
            $source,
            $adminNote,
            null,
            null,
            $now,
            $now,
        );
    }

    public function renew(
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $newExpiresAt,
        string $source,
        ?string $helloassoOrderId,
        ?string $adminNote,
        \DateTimeImmutable $now,
    ): void {
        $this->startedAt = $startedAt;
        $this->expiresAt = $newExpiresAt;
        $this->source = $source;
        if (null !== $helloassoOrderId) {
            $this->helloassoOrderId = $helloassoOrderId;
        }
        if (null !== $adminNote) {
            $this->adminNote = $adminNote;
        }
        $this->updatedAt = $now;
    }

    public function adminEdit(
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $expiresAt,
        ?string $adminNote,
        \DateTimeImmutable $now,
    ): void {
        $this->startedAt = $startedAt;
        $this->expiresAt = $expiresAt;
        $this->adminNote = $adminNote;
        $this->updatedAt = $now;
    }

    public function expire(\DateTimeImmutable $now): void
    {
        $this->status = 'expired';
        $this->updatedAt = $now;
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        $this->status = 'cancelled';
        $this->updatedAt = $now;
    }

    public function markReminder30Sent(\DateTimeImmutable $now): void
    {
        $this->reminder30SentAt = $now;
        $this->updatedAt = $now;
    }

    public function markReminder7Sent(\DateTimeImmutable $now): void
    {
        $this->reminder7SentAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getHelloassoOrderId(): ?string
    {
        return $this->helloassoOrderId;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getAdminNote(): ?string
    {
        return $this->adminNote;
    }

    public function getReminder30SentAt(): ?\DateTimeImmutable
    {
        return $this->reminder30SentAt;
    }

    public function getReminder7SentAt(): ?\DateTimeImmutable
    {
        return $this->reminder7SentAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
