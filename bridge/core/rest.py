from __future__ import annotations

import asyncio

from fastapi import FastAPI, Request
from starlette.exceptions import HTTPException as StarletteHTTPException
from starlette.responses import JSONResponse

from .ap_client import ArchipelagoClient
from .coordinator import PauseResumeCoordinator
from .rest_hints import router as hints_router
from .rest_operator import OperatorState, router as operator_router
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
    operator_state: OperatorState | None = None,
) -> FastAPI:
    if reachable_semaphore is None:
        reachable_semaphore = asyncio.Semaphore(1)
    if coordinator is None:
        coordinator = PauseResumeCoordinator()

    app = FastAPI(title="Archipelago Bridge", openapi_url="/openapi.json")

    # Store shared objects on app.state
    app.state.bridge_state = state
    app.state.ap_client = ap_client
    app.state.reachable_semaphore = reachable_semaphore
    app.state.coordinator = coordinator
    app.state.operator_state = operator_state
    app.state.broadcast = ws_server.broadcast if ws_server is not None else None

    # Map HTTPException → {"error": detail} for API compat; dict detail passed through as-is
    @app.exception_handler(StarletteHTTPException)
    async def _http_exc(request: Request, exc: StarletteHTTPException) -> JSONResponse:
        if isinstance(exc.detail, dict):
            return JSONResponse(exc.detail, status_code=exc.status_code)
        return JSONResponse({"error": exc.detail}, status_code=exc.status_code)

    app.include_router(session_router)

    # Slot-scoped endpoints (hints + reachable)
    app.include_router(hints_router)
    app.include_router(reachable_router)

    # WebSocket
    if ws_server is not None:
        app.add_api_websocket_route("/ws", ws_server.handle_ws)

    # Operator API (requires operator_state)
    if operator_state is not None:
        app.include_router(operator_router)

    return app
