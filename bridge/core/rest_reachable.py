from __future__ import annotations

import asyncio
import logging
from typing import Any

from fastapi import APIRouter, Depends, HTTPException

from .ap_client import ArchipelagoClient
from .deps import get_ap_client, get_bridge_state, get_runtime, get_semaphore
from .reachable import _compute_reachable, _reachable_cache
from .schemas import ItemLocationResponse, ItemLocationsResponse
from .state import StateManager

log = logging.getLogger("bridge.rest_reachable")

_CHECK_STATUS: dict[str, str] = {
    "reachable_unchecked":   "reachable",
    "reachable_checked":     "checked",
    "unreachable_unchecked": "blocked",
    "checked_unreachable":   "checked",
}

router = APIRouter(tags=["Reachable"])


@router.get("/slots/{slot}/reachable")
@router.get("/reachable/{slot}", include_in_schema=False)
async def get_reachable(
    slot: int,
    state: StateManager = Depends(get_bridge_state),
    ap_client: ArchipelagoClient = Depends(get_ap_client),
    semaphore: asyncio.Semaphore = Depends(get_semaphore),
    runtime: Any = Depends(get_runtime),
) -> dict[str, Any]:
    state.merge_state_from_save()
    result, err_msg = await _compute_reachable(slot, state, semaphore, log, runtime)

    if result is None:
        status = 504 if "timed out" in err_msg else 500
        raise HTTPException(status_code=status, detail=err_msg)

    ps = state._states.get(slot)
    if ps is not None:
        # Bridge slot_name (from AP Connected packet) is authoritative over the
        # reachability subprocess name (which may be the YAML file name, not the player alias).
        if ps.slot_name:
            result["player"] = ps.slot_name
        new_reachable = result.get("counts", {}).get("reachable_now", 0)
        if ps.reachable_now != new_reachable:
            ps.reachable_now = new_reachable
            await ap_client._broadcast_state_changed()

    return result


@router.get("/slots/{slot}/item-locations", response_model=ItemLocationsResponse)
@router.get("/item-locations/{slot}", response_model=ItemLocationsResponse, include_in_schema=False)
async def get_item_locations(
    slot: int,
    state: StateManager = Depends(get_bridge_state),
    ap_client: ArchipelagoClient = Depends(get_ap_client),
    semaphore: asyncio.Semaphore = Depends(get_semaphore),
    runtime: Any = Depends(get_runtime),
) -> ItemLocationsResponse:
    state.merge_state_from_save()

    # Ensure reachability is computed for slots not yet cached.
    # Use a short timeout per slot to avoid blocking the whole request if Docker
    # is slow (e.g. after bridge restart). Uncached slots are skipped gracefully;
    # the sweep loop will warm the cache asynchronously.
    for s in list(state._states.keys()):
        if s not in _reachable_cache:
            try:
                await asyncio.wait_for(
                    _compute_reachable(s, state, semaphore, log, runtime),
                    timeout=8.0,
                )
            except asyncio.TimeoutError:
                log.warning("item-locations: reachability timeout for slot %d, skipping", s)
            except Exception as exc:
                log.warning("item-locations: reachability error for slot %d: %s", s, exc)

    locations: list[ItemLocationResponse] = []
    for sender_slot, (_, result) in _reachable_cache.items():
        sender_name = ap_client._store.resolve_player(sender_slot)
        for list_name, check_status in _CHECK_STATUS.items():
            for check in result.get(list_name, []):
                item = check.get("item")
                if not item or item.get("slot") != slot:
                    continue
                locations.append(ItemLocationResponse(
                    itemId=item["id"],
                    itemName=item["name"],
                    locationId=check["id"],
                    locationName=check["name"],
                    findingPlayer=sender_slot,
                    findingPlayerName=sender_name,
                    checkStatus=check_status,
                ))

    return ItemLocationsResponse(slot=slot, locations=locations)
