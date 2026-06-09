<?php

declare(strict_types=1);

namespace App\SessionConfig\Application;

use App\SessionConfig\Domain\SessionConfigOverride;
use App\SessionConfig\Domain\SessionConfigOverrideRepositoryInterface;

final readonly class SetSessionConfigOverride
{
    public function __construct(
        private SessionConfigOverrideRepositoryInterface $overrides,
    ) {
    }

    /**
     * Validates a partial override (throws \DomainException('invalid_*') on a bad field) and stores
     * it for the scope. An empty override clears the scope (back to the type profile).
     *
     * @param array<array-key, mixed> $override
     */
    public function execute(string $scopeKey, array $override): void
    {
        $value = SessionConfigOverride::fromArray($override);

        if ($value->isEmpty()) {
            $this->overrides->delete($scopeKey);

            return;
        }

        $this->overrides->save($scopeKey, $value);
    }
}
