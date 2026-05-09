#!/usr/bin/env python3
"""Bridge.py - Real-time observer and REST gateway for Archipelago server sessions."""
from __future__ import annotations

import asyncio
import glob
import json
import logging
import os
import pickle
import uuid
import zlib
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any

import aiohttp
from aiohttp import web
import websockets
from websockets.exceptions import ConnectionClosed

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

@dataclass
class Config:
    mercure_hub_url: str
    central_api_secret: str
    symfony_internal_url: str
    run_id: str
    archipelago_ws_url: str = "ws://localhost:38281"
    save_dir: str = "/archipelago/output"
    rest_port: int = 5000
    token_refresh_interval: int = 3000  # 50 minutes

    slot_names: list[dict[str, str]] = field(default_factory=list)
    server_password: str = ""
    admin_password: str = ""

    @classmethod
    def from_env(cls) -> "Config":
        slot_names: list[dict[str, str]] = json.loads(os.environ.get("SLOT_NAMES", "[]"))
        return cls(
            mercure_hub_url=os.environ["MERCURE_HUB_URL"],
            central_api_secret=os.environ["CENTRAL_API_SECRET"],
            symfony_internal_url=os.environ["SYMFONY_INTERNAL_URL"],
            run_id=os.environ["RUN_ID"],
            slot_names=slot_names,
            server_password=os.environ.get("PASSWORD", ""),
            admin_password=os.environ.get("SERVER_PASSWORD", ""),
        )


# ---------------------------------------------------------------------------
# Domain types
# ---------------------------------------------------------------------------

@dataclass
class PlayerState:
    slot_index: int
    slot_name: str = ""
    checks_done: int = 0
    checks_total: int = 0
    items_received: int = 0
    client_status: int = 0
    goal_reached_at: str | None = None
    _checked_locations: set[int] = field(default_factory=set, repr=False)

    def to_dict(self) -> dict[str, Any]:
        return {
            "slot_name": self.slot_name,
            "checks_done": self.checks_done,
            "checks_total": self.checks_total,
            "items_received": self.items_received,
            "client_status": self.client_status,
            "goal_reached_at": self.goal_reached_at,
        }


# ---------------------------------------------------------------------------
# Save parser (AC #8)
# ---------------------------------------------------------------------------

def _save_slot_map(mapping: dict[Any, Any]) -> dict[int, Any]:
    """Normalize AP save keys: (team, slot[, ...]) tuples → slot int (team 0 only).

    received_items uses 3-element keys (team, slot, remote_flag) — values for the
    same slot are merged so the total count is correct.
    """
    result: dict[int, Any] = {}
    for key, val in mapping.items():
        if isinstance(key, tuple) and len(key) >= 2 and key[0] == 0:
            slot = int(key[1])
            if slot in result:
                existing = result[slot]
                if isinstance(existing, list) and isinstance(val, list):
                    result[slot] = existing + val
                elif isinstance(existing, (set, frozenset)) and isinstance(val, (set, frozenset)):
                    result[slot] = existing | val
                # else: scalar (client_game_state) — single key per slot, no conflict
            else:
                result[slot] = val
        elif isinstance(key, int):
            result[key] = val
    return result


