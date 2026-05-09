<?php

declare(strict_types=1);

namespace App\Payments\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'payments_helloasso_orders')]
final class HelloAssoOrder
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'helloasso_order_id', type: 'integer', unique: true)]
        private int $helloassoOrderId,
        #[ORM\Column(name: 'form_type', type: 'string', length: 60)]
        private string $formType,
        #[ORM\Column(name: 'form_slug', type: 'string', length: 120)]
        private string $formSlug,
        #[ORM\Column(type: 'string', length: 40)]
        private string $status,
        #[ORM\Column(name: 'amount_cents', type: 'integer')]
        private int $amountCents,
        #[ORM\Column(name: 'payer_email', type: 'string', length: 200, nullable: true)]
        private ?string $payerEmail,
        #[ORM\Column(name: 'payer_first_name', type: 'string', length: 100, nullable: true)]
        private ?string $payerFirstName,
        #[ORM\Column(name: 'payer_last_name', type: 'string', length: 100, nullable: true)]
        private ?string $payerLastName,
        #[ORM\Column(name: 'paid_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $paidAt,
        #[ORM\Column(name: 'synced_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $syncedAt,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromHelloAsso(
        int $helloassoOrderId,
        string $formType,
        string $formSlug,
        string $status,
        int $amountCents,
        ?string $payerEmail,
        ?string $payerFirstName,
        ?string $payerLastName,
        ?\DateTimeImmutable $paidAt,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            bin2hex(random_bytes(16)),
            $helloassoOrderId,
            $formType,
            $formSlug,
            $status,
            $amountCents,
            $payerEmail,
            $payerFirstName,
            $payerLastName,
            $paidAt,
            $now,
            $now,
            $now,
        );
    }

    public function updateFromSync(
        string $status,
        int $amountCents,
        ?string $payerEmail,
        ?string $payerFirstName,
        ?string $payerLastName,
        ?\DateTimeImmutable $paidAt,
        \DateTimeImmutable $now,
    ): void {
        $this->status = $status;
        $this->amountCents = $amountCents;
        $this->payerEmail = $payerEmail;
        $this->payerFirstName = $payerFirstName;
        $this->payerLastName = $payerLastName;
        $this->paidAt = $paidAt;
        $this->syncedAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getHelloassoOrderId(): int
    {
        return $this->helloassoOrderId;
    }

    public function getFormType(): string
    {
        return $this->formType;
    }

    public function getFormSlug(): string
    {
        return $this->formSlug;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function getPayerEmail(): ?string
    {
        return $this->payerEmail;
    }

    public function getPayerFirstName(): ?string
    {
        return $this->payerFirstName;
    }

    public function getPayerLastName(): ?string
    {
        return $this->payerLastName;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getSyncedAt(): \DateTimeImmutable
    {
        return $this->syncedAt;
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
