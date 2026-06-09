<?php

declare(strict_types=1);

namespace App\SessionConfig\Application;

use App\SessionConfig\Domain\SessionConfig;
use App\SessionConfig\Domain\SessionConfigOverride;
use App\SessionConfig\Domain\SessionConfigOverrideRepositoryInterface;
use App\SessionConfig\Domain\SessionConfigProfileRepositoryInterface;
use App\SessionConfig\Domain\SessionType;

/**
 * Resolves the effective config for a session: the type profile merged with the session's
 * per-field override (if any). Used by the launch/generation paths (story 27.5).
 */
final readonly class SessionConfigResolver
{
    public function __construct(
        private SessionConfigProfileRepositoryInterface $profiles,
        private SessionConfigOverrideRepositoryInterface $overrides,
    ) {
    }

    public function resolve(SessionType $type, ?string $sessionId = null): SessionConfig
    {
        $profile = $this->profiles->get($type);

        if (null === $sessionId) {
            return $profile;
        }

        $override = $this->overrides->find($sessionId);
        if (null === $override || $override->isEmpty()) {
            return $profile;
        }

        return $profile->withOverride($override);
    }

    /**
     * Snapshots the effective config for a session so a later restart reuses the exact
     * values rather than re-resolving against a possibly-changed profile. Stored as a full
     * override keyed by the session id.
     */
    public function recordResolvedForSession(string $sessionId, SessionConfig $resolved): void
    {
        $this->overrides->save($sessionId, SessionConfigOverride::fromConfig($resolved));
    }
}
