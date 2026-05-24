from __future__ import annotations

import glob
import json
import logging
import os
from datetime import datetime, timezone
from typing import Any

from .domain import HintInfo, PlayerState
from .save_parser import load_save_state


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
        # hint_cost_pct: the raw percentage from RoomInfo (e.g. 10 = 10%)
        # actual cost per hint = max(1, int(pct * 0.01 * total_locations_for_slot))
        self._hint_cost_pct: int = 0
        self._location_check_points: int = 1
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
            ps = PlayerState(slot_index=slot_index)
            ps.hint_points_per_check = self._location_check_points
            self._states[slot_index] = ps
        return self._states[slot_index]

    def compute_hint_cost(self, total_locations: int) -> int:
        """Compute the actual hint cost in points given a slot's total location count."""
        if not self._hint_cost_pct or not total_locations:
            return 0
        return max(1, int(self._hint_cost_pct * 0.01 * total_locations))

    def apply_hint_cost_for_slot(self, slot_index: int, total_locations: int) -> None:
        """Set the correct hint cost for a slot once its total location count is known."""
        cost = self.compute_hint_cost(total_locations)
        if cost:
            ps = self.ensure_slot(slot_index)
            ps.hint_cost = cost
            self._log.info("hint cost for slot %d: %d pts/hint (%d locs × %d%%)",
                           slot_index, cost, total_locations, self._hint_cost_pct)

    def set_slot_name(self, slot_index: int, name: str) -> None:
        self.ensure_slot(slot_index).slot_name = name

    def set_checks_total(self, slot_index: int, total: int) -> None:
        self.ensure_slot(slot_index).checks_total = total

    def add_location_checks(self, slot_index: int, locations: list[int]) -> None:
        ps = self.ensure_slot(slot_index)
        ps._checked_locations.update(locations)
        ps.checks_done = len(ps._checked_locations)

    def add_item_received(self, slot_index: int, item_id: int, sender_slot: int, location_id: int) -> None:
        ps = self.ensure_slot(slot_index)
        ps._received_items.append((item_id, sender_slot, location_id))
        ps.items_received = len(ps._received_items)

    def add_received_items(self, slot_index: int, count: int) -> None:
        """Increment items_received by count (simple counter update)."""
        self.ensure_slot(slot_index).items_received += count

    def update_client_status(self, slot_index: int, status: int) -> None:
        ps = self.ensure_slot(slot_index)
        ps.client_status = status
        if status == 30 and ps.goal_reached_at is None:  # GOAL
            ps.goal_reached_at = datetime.now(timezone.utc).isoformat()
            self._persist_goal(slot_index, ps.goal_reached_at)

    def handle_room_info(self, packet: dict[str, Any]) -> None:
        """AC #4 - extract checks_total per slot; also set names from slot_info if present.

        RoomInfo carries the session-level hint economy already computed by AP:
          hint_cost            - actual points required per hint (NOT a percentage)
          location_check_points - points earned per location check
        """
        locations: list[int] = packet.get("locations", [])
        for idx, total in enumerate(locations):
            self.set_checks_total(idx, total)
        for slot_str, info in packet.get("slot_info", {}).items():
            if isinstance(info, dict):
                self.set_slot_name(int(slot_str), info.get("name", ""))

        hint_cost_pct = int(packet.get("hint_cost", 0))
        location_check_points = int(packet.get("location_check_points", 1))
        if hint_cost_pct > 0:
            self._hint_cost_pct = hint_cost_pct
            self._location_check_points = location_check_points
            for ps in self._states.values():
                ps.hint_points_per_check = location_check_points
            self._log.info(
                "hint economy from RoomInfo: cost_pct=%d%%, earn=%d pts/check",
                hint_cost_pct, location_check_points,
            )

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

    def handle_received_items(self, packet: dict[str, Any]) -> None:
        """Increment items_received for the slot indicated in the packet."""
        slot: int = int(packet.get("slot", 0))
        items: list[Any] = packet.get("items", [])
        self.add_received_items(slot, len(items))

    def add_hint(self, slot_index: int, hint: HintInfo) -> bool:
        """Add or update a hint for a slot. Returns True if state changed.

        hints_used is intentionally NOT updated here - it is loaded from the
        apsave (AP's authoritative paid-hint counter) and incremented by the
        REST handler only when a paid hint request is explicitly made.
        Free (admin) hints must not affect the budget counter.
        """
        ps = self.ensure_slot(slot_index)
        for i, existing in enumerate(ps._hints):
            if existing.location_id == hint.location_id and existing.item_id == hint.item_id:
                if existing != hint:
                    ps._hints[i] = hint
                    return True
                return False
        ps._hints.append(hint)
        return True

    def get_hints(self, slot_index: int) -> list[HintInfo]:
        ps = self._states.get(slot_index)
        return list(ps._hints) if ps else []

    def apply_saved_states(self, saved: dict[int, PlayerState]) -> None:
        """Merge a dict of saved PlayerStates into current in-memory state."""
        for slot_id, saved_ps in saved.items():
            ps = self.ensure_slot(slot_id)
            if saved_ps._checked_locations:
                ps._checked_locations.update(saved_ps._checked_locations)
                ps.checks_done = len(ps._checked_locations)
            if saved_ps.items_received > ps.items_received:
                ps._received_items = saved_ps._received_items
                ps.items_received = saved_ps.items_received
            if saved_ps.client_status > ps.client_status:
                ps.client_status = saved_ps.client_status
                if saved_ps.client_status == 30 and ps.goal_reached_at is None:
                    ps.goal_reached_at = saved_ps.goal_reached_at
            # Hints: apsave is authoritative (includes status updates set by other clients)
            # hint_cost intentionally NOT overwritten - it's set by apply_hint_cost_for_slot
            # from the RoomInfo WS packet; the apsave only stores the default (0).
            if saved_ps._hints:
                ps._hints = saved_ps._hints
                ps.hints_used = saved_ps.hints_used
                ps.hint_points_per_check = saved_ps.hint_points_per_check

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
            self.apply_saved_states(saved)
            self._log.info(
                "merge_state_from_save: done - statuses=%s",
                {s: p.client_status for s, p in self._states.items()},
            )
        except Exception as exc:
            self._log.warning("merge_state_from_save failed: %s", exc)

    def to_api_dict(self) -> dict[str, Any]:
        return {"slots": {str(k): v.to_dict() for k, v in self._states.items()}}
