<?php

declare(strict_types=1);

namespace App\SessionConfig\Domain;

/**
 * The Archipelago server_options applied to a launched session. Pure value object:
 * self-validating, immutable, no I/O. The join password is the player `password`
 * (never the admin `server_password`).
 */
final readonly class SessionServerConfig
{
    public function __construct(
        public ReleaseCollectMode $releaseMode,
        public ReleaseCollectMode $collectMode,
        public RemainingMode $remainingMode,
        public bool $disableItemCheat,
        public int $hintCost,
        public int $locationCheckPoints,
        public CountdownMode $countdownMode,
        public int $autoShutdown,
        public Compatibility $compatibility,
        public ?string $joinPassword = null,
    ) {
        if ($hintCost < 0 || $hintCost > 100) {
            throw new \DomainException('invalid_hint_cost');
        }
        if ($locationCheckPoints < 0) {
            throw new \DomainException('invalid_location_check_points');
        }
        if ($autoShutdown < 0) {
            throw new \DomainException('invalid_auto_shutdown');
        }
    }

    /**
     * Transport seam for the orchestrateur launch request. Keys match the orchestrateur
     * JSON/form field names; the orchestrateur maps them to ArchipelagoServer flags.
     *
     * @return array<string, scalar>
     */
    public function toServerFlags(): array
    {
        $flags = [
            'releaseMode' => $this->releaseMode->value,
            'collectMode' => $this->collectMode->value,
            'remainingMode' => $this->remainingMode->value,
            'countdownMode' => $this->countdownMode->value,
            'disableItemCheat' => $this->disableItemCheat,
            'hintCost' => $this->hintCost,
            'locationCheckPoints' => $this->locationCheckPoints,
            'autoShutdown' => $this->autoShutdown,
            'compatibility' => $this->compatibility->value,
        ];
        if (null !== $this->joinPassword && '' !== $this->joinPassword) {
            $flags['password'] = $this->joinPassword;
        }

        return $flags;
    }
}
