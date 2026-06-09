<?php

declare(strict_types=1);

namespace App\SessionConfig\Application;

use App\SessionConfig\Domain\SessionConfigOverrideRepositoryInterface;

final readonly class ClearSessionConfigOverride
{
    public function __construct(
        private SessionConfigOverrideRepositoryInterface $overrides,
    ) {
    }

    public function execute(string $scopeKey): void
    {
        $this->overrides->delete($scopeKey);
    }
}
