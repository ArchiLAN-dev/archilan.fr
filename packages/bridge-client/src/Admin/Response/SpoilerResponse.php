<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Admin\Response;

final readonly class SpoilerResponse
{
    /**
     * @param LocationPlacement[] $placements
     */
    public function __construct(
        public array $placements,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $placements = [];
        foreach (is_array($data['placements'] ?? null) ? $data['placements'] : [] as $p) {
            if (is_array($p)) {
                /** @var array<string, mixed> $p */
                $placements[] = LocationPlacement::fromArray($p);
            }
        }

        return new self(placements: $placements);
    }
}
