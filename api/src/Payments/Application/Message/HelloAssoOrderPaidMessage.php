<?php

declare(strict_types=1);

namespace App\Payments\Application\Message;

final readonly class HelloAssoOrderPaidMessage
{
    public function __construct(
        public string $helloassoOrderId,
        public string $formSlug,
        public ?string $payerEmail,
        public ?\DateTimeImmutable $paidAt,
    ) {
    }
}
