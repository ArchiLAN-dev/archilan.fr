<?php

declare(strict_types=1);

namespace App\SessionConfig\Application;

use App\SessionConfig\Domain\SessionConfig;
use App\SessionConfig\Domain\SessionConfigOverrideRepositoryInterface;
use App\SessionConfig\Domain\SessionConfigProfileRepositoryInterface;
use App\SessionConfig\Domain\SessionType;

/**
 * Resolves the effective config for a session: the type profile merged with a per-scope override
 * (template id for weekly, session id for event, run id for private — the admin/owner-controlled
 * stable key). Used by the launch/generation paths (story 27.5). Scope-keyed overrides are stable,
 * so re-resolving at restart is deterministic without snapshotting.
 */
final readonly class SessionConfigResolver
{
    public function __construct(
        private SessionConfigProfileRepositoryInterface $profiles,
        private SessionConfigOverrideRepositoryInterface $overrides,
    ) {
    }

    public function resolve(SessionType $type, ?string $scopeKey = null): SessionConfig
    {
        $profile = $this->profiles->get($type);

        if (null === $scopeKey) {
            return $profile;
        }

        $override = $this->overrides->find($scopeKey);
        if (null === $override || $override->isEmpty()) {
            return $profile;
        }

        return $profile->withOverride($override);
    }
}
