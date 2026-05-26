<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Slots\Response;

final readonly class CheckLocation
{
    public function __construct(
        public int $locationId,
        public string $locationName,
        public bool $checked,
        public ?CheckItem $item,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $item = null;
        if (is_array($data['item'] ?? null)) {
            /** @var array<string, mixed> $itemData */
            $itemData = $data['item'];
            $item = CheckItem::fromArray($itemData);
        }

        return new self(
            locationId:   is_int($data['locationId'] ?? null) ? $data['locationId'] : 0,
            locationName: is_string($data['locationName'] ?? null) ? $data['locationName'] : '',
            checked:      (bool) ($data['checked'] ?? false),
            item:         $item,
        );
    }
}