def load_save_state(save_dir: str) -> dict[int, PlayerState]:
    """Read latest .apsave file and return initial PlayerState per slot index."""
    import sys
    _ap_src = "/app/ArchipelagoSrc"
    if os.path.isdir(_ap_src) and _ap_src not in sys.path:
        sys.path.insert(0, _ap_src)

    log = logging.getLogger(__name__)
    try:
        files = glob.glob(f"{save_dir}/*.apsave")
        if not files:
            return {}
        latest = max(files, key=os.path.getmtime)
        with open(latest, "rb") as fh:
            raw = fh.read()
        data: dict[str, Any] = pickle.loads(zlib.decompress(raw))  # noqa: S301

        log.info("save keys: %s", list(data.keys()))
        raw_cgs = data.get("client_game_state", {})
        log.info("client_game_state raw (first 10): %s", dict(list(raw_cgs.items())[:10]))

        location_checks = _save_slot_map(data.get("location_checks", {}))
        client_game_state = _save_slot_map(raw_cgs)
        received_items = _save_slot_map(data.get("received_items", {}))

        log.info("client_game_state after slot_map: %s", client_game_state)

        all_slots = set(location_checks) | set(client_game_state) | set(received_items)
        states: dict[int, PlayerState] = {}
        for slot in all_slots:
            checked: set[int] = set(location_checks.get(slot, set()))
            ps = PlayerState(slot_index=slot)
            ps._checked_locations = checked
            ps.checks_done = len(checked)
            ps.client_status = int(client_game_state.get(slot, 0))
            if ps.client_status == 30:
                ps.goal_reached_at = datetime.now(timezone.utc).isoformat()
            ps.items_received = len(received_items.get(slot, []))
            states[slot] = ps

        log.info("save state loaded: %d slot(s) — statuses: %s", len(states), {s: p.client_status for s, p in states.items()})
        return states
    except Exception as exc:
        log.warning("failed to load save state: %s", exc)
        return {}


# ---------------------------------------------------------------------------
# State manager
# ---------------------------------------------------------------------------

