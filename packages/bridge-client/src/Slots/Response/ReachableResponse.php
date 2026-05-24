<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Slots\Response;

final readonly class ReachableResponse
{
    /**
     * @param ReachableLocation[] $reachableUnchecked
     * @param ReachableLocation[] $reachableChecked
     * @param ReachableLocation[] $unreachableUnchecked
     */
    public function __construct(
        public int $slot,
        public string $player,
        public array $reachableUnchecked,
        public array $reachableChecked,
        public array $unreachableUnchecked,
        public bool $cached,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            slot:                 is_int($data['slot'] ?? null) ? $data['slot'] : 0,
            player:               is_string($data['player'] ?? null) ? $data['player'] : '',
            reachableUnchecked:   self::parseLocations($data['reachableUnchecked'] ?? null),
            reachableChecked:     self::parseLocations($data['reachableChecked'] ?? null),
            unreachableUnchecked: self::parseLocations($data['unreachableUnchecked'] ?? null),
            cached:               (bool) ($data['cached'] ?? false),
        );
    }

    /**
     * @param mixed $raw
     * @return ReachableLocation[]
     */
    private static function parseLocations(mixed $raw): array
    {
        $locations = [];
        foreach (is_array($raw) ? $raw : [] as $loc) {
            if (is_array($loc)) {
                /** @var array<string, mixed> $loc */
                $locations[] = ReachableLocation::fromArray($loc);
            }
        }

        return $locations;
    }
}
