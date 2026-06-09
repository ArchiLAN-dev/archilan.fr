<?php

declare(strict_types=1);

namespace App\SessionConfig\Domain;

/**
 * The full configuration applied to a session: server_options (launch-time) plus
 * generation options (generation-time). Immutable; merges a per-field override.
 */
final readonly class SessionConfig
{
    public function __construct(
        public SessionServerConfig $server,
        public SessionGenerationConfig $generation,
    ) {
    }

    /**
     * Default profile per session type. Weekly/event default to a competitive posture
     * (no auto-release/collect, item cheat disabled); private is lax. This table is the
     * single source of truth referenced by story 27.1.
     */
    public static function defaultsFor(SessionType $type): self
    {
        return match ($type) {
            SessionType::Private => new self(
                new SessionServerConfig(
                    releaseMode: ReleaseCollectMode::Goal,
                    collectMode: ReleaseCollectMode::Goal,
                    remainingMode: RemainingMode::Goal,
                    disableItemCheat: false,
                    hintCost: 10,
                    locationCheckPoints: 1,
                    countdownMode: CountdownMode::Auto,
                    autoShutdown: 0,
                    compatibility: Compatibility::Casual,
                ),
                new SessionGenerationConfig([], false, SpoilerLevel::WithPaths),
            ),
            SessionType::Event, SessionType::Weekly => new self(
                new SessionServerConfig(
                    releaseMode: ReleaseCollectMode::Disabled,
                    collectMode: ReleaseCollectMode::Disabled,
                    remainingMode: RemainingMode::Goal,
                    disableItemCheat: true,
                    hintCost: 10,
                    locationCheckPoints: 1,
                    countdownMode: CountdownMode::Auto,
                    autoShutdown: 0,
                    compatibility: Compatibility::Casual,
                ),
                new SessionGenerationConfig([], false, SpoilerLevel::WithPaths),
            ),
        };
    }

    /**
     * Returns a new config where each field present in the override replaces the
     * profile value (per-field merge); absent (null) fields keep the profile value.
     */
    public function withOverride(SessionConfigOverride $o): self
    {
        return new self(
            new SessionServerConfig(
                releaseMode: $o->releaseMode ?? $this->server->releaseMode,
                collectMode: $o->collectMode ?? $this->server->collectMode,
                remainingMode: $o->remainingMode ?? $this->server->remainingMode,
                disableItemCheat: $o->disableItemCheat ?? $this->server->disableItemCheat,
                hintCost: $o->hintCost ?? $this->server->hintCost,
                locationCheckPoints: $o->locationCheckPoints ?? $this->server->locationCheckPoints,
                countdownMode: $o->countdownMode ?? $this->server->countdownMode,
                autoShutdown: $o->autoShutdown ?? $this->server->autoShutdown,
                compatibility: $o->compatibility ?? $this->server->compatibility,
                joinPassword: $o->joinPassword ?? $this->server->joinPassword,
            ),
            new SessionGenerationConfig(
                plandoOptions: $o->plandoOptions ?? $this->generation->plandoOptions,
                race: $o->race ?? $this->generation->race,
                spoiler: $o->spoiler ?? $this->generation->spoiler,
            ),
        );
    }
}
