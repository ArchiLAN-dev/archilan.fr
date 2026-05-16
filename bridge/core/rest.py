from __future__ import annotations

import asyncio

from aiohttp import web

from .ap_client import ArchipelagoClient
from .coordinator import PauseResumeCoordinator
from .rest_hints import get_hints, request_hint
from .rest_keys import APP_AP_CLIENT, APP_COORDINATOR, APP_SEMAPHORE, APP_STATE
from .rest_reachable import get_item_locations, get_reachable
from .rest_session import (
    get_state,
    health,
    post_command,
    post_pause,
    post_resume,
    post_save,
)
from .state import StateManager


def create_app(
    state: StateManager,
    ap_client: ArchipelagoClient,
    reachable_semaphore: asyncio.Semaphore | None = None,
    *,
    coordinator: PauseResumeCoordinator | None = None,
) -> web.Application:
    if reachable_semaphore is None:
        reachable_semaphore = asyncio.Semaphore(1)
    if coordinator is None:
        coordinator = PauseResumeCoordinator()
    app = web.Application()
    app[APP_STATE] = state
    app[APP_AP_CLIENT] = ap_client
    app[APP_SEMAPHORE] = reachable_semaphore
    app[APP_COORDINATOR] = coordinator

    app.router.add_get("/health", health)
    app.router.add_get("/state", get_state)
    app.router.add_post("/commands", post_command)
    app.router.add_post("/save", post_save)
    app.router.add_post("/pause", post_pause)
    app.router.add_post("/resume", post_resume)
    app.router.add_get("/hints/{slot}", get_hints)
    app.router.add_post("/hints/{slot}/request", request_hint)
    app.router.add_get("/reachable/{slot}", get_reachable)
    app.router.add_get("/item-locations/{slot}", get_item_locations)
    return app
