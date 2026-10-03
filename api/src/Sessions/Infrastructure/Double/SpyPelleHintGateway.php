<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Double;

use App\Sessions\Application\Port\PelleHintGatewayInterface;

/**
 * Test double of the bridge's free hint (story 41.3): records the hints asked, and fails on demand to exercise
 * the refund.
 */
final class SpyPelleHintGateway implements PelleHintGatewayInterface
{
    /** @var list<string> */
    public array $hints = [];

    public bool $failNext = false;

    public function hintItem(string $sessionId, int $slotIndex, string $itemName): void
    {
        $this->answer(sprintf('%s/%d/item:%s', $sessionId, $slotIndex, $itemName));
    }

    public function hintLocation(string $sessionId, int $slotIndex, int $locationId): void
    {
        $this->answer(sprintf('%s/%d/location:%d', $sessionId, $slotIndex, $locationId));
    }

    private function answer(string $hint): void
    {
        if ($this->failNext) {
            $this->failNext = false;

            throw new \RuntimeException('bridge said no');
        }
        $this->hints[] = $hint;
    }
}