class StateManager:
    """Thread-safe (asyncio) in-memory player state store."""

    def __init__(
        self,
        initial: dict[int, PlayerState] | None = None,
        goals_file: str | None = None,
        save_dir: str | None = None,
    ) -> None:
        self._states: dict[int, PlayerState] = dict(initial or {})
        self._goals_file = goals_file
        self._save_dir = save_dir
        self._save_mtime: float = 0.0
        self._log = logging.getLogger(__name__)
        if goals_file:
            self._load_persisted_goals(goals_file)

    def _load_persisted_goals(self, path: str) -> None:
        try:
            with open(path) as fh:
                data: dict[str, Any] = json.load(fh)
            for slot_str, info in data.get("goals", {}).items():
                slot = int(slot_str)
                ps = self.ensure_slot(slot)
                if ps.client_status < 30:
                    ps.client_status = 30
                    ps.goal_reached_at = info.get("goal_reached_at")
            self._log.info("persisted goals loaded from %s", path)
        except FileNotFoundError:
            pass
        except Exception as exc:
            self._log.warning("failed to load persisted goals: %s", exc)

    def _persist_goal(self, slot_index: int, goal_reached_at: str) -> None:
        if not self._goals_file:
            return
        try:
            try:
                with open(self._goals_file) as fh:
                    data: dict[str, Any] = json.load(fh)
            except (FileNotFoundError, json.JSONDecodeError):
                data = {}
            data.setdefault("goals", {})[str(slot_index)] = {"goal_reached_at": goal_reached_at}
            with open(self._goals_file, "w") as fh:
                json.dump(data, fh)
        except Exception as exc:
            self._log.warning("failed to persist goal: %s", exc)

    def get_all(self) -> dict[int, PlayerState]:
        return dict(self._states)

    def ensure_slot(self, slot_index: int) -> PlayerState:
        if slot_index not in self._states:
            self._states[slot_index] = PlayerState(slot_index=slot_index)
        return self._states[slot_index]

    def set_slot_name(self, slot_index: int, name: str) -> None:
        self.ensure_slot(slot_index).slot_name = name

    def set_checks_total(self, slot_index: int, total: int) -> None:
        self.ensure_slot(slot_index).checks_total = total

    def add_location_checks(self, slot_index: int, locations: list[int]) -> None:
        ps = self.ensure_slot(slot_index)
        ps._checked_locations.update(locations)
        ps.checks_done = len(ps._checked_locations)

    def add_received_items(self, slot_index: int, count: int) -> None:
        self.ensure_slot(slot_index).items_received += count

    def set_items_received(self, slot_index: int, count: int) -> None:
        self.ensure_slot(slot_index).items_received = count

    def update_client_status(self, slot_index: int, status: int) -> None:
        ps = self.ensure_slot(slot_index)
        ps.client_status = status
        if status == 30 and ps.goal_reached_at is None:  # GOAL
            ps.goal_reached_at = datetime.now(timezone.utc).isoformat()
            self._persist_goal(slot_index, ps.goal_reached_at)

    def handle_room_info(self, packet: dict[str, Any]) -> None:
        """AC #4 - extract checks_total per slot from locations array."""
        locations: list[int] = packet.get("locations", [])
        for idx, total in enumerate(locations):
            self.set_checks_total(idx, total)

    def handle_status_update(self, packet: dict[str, Any]) -> None:
        """AC #6, #7 - update client_status; record goal_reached_at on GOAL."""
        slot: int = int(packet.get("slot", 0))
        status: int = int(packet.get("status", 0))
        self.update_client_status(slot, status)

    def handle_location_checks(self, packet: dict[str, Any]) -> None:
        """AC #6 - update checks_done for the indicated slot."""
        slot: int = int(packet.get("slot", 0))
        locations: list[int] = [int(loc) for loc in packet.get("locations", [])]
        self.add_location_checks(slot, locations)

    def merge_state_from_save(self) -> None:
        """Sync checks_done, items_received and client_status from the .apsave if it changed."""
        if not self._save_dir:
            return
        try:
            files = glob.glob(f"{self._save_dir}/*.apsave")
            if not files:
                self._log.info("merge_state_from_save: no .apsave in %s", self._save_dir)
                return
            latest = max(files, key=os.path.getmtime)
            mtime = os.path.getmtime(latest)
            if mtime <= self._save_mtime:
                return
            self._save_mtime = mtime
            self._log.info("merge_state_from_save: reading %s", latest)
            saved = load_save_state(self._save_dir)
            for slot_id, saved_ps in saved.items():
                ps = self.ensure_slot(slot_id)
                # Merge checked locations (union → no double-count with live events)
                if saved_ps._checked_locations:
                    ps._checked_locations.update(saved_ps._checked_locations)
                    ps.checks_done = len(ps._checked_locations)
                # Items received: save is authoritative if higher than live counter
                if saved_ps.items_received > ps.items_received:
                    ps.items_received = saved_ps.items_received
                # Client status: only upgrade, never downgrade
                if saved_ps.client_status > ps.client_status:
                    ps.client_status = saved_ps.client_status
                    if saved_ps.client_status == 30 and ps.goal_reached_at is None:
                        ps.goal_reached_at = saved_ps.goal_reached_at
            self._log.info(
                "merge_state_from_save: done — statuses=%s",
                {s: p.client_status for s, p in self._states.items()},
            )
        except Exception as exc:
            self._log.warning("merge_state_from_save failed: %s", exc)

    def to_api_dict(self) -> dict[str, Any]:
        return {"slots": {str(k): v.to_dict() for k, v in self._states.items()}}


# ---------------------------------------------------------------------------
# Data package store - resolves raw AP IDs to human-readable names
# ---------------------------------------------------------------------------

