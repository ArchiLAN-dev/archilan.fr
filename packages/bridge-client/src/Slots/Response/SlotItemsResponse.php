<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Slots\Response;

final readonly class SlotItemsResponse
{
    /**
     * @param SlotItem[] $items
     */
    public function __construct(
        public int $slot,
        public int $totalOwned,
        public int $receivedCount,
        public array $items,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $items = [];
        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $items[] = SlotItem::fromArray($item);
            }
        }

        return new self(
            slot:          is_int($data['slot'] ?? null) ? $data['slot'] : 0,
            totalOwned:    is_int($data['totalOwned'] ?? null) ? $data['totalOwned'] : 0,
            receivedCount: is_int($data['receivedCount'] ?? null) ? $data['receivedCount'] : 0,
            items:         $items,
        );
    }
}
