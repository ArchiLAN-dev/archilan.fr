from __future__ import annotations

import asyncio
import json
import logging
from typing import Any

from aiohttp import web

from ap_client import ArchipelagoClient
from reachable import _compute_reachable
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
            new_reachable = result.get("counts", {}).get("reachable_now", 0)
            if ps.reachable_now != new_reachable:
                ps.reachable_now = new_reachable
                await ap_client._publish_players()

        return web.json_response(result)

    app.router.add_get("/health", health)
    app.router.add_get("/state", get_state)
    app.router.add_post("/commands", post_command)
    app.router.add_get("/reachable/{slot}", get_reachable)
    return app
