from __future__ import annotations

import asyncio
import logging
from collections.abc import Awaitable, Callable


class WakeOnConnectServer:
    """
    Opens a TCP server on the AP port while the AP process is down.
    When the first client connects the socket is immediately closed (client gets
    connection-reset - expected UX) and on_connect() is called to trigger an
    automatic restart.  Setting stop_event before any connection is received
    causes serve() to return cleanly without calling on_connect().
    """

    def __init__(
        self,
        ap_port: int,
        stop_event: asyncio.Event,
        on_connect: Callable[[], Awaitable[None]],
    ) -> None:
        self._ap_port = ap_port
        self._stop_event = stop_event
        self._on_connect = on_connect
        self._log = logging.getLogger("bridge.wake_on_connect")

    async def serve(self) -> None:
        """Listen for the first TCP connection; call on_connect() when one arrives."""
        first_connection = asyncio.Event()

        async def _handle_client(
            _reader: asyncio.StreamReader,
            writer: asyncio.StreamWriter,
        ) -> None:
            try:
                writer.close()
                await writer.wait_closed()
            except Exception:
                pass
            first_connection.set()

        server = await asyncio.start_server(
            _handle_client,
            "0.0.0.0",
            self._ap_port,
        )
        self._log.info("wake: TCP listener started on port %d", self._ap_port)

        wait_connect = asyncio.create_task(first_connection.wait())
        wait_stop = asyncio.create_task(self._stop_event.wait())

        try:
            done, pending = await asyncio.wait(
                [wait_connect, wait_stop],
                return_when=asyncio.FIRST_COMPLETED,
            )
            for task in pending:
                task.cancel()
        finally:
            server.close()
            await server.wait_closed()
            self._log.info("wake: TCP listener closed on port %d", self._ap_port)

        # Only call on_connect if a real player connection triggered this
        if first_connection.is_set() and not self._stop_event.is_set():
            self._log.info("wake: player connection detected, triggering restart")
            await self._on_connect()
        else:
            self._log.info("wake: listener cancelled (stop_event set, no restart)")
