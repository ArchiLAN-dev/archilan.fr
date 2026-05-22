from __future__ import annotations

import asyncio
import json
import logging
import uuid
from collections.abc import Awaitable, Callable
from datetime import datetime, timezone
from typing import Any

import websockets

from .config import Config
from .domain import HintInfo
from .state import StateManager

_WS_RETRY_DELAYS = [1, 2, 4, 8, 16, 30]

# Maps AP PrintJSON types to the spec's FeedEventType names
_PRINT_TYPE_MAP: dict[str, str] = {
    "Hint": "hint",
    "ItemSend": "item_sent",
    "ItemCheat": "item_sent",
    "Chat": "chat",
    "ServerChat": "system",
    "Tutorial": "system",
    "TagsChanged": "system",
    "Goal": "goal",
    "Release": "release",
    "Collect": "collect",
    "Forfeit": "forfeit",
    "CounterMeasure": "system",
    "Countdown": "countdown",
    "Join": "join",
    "Part": "part",
}

_CLIENT_STATUS_NAMES: dict[int, str] = {
    0: "idle",
    5: "idle",
    10: "idle",
    20: "playing",
    30: "goal_reached",
    40: "done",
}

_SLOT_TYPE_NAMES: dict[int, str] = {
    1: "player",
    2: "spectator",
    3: "group",
}

# AP permission bitmask → named string
_PERMISSION_NAMES: dict[int, str] = {
    0: "disabled",
    1: "goal",
    2: "enabled",
    3: "enabled",
    4: "auto",
    6: "auto",
    7: "auto_enabled",
}

BroadcastFn = Callable[[str, dict[str, Any]], Awaitable[None]]


class DataPackageStore:
    """Maps (game, item_id / location_id) → name, and slot_id → player alias / game / type."""

    def __init__(self) -> None:
        self._item_names: dict[str, dict[int, str]] = {}
        self._location_names: dict[str, dict[int, str]] = {}
        self._slot_games: dict[int, str] = {}
        self._slot_aliases: dict[int, str] = {}
        self._slot_types: dict[int, str] = {}

    def handle_connected(self, packet: dict[str, Any]) -> None:
        for p in packet.get("players", []):
            slot = int(p.get("slot", 0))
            alias = str(p.get("alias", p.get("name", f"Player {slot}")))
            self._slot_aliases[slot] = alias
        for slot_str, info in packet.get("slot_info", {}).items():
            if isinstance(info, dict):
                slot = int(slot_str)
                self._slot_games[slot] = str(info.get("game", ""))
                raw_type = info.get("type", 1)
                self._slot_types[slot] = _SLOT_TYPE_NAMES.get(int(raw_type), "player")

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

    def resolve_hint_names(self, hint: HintInfo) -> HintInfo:
        return HintInfo(
            receiving_player=hint.receiving_player,
            finding_player=hint.finding_player,
            location_id=hint.location_id,
            item_id=hint.item_id,
            entrance=hint.entrance,
            item_flags=hint.item_flags,
            status=hint.status,
            receiving_player_name=self.resolve_player(hint.receiving_player),
            finding_player_name=self.resolve_player(hint.finding_player),
            item_name=self.resolve_item(hint.item_id, hint.receiving_player),
            location_name=self.resolve_location(hint.location_id, hint.finding_player),
        )


