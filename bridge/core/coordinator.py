from __future__ import annotations

import asyncio
from collections.abc import Awaitable, Callable
from dataclasses import dataclass, field


async def _default_approve() -> bool:
    return False


@dataclass
class PauseResumeCoordinator:
    wake_stop_event: asyncio.Event | None = None
    wake_task: "asyncio.Task[None] | None" = None
    request_approve_restart: Callable[[], Awaitable[bool]] = field(
        default_factory=lambda: _default_approve,
    )
