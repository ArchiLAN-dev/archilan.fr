<?php

declare(strict_types=1);

namespace App\Sessions\Application\Port;

/**
 * Gives a hint for nothing in Archipelago points, once the player has paid in pelles (story 41.3).
 *
 * The bridge already does it: its admin `/hint` path hints a slot without touching its hint points. Throws on
 * any failure (bridge unreachable, unknown item, location already found), so the caller can refund.
 */
interface PelleHintGatewayInterface
{
    public function hintItem(string $sessionId, int $slotIndex, string $itemName): void;

    public function hintLocation(string $sessionId, int $slotIndex, int $locationId): void;
}
