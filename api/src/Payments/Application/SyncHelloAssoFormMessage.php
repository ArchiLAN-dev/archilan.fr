<?php

declare(strict_types=1);

namespace App\Payments\Application;

final readonly class SyncHelloAssoFormMessage
{
    public function __construct(
        public string $formType,
        public string $formSlug,
    ) {
    }
}
