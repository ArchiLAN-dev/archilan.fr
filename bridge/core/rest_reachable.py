from __future__ import annotations

import logging

from aiohttp import web

from .ap_client import ArchipelagoClient
from .reachable import _compute_reachable, _reachable_cache
from .rest_keys import APP_AP_CLIENT, APP_SEMAPHORE, APP_STATE
from .state import StateManager

log = logging.getLogger("bridge.rest_reachable")


async def get_reachable(request: web.Request) -> web.Response:
    try:
        slot = int(request.match_info["slot"])
    except (KeyError, ValueError):
        return web.json_response({"error": "invalid slot"}, status=400)

    state: StateManager = request.app[APP_STATE]
    ap_client: ArchipelagoClient = request.app[APP_AP_CLIENT]
    semaphore = request.app[APP_SEMAPHORE]

    state.merge_state_from_save()
    result, err_msg = await _compute_reachable(slot, state, semaphore, log)

    if result is None:
        return web.json_response(
            {"error": err_msg},
            status=500 if "timed out" not in err_msg else 504,
        )

    ps = state._states.get(slot)
    if ps is not None:
        # Bridge slot_name (from AP Connected packet) is authoritative over the
        # reachability subprocess name (which may be the YAML file name, not the player alias).
        if ps.slot_name:
            result["player"] = ps.slot_name
        new_reachable = result.get("counts", {}).get("reachable_now", 0)
        if ps.reachable_now != new_reachable:
            ps.reachable_now = new_reachable
            await ap_client._publish_players()

    return web.json_response(result)


async def get_item_locations(request: web.Request) -> web.Response:
    try:
        slot = int(request.match_info["slot"])
    except (KeyError, ValueError):
        return web.json_response({"error": "invalid slot"}, status=400)

    state: StateManager = request.app[APP_STATE]
    ap_client: ArchipelagoClient = request.app[APP_AP_CLIENT]
    semaphore = request.app[APP_SEMAPHORE]

    state.merge_state_from_save()

    # Ensure reachability is computed for every known slot so locations across
    # all games are visible, not just slots that previously called /reachable/{slot}.
    for s in list(state._states.keys()):
        if s not in _reachable_cache:
            await _compute_reachable(s, state, semaphore, log)

    _CHECK_STATUS: dict[str, str] = {
        "reachable_unchecked": "reachable",
        "reachable_checked":   "checked",
        "unreachable_unchecked": "blocked",
        "checked_unreachable": "checked",
    }

    locations = []
    for sender_slot, (_, result) in _reachable_cache.items():
        sender_name = ap_client._store.resolve_player(sender_slot)
        for list_name, check_status in _CHECK_STATUS.items():
            for check in result.get(list_name, []):
                item = check.get("item")
                if not item or item.get("slot") != slot:
                    continue
                locations.append({
                    "item_id": item["id"],
                    "item_name": item["name"],
                    "location_id": check["id"],
                    "location_name": check["name"],
                    "finding_player": sender_slot,
                    "finding_player_name": sender_name,
                    "check_status": check_status,
                })

    return web.json_response({"slot": slot, "locations": locations})
