from __future__ import annotations

import asyncio
from dataclasses import dataclass


@dataclass
class PauseResumeCoordinator:
    wake_stop_event: asyncio.Event | None = None
    wake_task: "asyncio.Task[None] | None" = None
