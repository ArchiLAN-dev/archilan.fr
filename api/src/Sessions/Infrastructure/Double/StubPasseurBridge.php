<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Double;

use App\Sessions\Application\Port\PasseurBridgeInterface;
use App\Sessions\Domain\ValueObject\PasseurItem;

/**
 * Test double of the bridge's Passeur routes (story 52.3): answers what the test set, null meaning the bridge
 * cannot say.
 */
final class StubPasseurBridge implements PasseurBridgeInterface
{
    /** @var list<PasseurItem>|null */
    public ?array $items = [];

    /** @var array<string, int>|null */
    public ?array $gains = null;

    /** @var list<list<PasseurItem>> */
    public array $measured = [];

    public function items(string $sessionId, int $slotIndex): ?array
    {
        return $this->items;
    }

    public function gains(string $sessionId, int $slotIndex, array $candidates): ?array
    {
        $this->measured[] = $candidates;

        return $this->gains;
    }
}