class DataPackageStore:
    """Maps (game, item_id/location_id) → name, and slot_id → player alias."""

    def __init__(self) -> None:
        self._item_names: dict[str, dict[int, str]] = {}    # game → {id → name}
        self._location_names: dict[str, dict[int, str]] = {}  # game → {id → name}
        self._slot_games: dict[int, str] = {}   # slot → game name
        self._slot_aliases: dict[int, str] = {}  # slot → player alias

    def handle_connected(self, packet: dict[str, Any]) -> None:
        for p in packet.get("players", []):
            slot = int(p.get("slot", 0))
            alias = str(p.get("alias", p.get("name", f"Player {slot}")))
            self._slot_aliases[slot] = alias
        for slot_str, info in packet.get("slot_info", {}).items():
            if isinstance(info, dict):
                self._slot_games[int(slot_str)] = str(info.get("game", ""))

    def handle_data_package(self, packet: dict[str, Any]) -> None:
        games: dict[str, Any] = packet.get("data", {}).get("games", {})
        for game, gdata in games.items():
            if not isinstance(gdata, dict):
                continue
            self._item_names[game] = {v: k for k, v in gdata.get("item_name_to_id", {}).items()}
            self._location_names[game] = {v: k for k, v in gdata.get("location_name_to_id", {}).items()}

    def resolve_player(self, slot: int) -> str:
        return self._slot_aliases.get(slot, f"Player {slot}")

    def resolve_item(self, item_id: int, player_slot: int) -> str:
        game = self._slot_games.get(player_slot, "")
        return self._item_names.get(game, {}).get(item_id, f"Item #{item_id}")

    def resolve_location(self, loc_id: int, player_slot: int) -> str:
        game = self._slot_games.get(player_slot, "")
        return self._location_names.get(game, {}).get(loc_id, f"Location #{loc_id}")

    def slot_by_alias(self, name: str) -> int:
        for slot, alias in self._slot_aliases.items():
            if alias == name:
                return slot
        return 0


# ---------------------------------------------------------------------------
# Token manager (AC #9)
# ---------------------------------------------------------------------------

class TokenManager:
    def __init__(self, config: Config, http_session: aiohttp.ClientSession) -> None:
        self._config = config
        self._http = http_session
        self._token: str = ""
        self._refresh_task: asyncio.Task[None] | None = None

    @property
    def token(self) -> str:
        return self._token

    async def fetch_token(self) -> str:
        url = (
            f"{self._config.symfony_internal_url}"
            f"/api/v1/internal/sessions/{self._config.run_id}/publisher-token"
        )
        async with self._http.get(
            url, headers={"X-Internal-Secret": self._config.central_api_secret}
        ) as resp:
            resp.raise_for_status()
            body = await resp.json()
            self._token = body["data"]["token"]
            logging.getLogger(__name__).info("publisher token fetched")
            return self._token

    def schedule_refresh(self, interval: int = 3000) -> None:
        if self._refresh_task and not self._refresh_task.done():
            self._refresh_task.cancel()
        self._refresh_task = asyncio.create_task(self._refresh_loop(interval))

    async def _refresh_loop(self, interval: int) -> None:
        while True:
            await asyncio.sleep(interval)
            try:
                await self.fetch_token()
            except Exception as exc:
                logging.getLogger(__name__).error("token refresh failed: %s", exc)


# ---------------------------------------------------------------------------
# Mercure publisher (AC #5, #6)
# ---------------------------------------------------------------------------

class MercurePublisher:
    def __init__(
        self,
        config: Config,
        token_manager: TokenManager,
        http_session: aiohttp.ClientSession,
    ) -> None:
        self._config = config
        self._tokens = token_manager
        self._http = http_session
        self._log = logging.getLogger(__name__)

    async def publish(self, topic: str, data: dict[str, Any]) -> None:
        payload = {"topic": topic, "data": json.dumps(data)}
        headers = {"Authorization": f"Bearer {self._tokens.token}"}

        async with self._http.post(self._config.mercure_hub_url, data=payload, headers=headers) as resp:
            if resp.status == 401:
                await self._tokens.fetch_token()
            elif resp.status >= 400:
                body = await resp.text()
                self._log.error("mercure publish failed %d: %s", resp.status, body)
                return
            else:
                return  # success

        # Retry once after token refresh
        headers["Authorization"] = f"Bearer {self._tokens.token}"
        async with self._http.post(self._config.mercure_hub_url, data=payload, headers=headers) as resp:
            if resp.status >= 400:
                body = await resp.text()
                self._log.error("mercure retry failed %d: %s", resp.status, body)


# ---------------------------------------------------------------------------
# Archipelago WebSocket client (AC #3, #4, #5, #6, #7, #10)
# ---------------------------------------------------------------------------

_WS_RETRY_DELAYS = [1, 2, 4, 8, 16, 30]

