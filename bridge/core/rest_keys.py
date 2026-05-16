from __future__ import annotations

import asyncio
from typing import TYPE_CHECKING

from aiohttp.web import AppKey

if TYPE_CHECKING:
    from .ap_client import ArchipelagoClient
    from .coordinator import PauseResumeCoordinator
    from .state import StateManager

APP_STATE: AppKey[StateManager] = AppKey("state")
APP_AP_CLIENT: AppKey[ArchipelagoClient] = AppKey("ap_client")
APP_COORDINATOR: AppKey[PauseResumeCoordinator] = AppKey("coordinator")
APP_SEMAPHORE: AppKey[asyncio.Semaphore] = AppKey("semaphore")
