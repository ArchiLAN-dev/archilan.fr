<?php

declare(strict_types=1);

namespace App\Payments\Presentation;

final readonly class HelloAssoWebhookOrderData
{
    public function __construct(
        public int $id = 0,
        public string $formSlug = '',
        public string $formType = '',
        public HelloAssoWebhookPayerData $payer = new HelloAssoWebhookPayerData(),
        public string $date = '',
    ) {
    }
}