_PRINT_TYPE_MAP: dict[str, str] = {
    "Hint": "hint",
    "ItemSend": "item-received",
    "ItemCheat": "item-received",
    "Chat": "chat",
    "ServerChat": "system",
    "Tutorial": "system",
    "TagsChanged": "system",
    "Goal": "system",
    "Release": "system",
    "Collect": "system",
    "CounterMeasure": "system",
    "Countdown": "system",
}


def _build_feed_event(packet: dict[str, Any], store: DataPackageStore) -> dict[str, Any]:
    """Build a Mercure feed event dict from a PrintJSON packet.

    AP sends *_id parts when the client has no data package; *_name parts when
    it pre-resolves names server-side.  We handle both so the feed always shows
    human-readable text.
    """
    parts: list[str] = []
    for part in packet.get("data", []):
        if not isinstance(part, dict):
            continue
        part_type = part.get("type", "text")
        raw = str(part.get("text", ""))

        try:
            if part_type == "player_id":
                parts.append(store.resolve_player(int(raw)))
            elif part_type == "item_id":
                # player field = who receives this item (determines which game's item map)
                parts.append(store.resolve_item(int(raw), int(part.get("player", 0) or 0)))
            elif part_type == "location_id":
                # player field = whose world this location is in
                parts.append(store.resolve_location(int(raw), int(part.get("player", 0) or 0)))
            else:
                parts.append(raw)
        except (ValueError, TypeError):
            parts.append(raw)

    text = "".join(parts)
    # Some AP versions omit the data array for server messages; fall back to the
    # top-level message/text field so command responses are never silently lost.
    if not text:
        text = str(packet.get("message", "") or packet.get("text", ""))

    msg_type = _PRINT_TYPE_MAP.get(packet.get("type", ""), "system")
    return {
        "type": msg_type,
        "text": text,
        "color": packet.get("color", "white"),
        "timestamp": datetime.now(timezone.utc).isoformat(),
    }


