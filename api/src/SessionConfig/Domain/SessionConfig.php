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
     * @return array{server: array<string, scalar|null>, generation: array{plandoOptions: list<string>, race: bool, spoiler: int}}
     */
    public function toArray(): array
    {
        $server = $this->server->toServerFlags();
        // toServerFlags omits an empty join password; the canonical config form always
        // carries the key (nullable) so the admin form round-trips it.
        $server['joinPassword'] = $this->server->joinPassword;

        return [
            'server' => $server,
            'generation' => $this->generation->toGenerationParams(),
        ];
    }

    /**
     * Builds a config from its canonical array form (admin API body / JSON storage).
     * Throws \DomainException('invalid_*') on any malformed field.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $server = self::section($data, 'server');
        $generation = self::section($data, 'generation');

        $plandoRaw = $generation['plandoOptions'] ?? [];
        if (!is_array($plandoRaw)) {
            throw new \DomainException('invalid_plando_option');
        }
        $plando = [];
        foreach ($plandoRaw as $p) {
            $plando[] = PlandoOption::fromString(self::asString($p, 'invalid_plando_option'));
        }

        return new self(
            new SessionServerConfig(
                releaseMode: ReleaseCollectMode::fromString(self::reqString($server, 'releaseMode')),
                collectMode: ReleaseCollectMode::fromString(self::reqString($server, 'collectMode')),
                remainingMode: RemainingMode::fromString(self::reqString($server, 'remainingMode')),
                disableItemCheat: self::reqBool($server, 'disableItemCheat'),
                hintCost: self::reqInt($server, 'hintCost'),
                locationCheckPoints: self::reqInt($server, 'locationCheckPoints'),
                countdownMode: CountdownMode::fromString(self::reqString($server, 'countdownMode')),
                autoShutdown: self::reqInt($server, 'autoShutdown'),
                compatibility: Compatibility::fromInt(self::reqInt($server, 'compatibility')),
                joinPassword: self::optString($server, 'joinPassword'),
            ),
            new SessionGenerationConfig(
                $plando,
                self::reqBool($generation, 'race'),
                SpoilerLevel::fromInt(self::reqInt($generation, 'spoiler')),
            ),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function section(array $data, string $key): array
    {
        $section = $data[$key] ?? null;
        if (!is_array($section)) {
            throw new \DomainException('invalid_session_config');
        }

        return $section;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function reqString(array $data, string $key): string
    {
        return self::asString($data[$key] ?? null, 'invalid_session_config');
    }

    private static function asString(mixed $value, string $error): string
    {
        if (!is_string($value)) {
            throw new \DomainException($error);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function reqInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new \DomainException('invalid_session_config');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function reqBool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new \DomainException('invalid_session_config');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function optString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new \DomainException('invalid_session_config');
        }

        return '' === $value ? null : $value;
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
