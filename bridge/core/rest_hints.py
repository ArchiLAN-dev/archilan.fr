from __future__ import annotations

import logging

from fastapi import APIRouter, Depends, HTTPException

from .ap_client import ArchipelagoClient
from .deps import get_ap_client, get_bridge_state
from .schemas import HintItemResponse, HintOkResponse, HintRequest, HintsResponse
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
    if not ap_client.ws_connected:
        raise HTTPException(status_code=503, detail="ws_disconnected")

    # create_as_hint: 2 = admin scout (no point cost); 1 = normal (costs points)
    create_as_hint = 2 if body.free else 1
    try:
        await ap_client.send_packet({
            "cmd": "LocationScouts",
            "locations": [body.locationId],
            "create_as_hint": create_as_hint,
        })
    except RuntimeError as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc

    if not body.free:
        ps = state.ensure_slot(slot)
        ps.hints_used = max(0, ps.hints_used + 1)
        await ap_client._broadcast_hints(slot)

    log.info("hint requested: slot=%d locationId=%d free=%s", slot, body.locationId, body.free)
    return HintOkResponse(ok=True, slot=slot, locationId=body.locationId, free=body.free)
