<?php

declare(strict_types=1);

namespace App\SessionConfig\Application\Command;

final readonly class SessionConfigResult
{
    /**
     * @param array<string, mixed> $config the saved profile's canonical array (owned by the SessionConfig VO)
     */
    public function __construct(
        public string $type,
        public array $config,
    ) {
    }
}
