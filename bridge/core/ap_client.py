from __future__ import annotations

import asyncio
import json
import logging
import uuid
from datetime import datetime, timezone
from typing import Any

import websockets

from config import Config
from mercure import MercurePublisher
from state import StateManager

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


class DataPackageStore:
    """Maps (game, item_id/location_id) → name, and slot_id → player alias."""

    def __init__(self) -> None:
        self._item_names: dict[str, dict[int, str]] = {}
        self._location_names: dict[str, dict[int, str]] = {}
        self._slot_games: dict[int, str] = {}
        self._slot_aliases: dict[int, str] = {}

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


def _build_feed_event(packet: dict[str, Any], store: DataPackageStore) -> dict[str, Any]:
    """Build a Mercure feed event dict from a PrintJSON packet."""
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
                parts.append(store.resolve_item(int(raw), int(part.get("player", 0) or 0)))
            elif part_type == "location_id":
                parts.append(store.resolve_location(int(raw), int(part.get("player", 0) or 0)))
            else:
                parts.append(raw)
        except (ValueError, TypeError):
            parts.append(raw)

    text = "".join(parts)
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
        recompute_event: asyncio.Event | None = None,
    ) -> None:
        self._config = config
        self._state = state
        self._publisher = publisher
        self._recompute_event: asyncio.Event = recompute_event if recompute_event is not None else asyncio.Event()
        self._store = DataPackageStore()
        self._my_slot: int = 0
        self._ws: Any = None
        self.ws_connected: bool = False
        self._log = logging.getLogger(__name__)

    async def send_command(self, command: str) -> None:
        if self._ws is not None and self.ws_connected:
            await self._ws.send(json.dumps([{"cmd": "Say", "text": command}]))

    async def _connect_and_run(self) -> None:
        async with websockets.connect(self._config.archipelago_ws_url) as ws:
            self._ws = ws
            self.ws_connected = True
            self._log.info("connected to archipelago ws at %s", self._config.archipelago_ws_url)

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
            self._log.info(
                "sending Connect with game=%r name=%r password=%r",
                connect_game, connect_name, bool(self._config.server_password),
            )
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

            checked_locs = [int(loc) for loc in packet.get("checked_locations", [])]
            missing_locs = packet.get("missing_locations", [])
            if self._my_slot:
                total = len(checked_locs) + len(missing_locs)
                if total > 0:
                    self._state.set_checks_total(self._my_slot, total)
                if checked_locs:
                    self._state.add_location_checks(self._my_slot, checked_locs)

            for slot_id, game in self._store._slot_games.items():
                if slot_id == self._my_slot:
                    continue
                dp_total = len(self._store._location_names.get(game, {}))
                if dp_total > 0:
                    self._state.set_checks_total(slot_id, dp_total)

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
            if self._my_slot:
                items: list[Any] = packet.get("items", [])
                if packet.get("index", -1) == 0:
                    ps = self._state.ensure_slot(self._my_slot)
                    ps._received_items = [
                        (int(it.get("item", 0)), int(it.get("player", 0)), int(it.get("location", 0)))
                        for it in items if isinstance(it, dict)
                    ]
                    ps.items_received = len(ps._received_items)
                    self._recompute_event.set()
                elif items:
                    for it in items:
                        if isinstance(it, dict):
                            self._state.add_item_received(
                                self._my_slot,
                                int(it.get("item", 0)),
                                int(it.get("player", 0)),
                                int(it.get("location", 0)),
                            )
                    self._recompute_event.set()
            await self._publish_players()

        elif cmd == "LocationChecks":
            self._state.handle_location_checks(packet)
            self._recompute_event.set()
            await self._publish_players()

    def _slot_from_part(self, part: dict[str, Any]) -> int:
        try:
            if part.get("type") == "player_id":
                return int(str(part.get("text", "0")))
            if part.get("type") == "player_name":
                return self._store.slot_by_alias(str(part.get("text", "")))
        except (ValueError, TypeError):
            pass
        return 0

    def _track_item_send(self, packet: dict[str, Any]) -> None:
        data = [p for p in packet.get("data", []) if isinstance(p, dict)]

        sender = 0
        for part in data:
            sender = self._slot_from_part(part)
            if sender:
                break
        if not sender:
            return

        found_item_id = 0
        found_loc_id = 0
        found_receiver = 0

        for part in data:
            part_type = part.get("type", "")
            try:
                raw_val = int(str(part.get("text", "0")))
            except (ValueError, TypeError):
                continue
            if part_type == "location_id" and raw_val:
                found_loc_id = raw_val
            elif part_type == "item_id" and raw_val:
                found_item_id = raw_val
                found_receiver = int(part.get("player", 0) or sender)

        if found_loc_id:
            self._state.add_location_checks(sender, [found_loc_id])
            ps = self._state.ensure_slot(sender)
            if ps.client_status < 20:
                self._state.update_client_status(sender, 20)

        if found_item_id and found_receiver:
            self._state.add_item_received(found_receiver, found_item_id, sender, found_loc_id)

        if found_loc_id or (found_item_id and found_receiver):
            self._recompute_event.set()

    def _track_goal(self, packet: dict[str, Any]) -> None:
        top_slot = int(packet.get("slot", 0))
        if top_slot:
            self._state.update_client_status(top_slot, 30)
            return
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
                self._log.warning("ws disconnected (%s), reconnecting in %ds", exc, delay)
                retry_idx += 1
                await asyncio.sleep(delay)
