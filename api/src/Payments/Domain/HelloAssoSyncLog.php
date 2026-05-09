<?php

declare(strict_types=1);

namespace App\Payments\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'payments_helloasso_sync_logs')]
final class HelloAssoSyncLog
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'form_slug', type: 'string', length: 120)]
        private string $formSlug,
        #[ORM\Column(name: 'attempt_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $attemptAt,
        #[ORM\Column(type: 'boolean')]
        private bool $success,
        #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
        private ?string $errorMessage,
    ) {
    }

    public static function fromSuccess(string $formSlug, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $formSlug, $now, true, null);
    }

    public static function fromFailure(string $formSlug, string $errorMessage, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $formSlug, $now, false, $errorMessage);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getFormSlug(): string
    {
        return $this->formSlug;
    }

    public function getAttemptAt(): \DateTimeImmutable
    {
        return $this->attemptAt;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
