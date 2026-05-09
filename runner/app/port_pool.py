from __future__ import annotations

import threading


class PortPool:
    """Thread-safe in-memory pool of TCP ports within a fixed range."""

    def __init__(self, start: int, end: int) -> None:
        if start > end:
            raise ValueError("Port pool start must be lower than or equal to end.")

        self._managed: set[int] = set(range(start, end + 1))
        self._available: set[int] = set(range(start, end + 1))
        self._in_use: set[int] = set()
        self._lock = threading.Lock()

    def allocate(self) -> int | None:
        """Return the lowest available port and mark it as in use, or None if exhausted."""
        with self._lock:
            if not self._available:
                return None
            port = min(self._available)
            self._available.discard(port)
            self._in_use.add(port)
            return port

    def release(self, port: int) -> None:
        """Return a previously allocated managed port to the pool."""
        with self._lock:
            if port not in self._managed or port not in self._in_use:
                return

            self._in_use.remove(port)
            self._available.add(port)

    @property
    def total(self) -> int:
        with self._lock:
            return len(self._available) + len(self._in_use)

    @property
    def available(self) -> int:
        with self._lock:
            return len(self._available)

    @property
    def in_use(self) -> int:
        with self._lock:
            return len(self._in_use)
