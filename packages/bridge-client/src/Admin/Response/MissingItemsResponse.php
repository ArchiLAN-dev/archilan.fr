<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Admin\Response;

final readonly class MissingItemsResponse
{
    /**
     * @param LocationPlacement[] $missing
     */
    public function __construct(
        public int $slot,
        public array $missing,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $missing = [];
        foreach (is_array($data['missing'] ?? null) ? $data['missing'] : [] as $p) {
            if (is_array($p)) {
                /** @var array<string, mixed> $p */
                $missing[] = LocationPlacement::fromArray($p);
            }
        }

        return new self(
            slot:    is_int($data['slot'] ?? null) ? $data['slot'] : 0,
            missing: $missing,
        );
    }
}
