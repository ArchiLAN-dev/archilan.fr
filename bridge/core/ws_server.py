from __future__ import annotations

import asyncio
import json
import logging
from typing import TYPE_CHECKING, Any

from starlette.websockets import WebSocket, WebSocketDisconnect

from .config import Config

if TYPE_CHECKING:
    from .ap_client import ArchipelagoClient
    from .state import StateManager


class WsServer:
    """WebSocket server — real-time event bus between the bridge and its clients."""

    def __init__(self, config: Config) -> None:
        self._config = config
        self._clients: set[WebSocket] = set()
        self._pending_requests: dict[str, asyncio.Future[dict[str, Any]]] = {}
        self._state: StateManager | None = None
        self._ap_client: ArchipelagoClient | None = None
        self._log = logging.getLogger(__name__)

    def bind(self, state: StateManager, ap_client: ArchipelagoClient) -> None:
        self._state = state
        self._ap_client = ap_client

    # ------------------------------------------------------------------
    # Public API used by ap_client, loops, and rest_session
    # ------------------------------------------------------------------

    async def broadcast(self, event_type: str, payload: dict[str, Any]) -> None:
        """Send an event notification to all connected WS clients."""
        if not self._clients:
            return
        msg = json.dumps({"type": event_type, **payload})
        dead: set[WebSocket] = set()
        for ws in list(self._clients):
            try:
                await ws.send_text(msg)
            except Exception:
                dead.add(ws)
        self._clients -= dead

    async def request_approve_restart(self) -> bool:
        """Ask all connected clients to approve an automatic restart (5 s timeout).

        Any client responding with approved=true causes an immediate True return.
        No connected clients or timeout → False.
        """
        if not self._clients:
            return False

        req_id = f"req-{id(self)}"
        loop = asyncio.get_running_loop()
        fut: asyncio.Future[dict[str, Any]] = loop.create_future()
        self._pending_requests[req_id] = fut

        request_msg = json.dumps({"id": req_id, "type": "request", "action": "approve_restart"})
        dead: set[WebSocket] = set()
        for ws in list(self._clients):
            try:
                await ws.send_text(request_msg)
            except Exception:
                dead.add(ws)
        self._clients -= dead

        try:
            response = await asyncio.wait_for(fut, timeout=5.0)
            return bool(response.get("approved", False))
        except asyncio.TimeoutError:
            self._pending_requests.pop(req_id, None)
            return False

    # ------------------------------------------------------------------
    # WebSocket route handler (Starlette WebSocket)
    # ------------------------------------------------------------------

    async def handle_ws(self, websocket: WebSocket) -> None:
        token = websocket.query_params.get("token", "")
        if not self._config.internal_token or token != self._config.internal_token:
            await websocket.close(code=4001)
            return

        await websocket.accept()
        self._clients.add(websocket)
        self._log.info("ws client connected, total=%d", len(self._clients))

        try:
            await self._send_snapshot(websocket)
            while True:
                try:
                    data = await websocket.receive_text()
                    await self._handle_message(websocket, data)
                except WebSocketDisconnect:
                    break
        finally:
            self._clients.discard(websocket)
            self._log.info("ws client disconnected, total=%d", len(self._clients))

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------

    async def _send_snapshot(self, ws: WebSocket) -> None:
        ap = self._ap_client
        if ap is None:
            return
        snapshot = {
            "type": "snapshot",
            "sessionId": self._config.session_id,
            "room": ap.get_room_dict(),
            "slots": ap.get_slots_summary(),
            "wsConnected": ap.ws_connected,
        }
        await ws.send_text(json.dumps(snapshot))

    async def _handle_message(self, ws: WebSocket, raw: str) -> None:
        try:
            msg: dict[str, Any] = json.loads(raw)
        except json.JSONDecodeError:
            return

        msg_type = msg.get("type", "")
        if msg_type == "command":
            await self._handle_command(ws, msg)
        elif msg_type == "response":
            self._handle_response(msg)

    async def _handle_command(self, ws: WebSocket, msg: dict[str, Any]) -> None:
        text = str(msg.get("text", ""))
        cmd_id = msg.get("id")
        ap = self._ap_client

        if ap is None or not ap.ws_connected:
            if cmd_id:
                await ws.send_text(json.dumps({"type": "error", "id": cmd_id, "code": "ws_disconnected"}))
            return

        await ap.send_command(text)
        if cmd_id:
            await ws.send_text(json.dumps({"type": "ack", "id": cmd_id, "queued": True}))

    def _handle_response(self, msg: dict[str, Any]) -> None:
        req_id = str(msg.get("id", ""))
        if req_id in self._pending_requests:
            fut = self._pending_requests.pop(req_id)
            if not fut.done():
                fut.set_result(msg)
