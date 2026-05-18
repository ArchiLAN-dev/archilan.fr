<?php

declare(strict_types=1);

namespace App\Payments\Presentation;

final readonly class HelloAssoWebhookPayerData
{
    public function __construct(
        public string $email = '',
        public string $firstName = '',
        public string $lastName = '',
    ) {
    }
}
