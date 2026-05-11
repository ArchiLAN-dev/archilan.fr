from __future__ import annotations

import asyncio
import json
import logging
from typing import Any

from aiohttp import web

from ap_client import ArchipelagoClient
from reachable import _compute_reachable, _reachable_cache
from state import StateManager


def create_app(
    state: StateManager,
    ap_client: ArchipelagoClient,
    reachable_semaphore: asyncio.Semaphore | None = None,
) -> web.Application:
    if reachable_semaphore is None:
        reachable_semaphore = asyncio.Semaphore(1)
    app = web.Application()
    log = logging.getLogger(__name__)

    async def health(_: web.Request) -> web.Response:
        return web.json_response({"status": "ok", "ws_connected": ap_client.ws_connected})

    async def get_state(_: web.Request) -> web.Response:
        state.merge_state_from_save()
        return web.json_response(state.to_api_dict())

    async def post_command(request: web.Request) -> web.Response:
        try:
            body = await request.json()
        except (json.JSONDecodeError, Exception):
            return web.json_response({"error": "invalid_json"}, status=400)
        command = body.get("command")
        if not isinstance(command, str) or not command.strip():
            return web.json_response({"error": "command is required"}, status=400)
        if not ap_client.ws_connected:
            return web.json_response(
                {"error": "ws_disconnected", "message": "Le serveur Archipelago est déconnecté"},
                status=503,
            )
        await ap_client.send_command(command)
        return web.json_response({"ok": True})

    async def get_hints(request: web.Request) -> web.Response:
        try:
            slot = int(request.match_info["slot"])
        except (KeyError, ValueError):
            return web.json_response({"error": "invalid slot"}, status=400)

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

    async def get_reachable(request: web.Request) -> web.Response:
        try:
            slot = int(request.match_info["slot"])
        except (KeyError, ValueError):
            return web.json_response({"error": "invalid slot"}, status=400)

        state.merge_state_from_save()
        result, err_msg = await _compute_reachable(slot, state, reachable_semaphore, log)

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

        state.merge_state_from_save()

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

    app.router.add_get("/health", health)
    app.router.add_get("/state", get_state)
    app.router.add_post("/commands", post_command)
    app.router.add_get("/hints/{slot}", get_hints)
    app.router.add_post("/hints/{slot}/request", request_hint)
    app.router.add_get("/reachable/{slot}", get_reachable)
    app.router.add_get("/item-locations/{slot}", get_item_locations)
    return app
