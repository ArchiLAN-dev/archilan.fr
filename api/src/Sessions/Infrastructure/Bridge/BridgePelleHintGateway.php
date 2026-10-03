<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Bridge;

use App\Sessions\Application\Port\PelleHintGatewayInterface;
use App\Shared\Application\Support\BridgeEndpoint;
use Archilan\BridgeClientBundle\Bridge\BridgeClientPool;

/**
 * The bridge's free hint (its admin `/hint` command), used once pelles are paid (story 41.3).
 */
final readonly class BridgePelleHintGateway implements PelleHintGatewayInterface
{
    public function __construct(private BridgeClientPool $bridgeClientPool)
    {
    }

    public function hintItem(string $sessionId, int $slotIndex, string $itemName): void
    {
        $this->bridgeClientPool->get($sessionId, BridgeEndpoint::baseUrl($sessionId))->slots()->requestHintItem($slotIndex, $itemName, true);
    }

    public function hintLocation(string $sessionId, int $slotIndex, int $locationId): void
    {
        $this->bridgeClientPool->get($sessionId, BridgeEndpoint::baseUrl($sessionId))->slots()->requestHint($slotIndex, $locationId, true);
    }
}