class ArchipelagoClient:
    def __init__(
        self,
        config: Config,
        state: StateManager,
        publisher: MercurePublisher,
    ) -> None:
        self._config = config
        self._state = state
        self._publisher = publisher
        self._store = DataPackageStore()
        self._my_slot: int = 0  # slot the bridge connected as (from Connected packet)
        self._ws: Any = None  # websockets.WebSocketClientProtocol
        self.ws_connected: bool = False
        self._log = logging.getLogger(__name__)

    async def send_command(self, command: str) -> None:
        """Forward an admin command as a WS Say packet (AC #11)."""
        if self._ws is not None and self.ws_connected:
            await self._ws.send(json.dumps([{"cmd": "Say", "text": command}]))

    async def _connect_and_run(self) -> None:
        async with websockets.connect(self._config.archipelago_ws_url) as ws:
            self._ws = ws
            self.ws_connected = True
            self._log.info("connected to archipelago ws at %s", self._config.archipelago_ws_url)

            # AP 0.6.x silently ignores Connect with game:"" - receive RoomInfo first
            # to extract a valid game name before authenticating.
            try:
                raw = await asyncio.wait_for(ws.recv(), timeout=15)
                first_packets: list[dict[str, Any]] = json.loads(raw)
            except Exception as exc:
                self._log.warning("failed to receive RoomInfo: %s", exc)
                return

            games_in_session: list[str] = []
            for packet in first_packets:
                if packet.get("cmd") == "RoomInfo":
                    games_in_session = packet.get("games", [])
                    self._log.info("RoomInfo received - games: %s", games_in_session)
                    self._state.handle_room_info(packet)
                    await self._publish_players()

            if games_in_session:
                await ws.send(json.dumps([{"cmd": "GetDataPackage", "games": games_in_session}]))
                self._log.info("GetDataPackage requested for: %s", games_in_session)

            # AP 0.6.x requires both a valid slot name AND the matching game name.
            # Read them from the YAML files (injected as SLOT_NAMES env var).
            first_slot = self._config.slot_names[0] if self._config.slot_names else {}
            connect_name = first_slot.get("name", "Bridge")
            connect_game = first_slot.get("game", "Archipelago")

            connect_packet = {
                "cmd": "Connect",
                "name": connect_name,
                "game": connect_game,
                "password": self._config.server_password,
                "uuid": str(uuid.uuid4()),
                "version": {"major": 0, "minor": 6, "build": 7, "class": "Version"},
                "tags": ["TextOnly"],
                "items_handling": 0,
                "slot_data": False,
            }
            self._log.info("sending Connect with game=%r name=%r password=%r", connect_game, connect_name, bool(self._config.server_password))
            await ws.send(json.dumps([connect_packet]))

            async for raw in ws:
                try:
                    packets: list[dict[str, Any]] = json.loads(raw)
                except Exception as exc:
                    self._log.warning("packet parse error: %s", exc)
                    continue
                for packet in packets:
                    try:
                        await self._handle_packet(packet)
                    except Exception as exc:
                        self._log.warning("packet handling error (%s): %s", packet.get("cmd", "?"), exc)

    async def _handle_packet(self, packet: dict[str, Any]) -> None:
        cmd: str = packet.get("cmd", "")
        self._log.debug("packet received: %s", cmd)

        if cmd == "ConnectionRefused":
            errors = packet.get("errors", [])
            self._log.error("connection refused by archipelago server: %s", errors)
            raise RuntimeError(f"ConnectionRefused: {errors}")

        if cmd == "RoomInfo":
            self._log.info("RoomInfo received (reconnect)")
            self._state.handle_room_info(packet)
            await self._publish_players()

        elif cmd == "DataPackage":
            self._store.handle_data_package(packet)
            games_loaded = list(packet.get("data", {}).get("games", {}).keys())
            self._log.info("DataPackage received - games: %s", games_loaded)

        elif cmd == "Connected":
            players: list[dict[str, Any]] = packet.get("players", [])
            slot_info: dict[str, Any] = packet.get("slot_info", {})
            self._my_slot = int(packet.get("slot", 0))
            self._log.info("Connected received - slot=%d players=%d", self._my_slot, len(players))
            self._store.handle_connected(packet)

            if self._config.admin_password and self._ws is not None:
                await self._ws.send(json.dumps([{"cmd": "Say", "text": f"!admin {self._config.admin_password}"}]))
                self._log.info("admin auth sent")

            for slot_str, info in slot_info.items():
                if isinstance(info, dict):
                    self._state.set_slot_name(int(slot_str), info.get("name", ""))
            for p in players:
                slot = int(p.get("slot", 0))
                name = p.get("alias", p.get("name", ""))
                if slot:
                    self._state.set_slot_name(slot, name)

            # checks_total: authoritative for our slot from Connected, DataPackage for others
            checked_locs = [int(loc) for loc in packet.get("checked_locations", [])]
            missing_locs = packet.get("missing_locations", [])
            if self._my_slot:
                total = len(checked_locs) + len(missing_locs)
                if total > 0:
                    self._state.set_checks_total(self._my_slot, total)
                if checked_locs:
                    self._state.add_location_checks(self._my_slot, checked_locs)

            # DataPackage gives checks_total for all other slots
            for slot_id, game in self._store._slot_games.items():
                if slot_id == self._my_slot:
                    continue
                dp_total = len(self._store._location_names.get(game, {}))
                if dp_total > 0:
                    self._state.set_checks_total(slot_id, dp_total)

            # Infer PLAYING for slots that already have checks (connected before bridge)
            for slot_id, ps in self._state.get_all().items():
                if ps.client_status == 0 and ps.checks_done > 0:
                    self._state.update_client_status(slot_id, 20)

            self._log.info("state after Connected: %s", list(self._state.get_all().keys()))
            await self._publish_players()

        elif cmd == "PrintJSON":
            msg_type = packet.get("type", "")
            state_changed = False
            if msg_type == "ItemSend":
                self._track_item_send(packet)
                state_changed = True
            elif msg_type == "Goal":
                self._track_goal(packet)
                state_changed = True
            event = _build_feed_event(packet, self._store)
            await self._publisher.publish(f"runs/{self._config.run_id}/feed", event)
            if state_changed:
                await self._publish_players()

        elif cmd == "StatusUpdate":
            slot = int(packet.get("slot", 0))
            status = int(packet.get("status", 0))
            self._log.info("StatusUpdate: slot=%d status=%d", slot, status)
            self._state.handle_status_update(packet)
            await self._publish_players()

        elif cmd == "ReceivedItems":
            # items_handling:0 → AP never sends ReceivedItems to TextOnly clients.
            # Kept as fallback in case the server sends it anyway.
            if self._my_slot:
                items: list[Any] = packet.get("items", [])
                if packet.get("index", -1) == 0:
                    self._state.set_items_received(self._my_slot, len(items))
                elif items:
                    self._state.add_received_items(self._my_slot, len(items))
            await self._publish_players()

        elif cmd == "LocationChecks":
            self._state.handle_location_checks(packet)
            await self._publish_players()

    def _slot_from_part(self, part: dict[str, Any]) -> int:
        """Extract slot index from a player_id or player_name data part."""
        try:
            if part.get("type") == "player_id":
                return int(str(part.get("text", "0")))
            if part.get("type") == "player_name":
                return self._store.slot_by_alias(str(part.get("text", "")))
        except (ValueError, TypeError):
            pass
        return 0

    def _track_item_send(self, packet: dict[str, Any]) -> None:
        """Extract checks_done (finder) and items_received (receiver) from ItemSend PrintJSON.

        AP 0.6.7 puts the player context in the first player_id/player_name part of the
        message, not in a 'player' sub-field of each item/location part.
        """
        data = [p for p in packet.get("data", []) if isinstance(p, dict)]

        # The first player part is always the finder (sender).
        sender = 0
        for part in data:
            sender = self._slot_from_part(part)
            if sender:
                break

        if not sender:
            return

        for part in data:
            part_type = part.get("type", "")
            try:
                raw = str(part.get("text", ""))
                if part_type == "location_id":
                    loc_id = int(raw)
                    if loc_id:
                        self._state.add_location_checks(sender, [loc_id])
                        ps = self._state.ensure_slot(sender)
                        if ps.client_status < 20:
                            self._state.update_client_status(sender, 20)
                elif part_type == "item_id":
                    # Try player sub-field first (newer AP); fall back to sender (single-game).
                    receiver = int(part.get("player", 0) or sender)
                    if receiver:
                        self._state.add_received_items(receiver, 1)
            except (ValueError, TypeError):
                pass

    def _track_goal(self, packet: dict[str, Any]) -> None:
        """Set slot status to GOAL (30) when a Goal PrintJSON is received."""
        # AP includes the slot directly at the top level of Goal packets.
        top_slot = int(packet.get("slot", 0))
        if top_slot:
            self._state.update_client_status(top_slot, 30)
            return
        # Fallback: parse data parts (older AP versions).
        for part in packet.get("data", []):
            if not isinstance(part, dict):
                continue
            slot = self._slot_from_part(part)
            if slot:
                self._state.update_client_status(slot, 30)
                return

    async def _publish_players(self) -> None:
        await self._publisher.publish(
            f"runs/{self._config.run_id}/players",
            self._state.to_api_dict(),
        )

    async def run_with_reconnect(self) -> None:
        """Run WS client with exponential backoff reconnect (AC #10)."""
        retry_idx = 0
        while True:
            try:
                await self._connect_and_run()
                self.ws_connected = False
                self._ws = None
                retry_idx = 0
                self._log.info("ws connection closed cleanly, reconnecting in 5s")
                await asyncio.sleep(5)
            except Exception as exc:
                self.ws_connected = False
                self._ws = None
                delay = _WS_RETRY_DELAYS[min(retry_idx, len(_WS_RETRY_DELAYS) - 1)]
                self._log.warning(
                    "ws disconnected (%s), reconnecting in %ds", exc, delay
                )
                retry_idx += 1
                await asyncio.sleep(delay)


