from __future__ import annotations

import logging

from aiohttp import web

from .ap_client import ArchipelagoClient
from .rest_keys import APP_AP_CLIENT, APP_STATE
from .state import StateManager

log = logging.getLogger("bridge.rest_hints")


async def get_hints(request: web.Request) -> web.Response:
    try:
        slot = int(request.match_info["slot"])
    except (KeyError, ValueError):
        return web.json_response({"error": "invalid slot"}, status=400)

    state: StateManager = request.app[APP_STATE]
    ap_client: ArchipelagoClient = request.app[APP_AP_CLIENT]

    state.merge_state_from_save()
    ap_client.resolve_slot_hint_names(slot)
    ps = state._states.get(slot)
    hints = state.get_hints(slot)
    return web.json_response({
        "slot": slot,
        "hints": [h.to_dict() for h in hints],
        "hints_used": ps.hints_used if ps else 0,
        "hint_points_available": ps.hint_points_available if ps else 0,
        "hint_cost": ps.hint_cost if ps else 10,
    })


async def request_hint(request: web.Request) -> web.Response:
    try:
        slot = int(request.match_info["slot"])
    except (KeyError, ValueError):
        return web.json_response({"error": "invalid slot"}, status=400)

    try:
        body = await request.json()
    except Exception:
        return web.json_response({"error": "invalid_json"}, status=400)

    location_id = body.get("location_id")
    if not isinstance(location_id, int) or location_id <= 0:
        return web.json_response({"error": "location_id (int > 0) is required"}, status=400)

    free = bool(body.get("free", False))

    state: StateManager = request.app[APP_STATE]
    ap_client: ArchipelagoClient = request.app[APP_AP_CLIENT]

    if not ap_client.ws_connected:
        return web.json_response(
            {"error": "ws_disconnected", "message": "Le serveur Archipelago est déconnecté"},
            status=503,
        )

    # create_as_hint: 2 = admin scout (no point cost); 1 = normal (costs points from bridge slot)
    create_as_hint = 2 if free else 1
    try:
        await ap_client.send_packet({
            "cmd": "LocationScouts",
            "locations": [location_id],
            "create_as_hint": create_as_hint,
        })
    except RuntimeError as exc:
        return web.json_response({"error": str(exc)}, status=503)

    # Paid hint: optimistically increment budget counter before AP confirms via PrintJSON.
    # Free hints must not touch hints_used - the apsave won't reflect a cost for them.
    if not free:
        ps = state.ensure_slot(slot)
        ps.hints_used = max(0, ps.hints_used + 1)
        await ap_client._publish_hints(slot)

    log.info("hint requested: slot=%d location_id=%d free=%s", slot, location_id, free)
    return web.json_response({"ok": True, "slot": slot, "location_id": location_id, "free": free})
