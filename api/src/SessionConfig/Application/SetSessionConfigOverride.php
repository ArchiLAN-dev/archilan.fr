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
        // autoShutdown is locked to the type profile and is never overridable per scope
        // (story 27.9). Strip it on every write path - admin and owner alike - so a stale or
        // misconfigured override can never disable a private run's idle shutdown.
        unset($override['autoShutdown']);

        $value = SessionConfigOverride::fromArray($override);

        if ($value->isEmpty()) {
            $this->overrides->delete($scopeKey);

            return;
        }

        $this->overrides->save($scopeKey, $value);
    }
}