def _build_feed_event(packet: dict[str, Any], store: DataPackageStore) -> dict[str, Any]:
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
        broadcast: BroadcastFn,
        recompute_event: asyncio.Event | None = None,
    ) -> None:
        self._config = config
        self._state = state
        self._broadcast = broadcast
        self._recompute_event: asyncio.Event = recompute_event if recompute_event is not None else asyncio.Event()
        self._store = DataPackageStore()
        self._my_slot: int = 0
        self._ws: Any = None
        self.ws_connected: bool = False
        self._log = logging.getLogger(__name__)

        # Room state (populated from AP RoomInfo / RoomUpdate)
        self._room_forfeit_mode: str = "disabled"
        self._room_release_mode: str = "disabled"
        self._room_collect_mode: str = "disabled"
        self._death_link_active: bool = False
        self._race_mode: bool = False

        # Per-slot connection tracking (populated from Join/Part PrintJSON)
        self._connected_slots: set[int] = set()

    # ------------------------------------------------------------------
    # Read-only views for WsServer snapshot
    # ------------------------------------------------------------------

    def get_room_dict(self) -> dict[str, Any]:
        return {
            "sessionId": self._config.session_id,
            "slotCount": len(self._state.get_all()),
            "hintCostPercent": self._state._hint_cost_pct,
            "locationCheckPoints": self._state._location_check_points,
            "forfeitMode": self._room_forfeit_mode,
            "releaseMode": self._room_release_mode,
            "collectMode": self._room_collect_mode,
            "deathLinkActive": self._death_link_active,
            "raceMode": self._race_mode,
            "wsConnected": self.ws_connected,
        }

    def get_slots_summary(self) -> list[dict[str, Any]]:
        result = []
        for slot_id, ps in sorted(self._state.get_all().items()):
            result.append({
                "slot": slot_id,
                "name": ps.slot_name,
                "game": self._store._slot_games.get(slot_id, ""),
                "type": self._store._slot_types.get(slot_id, "player"),
                "status": _CLIENT_STATUS_NAMES.get(ps.client_status, "idle"),
                "connected": slot_id in self._connected_slots,
                "checksDone": ps.checks_done,
                "checksTotal": ps.checks_total,
                "itemsReceived": ps.items_received,
                "goalReachedAt": ps.goal_reached_at,
                "reachableNow": ps.reachable_now,
            })
        return result

    # ------------------------------------------------------------------
    # Commands
    # ------------------------------------------------------------------

    async def send_command(self, command: str) -> None:
        if self._ws is not None and self.ws_connected:
            await self._ws.send(json.dumps([{"cmd": "Say", "text": command}]))

    async def send_packet(self, packet: dict[str, Any]) -> None:
        if self._ws is None or not self.ws_connected:
            raise RuntimeError("WebSocket not connected")
        await self._ws.send(json.dumps([packet]))

    # ------------------------------------------------------------------
    # Connection loop
    # ------------------------------------------------------------------

    async def _connect_and_run(self) -> None:
        async with websockets.connect(self._config.ap_ws_url) as ws:
            self._ws = ws
            self.ws_connected = True
            self._log.info("connected to archipelago ws at %s", self._config.ap_ws_url)

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
                    self._handle_room_info_permissions(packet)
                    await self._broadcast_state_changed()

            if games_in_session:
                await ws.send(json.dumps([{"cmd": "GetDataPackage", "games": games_in_session}]))

            first_slot = self._config.slot_names[0] if self._config.slot_names else {}
            connect_name = first_slot.get("name", "Bridge")
            connect_game = first_slot.get("game", "Archipelago")

            connect_packet = {
                "cmd": "Connect",
                "name": connect_name,
                "game": connect_game,
                "password": self._config.ap_server_password,
                "uuid": str(uuid.uuid4()),
                "version": {"major": 0, "minor": 6, "build": 7, "class": "Version"},
                "tags": ["TextOnly"],
                "items_handling": 0,
                "slot_data": False,
            }
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

    def _handle_room_info_permissions(self, packet: dict[str, Any]) -> None:
        perms = packet.get("permissions")
        if not isinstance(perms, dict):
            return
        forfeit_raw = perms.get("forfeit", 0)
        remaining_raw = perms.get("remaining", 0)
        release_raw = perms.get("release", remaining_raw)
        collect_raw = perms.get("collect", 0)
        self._room_forfeit_mode = _PERMISSION_NAMES.get(int(forfeit_raw or 0), "disabled")
        self._room_release_mode = _PERMISSION_NAMES.get(int(release_raw or 0), "disabled")
        self._room_collect_mode = _PERMISSION_NAMES.get(int(collect_raw or 0), "disabled")

    async def _handle_packet(self, packet: dict[str, Any]) -> None:
        cmd: str = packet.get("cmd", "")
        self._log.debug("packet received: %s", cmd)

        if cmd == "ConnectionRefused":
            errors = packet.get("errors", [])
            self._log.error("connection refused by archipelago server: %s", errors)
            raise RuntimeError(f"ConnectionRefused: {errors}")

        elif cmd == "RoomInfo":
            self._log.info("RoomInfo received (reconnect)")
            self._state.handle_room_info(packet)
            self._handle_room_info_permissions(packet)
            await self._broadcast_state_changed()

        elif cmd == "RoomUpdate":
            self._handle_room_update(packet)
            await self._broadcast_room_updated()

        elif cmd == "DataPackage":
            self._store.handle_data_package(packet)
            games_loaded = list(packet.get("data", {}).get("games", {}).keys())
            self._log.info("DataPackage received - games: %s", games_loaded)
            self._resolve_all_hint_names()
            for slot_id, game in self._store._slot_games.items():
                if slot_id == self._my_slot:
                    continue
                dp_total = len(self._store._location_names.get(game, {}))
                if dp_total > 0:
                    self._state.apply_hint_cost_for_slot(slot_id, dp_total)

        elif cmd == "Connected":
            await self._handle_connected(packet)

        elif cmd == "PrintJSON":
            await self._handle_print_json(packet)

        elif cmd == "StatusUpdate":
            slot = int(packet.get("slot", 0))
            status = int(packet.get("status", 0))
            self._log.info("StatusUpdate: slot=%d status=%d", slot, status)
            self._state.handle_status_update(packet)
            await self._broadcast_state_changed()

        elif cmd == "ReceivedItems":
            await self._handle_received_items(packet)

        elif cmd == "LocationChecks":
            self._state.handle_location_checks(packet)
            self._recompute_event.set()
            await self._broadcast_state_changed()

        elif cmd == "Bounced":
            await self._handle_bounced(packet)

    def _handle_room_update(self, packet: dict[str, Any]) -> None:
        # RoomUpdate is a partial RoomInfo; only update fields that are present
        if "permissions" in packet:
            self._handle_room_info_permissions(packet)
        if "hint_cost" in packet or "location_check_points" in packet:
            self._state.handle_room_info(packet)

    async def _handle_connected(self, packet: dict[str, Any]) -> None:
        players: list[dict[str, Any]] = packet.get("players", [])
        slot_info: dict[str, Any] = packet.get("slot_info", {})
        self._my_slot = int(packet.get("slot", 0))
        self._log.info("Connected received - slot=%d players=%d", self._my_slot, len(players))
        self._store.handle_connected(packet)

        if self._config.ap_admin_password and self._ws is not None:
            await self._ws.send(json.dumps([{"cmd": "Say", "text": f"!admin login {self._config.ap_admin_password}"}]))

        for slot_str, info in slot_info.items():
            if isinstance(info, dict):
                self._state.set_slot_name(int(slot_str), info.get("name", ""))
        for p in players:
            slot = int(p.get("slot", 0))
            name = p.get("alias", p.get("name", ""))
            if slot:
                self._state.set_slot_name(slot, name)
                self._connected_slots.add(slot)

        checked_locs = [int(loc) for loc in packet.get("checked_locations", [])]
        missing_locs = packet.get("missing_locations", [])
        if self._my_slot:
            total = len(checked_locs) + len(missing_locs)
            if total > 0:
                self._state.set_checks_total(self._my_slot, total)
                self._state.apply_hint_cost_for_slot(self._my_slot, total)
            if checked_locs:
                self._state.add_location_checks(self._my_slot, checked_locs)

        for slot_id, game in self._store._slot_games.items():
            if slot_id == self._my_slot:
                continue
            dp_total = len(self._store._location_names.get(game, {}))
            if dp_total > 0:
                self._state.set_checks_total(slot_id, dp_total)
                self._state.apply_hint_cost_for_slot(slot_id, dp_total)

        for slot_id, ps in self._state.get_all().items():
            if ps.client_status == 0 and ps.checks_done > 0:
                self._state.update_client_status(slot_id, 20)

        await self._broadcast_state_changed()

    async def _handle_print_json(self, packet: dict[str, Any]) -> None:
        msg_type = packet.get("type", "")
        state_changed = False

        if msg_type == "ItemSend":
            self._track_item_send(packet)
            state_changed = True
        elif msg_type == "Goal":
            self._track_goal(packet)
            state_changed = True
        elif msg_type == "Hint":
            await self._track_hint(packet)
            state_changed = True
        elif msg_type == "Join":
            slot = int(packet.get("slot", 0))
            if slot:
                self._connected_slots.add(slot)
                state_changed = True
        elif msg_type == "Part":
            slot = int(packet.get("slot", 0))
            if slot:
                self._connected_slots.discard(slot)
                state_changed = True

        event = _build_feed_event(packet, self._store)
        await self._broadcast("feed", {
            "sessionId": self._config.session_id,
            "event": event,
        })

        if state_changed:
            await self._broadcast_state_changed()

    async def _handle_received_items(self, packet: dict[str, Any]) -> None:
        items: list[Any] = packet.get("items", [])
        if self._my_slot:
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
        if items:
            await self._broadcast_state_changed()

    async def _handle_bounced(self, packet: dict[str, Any]) -> None:
        tags: list[str] = packet.get("tags", [])
        data: dict[str, Any] = packet.get("data", {})
        if "DeathLink" not in tags:
            return

        source = str(data.get("source", ""))
        cause = data.get("cause")
        self._death_link_active = True

        await self._broadcast("feed", {
            "sessionId": self._config.session_id,
            "event": {
                "type": "death_link",
                "source": source,
                "cause": cause if isinstance(cause, str) else None,
                "timestamp": datetime.now(timezone.utc).isoformat(),
            },
        })

    # ------------------------------------------------------------------
    # State tracking helpers
    # ------------------------------------------------------------------

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

    def _resolve_all_hint_names(self) -> None:
        for slot_id, ps in self._state._states.items():
            if not ps._hints:
                continue
            ps._hints = [self._store.resolve_hint_names(h) for h in ps._hints]
            self._log.info("resolved names for %d hint(s) on slot %d", len(ps._hints), slot_id)

    def resolve_slot_hint_names(self, slot_id: int) -> None:
        ps = self._state._states.get(slot_id)
        if not ps or not ps._hints:
            return
        ps._hints = [self._store.resolve_hint_names(h) for h in ps._hints]

    async def _track_hint(self, packet: dict[str, Any]) -> None:
        receiving_player = int(packet.get("receiving", 0))
        net_item = packet.get("item", {})
        if not isinstance(net_item, dict):
            return

        item_id = int(net_item.get("item", 0))
        location_id = int(net_item.get("location", 0))
        finding_player = int(net_item.get("player", 0))
        item_flags = int(net_item.get("flags", 0))

        if not (item_id and location_id and receiving_player):
            return

        status_raw = packet.get("status", None)
        if status_raw is not None:
            status = int(status_raw)
        elif packet.get("found", False):
            status = 40
        else:
            status = 0

        hint = HintInfo(
            receiving_player=receiving_player,
            finding_player=finding_player,
            location_id=location_id,
            item_id=item_id,
            entrance=str(packet.get("entrance", "")),
            item_flags=item_flags,
            status=status,
            receiving_player_name=self._store.resolve_player(receiving_player),
            finding_player_name=self._store.resolve_player(finding_player),
            item_name=self._store.resolve_item(item_id, receiving_player),
            location_name=self._store.resolve_location(location_id, finding_player),
        )
        changed = self._state.add_hint(receiving_player, hint)
        if changed:
            await self._broadcast_hints(receiving_player)

    # ------------------------------------------------------------------
    # Broadcast helpers
    # ------------------------------------------------------------------

    async def _broadcast_state_changed(self) -> None:
        await self._broadcast("state_changed", {
            "sessionId": self._config.session_id,
            "slots": self.get_slots_summary(),
        })

    async def _broadcast_room_updated(self) -> None:
        await self._broadcast("room_updated", {
            "sessionId": self._config.session_id,
            "room": self.get_room_dict(),
        })

    async def _broadcast_hints(self, slot: int) -> None:
        hints = self._state.get_hints(slot)
        ps = self._state._states.get(slot)
        await self._broadcast("hints_changed", {
            "sessionId": self._config.session_id,
            "slot": slot,
            "hints": [h.to_dict() for h in hints],
            "budget": {
                "hintsUsed": ps.hints_used if ps else 0,
                "pointsAvailable": ps.hint_points_available if ps else 0,
                "costPerHint": ps.hint_cost if ps else 0,
                "pointsPerCheck": ps.hint_points_per_check if ps else 1,
            },
        })

    # ------------------------------------------------------------------
    # Reconnect loop
    # ------------------------------------------------------------------

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
