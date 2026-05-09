from __future__ import annotations

from datetime import datetime, timezone
from typing import Any


class SessionStore:
    """In-memory store for session generation state."""

    def __init__(self) -> None:
        self._sessions: dict[str, dict[str, Any]] = {}

    def create(self, session_id: str) -> dict[str, Any]:
        entry: dict[str, Any] = {
            "sessionId": session_id,
            "status": "generating",
            "outputFile": None,
            "error": None,
            "startedAt": datetime.now(timezone.utc).isoformat(),
        }
        self._sessions[session_id] = entry
        return entry

    def get(self, session_id: str) -> dict[str, Any] | None:
        return self._sessions.get(session_id)

    def update(self, session_id: str, **kwargs: Any) -> None:
        if session_id in self._sessions:
            self._sessions[session_id].update(kwargs)