# ---------------------------------------------------------------------------
# REST API (AC #11)
# ---------------------------------------------------------------------------

def create_app(state: StateManager, ap_client: ArchipelagoClient) -> web.Application:
    app = web.Application()

    async def health(_: web.Request) -> web.Response:
        return web.json_response({"status": "ok", "ws_connected": ap_client.ws_connected})

    async def get_state(_: web.Request) -> web.Response:
        state.merge_state_from_save()
        return web.json_response(state.to_api_dict())

    async def post_command(request: web.Request) -> web.Response:
        try:
            body = await request.json()
        except (json.JSONDecodeError, Exception):
            return web.json_response({"error": "invalid_json"}, status=400)
        command = body.get("command")
        if not isinstance(command, str) or not command.strip():
            return web.json_response({"error": "command is required"}, status=400)
        if not ap_client.ws_connected:
            return web.json_response(
                {"error": "ws_disconnected", "message": "Le serveur Archipelago est déconnecté"},
                status=503,
            )
        await ap_client.send_command(command)
        return web.json_response({"ok": True})

    app.router.add_get("/health", health)
    app.router.add_get("/state", get_state)
    app.router.add_post("/commands", post_command)
    return app


# ---------------------------------------------------------------------------
# Structured logging (AC #12)
# ---------------------------------------------------------------------------

