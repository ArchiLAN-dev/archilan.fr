from __future__ import annotations

import asyncio
import hmac
from collections.abc import Awaitable, Callable
from typing import Any

from fastapi import Depends, Header, HTTPException, Request

from .ap_client import ArchipelagoClient
from .coordinator import PauseResumeCoordinator
from .state import StateManager

BroadcastFn = Callable[[str, dict[str, Any]], Awaitable[None]]


def get_bridge_state(request: Request) -> StateManager:
    return request.app.state.bridge_state


def get_ap_client(request: Request) -> ArchipelagoClient:
    return request.app.state.ap_client


def get_coordinator(request: Request) -> PauseResumeCoordinator:
    return request.app.state.coordinator


def get_semaphore(request: Request) -> asyncio.Semaphore:
    return request.app.state.reachable_semaphore


def get_broadcast(request: Request) -> BroadcastFn | None:
    return getattr(request.app.state, "broadcast", None)


def get_runtime(request: Request) -> Any:
    return getattr(request.app.state, "runtime", None)


async def require_auth(
    ap_client: ArchipelagoClient = Depends(get_ap_client),
    authorization: str = Header(default=""),
) -> None:
    token = ap_client._config.internal_token
    if not token or not hmac.compare_digest(authorization, f"Bearer {token}"):
        raise HTTPException(status_code=401, detail="unauthorized")


def is_authorized(
    ap_client: ArchipelagoClient = Depends(get_ap_client),
    authorization: str = Header(default=""),
) -> bool:
    token = ap_client._config.internal_token
    if not token or not authorization:
        return False
    return hmac.compare_digest(authorization, f"Bearer {token}")
