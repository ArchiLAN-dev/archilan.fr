<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Slots;

use Archilan\BridgeClient\Enum\HintStatus;
use Archilan\BridgeClient\Http\HttpTransport;
use Archilan\BridgeClient\Slots\Response\HintOkResponse;
use Archilan\BridgeClient\Slots\Response\HintsResponse;
use Archilan\BridgeClient\Slots\Response\ItemLocationsResponse;
use Archilan\BridgeClient\Slots\Response\ReachableResponse;
use Archilan\BridgeClient\Slots\Response\SlotChecksResponse;
use Archilan\BridgeClient\Slots\Response\SlotDetail;
use Archilan\BridgeClient\Slots\Response\SlotItemsResponse;
use Archilan\BridgeClient\Slots\Response\SlotSummary;

final class SlotsClient
{
    public function __construct(private readonly HttpTransport $transport)
    {
    }

    /**
     * @return SlotSummary[]
     */
    public function list(): array
    {
        $data = $this->transport->getJson('/slots');
        $slots = [];
        foreach (is_array($data['slots'] ?? null) ? $data['slots'] : [] as $slot) {
            if (is_array($slot)) {
                /** @var array<string, mixed> $slot */
                $slots[] = SlotSummary::fromArray($slot);
            }
        }

        return $slots;
    }

    public function get(int $slot): SlotDetail
    {
        return SlotDetail::fromArray($this->transport->getJson('/slots/'.$slot));
    }

    public function checks(int $slot): SlotChecksResponse
    {
        return SlotChecksResponse::fromArray($this->transport->getJson('/slots/'.$slot.'/checks'));
    }

    public function items(int $slot): SlotItemsResponse
    {
        return SlotItemsResponse::fromArray($this->transport->getJson('/slots/'.$slot.'/items'));
    }

    public function hints(int $slot): HintsResponse
    {
        return HintsResponse::fromArray($this->transport->getJson('/slots/'.$slot.'/hints'));
    }

    public function requestHint(int $slot, int $locationId, bool $free = false): HintOkResponse
    {
        return HintOkResponse::fromArray(
            $this->transport->postJson('/slots/'.$slot.'/hints/request', [
                'locationId' => $locationId,
                'free'       => $free,
            ])
        );
    }

    public function updateHint(int $slot, int $locationId, HintStatus $status): HintOkResponse
    {
        return HintOkResponse::fromArray(
            $this->transport->patchJson('/slots/'.$slot.'/hints/'.$locationId, [
                'status' => $status->value,
            ])
        );
    }

    public function reachable(int $slot): ReachableResponse
    {
        return ReachableResponse::fromArray($this->transport->getJson('/slots/'.$slot.'/reachable'));
    }

    public function itemLocations(int $slot): ItemLocationsResponse
    {
        return ItemLocationsResponse::fromArray($this->transport->getJson('/slots/'.$slot.'/item-locations'));
    }
}