def setup_logging(run_id: str) -> None:
    class _JsonFormatter(logging.Formatter):
        def format(self, record: logging.LogRecord) -> str:
            return json.dumps({
                "event": record.getMessage(),
                "run_id": run_id,
                "timestamp": datetime.fromtimestamp(record.created, tz=timezone.utc).isoformat(),
                "severity": record.levelname,
            })

    handler = logging.StreamHandler()
    handler.setFormatter(_JsonFormatter())
    root = logging.getLogger()
    root.handlers = [handler]
    root.setLevel(logging.INFO)


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

async def _heartbeat_loop(config: Config, http: aiohttp.ClientSession) -> None:
    log = logging.getLogger(__name__)
    url = (
        f"{config.symfony_internal_url}"
        f"/api/v1/internal/sessions/{config.run_id}/heartbeat"
    )
    while True:
        await asyncio.sleep(30)
        try:
            async with http.post(
                url, headers={"X-Internal-Secret": config.central_api_secret}
            ) as resp:
                if resp.status >= 400:
                    log.warning("heartbeat failed %d", resp.status)
        except Exception as exc:
            log.warning("heartbeat error: %s", exc)


async def _main() -> None:
    config = Config.from_env()
    setup_logging(config.run_id)
    log = logging.getLogger(__name__)

    initial_state = load_save_state(config.save_dir)
    goals_file = os.path.join(config.save_dir, "bridge_goals.json")
    state = StateManager(initial_state, goals_file=goals_file, save_dir=config.save_dir)

    timeout = aiohttp.ClientTimeout(total=10)
    async with aiohttp.ClientSession(timeout=timeout) as http:
        token_mgr = TokenManager(config, http)
        await token_mgr.fetch_token()
        token_mgr.schedule_refresh(config.token_refresh_interval)

        publisher = MercurePublisher(config, token_mgr, http)
        ap_client = ArchipelagoClient(config, state, publisher)

        app = create_app(state, ap_client)
        runner = web.AppRunner(app)
        await runner.setup()
        site = web.TCPSite(runner, "0.0.0.0", config.rest_port)
        await site.start()
        log.info("REST API listening on port %d", config.rest_port)

        _heartbeat_task = asyncio.create_task(_heartbeat_loop(config, http))

        try:
            await ap_client.run_with_reconnect()
        finally:
            _heartbeat_task.cancel()


if __name__ == "__main__":
    asyncio.run(_main())
