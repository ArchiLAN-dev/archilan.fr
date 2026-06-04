from __future__ import annotations

import asyncio
from typing import Any

from fastapi import FastAPI, Request
from starlette.exceptions import HTTPException as StarletteHTTPException
from starlette.responses import JSONResponse

from .ap_client import ArchipelagoClient
from .coordinator import PauseResumeCoordinator
from .rest_checks import router as checks_router
from .rest_hints import router as hints_router
from .rest_output import router as output_router
from .rest_reachable import router as reachable_router
from .rest_session import router as session_router
from .state import StateManager
from .ws_server import WsServer


def create_app(
    state: StateManager,
    ap_client: ArchipelagoClient,
    reachable_semaphore: asyncio.Semaphore | None = None,
    *,
    coordinator: PauseResumeCoordinator | None = None,
    ws_server: WsServer | None = None,
    runtime: Any = None,
) -> FastAPI:
    if reachable_semaphore is None:
        reachable_semaphore = asyncio.Semaphore(1)
    if coordinator is None:
        coordinator = PauseResumeCoordinator()

    app = FastAPI(title="Archipelago Bridge", openapi_url="/openapi.json")

    app.state.bridge_state = state
    app.state.ap_client = ap_client
    app.state.reachable_semaphore = reachable_semaphore
    app.state.coordinator = coordinator
    app.state.runtime = runtime
    app.state.broadcast = ws_server.broadcast if ws_server is not None else None

    @app.exception_handler(StarletteHTTPException)
    async def _http_exc(request: Request, exc: StarletteHTTPException) -> JSONResponse:
        if isinstance(exc.detail, dict):
            return JSONResponse(exc.detail, status_code=exc.status_code)
        return JSONResponse({"error": exc.detail}, status_code=exc.status_code)

    app.include_router(session_router)
    app.include_router(hints_router)
    app.include_router(checks_router)
    app.include_router(reachable_router)
    app.include_router(output_router)

    if ws_server is not None:
        app.add_api_websocket_route("/ws", ws_server.handle_ws)

    return app
