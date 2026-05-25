from __future__ import annotations

import logging

from fastapi import APIRouter, Depends

from .ap_client import ArchipelagoClient
from .deps import get_ap_client, get_bridge_state, is_authorized, require_auth
from .schemas import (
    CheckItemContent,
    CheckLocation,
    ChecksResponse,
    LocationPlacementResponse,
    MissingItemsResponse,
    SlotItem,
    SlotItemFoundAt,
    SlotItemsResponse,
    SlotSpoilerResponse,
)
from .state import StateManager

log = logging.getLogger("bridge.rest_checks")

router = APIRouter(tags=["Checks & Items"])


@router.get("/slots/{slot}/checks", response_model=ChecksResponse)
async def get_slot_checks(
    slot: int,
    state: StateManager = Depends(get_bridge_state),
    ap_client: ArchipelagoClient = Depends(get_ap_client),
    admin: bool = Depends(is_authorized),
) -> ChecksResponse:
    """List all locations in a slot's world with their check status.

    With a valid Authorization bearer token, each location also includes the
    item it contains and which player it belongs to (spoiler data).
    """
    game = ap_client._store._slot_games.get(slot, "")
    location_names = ap_client._store._location_names.get(game, {})
    ps = state._states.get(slot)
    checked_locations: set[int] = ps._checked_locations if ps else set()

    locations: list[CheckLocation] = []
    for loc_id, loc_name in sorted(location_names.items()):
        checked = loc_id in checked_locations
        item_content: CheckItemContent | None = None

        if admin:
            placement = ap_client.get_placement(slot, loc_id)
            if placement is not None:
                item_id, receiver_slot = placement
                item_content = CheckItemContent(
                    id=item_id,
                    name=ap_client._store.resolve_item(item_id, receiver_slot),
                    flags=ap_client._store.resolve_item_flags(item_id),
                    receivingSlot=receiver_slot,
                    receivingPlayerName=ap_client._store.resolve_player(receiver_slot),
                )

        locations.append(CheckLocation(
            locationId=loc_id,
            locationName=loc_name,
            checked=checked,
            item=item_content,
        ))

    checked_count = sum(1 for loc in locations if loc.checked)
    return ChecksResponse(
        slot=slot,
        total=len(locations),
        checkedCount=checked_count,
        locations=locations,
    )


@router.get("/slots/{slot}/items", response_model=SlotItemsResponse)
async def get_slot_items(
    slot: int,
    state: StateManager = Depends(get_bridge_state),
    ap_client: ArchipelagoClient = Depends(get_ap_client),
    admin: bool = Depends(is_authorized),
) -> SlotItemsResponse:
    """List items belonging to a slot.

    Without a bearer token: only items already received by the slot.
    With a valid bearer token: all items this slot will receive (spoiler),
    each with the location where it can be found across all worlds.
    """
    ps = state._states.get(slot)
    received_set: set[tuple[int, int, int]] = set(ps._received_items) if ps else set()

    if admin:
        # Collect all placements where this slot is the receiver, across all worlds
        items: list[SlotItem] = []
        for finding_slot, loc_map in ap_client._placements.items():
            finding_ps = state._states.get(finding_slot)
            finding_checked = finding_ps._checked_locations if finding_ps else set()

            for location_id, (item_id, receiver_slot) in loc_map.items():
                if receiver_slot != slot:
                    continue

                received = (item_id, finding_slot, location_id) in received_set
                found_at = SlotItemFoundAt(
                    findingSlot=finding_slot,
                    findingPlayerName=ap_client._store.resolve_player(finding_slot),
                    locationId=location_id,
                    locationName=ap_client._store.resolve_location(location_id, finding_slot),
                    checked=location_id in finding_checked,
                )
                items.append(SlotItem(
                    id=item_id,
                    name=ap_client._store.resolve_item(item_id, slot),
                    flags=ap_client._store.resolve_item_flags(item_id),
                    received=received,
                    foundAt=found_at,
                ))

        items.sort(key=lambda i: (not i.received, i.name))
        received_count = sum(1 for i in items if i.received)
        return SlotItemsResponse(
            slot=slot,
            totalOwned=len(items),
            receivedCount=received_count,
            items=items,
        )

    # Public view: only received items, no location spoiler
    received_items: list[SlotItem] = []
    for item_id, sender_slot, location_id in (ps._received_items if ps else []):
        received_items.append(SlotItem(
            id=item_id,
            name=ap_client._store.resolve_item(item_id, slot),
            flags=ap_client._store.resolve_item_flags(item_id),
            received=True,
        ))

    return SlotItemsResponse(
        slot=slot,
        totalOwned=len(received_items),
        receivedCount=len(received_items),
        items=received_items,
    )


@router.get("/slots/{slot}/items/missing", response_model=MissingItemsResponse, dependencies=[Depends(require_auth)])
async def get_slot_missing_items(
    slot: int,
    state: StateManager = Depends(get_bridge_state),
    ap_client: ArchipelagoClient = Depends(get_ap_client),
) -> MissingItemsResponse:
    """List items that belong to this slot but have not yet been received."""
    ps = state._states.get(slot)
    received_set: set[tuple[int, int, int]] = set(ps._received_items) if ps else set()

    missing: list[LocationPlacementResponse] = []
    for finding_slot, loc_map in ap_client._placements.items():
        for location_id, (item_id, receiver_slot) in loc_map.items():
            if receiver_slot != slot:
                continue
            if (item_id, finding_slot, location_id) in received_set:
                continue
            missing.append(LocationPlacementResponse(
                locationId=location_id,
                locationName=ap_client._store.resolve_location(location_id, finding_slot),
                itemId=item_id,
                itemName=ap_client._store.resolve_item(item_id, slot),
                receivingSlot=slot,
                receivingPlayerName=ap_client._store.resolve_player(slot),
            ))

    missing.sort(key=lambda p: p.locationName)
    return MissingItemsResponse(slot=slot, missing=missing)


@router.get("/slots/{slot}/spoiler", response_model=SlotSpoilerResponse, dependencies=[Depends(require_auth)])
async def get_slot_spoiler(
    slot: int,
    ap_client: ArchipelagoClient = Depends(get_ap_client),
) -> SlotSpoilerResponse:
    """List all item placements in this slot's world (what items the slot's locations send)."""
    loc_map = ap_client._placements.get(slot, {})

    placements: list[LocationPlacementResponse] = []
    for location_id, (item_id, receiver_slot) in loc_map.items():
        placements.append(LocationPlacementResponse(
            locationId=location_id,
            locationName=ap_client._store.resolve_location(location_id, slot),
            itemId=item_id,
            itemName=ap_client._store.resolve_item(item_id, receiver_slot),
            receivingSlot=receiver_slot,
            receivingPlayerName=ap_client._store.resolve_player(receiver_slot),
        ))

    placements.sort(key=lambda p: p.locationName)
    return SlotSpoilerResponse(placements=placements)
