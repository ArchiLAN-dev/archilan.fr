<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Room\Response;

final readonly class RoomInfo
{
    public function __construct(
        public string $sessionId,
        public int $slotCount,
        public int $hintCostPercent,
        public int $locationCheckPoints,
        public string $forfeitMode,
        public string $releaseMode,
        public string $collectMode,
        public bool $deathLinkActive,
        public bool $raceMode,
        public bool $wsConnected,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            sessionId:           is_string($data['sessionId'] ?? null) ? $data['sessionId'] : '',
            slotCount:           is_int($data['slotCount'] ?? null) ? $data['slotCount'] : 0,
            hintCostPercent:     is_int($data['hintCostPercent'] ?? null) ? $data['hintCostPercent'] : 0,
            locationCheckPoints: is_int($data['locationCheckPoints'] ?? null) ? $data['locationCheckPoints'] : 0,
            forfeitMode:         is_string($data['forfeitMode'] ?? null) ? $data['forfeitMode'] : '',
            releaseMode:         is_string($data['releaseMode'] ?? null) ? $data['releaseMode'] : '',
            collectMode:         is_string($data['collectMode'] ?? null) ? $data['collectMode'] : '',
            deathLinkActive:     (bool) ($data['deathLinkActive'] ?? false),
            raceMode:            (bool) ($data['raceMode'] ?? false),
            wsConnected:         (bool) ($data['wsConnected'] ?? false),
        );
    }
}
