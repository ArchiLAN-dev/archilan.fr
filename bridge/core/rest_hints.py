from __future__ import annotations

import logging

from fastapi import APIRouter, Depends, HTTPException

from .ap_client import ArchipelagoClient
from .deps import get_ap_client, get_bridge_state
from .domain import HintInfo
from .schemas import HintItemResponse, HintOkResponse, HintRequest, HintStatusUpdateRequest, HintsResponse
from .state import StateManager

log = logging.getLogger("bridge.rest_hints")

router = APIRouter(tags=["Hints"])


@router.get("/slots/{slot}/hints", response_model=HintsResponse)
@router.get("/hints/{slot}", response_model=HintsResponse, include_in_schema=False)
async def get_hints(
    slot: int,
    state: StateManager = Depends(get_bridge_state),
    ap_client: ArchipelagoClient = Depends(get_ap_client),
) -> HintsResponse:
    state.merge_state_from_save()
    ap_client.resolve_slot_hint_names(slot)
    ps = state._states.get(slot)
    hints = state.get_hints(slot)
    return HintsResponse(
        slot=slot,
        hints=[HintItemResponse.from_hint(h) for h in hints],
        hintsUsed=ps.hints_used if ps else 0,
        hintPointsAvailable=ps.hint_points_available if ps else 0,
        hintCost=ps.hint_cost if ps else 10,
    )


@router.post("/slots/{slot}/hints/request", response_model=HintOkResponse)
@router.post("/hints/{slot}/request", response_model=HintOkResponse, include_in_schema=False)
async def request_hint(
    slot: int,
    body: HintRequest,
    state: StateManager = Depends(get_bridge_state),
    ap_client: ArchipelagoClient = Depends(get_ap_client),
) -> HintOkResponse:
    """Request a hint for a location in slot's world.

    Uses '!admin hint_location <player> <location>' via WS chat so the bridge
    (TextOnly observer, 0 locations) can hint any player's location without
    needing to own it.  The AP server generates a Hint PrintJSON which the
    bridge tracks automatically via _track_hint.
    """
    if not ap_client.ws_connected:
        raise HTTPException(status_code=503, detail="ws_disconnected")

    # Resolve location ID → name using the DataPackage store
    game = ap_client._store._slot_games.get(slot, "")
    location_name = ap_client._store._location_names.get(game, {}).get(body.locationId)
    if not location_name:
        raise HTTPException(
            status_code=422,
            detail=f"unknown location {body.locationId} for slot {slot} (game={game!r})",
        )

    player_name = ap_client._store.resolve_player(slot)

    # AP TextOnly observers don't receive Hint PrintJSON packets (AP only sends
    # them to the finding/receiving player slots). We build the hint locally from
    # the spoiler mapping loaded at startup, then send the admin command so real
    # game clients also see the hint on the AP server.
    placement = ap_client.get_placement(slot, body.locationId)

    try:
        await ap_client.send_admin_command(f"!admin /hint_location {player_name} {location_name}")
    except RuntimeError as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc

    if placement is not None:
        item_id, receiver_slot = placement
        hint = HintInfo(
            receiving_player=receiver_slot,
            finding_player=slot,
            location_id=body.locationId,
            item_id=item_id,
            entrance="",
            item_flags=0,
            status=0,
            receiving_player_name=ap_client._store.resolve_player(receiver_slot),
            finding_player_name=ap_client._store.resolve_player(slot),
            item_name=ap_client._store.resolve_item(item_id, receiver_slot),
            location_name=location_name,
        )
        changed = state.add_hint(receiver_slot, hint)
        if changed:
            await ap_client._broadcast_hints(receiver_slot)
    else:
        log.warning("hint: no spoiler placement for slot=%d locationId=%d", slot, body.locationId)

    if not body.free:
        ps = state.ensure_slot(slot)
        ps.hints_used = max(0, ps.hints_used + 1)
        await ap_client._broadcast_hints(slot)

    log.info("hint requested: slot=%d locationId=%d location=%r free=%s placement=%s",
             slot, body.locationId, location_name, body.free, placement)
    return HintOkResponse(ok=True, slot=slot, locationId=body.locationId, free=body.free)


@router.patch("/slots/{slot}/hints/{location_id}", response_model=HintOkResponse)
async def update_hint_status(
    slot: int,
    location_id: int,
    body: HintStatusUpdateRequest,
    state: StateManager = Depends(get_bridge_state),
    ap_client: ArchipelagoClient = Depends(get_ap_client),
) -> HintOkResponse:
    """Update the priority/status of an existing hint on the AP server."""
    if not ap_client.ws_connected:
        raise HTTPException(status_code=503, detail="ws_disconnected")

    hints = state.get_hints(slot)
    hint = next((h for h in hints if h.location_id == location_id), None)
    if hint is None:
        raise HTTPException(
            status_code=404,
            detail=f"no hint for slot={slot} location_id={location_id}",
        )

    updated = HintInfo(
        receiving_player=hint.receiving_player,
        finding_player=hint.finding_player,
        location_id=hint.location_id,
        item_id=hint.item_id,
        entrance=hint.entrance,
        item_flags=hint.item_flags,
        status=body.status,
        receiving_player_name=hint.receiving_player_name,
        finding_player_name=hint.finding_player_name,
        item_name=hint.item_name,
        location_name=hint.location_name,
    )
    state.add_hint(slot, updated)

    await ap_client.send_packet({
        "cmd": "UpdateHint",
        "player": slot,
        "location": location_id,
        "status": body.status,
    })
    await ap_client._broadcast_hints(slot)

    log.info("hint status updated: slot=%d locationId=%d status=%d", slot, location_id, body.status)
    return HintOkResponse(ok=True, slot=slot, locationId=location_id, free=False)
